<?php
/*
 * KnK Inn — iCalendar feed of all bookings
 *
 *   https://knkinn.com/bookings.ics.php?key=<ics_key>
 *
 * Simmo subscribes to this URL from his KnKinnSaigon@gmail Google Calendar
 * (Settings → Add calendar → From URL). Google re-polls every few hours, so
 * confirmed / pending bookings show up automatically without any manual sync.
 *
 * Security: a random key from config.php is required. URL is unguessable and
 * never exposed on the public site (only shown to logged-in admin). Rotating
 * the key in config.php rotates the feed.
 */

require_once __DIR__ . "/includes/bookings_store.php";
require_once __DIR__ . "/includes/ics_builder.php";

$CFG_path = __DIR__ . "/config.php";
if (!file_exists($CFG_path)) {
    http_response_code(500);
    echo "Server not configured.";
    exit;
}
$CFG = require $CFG_path;
$expected = (string)($CFG["ics_key"] ?? "");

$got = (string)($_GET["key"] ?? "");
if ($expected === "" || !hash_equals($expected, $got)) {
    http_response_code(403);
    header("Content-Type: text/plain; charset=UTF-8");
    echo "Forbidden.";
    exit;
}

$ROOM_LABELS = [
    "basic"             => "Basic",
    "standard-nowindow" => "Standard",
    "standard-balcony"  => "Superior",
    "vip"               => "Premium",
];

$holds = bookings_list_all(true);

/* Optional ?type= filter — when set, the feed only contains
 * bookings for one room type. Used for OTA channel sync (Airbnb /
 * Booking.com / Tripadvisor each accept an iCal URL per listing,
 * so we publish three: ?type=standard-nowindow, ?type=standard-
 * balcony, ?type=vip). Leave blank for the legacy all-types
 * feed Simmo subscribes to from his Google Calendar. */
$type_filter = trim((string)($_GET["type"] ?? ""));
$valid_types = ["standard-nowindow", "standard-balcony", "vip"];
if ($type_filter !== "" && !in_array($type_filter, $valid_types, true)) {
    http_response_code(400);
    header("Content-Type: text/plain; charset=UTF-8");
    echo "Unknown type. Use one of: " . implode(", ", $valid_types);
    exit;
}

/* Only include bookings that still hold their dates: confirmed, and non-expired pending. */
$events = [];
$now = time();
foreach ($holds as $h) {
    $status = $h["status"] ?? "pending";
    if (in_array($status, ["declined", "expired", "cancelled", "completed"], true)) continue;
    if ($status === "pending" && ($now - ($h["created_at"] ?? 0)) > KNK_HOLD_TTL) continue;
    if ($type_filter !== "" && ($h["room"] ?? "") !== $type_filter) continue;
    $events[] = $h;
}

$stamp = gmdate("Ymd\THis\Z");

$lines = [];
$lines[] = "BEGIN:VCALENDAR";
$lines[] = "VERSION:2.0";
$lines[] = "PRODID:-//KnK Inn//Bookings//EN";
$lines[] = "CALSCALE:GREGORIAN";
$lines[] = "METHOD:PUBLISH";
$cal_name = $type_filter !== ""
    ? ("KnK Inn — " . ($ROOM_LABELS[$type_filter] ?? $type_filter))
    : "KnK Inn bookings";
$lines[] = "X-WR-CALNAME:" . $cal_name;
$lines[] = "X-WR-TIMEZONE:Asia/Ho_Chi_Minh";
$lines[] = "X-WR-CALDESC:" . ($type_filter !== ""
    ? "Confirmed + pending bookings for " . ($ROOM_LABELS[$type_filter] ?? $type_filter) . " — for OTA channel sync."
    : "Confirmed and pending room bookings for KnK Inn.");

foreach ($events as $h) {
    $id     = $h["id"] ?? "";
    $room   = $ROOM_LABELS[$h["room"] ?? ""] ?? ($h["room"] ?? "Room");
    $guest  = $h["guest"] ?? [];
    $name   = $guest["name"] ?? "";
    $status = $h["status"] ?? "pending";

    $statusTag = $status === "pending" ? " (PENDING)" : "";
    $summary = $name !== ""
        ? "{$room} · {$name}{$statusTag}"
        : "{$room}{$statusTag}";

    $desc_parts = [];
    if ($status === "pending") $desc_parts[] = "⚠︎ PENDING — not yet confirmed";
    if (!empty($guest["email"]))   $desc_parts[] = "Email: " . $guest["email"];
    if (!empty($guest["phone"]))   $desc_parts[] = "Phone: " . $guest["phone"];
    if (!empty($guest["guests"]))  $desc_parts[] = "Guests: " . $guest["guests"];
    $nights = (int)($h["nights"] ?? 0);
    if ($nights > 0) $desc_parts[] = "{$nights} night" . ($nights === 1 ? "" : "s");
    if (!empty($h["price_vnd_per_night"])) {
        $total = (int)$h["price_vnd_per_night"] * $nights;
        $desc_parts[] = number_format((int)$h["price_vnd_per_night"], 0, ".", ",") . " VND/night"
                      . ($total ? " · total " . number_format($total, 0, ".", ",") . " VND" : "");
    }
    if (!empty($guest["message"])) $desc_parts[] = "Message: " . $guest["message"];
    $desc_parts[] = "Hold ID: " . $id;
    $desc = implode("\n", $desc_parts);

    $uid = ($id !== "" ? $id : "hold_" . substr(md5(json_encode($h)), 0, 10)) . "@knkinn.com";

    $lines[] = "BEGIN:VEVENT";
    $lines[] = "UID:" . $uid;
    $lines[] = "DTSTAMP:" . $stamp;
    $lines[] = "DTSTART;VALUE=DATE:" . knk_ics_date($h["checkin"]);
    $lines[] = "DTEND;VALUE=DATE:"   . knk_ics_date($h["checkout"]);  // exclusive, matches hotel checkout
    $lines[] = knk_ics_fold("SUMMARY:" . knk_ics_esc($summary));
    $lines[] = knk_ics_fold("DESCRIPTION:" . knk_ics_esc($desc));
    $lines[] = "STATUS:" . ($status === "confirmed" ? "CONFIRMED" : "TENTATIVE");
    $lines[] = "TRANSP:OPAQUE";
    $lines[] = "CATEGORIES:KnK Inn," . knk_ics_esc($room);
    $lines[] = "END:VEVENT";
}

$lines[] = "END:VCALENDAR";

$ics = implode("\r\n", $lines) . "\r\n";

header("Content-Type: text/calendar; charset=UTF-8");
header('Content-Disposition: inline; filename="knkinn-bookings.ics"');
header("Cache-Control: no-store, max-age=0");
echo $ics;

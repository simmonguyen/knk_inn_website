<?php
/*
 * KnK Inn — iCalendar event builder
 *
 * knk_ics_single($hold)  → full VCALENDAR body for ONE booking (attach to confirm email).
 * knk_ics_esc / knk_ics_fold / knk_ics_date  — RFC 5545 helpers, reused by bookings.ics.php.
 */

if (!function_exists('knk_ics_esc')) {
function knk_ics_esc(string $s): string {
    $s = str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], $s);
    return $s;
}
}
if (!function_exists('knk_ics_fold')) {
function knk_ics_fold(string $line): string {
    if (strlen($line) <= 75) return $line;
    $out = "";
    $first = true;
    while (strlen($line) > 0) {
        $take = $first ? 75 : 74;
        $out .= ($first ? "" : " ") . substr($line, 0, $take);
        $line = substr($line, $take);
        if (strlen($line) > 0) $out .= "\r\n";
        $first = false;
    }
    return $out;
}
}
if (!function_exists('knk_ics_date')) {
function knk_ics_date(string $ymd): string {
    return str_replace("-", "", $ymd);
}
}

/**
 * Build a single-event VCALENDAR for attaching to a confirmation email.
 * Returns the ICS text (CRLF-terminated).
 */
function knk_ics_single(array $hold, array $room_labels = []): string {
    $id     = $hold["id"] ?? "";
    $room   = $room_labels[$hold["room"] ?? ""] ?? ($hold["room"] ?? "Room");
    $guest  = $hold["guest"] ?? [];
    $name   = $guest["name"] ?? "";

    $summary = $name !== "" ? "{$room} · {$name}" : $room;

    $desc_parts = [];
    if (!empty($guest["email"]))  $desc_parts[] = "Email: " . $guest["email"];
    if (!empty($guest["phone"]))  $desc_parts[] = "Phone: " . $guest["phone"];
    if (!empty($guest["guests"])) $desc_parts[] = "Guests: " . $guest["guests"];
    $nights = (int)($hold["nights"] ?? 0);
    if ($nights > 0) $desc_parts[] = "{$nights} night" . ($nights === 1 ? "" : "s");
    if (!empty($hold["price_vnd_per_night"])) {
        $total = (int)$hold["price_vnd_per_night"] * $nights;
        $desc_parts[] = number_format((int)$hold["price_vnd_per_night"], 0, ".", ",") . " VND/night"
                     . ($total ? " · total " . number_format($total, 0, ".", ",") . " VND" : "");
    }
    if (!empty($guest["message"])) $desc_parts[] = "Message: " . $guest["message"];
    $desc_parts[] = "Hold ID: " . $id;
    $desc = implode("\n", $desc_parts);

    $uid   = ($id !== "" ? $id : "hold_" . substr(md5(json_encode($hold)), 0, 10)) . "@knkinn.com";
    $stamp = gmdate("Ymd\THis\Z");

    $L = [];
    $L[] = "BEGIN:VCALENDAR";
    $L[] = "VERSION:2.0";
    $L[] = "PRODID:-//KnK Inn//Bookings//EN";
    $L[] = "CALSCALE:GREGORIAN";
    $L[] = "METHOD:PUBLISH";
    $L[] = "BEGIN:VEVENT";
    $L[] = "UID:" . $uid;
    $L[] = "DTSTAMP:" . $stamp;
    $L[] = "DTSTART;VALUE=DATE:" . knk_ics_date($hold["checkin"]);
    $L[] = "DTEND;VALUE=DATE:"   . knk_ics_date($hold["checkout"]);
    $L[] = knk_ics_fold("SUMMARY:" . knk_ics_esc($summary));
    $L[] = knk_ics_fold("DESCRIPTION:" . knk_ics_esc($desc));
    $L[] = "STATUS:CONFIRMED";
    $L[] = "TRANSP:OPAQUE";
    $L[] = "CATEGORIES:KnK Inn," . knk_ics_esc($room);
    $L[] = "END:VEVENT";
    $L[] = "END:VCALENDAR";

    return implode("\r\n", $L) . "\r\n";
}

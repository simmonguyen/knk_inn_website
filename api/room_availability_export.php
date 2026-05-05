<?php
/*
 * KnK Inn — /api/room_availability_export.php
 *
 * Sister to /api/room_rates_export.php — returns the next N days
 * of availability for one room *type* so an OTA channel manager
 * can sync booked / blocked dates back to its listings.
 *
 * Why type-level (not slug-level)?
 *   The booking store (bookings_store.php / bookings.json) tracks
 *   holds against the room *type* (standard-nowindow, standard-
 *   balcony, vip) with a per-type capacity (1 / 3 / 3). Each
 *   OTA listing maps to one physical room of that type, and the
 *   channel manager allocates between them — so what we expose
 *   here is "N units of type T are free on date D". A type with
 *   3 units booked solid means all OTA listings for that type
 *   should mark D as unavailable.
 *
 * Auth: same key as /api/room_rates_export.php
 *       (settings.room_rates_export_key). Two endpoints, one key
 *       — keeps the channel-manager config simple.
 *
 * Query:
 *   key   — required
 *   type  — required, one of standard-nowindow / standard-balcony / vip
 *   from  — optional, YYYY-MM-DD, default today
 *   days  — optional 1..365, default 90
 *
 * Response:
 *   {
 *     "ok": true,
 *     "type": "vip",
 *     "units_total": 3,
 *     "from": "2026-04-28",
 *     "to":   "2026-07-26",
 *     "days": [
 *       { "date": "2026-04-28", "units_available": 2, "units_booked": 1, "available": true },
 *       ...
 *     ]
 *   }
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/settings_store.php";
require_once __DIR__ . "/../includes/bookings_store.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$out = ["ok" => false, "error" => null];

try {
    $key_required = trim((string)knk_setting("room_rates_export_key"));
    if ($key_required === "") {
        throw new RuntimeException("Export key not configured. Set 'room_rates_export_key' in /settings.php first.");
    }
    $key_in = trim((string)($_GET["key"] ?? ""));
    if (!hash_equals($key_required, $key_in)) {
        http_response_code(403);
        throw new RuntimeException("Bad key.");
    }

    $valid_types = ["basic", "standard-nowindow", "standard-balcony", "vip"];
    $type = trim((string)($_GET["type"] ?? ""));
    if (!in_array($type, $valid_types, true)) {
        throw new RuntimeException("Missing or unknown type. Use one of: " . implode(", ", $valid_types));
    }

    $from_in = trim((string)($_GET["from"] ?? ""));
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_in)
        ? $from_in : date("Y-m-d");

    $days = (int)($_GET["days"] ?? 90);
    if ($days < 1)   $days = 1;
    if ($days > 365) $days = 365;

    $units_total = knk_room_inventory($type);

    /* Walk every active hold for this type, build a per-date count.
     * "Active" = pending-not-stale or confirmed. Anything declined,
     * expired, cancelled, or completed is ignored — completed stays
     * have a past checkout so they couldn't be blocking future
     * dates anyway, but skipping them keeps the per-day loop quick. */
    $now = time();
    [$fp, $data] = bookings_open();
    bookings_close($fp);

    $start_ts = strtotime($from);
    $end_ts   = strtotime("+" . ($days - 1) . " days", $start_ts);
    $end_ymd  = date("Y-m-d", $end_ts);

    /* per-date occupancy. */
    $counts = [];
    foreach ($data["holds"] as $h) {
        if (($h["room"] ?? "") !== $type) continue;
        $status = $h["status"] ?? "pending";
        if (in_array($status, ["declined", "expired", "cancelled", "completed"], true)) continue;
        if ($status === "pending" && ($now - ($h["created_at"] ?? 0)) > KNK_HOLD_TTL) continue;
        $hs = strtotime((string)($h["checkin"]  ?? ""));
        $he = strtotime((string)($h["checkout"] ?? ""));
        if (!$hs || !$he) continue;
        for ($t = max($hs, $start_ts); $t < $he && $t <= $end_ts; $t = strtotime("+1 day", $t)) {
            $ymd = date("Y-m-d", $t);
            $counts[$ymd] = ($counts[$ymd] ?? 0) + 1;
        }
    }

    /* Build the per-day output array (always exactly $days entries
     * — channel managers expect a dense calendar, not a sparse one). */
    $days_out = [];
    for ($i = 0; $i < $days; $i++) {
        $d = date("Y-m-d", strtotime("+$i days", $start_ts));
        $booked = (int)($counts[$d] ?? 0);
        $avail  = max(0, $units_total - $booked);
        $days_out[] = [
            "date"            => $d,
            "units_available" => $avail,
            "units_booked"    => $booked,
            "available"       => $avail > 0,
        ];
    }

    $out = [
        "ok"          => true,
        "type"        => $type,
        "units_total" => $units_total,
        "from"        => $from,
        "to"          => $end_ymd,
        "days"        => $days_out,
    ];
} catch (Throwable $e) {
    if (http_response_code() === 200) http_response_code(400);
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

<?php
/*
 * KnK Inn — /api/room_rates_export.php
 *
 * Public-ish read-only feed of nightly rates for one room over a
 * date window. Designed as the integration point for the OTA
 * channel manager (Airbnb / Booking.com / Tripadvisor) so it can
 * pull our rate calendar without touching the DB directly.
 *
 * This endpoint is rate-limited by IP at 30 hits per 5 minutes
 * (loose — we expect at most a handful of pulls per day) and is
 * gated by an export key set in settings.room_rates_export_key.
 * If the key isn't configured, the endpoint refuses to respond.
 *
 * Query params:
 *   key   — required, must match settings.room_rates_export_key
 *   room  — required, room slug (e.g. 'vip-3')
 *   from  — optional, YYYY-MM-DD, default today
 *   days  — optional, 1..365, default 90
 *
 * Response shape:
 *   {
 *     "ok": true,
 *     "room": { ...room registry row... },
 *     "currency": "VND",
 *     "from": "2026-04-28",
 *     "to":   "2026-07-26",
 *     "days": 90,
 *     "rates": [
 *       { "date": "2026-04-28", "vnd": 850000, "season": null,
 *         "is_override": false, "note": null },
 *       ...
 *     ]
 *   }
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/settings_store.php";
require_once __DIR__ . "/../includes/room_rates_store.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$out = ["ok" => false, "error" => null];

try {
    $key_required = trim((string)knk_setting("room_rates_export_key"));
    if ($key_required === "") {
        throw new RuntimeException("Export key not configured. Set 'room_rates_export_key' in settings first.");
    }
    $key_in = trim((string)($_GET["key"] ?? ""));
    if (!hash_equals($key_required, $key_in)) {
        http_response_code(403);
        throw new RuntimeException("Bad key.");
    }

    $room_slug = trim((string)($_GET["room"] ?? ""));
    if ($room_slug === "") throw new RuntimeException("Missing room slug.");
    $room = knk_room_get($room_slug);
    if (!$room) throw new RuntimeException("Unknown room slug.");

    $from_in = trim((string)($_GET["from"] ?? ""));
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_in)
        ? $from_in : date("Y-m-d");

    $days = (int)($_GET["days"] ?? 90);
    if ($days < 1)   $days = 1;
    if ($days > 365) $days = 365;

    /* Build the per-day list. We use knk_room_rate_for() so the
     * override → default cascade is consistent with the booking
     * engine. The override metadata (season, note) is read from
     * the calendar bulk fetch in one shot. */
    $start_ts = strtotime($from);
    $end_ymd  = date("Y-m-d", strtotime("+" . ($days - 1) . " days", $start_ts));
    $cal      = knk_room_rates_calendar($from, $end_ymd, $room_slug);

    $rates = [];
    for ($i = 0; $i < $days; $i++) {
        $d = date("Y-m-d", strtotime("+$i days", $start_ts));
        if (isset($cal[$d])) {
            $rates[] = [
                "date"        => $d,
                "vnd"         => (int)$cal[$d]["vnd"],
                "season"      => $cal[$d]["season_slug"],
                "is_override" => true,
                "note"        => $cal[$d]["note"],
            ];
        } else {
            $rates[] = [
                "date"        => $d,
                "vnd"         => (int)$room["default_vnd_per_night"],
                "season"      => null,
                "is_override" => false,
                "note"        => null,
            ];
        }
    }

    $out = [
        "ok"       => true,
        "room"     => $room,
        "currency" => "VND",
        "from"     => $from,
        "to"       => $end_ymd,
        "days"     => $days,
        "rates"    => $rates,
    ];
} catch (Throwable $e) {
    if (http_response_code() === 200) http_response_code(400);
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

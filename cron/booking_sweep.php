<?php
/*
 * KnK Inn — daily booking sweep.
 *
 * Two housekeeping passes:
 *   1. Auto-complete: any confirmed booking whose checkout date
 *      has passed flips to status "completed". Stops the upcoming
 *      list, the today-snapshot widget, the forecast, and the iCal
 *      feed from carrying around stays that already happened.
 *   2. (Future hook) Anything else cheap that benefits from a
 *      once-a-day pass over bookings.json.
 *
 * Pending holds older than KNK_HOLD_TTL are already lazy-expired
 * inside bookings_list_all(), so they don't need a cron pass.
 *
 * Cron line for DirectAdmin (00:30 UTC = 07:30 Asia/Ho_Chi_Minh —
 * 30 min after the daily digest so the digest still sees the
 * pre-promotion view):
 *
 *   30 0 * * * curl -s "https://knkinn.com/cron/booking_sweep.php?key=Knk@070475" > /dev/null 2>&1
 *
 * Output: plain-text log, one line per action.
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/bookings_store.php";

header("Content-Type: text/plain; charset=utf-8");

$cfg   = knk_config();
$guard = $cfg["admin_password"] ?? "";
$key   = $_GET["key"] ?? "";
if ($guard === "" || !hash_equals($guard, (string)$key)) {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

echo "KnK Inn — booking sweep at " . date("c") . "\n";

$promoted = bookings_auto_complete_past();
echo "Auto-completed: $promoted booking(s)\n";
echo "Done.\n";

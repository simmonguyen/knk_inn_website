<?php
/*
 * KnK Inn — Beer Stock Market tick.
 *
 * Run this URL from Matbao's Cron Jobs panel once a minute:
 *   curl -s "https://knkinn.com/cron/market_tick.php?key=<ADMIN_PASSWORD>"
 *
 * What it does (one beat of the market):
 *   1. Unwind any crashes whose crash_until has passed.
 *   2. Refresh band + demand prices for eligible drinks.
 *   3. Maybe fire an auto-crash (probabilistic, clamped by cadence).
 *
 * All the logic lives in includes/market_engine.php — this file
 * is just the URL-gated entry point cron calls.
 *
 * Guard: same `admin_password` pattern used by /migrate.php and
 * /cron/reminders.php. Mismatch = 403.
 *
 * Output: plain-text log, one line per action. Matbao's cron
 * mailer captures this; Simmo never sees it.
 *
 * Kill switch: if market_config.enabled = 0 the engine bails out
 * immediately and this script writes nothing.
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/market_engine.php";

header("Content-Type: text/plain; charset=utf-8");

/* --------------------------------------------------------------------
 * Guard — must match config.php's admin_password
 * ------------------------------------------------------------------ */
$cfg   = knk_config();
$guard = $cfg["admin_password"] ?? "";
$key   = $_GET["key"] ?? "";
if ($guard === "" || !hash_equals($guard, (string)$key)) {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

function mkt_log(string $s): void { echo $s . "\n"; }

mkt_log("KnK Inn — market tick at " . date("c"));

if (!knk_market_enabled()) {
    mkt_log("Market is OFF (see /market-admin.php). No-op.");
    exit;
}

$band = knk_market_band_active();
mkt_log("Band: " . knk_market_band_label($band["band"])
      . " (x" . number_format($band["mult_bp"] / 100, 2) . ")");

try {
    $result = knk_market_tick();
} catch (Throwable $e) {
    mkt_log("ERROR: " . $e->getMessage());
    error_log("market_tick.php: " . $e->getMessage());
    http_response_code(500);
    exit;
}

$updated = $result["updated"] ?? [];
$crashed = $result["crashed"] ?? [];
$unwound = $result["unwound"] ?? [];

mkt_log("Updated : " . (count($updated) ? implode(", ", $updated) : "(none)"));
mkt_log("Crashed : " . (count($crashed) ? implode(", ", $crashed) : "(none)"));
mkt_log("Unwound : " . (count($unwound) ? implode(", ", $unwound) : "(none)"));
mkt_log("Done.");

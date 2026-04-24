<?php
/*
 * KnK Inn — /api/market_state.php
 *
 * JSON feed for the Big Board (/market.php) to poll.
 * No auth, read-only, safe to expose.
 *
 * Response shape (always HTTP 200 so the board doesn't go dark on
 * a transient error — an `error` field carries the reason):
 *
 * {
 *   "enabled": true,
 *   "now_ts": 1714035123,
 *   "poll_seconds": 5,
 *   "band": {
 *     "name": "peak",
 *     "label": "Peak",
 *     "multiplier_bp": 110
 *   },
 *   "any_crash": true,
 *   "crash_names": ["Tiger Draft", "Saigon Red"],
 *   "items": [
 *     {
 *       "item_code": "beer-tiger-draft",
 *       "name": "Tiger Draft",
 *       "category": "Beer",
 *       "price_vnd": 68000,
 *       "base_price_vnd": 60000,
 *       "multiplier_bp": 113,
 *       "pct_vs_base": 13,
 *       "trend": "up",
 *       "in_crash": false,
 *       "pin_slot": "beer",
 *       "volume_7d": 42,
 *       "sparkline": [60000, 61000, 64000, 62000, 68000]
 *     },
 *     ...
 *   ]
 * }
 *
 * When the market kill-switch is off, we return
 *   { "enabled": false, "items": [], "poll_seconds": 60, ... }
 * so the board can render a quiet "market closed" state and slow
 * its poll to once a minute instead of every 5s.
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/market_engine.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$out = [
    "enabled"      => false,
    "now_ts"       => time(),
    "poll_seconds" => 60,
    "band"         => null,
    "any_crash"    => false,
    "crash_names"  => [],
    "items"        => [],
    "error"        => null,
];

try {
    $enabled = knk_market_enabled();
    $cfg     = knk_market_config();
    $out["enabled"]      = $enabled;
    $out["poll_seconds"] = $enabled
        ? max(2, (int)$cfg["board_poll_seconds"])
        : 60;

    $band = knk_market_band_active();
    $out["band"] = [
        "name"          => $band["band"],
        "label"         => knk_market_band_label($band["band"]),
        "multiplier_bp" => (int)$band["mult_bp"],
    ];

    if (!$enabled) {
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $board = knk_market_board_items();
    $items = [];
    foreach ($board as $row) {
        $code   = (string)$row["item_code"];
        $quote  = knk_market_quote($code);
        $base   = (int)$quote["base_price_vnd"];
        $price  = (int)$quote["price_vnd"];
        $pct    = $base > 0 ? (int)round(($price - $base) * 100 / $base) : 0;
        $trend  = knk_market_trend($code, 900); // 15-min window
        $spark  = knk_market_sparkline($code, 24);
        if ($quote["in_crash"]) {
            $out["any_crash"]    = true;
            $out["crash_names"][] = (string)$row["name"];
        }
        $items[] = [
            "item_code"      => $code,
            "name"           => (string)$row["name"],
            "category"       => (string)($row["category"] ?? ""),
            "price_vnd"      => $price,
            "base_price_vnd" => $base,
            "multiplier_bp"  => (int)$quote["multiplier_bp"],
            "pct_vs_base"    => $pct,
            "trend"          => $trend,
            "in_crash"       => (bool)$quote["in_crash"],
            "pin_slot"       => $row["pin_slot"] ?? null,
            "volume_7d"      => (int)($row["volume"] ?? 0),
            "sparkline"      => $spark,
        ];
    }
    $out["items"] = $items;
} catch (Throwable $e) {
    // Keep the board alive — return an error field so the client can
    // choose to flash a subtle warning, but don't 500.
    $out["error"] = "engine_error";
    error_log("market_state.php: " . $e->getMessage());
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

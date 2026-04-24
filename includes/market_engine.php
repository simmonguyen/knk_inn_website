<?php
/*
 * KnK Inn — Beer Stock Market engine (Phase 2).
 *
 * Responsibilities:
 *   • Compute the live market price for any drink given:
 *       base price (menu_drinks)  × time-of-day band
 *                                  × demand multiplier
 *                                  × active crash (if any)
 *     clamped to [cap_floor, cap_ceiling].
 *   • Decide which drinks make the Big Board (top-N by 7-day
 *     order volume with a min-orders floor, plus 2 pinned slots).
 *   • Apply scheduled + manual updates via knk_market_tick().
 *   • Answer price/arrow/sparkline queries for the UI.
 *   • Enforce fair-play on /order.php submits.
 *
 * All arithmetic is integer basis-point (100 = 1.00x). Prices
 * are whole VND. No floats touching money.
 *
 * Storage:
 *   market_config   — single-row settings (see migration 004).
 *   market_pinned   — two pin slots ('beer' / 'owner').
 *   market_events   — append-only price history. "Current price"
 *                     for an item is the newest event row.
 *   orders.json     — existing flat-file store (order_store.php).
 *                     Demand signal + fair-play read from this.
 *   menu_drinks     — authoritative base price + item catalog.
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/menu_store.php";

if (!defined("KNK_ORDERS_PATH")) {
    define("KNK_ORDERS_PATH", __DIR__ . "/../orders.json");
}

/* ==========================================================
 * CONFIG
 * ======================================================== */

/**
 * Recommended defaults. These are the values the migration
 * seeds, and what "Reset to defaults" in the admin restores.
 * Keeping them here (not just in SQL) makes the reset path
 * explicit in code.
 */
function knk_market_defaults(): array {
    return [
        "enabled"                    => 0,
        "happy_start"                => "16:00",
        "happy_end"                  => "19:00",
        "happy_mult_bp"              => 85,
        "peak_start"                 => "20:00",
        "peak_end"                   => "23:00",
        "peak_mult_bp"               => 110,
        "default_mult_bp"            => 100,
        "demand_window_minutes"      => 45,
        "baseline_orders_per_hour"   => 4,
        "demand_min_bp"              => 90,
        "demand_max_bp"              => 130,
        "crash_cadence_min"          => 45,
        "crash_cadence_max"          => 60,
        "crash_item_cooldown_min"    => 30,
        "crash_items_max"            => 2,
        "crash_drop_min_bp"          => 15,
        "crash_drop_max_bp"          => 30,
        "crash_duration_min_minutes" => 2,
        "crash_duration_max_minutes" => 5,
        "cap_floor_bp"               => 70,
        "cap_ceiling_bp"             => 200,
        "eligibility_window_days"    => 7,
        "eligibility_top_n"          => 10,
        "eligibility_min_orders"     => 5,
        "price_lock_seconds"         => 15,
        "fairplay_max_market_items"  => 2,
        "fairplay_cooldown_seconds"  => 120,
        "board_poll_seconds"         => 5,
    ];
}

/** Fields that the admin UI can write. Kept separate from
 *  defaults() so an attacker POSTing 'id' or 'updated_by'
 *  can't sneak that through. */
function knk_market_config_fields(): array {
    return array_keys(knk_market_defaults());
}

/** Get the single config row as an assoc array (request-cached). */
function knk_market_config(bool $force = false): array {
    static $cache = null;
    if (!$force && $cache !== null) return $cache;
    $pdo = knk_db();
    $row = $pdo->query("SELECT * FROM market_config WHERE id = 1")->fetch();
    if (!$row) {
        // Migration hasn't run yet — fall back to defaults in-memory
        // so callers don't blow up. All features that would mutate
        // state still require the DB, so we stay safe.
        $row = array_merge(["id" => 1], knk_market_defaults());
    }
    return $cache = $row;
}

/** Is the market live? Cheap to call on every order.php render. */
function knk_market_enabled(): bool {
    try {
        $cfg = knk_market_config();
        return !empty($cfg["enabled"]);
    } catch (Throwable $e) {
        return false;  // DB down → market quietly off
    }
}

/** Update a subset of config fields. Unknown keys are ignored. */
function knk_market_config_update(array $updates, ?int $userId = null): void {
    $allowed = knk_market_config_fields();
    $sets    = [];
    $params  = [];
    foreach ($updates as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        $sets[] = "`{$k}` = ?";
        $params[] = $v;
    }
    if (!$sets) return;
    $sets[] = "updated_by = ?";
    $params[] = $userId;
    $sql = "UPDATE market_config SET " . implode(", ", $sets) . " WHERE id = 1";
    knk_db()->prepare($sql)->execute($params);
    // bust cache
    knk_market_config(true);
}

/** Rewrite every tunable field back to the recommended default. */
function knk_market_reset_defaults(?int $userId = null): void {
    knk_market_config_update(knk_market_defaults(), $userId);
}

/* ==========================================================
 * PINS
 * ======================================================== */

/** Both pin rows, keyed by slot ('beer' / 'owner'). Joins menu_drinks
 *  so the admin UI can show the drink name without a second query. */
function knk_market_pinned(): array {
    $pdo = knk_db();
    $stmt = $pdo->query(
        "SELECT p.slot, p.item_code, p.updated_at,
                d.name, d.category, d.price_vnd, d.is_visible
         FROM market_pinned p
         LEFT JOIN menu_drinks d ON d.item_code = p.item_code
         ORDER BY p.slot"
    );
    $out = [];
    foreach ($stmt as $r) $out[$r["slot"]] = $r;
    // Ensure both slots are present even if a migration seeded fewer.
    foreach (["beer", "owner"] as $s) {
        if (!isset($out[$s])) $out[$s] = ["slot" => $s, "item_code" => null];
    }
    return $out;
}

function knk_market_pin_set(string $slot, ?string $itemCode, ?int $userId = null): void {
    if (!in_array($slot, ["beer", "owner"], true)) {
        throw new InvalidArgumentException("Unknown pin slot: {$slot}");
    }
    if ($itemCode !== null) {
        // Validate it's a real drink, otherwise a typo silently breaks the board.
        $row = knk_menu_find_by_code($itemCode);
        if (!$row) throw new InvalidArgumentException("No drink with code: {$itemCode}");
    }
    $pdo = knk_db();
    $stmt = $pdo->prepare(
        "INSERT INTO market_pinned (slot, item_code, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE item_code = VALUES(item_code), updated_by = VALUES(updated_by)"
    );
    $stmt->execute([$slot, $itemCode, $userId]);
}

/* ==========================================================
 * EVENTS (append-only price history)
 * ======================================================== */

/** Newest event row for an item, or null if none. */
function knk_market_latest_event(string $itemCode): ?array {
    $pdo = knk_db();
    $stmt = $pdo->prepare(
        "SELECT * FROM market_events
         WHERE item_code = ?
         ORDER BY created_at DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([$itemCode]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Write a new event row. All state mutations flow through here
 *  so we have one audit trail. */
function knk_market_record_event(
    string $itemCode,
    int $newPriceVnd,
    int $basePriceVnd,
    int $multiplierBp,
    string $source,
    ?int $actorUserId = null,
    ?int $lockedUntil = null,
    ?int $crashUntil = null
): int {
    static $sourceOk = [
        "band", "demand", "crash_auto", "crash_staff",
        "manual", "bootstrap", "reset"
    ];
    if (!in_array($source, $sourceOk, true)) {
        throw new InvalidArgumentException("Unknown market event source: {$source}");
    }
    $prev = knk_market_latest_event($itemCode);
    $old  = $prev ? (int)$prev["new_price_vnd"] : $basePriceVnd;
    $pdo = knk_db();
    $stmt = $pdo->prepare(
        "INSERT INTO market_events
             (item_code, old_price_vnd, new_price_vnd, base_price_vnd,
              multiplier_bp, source, actor_user_id, locked_until, crash_until)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $itemCode, $old, $newPriceVnd, $basePriceVnd,
        $multiplierBp, $source, $actorUserId, $lockedUntil, $crashUntil,
    ]);
    return (int)$pdo->lastInsertId();
}

/* ==========================================================
 * PRICE COMPUTATION
 * ======================================================== */

/** Which band is active right now? Returns ['band'=>'happy|peak|default', 'mult_bp'=>int]. */
function knk_market_band_active(?int $nowTs = null): array {
    $cfg = knk_market_config();
    // Saigon wall-clock. Explicit timezone in case PHP default is UTC.
    $tz  = new DateTimeZone("Asia/Ho_Chi_Minh");
    $dt  = (new DateTimeImmutable("@" . ($nowTs ?? time())))->setTimezone($tz);
    $hm  = $dt->format("H:i");

    // Helper: is $hm in [start, end]? Always treat end exclusive so
    // back-to-back bands don't double-count. If end < start we treat
    // the band as "wraps midnight" (e.g. 22:00 → 02:00).
    $in = function (string $hm, string $start, string $end): bool {
        if ($start === "" || $end === "") return false;
        if ($start === $end) return false;
        if ($start < $end)  return $hm >= $start && $hm < $end;
        return $hm >= $start || $hm < $end;  // wrap past midnight
    };

    if ($in($hm, (string)$cfg["happy_start"], (string)$cfg["happy_end"])) {
        return ["band" => "happy", "mult_bp" => (int)$cfg["happy_mult_bp"]];
    }
    if ($in($hm, (string)$cfg["peak_start"], (string)$cfg["peak_end"])) {
        return ["band" => "peak", "mult_bp" => (int)$cfg["peak_mult_bp"]];
    }
    return ["band" => "default", "mult_bp" => (int)$cfg["default_mult_bp"]];
}

/**
 * Orders-per-item counts in the last $windowMinutes, derived from
 * orders.json. Returns [item_code => orders_in_window].
 * Excludes cancelled orders. Only looks at `created_at` timestamps.
 */
function knk_market_order_counts(int $windowMinutes): array {
    $cutoff = time() - ($windowMinutes * 60);
    $path   = KNK_ORDERS_PATH;
    if (!file_exists($path)) return [];
    $raw  = @file_get_contents($path);
    $data = json_decode($raw ?: "", true);
    if (!is_array($data) || empty($data["orders"])) return [];
    $counts = [];
    foreach ($data["orders"] as $o) {
        if (($o["status"] ?? "") === "cancelled") continue;
        $ts = (int)($o["created_at"] ?? 0);
        if ($ts < $cutoff) continue;
        foreach (($o["items"] ?? []) as $it) {
            $code = (string)($it["id"] ?? "");
            if ($code === "") continue;
            $qty = (int)($it["qty"] ?? 0);
            if ($qty < 1) $qty = 1;
            $counts[$code] = ($counts[$code] ?? 0) + $qty;
        }
    }
    return $counts;
}

/** Demand multiplier (bp) for an item, given its order count in the window. */
function knk_market_demand_bp(int $countInWindow): int {
    $cfg = knk_market_config();
    $windowMin = max(1, (int)$cfg["demand_window_minutes"]);
    $baselinePerHr = max(1, (int)$cfg["baseline_orders_per_hour"]);
    $minBp = (int)$cfg["demand_min_bp"];
    $maxBp = (int)$cfg["demand_max_bp"];

    // Actual rate over the window, scaled to orders-per-hour.
    // ratio_bp = (actual / baseline) × 100, all integer math.
    $actualPerHr = (int)round($countInWindow * 60 / $windowMin);
    $ratioBp     = (int)round(($actualPerHr / $baselinePerHr) * 100);

    // Sensitivity: each 100bp above/below baseline moves the
    // multiplier by 30bp. Keeps response soft.
    $demandBp = 100 + (int)round(($ratioBp - 100) * 30 / 100);
    if ($demandBp < $minBp) $demandBp = $minBp;
    if ($demandBp > $maxBp) $demandBp = $maxBp;
    return $demandBp;
}

/** Clamp a price to the configured floor/ceiling of base. */
function knk_market_clamp_price(int $priceVnd, int $baseVnd): int {
    $cfg = knk_market_config();
    $floor   = (int)floor($baseVnd * ((int)$cfg["cap_floor_bp"]) / 100);
    $ceiling = (int)floor($baseVnd * ((int)$cfg["cap_ceiling_bp"]) / 100);
    if ($priceVnd < $floor)   $priceVnd = $floor;
    if ($priceVnd > $ceiling) $priceVnd = $ceiling;
    return $priceVnd;
}

/**
 * The authoritative "what should this item cost right now?" function.
 * Returns:
 *   price_vnd       — clamped final price
 *   base_price_vnd  — menu base
 *   band_bp         — time-of-day multiplier
 *   demand_bp       — demand multiplier
 *   multiplier_bp   — combined (band × demand / 100)
 *   in_crash        — bool, current event is a live crash
 *   crash_until     — unix ts of crash unwind, or null
 *   locked_until    — unix ts of manual lock, or null
 *   eligible        — bool, item is currently on the Big Board
 *   source          — where the current price came from
 *
 * Callers: /order.php (display + submit validation), /market.php
 * (Big Board via api/market_state.php), admin "Live state" table.
 */
function knk_market_quote(string $itemCode): array {
    $menuRow = knk_menu_find_by_code($itemCode);
    $base = $menuRow ? (int)$menuRow["price_vnd"] : 0;

    $eligibleCodes = knk_market_eligible_codes();
    $eligible = in_array($itemCode, $eligibleCodes, true);

    $band = knk_market_band_active();

    if (!$eligible || $base <= 0) {
        // Ineligible drinks always trade at base.
        return [
            "price_vnd"      => $base,
            "base_price_vnd" => $base,
            "band_bp"        => (int)$band["mult_bp"],
            "demand_bp"      => 100,
            "multiplier_bp"  => 100,
            "in_crash"       => false,
            "crash_until"    => null,
            "locked_until"   => null,
            "eligible"       => false,
            "source"         => "menu",
        ];
    }

    $latest = knk_market_latest_event($itemCode);
    $now = time();

    // Honour active crashes + manual locks — those win over the
    // computed band/demand price until they expire.
    if ($latest) {
        $lock  = (int)($latest["locked_until"] ?? 0);
        $crash = (int)($latest["crash_until"]  ?? 0);
        if ($lock > $now || $crash > $now) {
            return [
                "price_vnd"      => (int)$latest["new_price_vnd"],
                "base_price_vnd" => $base,
                "band_bp"        => (int)$band["mult_bp"],
                "demand_bp"      => 100,
                "multiplier_bp"  => (int)$latest["multiplier_bp"],
                "in_crash"       => $crash > $now,
                "crash_until"    => $crash > $now ? $crash : null,
                "locked_until"   => $lock  > $now ? $lock  : null,
                "eligible"       => true,
                "source"         => (string)$latest["source"],
            ];
        }
    }

    // No active lock/crash — compute fresh.
    $counts    = knk_market_order_counts((int)knk_market_config()["demand_window_minutes"]);
    $demandBp  = knk_market_demand_bp((int)($counts[$itemCode] ?? 0));
    $combined  = (int)round(((int)$band["mult_bp"]) * $demandBp / 100);
    $priceRaw  = (int)round($base * $combined / 100);
    $price     = knk_market_clamp_price($priceRaw, $base);

    return [
        "price_vnd"      => $price,
        "base_price_vnd" => $base,
        "band_bp"        => (int)$band["mult_bp"],
        "demand_bp"      => $demandBp,
        "multiplier_bp"  => $combined,
        "in_crash"       => false,
        "crash_until"    => null,
        "locked_until"   => null,
        "eligible"       => true,
        "source"         => $latest ? (string)$latest["source"] : "computed",
    ];
}

/** Cheap wrapper when you only need the number. */
function knk_market_current_price(string $itemCode): int {
    return (int)knk_market_quote($itemCode)["price_vnd"];
}

/**
 * Batch quote for many items at once. Used by the Big Board and
 * order.php to avoid N+1 round-trips.
 * Returns [item_code => quote-array].
 */
function knk_market_quotes(array $itemCodes): array {
    $out = [];
    foreach ($itemCodes as $code) {
        $out[$code] = knk_market_quote($code);
    }
    return $out;
}

/* ==========================================================
 * ELIGIBILITY (who's on the Big Board)
 * ======================================================== */

/**
 * Top-N eligible item_codes in order (trending first, then pins
 * appended if not already present). Respects min_orders floor.
 * Request-cached so repeated calls in one page load are cheap.
 */
function knk_market_eligible_codes(bool $force = false): array {
    static $cache = null;
    if (!$force && $cache !== null) return $cache;
    $cfg = knk_market_config();

    $windowDays = max(1, (int)$cfg["eligibility_window_days"]);
    $topN       = max(1, (int)$cfg["eligibility_top_n"]);
    $minOrders  = max(0, (int)$cfg["eligibility_min_orders"]);

    $counts = knk_market_order_counts($windowDays * 24 * 60);
    // Only count drinks that still exist + are visible.
    $visible = [];
    foreach (knk_menu_grouped(true) as $g) {
        foreach ($g["items"] as $it) $visible[$it["item_code"]] = true;
    }

    $filtered = [];
    foreach ($counts as $code => $n) {
        if (!isset($visible[$code])) continue;
        if ($n < $minOrders) continue;
        $filtered[$code] = $n;
    }
    arsort($filtered, SORT_NUMERIC);
    $top = array_slice(array_keys($filtered), 0, $topN);

    foreach (knk_market_pinned() as $p) {
        $code = $p["item_code"] ?? null;
        if ($code && isset($visible[$code]) && !in_array($code, $top, true)) {
            $top[] = $code;
        }
    }
    return $cache = $top;
}

/** Richer version for admin + Big Board: includes names, volumes,
 *  pin flag. Sorted: pins first, then trending by volume desc. */
function knk_market_board_items(): array {
    $cfg        = knk_market_config();
    $windowDays = max(1, (int)$cfg["eligibility_window_days"]);
    $counts     = knk_market_order_counts($windowDays * 24 * 60);

    $menu = [];
    foreach (knk_menu_grouped(true) as $g) {
        foreach ($g["items"] as $it) {
            $menu[$it["item_code"]] = $it;
            $menu[$it["item_code"]]["_category"] = $g["title"];
        }
    }

    $pinMap = [];
    foreach (knk_market_pinned() as $slot => $p) {
        if (!empty($p["item_code"])) $pinMap[$p["item_code"]] = $slot;
    }

    $codes = knk_market_eligible_codes();
    $rows  = [];
    foreach ($codes as $code) {
        if (!isset($menu[$code])) continue;
        $rows[] = [
            "item_code" => $code,
            "name"      => $menu[$code]["name"],
            "category"  => $menu[$code]["_category"] ?? "",
            "volume"    => (int)($counts[$code] ?? 0),
            "base_price_vnd" => (int)$menu[$code]["price_vnd"],
            "pin_slot"  => $pinMap[$code] ?? null,
        ];
    }

    // Pins first; within each group, highest volume first.
    usort($rows, function (array $a, array $b): int {
        $ap = $a["pin_slot"] ? 0 : 1;
        $bp = $b["pin_slot"] ? 0 : 1;
        if ($ap !== $bp) return $ap - $bp;
        return $b["volume"] - $a["volume"];
    });
    return $rows;
}

/* ==========================================================
 * TREND + SPARKLINE
 * ======================================================== */

/** 'up' / 'down' / 'flat' compared to the newest event that's
 *  older than $windowSec seconds. If no such event, 'flat'. */
function knk_market_trend(string $itemCode, int $windowSec = 900): string {
    $pdo = knk_db();
    $cutoff = date("Y-m-d H:i:s", time() - $windowSec);
    $stmt = $pdo->prepare(
        "SELECT new_price_vnd FROM market_events
         WHERE item_code = ? AND created_at <= ?
         ORDER BY created_at DESC, id DESC LIMIT 1"
    );
    $stmt->execute([$itemCode, $cutoff]);
    $past = $stmt->fetch();
    if (!$past) return "flat";
    $now = knk_market_current_price($itemCode);
    if ($now > (int)$past["new_price_vnd"]) return "up";
    if ($now < (int)$past["new_price_vnd"]) return "down";
    return "flat";
}

/** Last N price points for this item (oldest → newest), for the Big Board sparkline. */
function knk_market_sparkline(string $itemCode, int $n = 20): array {
    $pdo = knk_db();
    $n = max(2, min(100, $n));
    $stmt = $pdo->prepare(
        "SELECT new_price_vnd FROM market_events
         WHERE item_code = ?
         ORDER BY created_at DESC, id DESC LIMIT {$n}"
    );
    $stmt->execute([$itemCode]);
    $vals = [];
    foreach ($stmt as $r) $vals[] = (int)$r["new_price_vnd"];
    return array_reverse($vals);
}

/* ==========================================================
 * TICK (the cron heartbeat)
 * ======================================================== */

/**
 * Run one tick of the market.
 *   1. Unwind any crashes whose crash_until has passed (fresh 'band' event).
 *   2. Refresh band/demand price for every eligible item whose
 *      latest event has no active lock/crash.
 *   3. Maybe fire an auto-crash if cadence window has elapsed.
 *
 * Idempotent: if a price hasn't changed, we don't write an event.
 * Cheap enough to run every minute on Matbao cron.
 */
function knk_market_tick(?int $nowTs = null): array {
    $out = ["enabled" => false, "updated" => [], "crashed" => [], "unwound" => []];
    if (!knk_market_enabled()) return $out;
    $out["enabled"] = true;
    $now = $nowTs ?? time();

    // 1. Unwind expired crashes → force a fresh recompute below.
    $pdo = knk_db();
    $stmt = $pdo->prepare(
        "SELECT DISTINCT e.item_code
         FROM market_events e
         INNER JOIN (
             SELECT item_code, MAX(id) AS max_id
             FROM market_events GROUP BY item_code
         ) last ON last.max_id = e.id
         WHERE e.crash_until IS NOT NULL AND e.crash_until > 0 AND e.crash_until <= ?"
    );
    $stmt->execute([$now]);
    $toUnwind = [];
    foreach ($stmt as $r) $toUnwind[] = $r["item_code"];

    // 2. Refresh eligible items.
    $eligible  = knk_market_eligible_codes(true);
    $band      = knk_market_band_active($now);
    $counts    = knk_market_order_counts((int)knk_market_config()["demand_window_minutes"]);

    foreach ($eligible as $code) {
        $menuRow = knk_menu_find_by_code($code);
        if (!$menuRow) continue;
        $base = (int)$menuRow["price_vnd"];
        if ($base <= 0) continue;

        $latest = knk_market_latest_event($code);
        if ($latest) {
            $lock  = (int)($latest["locked_until"] ?? 0);
            $crash = (int)($latest["crash_until"]  ?? 0);
            // Active crash/lock — leave it alone (step 1 picks it up
            // when the timer expires).
            if ($lock > $now || $crash > $now) continue;
        }

        $demandBp = knk_market_demand_bp((int)($counts[$code] ?? 0));
        $combined = (int)round(((int)$band["mult_bp"]) * $demandBp / 100);
        $priceRaw = (int)round($base * $combined / 100);
        $price    = knk_market_clamp_price($priceRaw, $base);

        $prev = $latest ? (int)$latest["new_price_vnd"] : -1;
        if (!$latest) {
            // Bootstrap event so the item has history for sparklines.
            knk_market_record_event($code, $base, $base, 100, "bootstrap");
            // Then the real one.
            if ($price !== $base) {
                knk_market_record_event($code, $price, $base, $combined, "band");
                $out["updated"][] = $code;
            }
        } elseif ($price !== $prev) {
            knk_market_record_event($code, $price, $base, $combined, "band");
            $out["updated"][] = $code;
        }
        if (in_array($code, $toUnwind, true) && $price === $prev) {
            // Still write an unwind event so the crash "ends" visibly.
            knk_market_record_event($code, $price, $base, $combined, "band");
            $out["unwound"][] = $code;
        } elseif (in_array($code, $toUnwind, true)) {
            $out["unwound"][] = $code;
        }
    }

    // 3. Maybe fire an auto-crash.
    $cfg = knk_market_config();
    $cadMin = (int)$cfg["crash_cadence_min"];
    $cadMax = (int)$cfg["crash_cadence_max"];
    $last   = $pdo->query(
        "SELECT created_at FROM market_events
         WHERE source IN ('crash_auto','crash_staff')
         ORDER BY created_at DESC, id DESC LIMIT 1"
    )->fetch();
    $elapsedMin = $last ? ((int)((time() - strtotime($last["created_at"])) / 60)) : 9999;

    if ($elapsedMin >= $cadMin) {
        // Roll for the cadence — the closer we are to cadMax, the more
        // likely a crash fires, so the market doesn't clockwork-fire
        // at minute 45 every time.
        $span = max(1, $cadMax - $cadMin);
        $over = $elapsedMin - $cadMin;
        // Clamp over to [0, span]; probability = over/span (in %).
        if ($over > $span) $over = $span;
        if (mt_rand(0, $span) < $over) {
            $fired = knk_market_fire_auto_crash();
            if ($fired) $out["crashed"] = $fired;
        }
    }

    return $out;
}

/** Pick victims + fire an auto-crash. Returns list of item codes crashed. */
function knk_market_fire_auto_crash(): array {
    $cfg = knk_market_config();
    $maxItems = max(1, (int)$cfg["crash_items_max"]);
    $cooldownMin = max(0, (int)$cfg["crash_item_cooldown_min"]);
    $dropMin = (int)$cfg["crash_drop_min_bp"];
    $dropMax = (int)$cfg["crash_drop_max_bp"];
    $durMin  = (int)$cfg["crash_duration_min_minutes"];
    $durMax  = (int)$cfg["crash_duration_max_minutes"];

    $eligible = knk_market_eligible_codes();
    if (!$eligible) return [];

    // Filter out anything that's still in per-item cooldown.
    $pdo = knk_db();
    $cooldownCutoff = date("Y-m-d H:i:s", time() - ($cooldownMin * 60));
    $victims = [];
    foreach ($eligible as $code) {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM market_events
             WHERE item_code = ? AND source IN ('crash_auto','crash_staff')
               AND created_at > ? LIMIT 1"
        );
        $stmt->execute([$code, $cooldownCutoff]);
        if ($stmt->fetch()) continue;
        $victims[] = $code;
    }
    if (!$victims) return [];
    shuffle($victims);
    $victims = array_slice($victims, 0, $maxItems);

    $fired = [];
    foreach ($victims as $code) {
        $drop  = mt_rand($dropMin, max($dropMin, $dropMax));
        $dur   = mt_rand($durMin,  max($durMin,  $durMax));
        if (knk_market_apply_crash($code, $drop, $dur, "crash_auto", null)) {
            $fired[] = $code;
        }
    }
    return $fired;
}

/** Apply a crash to one item. Returns true if the event was written. */
function knk_market_apply_crash(
    string $itemCode,
    int $dropBp,
    int $durationMin,
    string $source,
    ?int $actorUserId
): bool {
    if (!in_array($source, ["crash_auto", "crash_staff"], true)) {
        throw new InvalidArgumentException("Bad crash source: {$source}");
    }
    $menuRow = knk_menu_find_by_code($itemCode);
    if (!$menuRow) return false;
    $base = (int)$menuRow["price_vnd"];
    if ($base <= 0) return false;

    $multBp = 100 - max(1, min(90, $dropBp));   // clamp to sensible
    $priceRaw = (int)round($base * $multBp / 100);
    $price    = knk_market_clamp_price($priceRaw, $base);
    $crashUntil = time() + ($durationMin * 60);

    knk_market_record_event(
        $itemCode, $price, $base, $multBp, $source, $actorUserId,
        null, $crashUntil
    );
    return true;
}

/* ==========================================================
 * MANUAL OVERRIDES + RESET
 * ======================================================== */

/**
 * Force a specific price on one item, optionally locked for N minutes.
 * Writes a 'manual' event. Passing lockMinutes=0 means "one-shot"
 * (next tick may overwrite it).
 */
function knk_market_force_price(
    string $itemCode,
    int $priceVnd,
    int $lockMinutes,
    ?int $actorUserId
): void {
    $menuRow = knk_menu_find_by_code($itemCode);
    if (!$menuRow) throw new InvalidArgumentException("No drink with code: {$itemCode}");
    $base = (int)$menuRow["price_vnd"];
    if ($priceVnd < 0) $priceVnd = 0;
    $priceVnd = knk_market_clamp_price($priceVnd, $base);
    $multBp   = $base > 0 ? (int)round($priceVnd * 100 / $base) : 100;
    $lockedUntil = $lockMinutes > 0 ? (time() + ($lockMinutes * 60)) : null;
    knk_market_record_event(
        $itemCode, $priceVnd, $base, $multBp, "manual",
        $actorUserId, $lockedUntil, null
    );
}

/** "Social Crash" button — crash the top-K trending drinks right now. */
function knk_market_social_crash(int $dropBp, int $durationMin, ?int $actorUserId): array {
    $cfg = knk_market_config();
    $k   = max(1, (int)$cfg["crash_items_max"]);
    $eligible = knk_market_eligible_codes();
    // Take the top by volume (already sorted by knk_market_eligible_codes).
    $victims = array_slice($eligible, 0, $k);
    $fired = [];
    foreach ($victims as $code) {
        if (knk_market_apply_crash($code, $dropBp, $durationMin, "crash_staff", $actorUserId)) {
            $fired[] = $code;
        }
    }
    return $fired;
}

/** Reset every eligible item to base price (writes a 'reset' event per item). */
function knk_market_reset_prices(?int $actorUserId = null): array {
    $done = [];
    foreach (knk_market_eligible_codes() as $code) {
        $menuRow = knk_menu_find_by_code($code);
        if (!$menuRow) continue;
        $base = (int)$menuRow["price_vnd"];
        knk_market_record_event($code, $base, $base, 100, "reset", $actorUserId);
        $done[] = $code;
    }
    return $done;
}

/* ==========================================================
 * FAIR-PLAY (order.php submit guards)
 * ======================================================== */

/**
 * Given a cart (array of item_codes the guest wants to order),
 * return ['ok'=>bool, 'reason'=>string|null].
 *   • Max $fairplay_max_market_items eligible items per order.
 *   • $fairplay_cooldown_seconds since this email's last order
 *     that contained an eligible item.
 */
function knk_market_fairplay_check(array $cartItemCodes, string $email): array {
    if (!knk_market_enabled()) return ["ok" => true, "reason" => null];
    $cfg = knk_market_config();
    $eligible = knk_market_eligible_codes();

    $marketInCart = 0;
    foreach ($cartItemCodes as $c) {
        if (in_array($c, $eligible, true)) $marketInCart++;
    }
    $max = (int)$cfg["fairplay_max_market_items"];
    if ($max > 0 && $marketInCart > $max) {
        return [
            "ok" => false,
            "reason" => "Only {$max} market-priced drink"
                . ($max === 1 ? "" : "s")
                . " per order while the market's running. Drop one and try again.",
        ];
    }

    $cooldown = (int)$cfg["fairplay_cooldown_seconds"];
    if ($cooldown > 0 && $email !== "" && $marketInCart > 0) {
        $last = knk_market_last_market_order_ts($email);
        if ($last && (time() - $last) < $cooldown) {
            $wait = $cooldown - (time() - $last);
            return [
                "ok" => false,
                "reason" => "Hold up — another market-priced order from you "
                    . "lands in {$wait}s. Grab a water in the meantime.",
            ];
        }
    }
    return ["ok" => true, "reason" => null];
}

/** Unix ts of this email's newest order that contained an eligible item. */
function knk_market_last_market_order_ts(string $email): ?int {
    $email = strtolower(trim($email));
    if ($email === "") return null;
    $eligible = knk_market_eligible_codes();
    if (!$eligible) return null;
    $path = KNK_ORDERS_PATH;
    if (!file_exists($path)) return null;
    $raw  = @file_get_contents($path);
    $data = json_decode($raw ?: "", true);
    if (!is_array($data) || empty($data["orders"])) return null;
    $best = null;
    foreach ($data["orders"] as $o) {
        if (strtolower((string)($o["email"] ?? "")) !== $email) continue;
        $hit = false;
        foreach (($o["items"] ?? []) as $it) {
            if (in_array((string)($it["id"] ?? ""), $eligible, true)) { $hit = true; break; }
        }
        if (!$hit) continue;
        $ts = (int)($o["created_at"] ?? 0);
        if ($best === null || $ts > $best) $best = $ts;
    }
    return $best;
}

/* ==========================================================
 * ADMIN DISPLAY HELPERS
 * ======================================================== */

/** Newest N events across all items, for the admin log panel. */
function knk_market_recent_events(int $limit = 50): array {
    $limit = max(1, min(500, $limit));
    $pdo = knk_db();
    $stmt = $pdo->prepare(
        "SELECT e.*, u.name AS actor_name, d.name AS item_name
         FROM market_events e
         LEFT JOIN users u       ON u.id = e.actor_user_id
         LEFT JOIN menu_drinks d ON d.item_code = e.item_code
         ORDER BY e.created_at DESC, e.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute();
    $rows = [];
    foreach ($stmt as $r) $rows[] = $r;
    return $rows;
}

/** Human label for source (admin UI). */
function knk_market_source_label(string $source): string {
    $map = [
        "band"        => "Time band",
        "demand"      => "Demand",
        "crash_auto"  => "Auto crash",
        "crash_staff" => "Staff crash",
        "manual"      => "Manual override",
        "bootstrap"   => "Bootstrap",
        "reset"       => "Reset to base",
    ];
    return $map[$source] ?? $source;
}

/** Human label for band. */
function knk_market_band_label(string $band): string {
    $map = ["happy" => "Happy hour", "peak" => "Peak", "default" => "Off-peak"];
    return $map[$band] ?? $band;
}

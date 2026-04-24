<?php
/*
 * KnK Inn — sales aggregation (V2 Phase 5).
 *
 * Rolls up bookings.json + orders.json into the small set of arrays
 * the sales.php dashboard needs. No DB — the JSON stores are still
 * authoritative for history.
 *
 * "Revenue" rules:
 *   room   = price_vnd_per_night attributed to each night the guest
 *            slept (spread across the stay, not dumped on check-in).
 *            Only status = "confirmed" counts. Manual blocks ignored.
 *   drinks = total_vnd (VAT-inclusive) attributed to the order's
 *            created_at date. Cancelled orders ignored.
 *
 * Timezone: uses PHP's default server TZ the same way bookings.php does.
 */

declare(strict_types=1);

require_once __DIR__ . "/bookings_store.php";
require_once __DIR__ . "/orders_store.php";

/* ---------- Helpers ---------- */

/**
 * True if this booking row is a manual block created from bookings.php.
 * Manual blocks use "Blocked" as the guest name.
 */
function knk_sales_is_block(array $h): bool {
    $name = strtolower(trim((string)($h["guest"]["name"] ?? "")));
    return $name === "blocked";
}

/**
 * Return last N dates as Y-m-d strings, oldest first.
 * $days must be >= 1.
 */
function knk_sales_days_range(int $days): array {
    if ($days < 1) $days = 1;
    $out = [];
    $today_ts = strtotime(date("Y-m-d") . " 00:00:00");
    for ($i = $days - 1; $i >= 0; $i--) {
        $out[] = date("Y-m-d", $today_ts - ($i * 86400));
    }
    return $out;
}

/* ---------- Daily rollup (Tab 1) ---------- */

/**
 * Daily room + drinks revenue for the last N days.
 *
 * Returns: [ "YYYY-MM-DD" => ["room" => int, "drinks" => int], ... ]
 * (every day in range present, zero-filled if nothing happened)
 */
function knk_sales_daily_totals(int $days): array {
    $dates = knk_sales_days_range($days);
    $out = [];
    foreach ($dates as $d) $out[$d] = ["room" => 0, "drinks" => 0];
    if (empty($dates)) return $out;

    $first_ts    = strtotime($dates[0] . " 00:00:00");
    $last_end_ts = strtotime(end($dates) . " 00:00:00") + 86400;  // exclusive

    // Room revenue — spread per-night rate across the stay.
    foreach (bookings_list_all(false) as $h) {
        if (($h["status"] ?? "") !== "confirmed") continue;
        if (knk_sales_is_block($h)) continue;
        $ppn   = (int)($h["price_vnd_per_night"] ?? 0);
        $ci_ts = strtotime(((string)($h["checkin"]  ?? "")) . " 00:00:00");
        $co_ts = strtotime(((string)($h["checkout"] ?? "")) . " 00:00:00");
        if (!$ci_ts || !$co_ts || $co_ts <= $ci_ts || $ppn <= 0) continue;
        for ($t = $ci_ts; $t < $co_ts; $t += 86400) {
            if ($t < $first_ts || $t >= $last_end_ts) continue;
            $d = date("Y-m-d", $t);
            if (isset($out[$d])) $out[$d]["room"] += $ppn;
        }
    }

    // Drinks — total_vnd attributed to created_at date.
    foreach (orders_all() as $o) {
        if (($o["status"] ?? "") === "cancelled") continue;
        $ts = (int)($o["created_at"] ?? 0);
        if ($ts < $first_ts || $ts >= $last_end_ts) continue;
        $d = date("Y-m-d", $ts);
        if (isset($out[$d])) $out[$d]["drinks"] += (int)($o["total_vnd"] ?? 0);
    }

    return $out;
}

/* ---------- Period totals (Tab 2 + header KPIs) ---------- */

/**
 * Totals for a period ending today.
 *   $days = 0  → all time
 *   $days > 0  → last N days (including today)
 *
 * Returns ["room","drinks","combined","drink_orders","room_nights"].
 */
function knk_sales_period_totals(int $days): array {
    $today_ts     = strtotime(date("Y-m-d") . " 00:00:00");
    $tomorrow_ts  = $today_ts + 86400;  // exclusive upper cap — don't count nights the guest hasn't slept yet
    $cutoff = 0;
    if ($days > 0) {
        $cutoff = $today_ts - (($days - 1) * 86400);
    }

    $room = 0;
    $room_nights = 0;
    foreach (bookings_list_all(false) as $h) {
        if (($h["status"] ?? "") !== "confirmed") continue;
        if (knk_sales_is_block($h)) continue;
        $ppn   = (int)($h["price_vnd_per_night"] ?? 0);
        $ci_ts = strtotime(((string)($h["checkin"]  ?? "")) . " 00:00:00");
        $co_ts = strtotime(((string)($h["checkout"] ?? "")) . " 00:00:00");
        if (!$ci_ts || !$co_ts || $co_ts <= $ci_ts || $ppn <= 0) continue;
        for ($t = $ci_ts; $t < $co_ts; $t += 86400) {
            if ($t >= $tomorrow_ts) continue;               // future night — not realized yet
            if ($cutoff > 0 && $t < $cutoff) continue;
            $room += $ppn;
            $room_nights++;
        }
    }

    $drinks = 0;
    $drink_orders = 0;
    foreach (orders_all() as $o) {
        if (($o["status"] ?? "") === "cancelled") continue;
        $ts = (int)($o["created_at"] ?? 0);
        if ($cutoff > 0 && $ts < $cutoff) continue;
        $drinks += (int)($o["total_vnd"] ?? 0);
        $drink_orders++;
    }

    return [
        "room"         => $room,
        "drinks"       => $drinks,
        "combined"     => $room + $drinks,
        "drink_orders" => $drink_orders,
        "room_nights"  => $room_nights,
    ];
}

/* ---------- Day-of-week heatmap (Tab 3) ---------- */

/**
 * Drink orders counted by weekday. Returns a 7-element array keyed
 * 0=Mon .. 6=Sun (PHP date("N") - 1). All time, cancelled excluded.
 */
function knk_sales_orders_by_weekday(): array {
    $out = [0, 0, 0, 0, 0, 0, 0];
    foreach (orders_all() as $o) {
        if (($o["status"] ?? "") === "cancelled") continue;
        $ts = (int)($o["created_at"] ?? 0);
        if (!$ts) continue;
        $idx = ((int)date("N", $ts)) - 1;  // 1..7 → 0..6
        if ($idx >= 0 && $idx <= 6) $out[$idx]++;
    }
    return $out;
}

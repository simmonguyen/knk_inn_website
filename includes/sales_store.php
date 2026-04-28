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

/* ---------- Forward-looking room revenue forecast (Tab 4) ----------
 *
 * Walks every booking that's still on the books (confirmed +
 * non-stale pending) with a checkout in the future, and asks the
 * rate engine (migration 026) for the actual nightly quote. This
 * means seasonal overrides flow through — a pending Tet booking
 * forecasts the Tet rate, not the rack rate.
 *
 * Falls back to (price_vnd_per_night × nights) when the rate engine
 * can't price the stay (no rooms registered yet, or zero-rated
 * nights), so the forecast still reflects what was originally
 * quoted to the guest.
 *
 * Returns:
 *   [
 *     "by_month" => [ "YYYY-MM" => ["confirmed" => int, "pending" => int], ... ],
 *     "totals"   => [
 *        "confirmed" => int VND, "pending" => int VND, "combined" => int VND,
 *        "next_30"   => int VND,            // confirmed + pending in next 30 days
 *        "rooms_30"  => int                 // distinct room-nights in next 30 days
 *     ],
 *     "rows" => [ <one row per booking, sorted by checkin> ],
 *   ]
 */
function knk_sales_room_forecast(int $horizon_days = 365): array {
    if ($horizon_days < 7)   $horizon_days = 7;
    if ($horizon_days > 730) $horizon_days = 730;

    /* Pull the rate engine lazily — sales.php is gated under a
     * different permission than room-rates and we don't want a
     * missing migration to crash the dashboard. */
    $have_rate_engine = false;
    if (file_exists(__DIR__ . "/room_rates_store.php")) {
        require_once __DIR__ . "/room_rates_store.php";
        $have_rate_engine = function_exists('knk_rooms_by_type');
    }

    [$fp, $data] = bookings_open();
    bookings_close($fp);

    $now      = time();
    $today_ts = strtotime(date("Y-m-d") . " 00:00:00");
    $end_ts   = strtotime("+{$horizon_days} days", $today_ts);
    $win30_ts = strtotime("+30 days", $today_ts);

    $by_month = [];
    $rows     = [];
    $tot_conf = 0; $tot_pend = 0;
    $next30   = 0; $nights30 = 0;

    foreach ($data["holds"] as $h) {
        $status = $h["status"] ?? "pending";
        if (in_array($status, ["declined", "expired", "cancelled", "completed"], true)) continue;
        if ($status === "pending" && ($now - ($h["created_at"] ?? 0)) > KNK_HOLD_TTL) continue;
        if (knk_sales_is_block($h)) continue;          // skip manual blocks
        $checkin  = (string)($h["checkin"]  ?? "");
        $checkout = (string)($h["checkout"] ?? "");
        if ($checkin === "" || $checkout === "") continue;
        $hs = strtotime($checkin);
        $he = strtotime($checkout);
        if (!$hs || !$he || $he <= $today_ts) continue;  // already left
        if ($hs > $end_ts) continue;                     // beyond horizon

        $type    = (string)($h["room"] ?? "");
        $nights  = (int)($h["nights"] ?? 0);
        if ($nights <= 0) $nights = max(1, (int)(($he - $hs) / 86400));

        /* Try the rate engine first. We quote against the cheapest
         * active physical room of the type — same approximation
         * enquire.php uses. */
        $total = 0;
        $lines = [];
        if ($have_rate_engine) {
            try {
                $candidates = knk_rooms_by_type($type);
                if (!empty($candidates)) {
                    usort($candidates, function ($a, $b) {
                        return ((int)$a["default_vnd_per_night"]) - ((int)$b["default_vnd_per_night"]);
                    });
                    $q = knk_room_rate_quote((string)$candidates[0]["slug"], $checkin, $checkout);
                    if (!empty($q) && $q["nights"] > 0 && empty($q["any_zero"])) {
                        $total = (int)$q["total"];
                        $lines = $q["lines"];
                    }
                }
            } catch (Throwable $e) {
                /* swallow — fall through to legacy snapshot */
            }
        }
        if ($total <= 0) {
            $total = ((int)($h["price_vnd_per_night"] ?? 0)) * $nights;
        }

        /* Spread the booking across calendar months for the by_month
         * grouping. We use per-night $lines if the rate engine
         * returned them, otherwise treat each night as having
         * total/nights. */
        $per_night = [];
        if (!empty($lines)) {
            foreach ($lines as $ln) {
                $per_night[(string)$ln["date"]] = (int)$ln["vnd"];
            }
        } else {
            for ($t = $hs; $t < $he; $t = strtotime("+1 day", $t)) {
                $per_night[date("Y-m-d", $t)] = (int)round($total / max(1, $nights));
            }
        }

        foreach ($per_night as $night_ymd => $vnd) {
            $night_ts = strtotime($night_ymd);
            if ($night_ts < $today_ts || $night_ts > $end_ts) continue;
            $month = substr($night_ymd, 0, 7);
            if (!isset($by_month[$month])) $by_month[$month] = ["confirmed" => 0, "pending" => 0];
            if ($status === "confirmed") {
                $by_month[$month]["confirmed"] += $vnd;
                $tot_conf                     += $vnd;
            } else {
                $by_month[$month]["pending"]   += $vnd;
                $tot_pend                     += $vnd;
            }
            if ($night_ts < $win30_ts) {
                $next30   += $vnd;
                $nights30 += 1;
            }
        }

        $rows[] = [
            "id"       => (string)($h["id"] ?? ""),
            "room"     => $type,
            "checkin"  => $checkin,
            "checkout" => $checkout,
            "nights"   => $nights,
            "status"   => $status,
            "guest"    => (string)($h["guest"]["name"] ?? ""),
            "total"    => $total,
        ];
    }

    /* Sort by_month and rows for stable output. */
    ksort($by_month);
    usort($rows, function ($a, $b) {
        return strcmp($a["checkin"], $b["checkin"]);
    });

    return [
        "by_month" => $by_month,
        "totals"   => [
            "confirmed" => $tot_conf,
            "pending"   => $tot_pend,
            "combined"  => $tot_conf + $tot_pend,
            "next_30"   => $next30,
            "rooms_30"  => $nights30,
        ],
        "rows"     => $rows,
    ];
}

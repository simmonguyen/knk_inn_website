<?php
/*
 * KnK Inn — includes/room_rates_store.php
 *
 * Per-room, per-date pricing (migration 026).
 *
 * Design:
 *   - rooms.default_vnd_per_night is the fallback rack rate.
 *   - room_rates is sparse — only dates with an explicit override
 *     have a row.
 *   - knk_room_rate_for($slug, $date) walks: explicit override
 *     first, default room rate second.
 *   - knk_room_rate_quote($slug, $checkin, $checkout) sums per-
 *     night rates and returns a breakdown the booking flow / OTA
 *     channel manager can render.
 *
 * Booking integration:
 *   The bookings table stays as-is. At quote time we compute
 *   total_vnd from this store and snapshot it into bookings.total_vnd
 *   on confirm — historical bookings keep the price they were
 *   quoted, even if the rate calendar changes later.
 *
 * PHP 7.4 compatible (Matbao runs 7.4) — no match, no
 * str_*_with, no nullsafe.
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";

/* ==========================================================
 * READ — rooms
 * ========================================================== */

/**
 * All physical rooms, optionally including inactive. Used by the
 * admin UI and the booking-engine room selector.
 */
function knk_rooms_all(bool $include_inactive = false): array {
    try {
        $sql = "SELECT id, slug, room_type, display_name, floor,
                       sort_order, default_vnd_per_night, is_active
                  FROM rooms";
        if (!$include_inactive) $sql .= " WHERE is_active = 1";
        $sql .= " ORDER BY sort_order ASC, slug ASC";
        $rows = knk_db()->query($sql)->fetchAll();
        foreach ($rows as &$r) {
            $r["id"]                    = (int)$r["id"];
            $r["floor"]                 = (int)$r["floor"];
            $r["sort_order"]            = (int)$r["sort_order"];
            $r["default_vnd_per_night"] = (int)$r["default_vnd_per_night"];
            $r["is_active"]             = (int)$r["is_active"];
        }
        unset($r);
        return $rows;
    } catch (Throwable $e) {
        error_log("knk_rooms_all: " . $e->getMessage());
        return [];
    }
}

/**
 * Single room by slug. Returns null when the slug is unknown
 * (the booking engine treats this as a hard error — we don't
 * silently quote a default rate for a room that doesn't exist).
 */
function knk_room_get(string $slug): ?array {
    $slug = trim($slug);
    if ($slug === "") return null;
    try {
        $st = knk_db()->prepare(
            "SELECT id, slug, room_type, display_name, floor,
                    sort_order, default_vnd_per_night, is_active
               FROM rooms WHERE slug = ? LIMIT 1"
        );
        $st->execute([$slug]);
        $r = $st->fetch();
        if (!$r) return null;
        $r["id"]                    = (int)$r["id"];
        $r["floor"]                 = (int)$r["floor"];
        $r["sort_order"]            = (int)$r["sort_order"];
        $r["default_vnd_per_night"] = (int)$r["default_vnd_per_night"];
        $r["is_active"]             = (int)$r["is_active"];
        return $r;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * All rooms of a given type (standard-nowindow / standard-balcony
 * / vip). Used when a guest picks a room *type* on the website
 * and we need to map them to the cheapest available physical room.
 */
function knk_rooms_by_type(string $type): array {
    $type = trim($type);
    if ($type === "") return [];
    try {
        $st = knk_db()->prepare(
            "SELECT id, slug, room_type, display_name, floor,
                    sort_order, default_vnd_per_night, is_active
               FROM rooms
              WHERE room_type = ? AND is_active = 1
           ORDER BY sort_order ASC, slug ASC"
        );
        $st->execute([$type]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r["id"]                    = (int)$r["id"];
            $r["floor"]                 = (int)$r["floor"];
            $r["sort_order"]            = (int)$r["sort_order"];
            $r["default_vnd_per_night"] = (int)$r["default_vnd_per_night"];
            $r["is_active"]             = (int)$r["is_active"];
        }
        unset($r);
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/* ==========================================================
 * READ — rates
 * ========================================================== */

/**
 * Nightly rate for one room on one date.
 *
 * Order of precedence:
 *   1. explicit room_rates row (vnd_amount)
 *   2. rooms.default_vnd_per_night
 *
 * Returns 0 if the room slug is unknown — caller should treat
 * 0 as "no quote possible" rather than free.
 */
function knk_room_rate_for(string $room_slug, string $date_ymd): int {
    $room_slug = trim($room_slug);
    if ($room_slug === "") return 0;
    $d = trim($date_ymd);
    if ($d === "" || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return 0;

    try {
        /* 1. Explicit override. */
        $st = knk_db()->prepare(
            "SELECT vnd_amount
               FROM room_rates
              WHERE room_slug = ? AND stay_date = ?
              LIMIT 1"
        );
        $st->execute([$room_slug, $d]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) return (int)$v;

        /* 2. Room default. */
        $st2 = knk_db()->prepare(
            "SELECT default_vnd_per_night
               FROM rooms WHERE slug = ? LIMIT 1"
        );
        $st2->execute([$room_slug]);
        $d2 = $st2->fetchColumn();
        if ($d2 === false || $d2 === null) return 0;
        return (int)$d2;
    } catch (Throwable $e) {
        error_log("knk_room_rate_for: " . $e->getMessage());
        return 0;
    }
}

/**
 * Quote a stay — sums per-night rates from $checkin (inclusive)
 * to $checkout (exclusive), the standard hotel convention. A
 * 1-night stay has 1 rate row.
 *
 * Returns:
 *   [
 *     "room"     => <room row>,
 *     "nights"   => <int>,
 *     "total"    => <int VND>,
 *     "average"  => <int VND per night, rounded>,
 *     "any_zero" => <bool — true if any night couldn't be priced>,
 *     "lines"    => [ ["date"=>"YYYY-MM-DD", "vnd"=>123000, "season_slug"=>"high"|null, "is_override"=>true|false], ... ]
 *   ]
 *
 * The "any_zero" flag lets callers refuse to confirm a booking
 * that has gaps in the rate calendar — better to surface a
 * "rates not loaded for this date range" error than quote 0 VND.
 */
function knk_room_rate_quote(string $room_slug, string $checkin_ymd, string $checkout_ymd): array {
    $room = knk_room_get($room_slug);
    $blank = [
        "room" => $room, "nights" => 0, "total" => 0, "average" => 0,
        "any_zero" => true, "lines" => [],
    ];
    if (!$room) return $blank;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkin_ymd))  return $blank;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkout_ymd)) return $blank;

    $start = strtotime($checkin_ymd);
    $end   = strtotime($checkout_ymd);
    if ($start === false || $end === false || $end <= $start) return $blank;

    /* Pull all override rows for the range in one shot — cheaper
     * than a per-day SELECT loop. We re-key by date for O(1)
     * lookup while iterating the date range. */
    try {
        $st = knk_db()->prepare(
            "SELECT stay_date, vnd_amount, season_slug
               FROM room_rates
              WHERE room_slug = ?
                AND stay_date >= ?
                AND stay_date <  ?"
        );
        $st->execute([$room_slug, $checkin_ymd, $checkout_ymd]);
        $overrides = [];
        foreach ($st->fetchAll() as $r) {
            $overrides[(string)$r["stay_date"]] = [
                "vnd"         => (int)$r["vnd_amount"],
                "season_slug" => $r["season_slug"] !== null ? (string)$r["season_slug"] : null,
            ];
        }
    } catch (Throwable $e) {
        $overrides = [];
    }

    $default = (int)$room["default_vnd_per_night"];
    $nights  = 0;
    $total   = 0;
    $any_zero = false;
    $lines   = [];

    /* Walk the date range. checkin inclusive, checkout exclusive. */
    for ($t = $start; $t < $end; $t = strtotime("+1 day", $t)) {
        $d = date("Y-m-d", $t);
        $nights++;
        if (isset($overrides[$d])) {
            $vnd = (int)$overrides[$d]["vnd"];
            $lines[] = [
                "date"        => $d,
                "vnd"         => $vnd,
                "season_slug" => $overrides[$d]["season_slug"],
                "is_override" => true,
            ];
        } else {
            $vnd = $default;
            $lines[] = [
                "date"        => $d,
                "vnd"         => $vnd,
                "season_slug" => null,
                "is_override" => false,
            ];
        }
        $total += $vnd;
        if ($vnd <= 0) $any_zero = true;
    }

    return [
        "room"     => $room,
        "nights"   => $nights,
        "total"    => $total,
        "average"  => $nights > 0 ? (int)round($total / $nights) : 0,
        "any_zero" => $any_zero,
        "lines"    => $lines,
    ];
}

/**
 * Bulk read for a calendar grid — returns a date-keyed array of
 * { rate, season_slug, is_override } for every row in the range,
 * across all rooms (or one room when $room_slug is set).
 *
 * Output shape (room-scoped):
 *   [ "YYYY-MM-DD" => ["vnd"=>..., "season_slug"=>..., "is_override"=>true], ... ]
 *
 * Output shape (all rooms):
 *   [ "<slug>" => [ "YYYY-MM-DD" => [...], ... ], ... ]
 */
function knk_room_rates_calendar(string $start_ymd, string $end_ymd, ?string $room_slug = null): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_ymd)) return [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_ymd))   return [];
    try {
        if ($room_slug !== null) {
            $st = knk_db()->prepare(
                "SELECT stay_date, vnd_amount, season_slug, note
                   FROM room_rates
                  WHERE room_slug = ?
                    AND stay_date BETWEEN ? AND ?
               ORDER BY stay_date ASC"
            );
            $st->execute([$room_slug, $start_ymd, $end_ymd]);
            $out = [];
            foreach ($st->fetchAll() as $r) {
                $out[(string)$r["stay_date"]] = [
                    "vnd"         => (int)$r["vnd_amount"],
                    "season_slug" => $r["season_slug"] !== null ? (string)$r["season_slug"] : null,
                    "note"        => $r["note"] !== null ? (string)$r["note"] : null,
                    "is_override" => true,
                ];
            }
            return $out;
        }
        $st = knk_db()->prepare(
            "SELECT room_slug, stay_date, vnd_amount, season_slug, note
               FROM room_rates
              WHERE stay_date BETWEEN ? AND ?
           ORDER BY room_slug ASC, stay_date ASC"
        );
        $st->execute([$start_ymd, $end_ymd]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $slug = (string)$r["room_slug"];
            if (!isset($out[$slug])) $out[$slug] = [];
            $out[$slug][(string)$r["stay_date"]] = [
                "vnd"         => (int)$r["vnd_amount"],
                "season_slug" => $r["season_slug"] !== null ? (string)$r["season_slug"] : null,
                "note"        => $r["note"] !== null ? (string)$r["note"] : null,
                "is_override" => true,
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/* ==========================================================
 * READ — seasons
 * ========================================================== */

function knk_room_rate_seasons(): array {
    try {
        $rows = knk_db()->query(
            "SELECT id, slug, display_name, color_hex, sort_order, is_default
               FROM room_rate_seasons
           ORDER BY sort_order ASC, id ASC"
        )->fetchAll();
        foreach ($rows as &$r) {
            $r["id"]         = (int)$r["id"];
            $r["sort_order"] = (int)$r["sort_order"];
            $r["is_default"] = (int)$r["is_default"];
        }
        unset($r);
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/* ==========================================================
 * WRITE
 * ========================================================== */

/**
 * Set or update one (room, date) override. NULL $season_slug
 * means "manually set, no tier label".
 */
function knk_room_rate_set(string $room_slug, string $date_ymd, int $vnd_amount, ?string $season_slug = null, ?string $note = null): bool {
    $room_slug = trim($room_slug);
    if ($room_slug === "") return false;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($date_ymd))) return false;
    if ($vnd_amount < 0) return false;
    if ($season_slug !== null) $season_slug = trim($season_slug) ?: null;

    try {
        $st = knk_db()->prepare(
            "INSERT INTO room_rates (room_slug, stay_date, vnd_amount, season_slug, note)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                vnd_amount  = VALUES(vnd_amount),
                season_slug = VALUES(season_slug),
                note        = VALUES(note),
                updated_at  = CURRENT_TIMESTAMP"
        );
        return $st->execute([$room_slug, $date_ymd, $vnd_amount, $season_slug, $note]);
    } catch (Throwable $e) {
        error_log("knk_room_rate_set: " . $e->getMessage());
        return false;
    }
}

/**
 * Bulk-set a date range to a season + rate. Used by the admin UI
 * "paint" tool — drag across April/May/June with a tier, get a
 * per-day rate row for each. Existing overrides in the range are
 * replaced.
 *
 * Returns rows-affected count (0 on error).
 */
function knk_room_rate_set_range(string $room_slug, string $start_ymd, string $end_ymd, int $vnd_amount, ?string $season_slug = null, ?string $note = null): int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_ymd)) return 0;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_ymd))   return 0;
    $s = strtotime($start_ymd);
    $e = strtotime($end_ymd);
    if ($s === false || $e === false || $e < $s) return 0;

    $touched = 0;
    try {
        $db = knk_db();
        $db->beginTransaction();
        for ($t = $s; $t <= $e; $t = strtotime("+1 day", $t)) {
            $d = date("Y-m-d", $t);
            if (knk_room_rate_set($room_slug, $d, $vnd_amount, $season_slug, $note)) {
                $touched++;
            }
        }
        $db->commit();
    } catch (Throwable $e2) {
        try { knk_db()->rollBack(); } catch (Throwable $ignored) {}
        return 0;
    }
    return $touched;
}

/**
 * Drop a single (room, date) override. Returns true on success
 * (whether or not a row actually existed).
 */
function knk_room_rate_clear(string $room_slug, string $date_ymd): bool {
    $room_slug = trim($room_slug);
    if ($room_slug === "") return false;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($date_ymd))) return false;
    try {
        $st = knk_db()->prepare(
            "DELETE FROM room_rates WHERE room_slug = ? AND stay_date = ?"
        );
        return $st->execute([$room_slug, $date_ymd]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Update a room's rack rate (rooms.default_vnd_per_night). This
 * is the fallback used when no override row exists, so changing
 * it shifts every un-overridden date silently.
 */
function knk_room_default_rate_set(string $room_slug, int $vnd_amount): bool {
    $room_slug = trim($room_slug);
    if ($room_slug === "" || $vnd_amount < 0) return false;
    try {
        $st = knk_db()->prepare(
            "UPDATE rooms SET default_vnd_per_night = ? WHERE slug = ?"
        );
        return $st->execute([$vnd_amount, $room_slug]);
    } catch (Throwable $e) {
        return false;
    }
}

<?php
/*
 * KnK Inn — bookings store (flat-file, flock-protected).
 *
 * bookings.json schema:
 * {
 *   "holds": [
 *     { "id": "b_abc123", "token": "tok_...", "room": "vip",
 *       "checkin": "2026-05-10", "checkout": "2026-05-14", "nights": 4,
 *       "guest": {"name":"...","email":"...","phone":"...","message":"..."},
 *       "status": "pending|confirmed|declined|expired",
 *       "created_at": 1714000000,
 *       "price_vnd_per_night": 900000
 *     }, ...
 *   ]
 * }
 *
 * Pending holds auto-expire after HOLD_TTL seconds (24h) — availability
 * treats them as NOT blocking dates past expiry.
 */

if (!defined("KNK_HOLD_TTL"))         define("KNK_HOLD_TTL", 86400);    // 24h
if (!defined("KNK_BOOKINGS_PATH"))    define("KNK_BOOKINGS_PATH", __DIR__ . "/../bookings.json");

/* Beds24 outbound sync — included lazily inside the confirm helpers
 * so the rest of the file works fine even if the file is missing
 * (e.g. on a deploy that hasn't updated this folder yet). */
require_once __DIR__ . "/beds24_api.php";

/*
 * Physical room inventory — keyed by room slug.
 *
 *   Ground floor:         1 × Basic (Room 9, Queen, skylight not window)
 *   1st floor:            2 × Standard (Room 1 King + Room 2 Queen)
 *   2nd / 3rd / 4th:      each has 1 × Superior + 1 × Premium
 *   --------------------------------------------------------------
 *                          1 + 2 + 3 + 3 = 9 rooms (matches Beds24)
 *
 * Availability is measured against these counts, so a slug only blocks
 * dates once every physical unit of that type is taken.
 */
if (!isset($GLOBALS["KNK_ROOM_INVENTORY"])) {
    $GLOBALS["KNK_ROOM_INVENTORY"] = [
        "basic"             => 1,
        "standard-nowindow" => 2,
        "standard-balcony"  => 3,
        "vip"               => 3,
    ];
}

function knk_room_inventory(string $room): int {
    $inv = $GLOBALS["KNK_ROOM_INVENTORY"] ?? [];
    return (int)($inv[$room] ?? 1);
}

function knk_total_rooms(): int {
    return (int)array_sum($GLOBALS["KNK_ROOM_INVENTORY"] ?? []);
}

/** Acquire exclusive lock, load JSON, return [$fp, $data]. Caller must call bookings_save() or bookings_close(). */
function bookings_open(): array {
    $path = KNK_BOOKINGS_PATH;
    if (!file_exists($path)) {
        @file_put_contents($path, json_encode(["holds" => []], JSON_PRETTY_PRINT));
    }
    $fp = fopen($path, "r+");
    if (!$fp) {
        throw new RuntimeException("Cannot open bookings store");
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException("Cannot lock bookings store");
    }
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: '{"holds":[]}', true);
    if (!is_array($data) || !isset($data["holds"])) {
        $data = ["holds" => []];
    }
    return [$fp, $data];
}

function bookings_save($fp, array $data): void {
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function bookings_close($fp): void {
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Return array of YYYY-MM-DD strings that are unavailable for the given room.
 * Inventory-aware: a date is only "blocked" once every physical unit of that
 * room type is taken (confirmed bookings and non-expired pending holds).
 * checkout date is EXCLUSIVE (guest leaves that morning).
 */
function bookings_blocked_dates(string $room): array {
    [$fp, $data] = bookings_open();
    $now      = time();
    $capacity = knk_room_inventory($room);
    $counts   = []; // ymd => int
    foreach ($data["holds"] as $h) {
        if (($h["room"] ?? "") !== $room) continue;
        $status = $h["status"] ?? "pending";
        if ($status === "declined" || $status === "expired") continue;
        if ($status === "pending" && ($now - ($h["created_at"] ?? 0)) > KNK_HOLD_TTL) continue;
        $start = strtotime($h["checkin"]);
        $end   = strtotime($h["checkout"]);
        if (!$start || !$end) continue;
        for ($t = $start; $t < $end; $t += 86400) {
            $ymd = date("Y-m-d", $t);
            $counts[$ymd] = ($counts[$ymd] ?? 0) + 1;
        }
    }
    bookings_close($fp);
    $blocked = [];
    foreach ($counts as $ymd => $n) {
        if ($n >= $capacity) $blocked[] = $ymd;
    }
    sort($blocked);
    return $blocked;
}

/**
 * Return [ymd => int] occupancy aggregated across ALL room types.
 * Used by the unified calendar view to render fill ratios.
 */
function bookings_daily_occupancy(): array {
    [$fp, $data] = bookings_open();
    $now    = time();
    $counts = [];
    foreach ($data["holds"] as $h) {
        $status = $h["status"] ?? "pending";
        if ($status === "declined" || $status === "expired") continue;
        if ($status === "pending" && ($now - ($h["created_at"] ?? 0)) > KNK_HOLD_TTL) continue;
        $start = strtotime($h["checkin"] ?? "");
        $end   = strtotime($h["checkout"] ?? "");
        if (!$start || !$end) continue;
        for ($t = $start; $t < $end; $t += 86400) {
            $ymd = date("Y-m-d", $t);
            $counts[$ymd] = ($counts[$ymd] ?? 0) + 1;
        }
    }
    bookings_close($fp);
    return $counts;
}

/**
 * Create a new pending hold. Returns the hold array (incl. generated id + token).
 * Throws if the dates overlap with an existing non-expired hold for the same room.
 */
function bookings_create_hold(array $input): array {
    [$fp, $data] = bookings_open();
    try {
        $room = $input["room"] ?? "";
        $checkin = $input["checkin"] ?? "";
        $checkout = $input["checkout"] ?? "";
        $start = strtotime($checkin);
        $end = strtotime($checkout);
        if (!$room || !$start || !$end || $end <= $start) {
            throw new InvalidArgumentException("Invalid room or dates");
        }

        $now      = time();
        $capacity = knk_room_inventory($room);
        // Count existing overlap per day; reject only if ANY day in the
        // requested range is already at inventory capacity for this slug.
        $dayCounts = [];
        foreach ($data["holds"] as $h) {
            if (($h["room"] ?? "") !== $room) continue;
            $status = $h["status"] ?? "pending";
            if ($status === "declined" || $status === "expired") continue;
            if ($status === "pending" && ($now - ($h["created_at"] ?? 0)) > KNK_HOLD_TTL) continue;
            $hs = strtotime($h["checkin"]);
            $he = strtotime($h["checkout"]);
            if (!$hs || !$he) continue;
            $ovStart = max($start, $hs);
            $ovEnd   = min($end,   $he);
            for ($t = $ovStart; $t < $ovEnd; $t += 86400) {
                $ymd = date("Y-m-d", $t);
                $dayCounts[$ymd] = ($dayCounts[$ymd] ?? 0) + 1;
                if ($dayCounts[$ymd] >= $capacity) {
                    throw new RuntimeException("Dates already held for this room");
                }
            }
        }

        $id    = "b_" . bin2hex(random_bytes(6));
        $token = "tok_" . bin2hex(random_bytes(16));
        $hold = [
            "id"                   => $id,
            "token"                => $token,
            "room"                 => $room,
            "checkin"              => $checkin,
            "checkout"             => $checkout,
            "nights"               => (int)(($end - $start) / 86400),
            "guest"                => $input["guest"] ?? [],
            "status"               => "pending",
            "created_at"           => $now,
            "price_vnd_per_night"  => (int)($input["price_vnd_per_night"] ?? 0),
        ];
        /* Optional metadata (mirrors migration 001's bookings table).
         * Lets staff tag externally-mirrored bookings (Airbnb /
         * Booking.com / Tripadvisor / walk-in / phone) so the
         * dashboard can show them differently. Both fields are
         * free-form strings — we don't validate the values, just
         * cap their length and pass them through. */
        if (!empty($input["source"])) {
            $hold["source"] = mb_substr((string)$input["source"], 0, 40);
        }
        if (!empty($input["external_ref"])) {
            $hold["external_ref"] = mb_substr((string)$input["external_ref"], 0, 120);
        }
        $data["holds"][] = $hold;
        bookings_save($fp, $data);
        return $hold;
    } catch (Throwable $e) {
        bookings_close($fp);
        throw $e;
    }
}

/**
 * Sweep for confirmed bookings whose checkout is in the past, flip
 * them to "completed". Idempotent — already-completed bookings are
 * untouched. Designed to run from cron so the upcoming list, the
 * snapshot widget, and the forecast all stop counting stays that
 * have already happened.
 *
 * Returns the number of rows promoted.
 */
function bookings_auto_complete_past(): int {
    [$fp, $data] = bookings_open();
    $today_ts = strtotime(date("Y-m-d") . " 00:00:00");
    $promoted = 0;
    $dirty    = false;
    foreach ($data["holds"] as $i => $h) {
        if (($h["status"] ?? "") !== "confirmed") continue;
        $co = strtotime((string)($h["checkout"] ?? ""));
        if (!$co || $co > $today_ts) continue;
        /* Don't touch manual blocks — they're not real stays and
         * can stretch indefinitely without expiring. */
        $name = strtolower(trim((string)($h["guest"]["name"] ?? "")));
        if ($name === "blocked") continue;
        $data["holds"][$i]["status"]       = "completed";
        $data["holds"][$i]["completed_at"] = time();
        $promoted++;
        $dirty = true;
    }
    if ($dirty) {
        bookings_save($fp, $data);
    } else {
        bookings_close($fp);
    }
    return $promoted;
}

/** Look up a hold by token (constant-time compare). Returns [hold, index] or null. */
function bookings_find_by_token(string $token): ?array {
    [$fp, $data] = bookings_open();
    foreach ($data["holds"] as $i => $h) {
        if (isset($h["token"]) && hash_equals($h["token"], $token)) {
            bookings_close($fp);
            return [$h, $i];
        }
    }
    bookings_close($fp);
    return null;
}

/** Return ALL holds, newest first by created_at. Optionally expire stale pending holds on the fly. */
function bookings_list_all(bool $expire_stale = true): array {
    [$fp, $data] = bookings_open();
    $now = time();
    $dirty = false;
    foreach ($data["holds"] as $i => $h) {
        $status = $h["status"] ?? "pending";
        if ($expire_stale && $status === "pending" && ($now - ($h["created_at"] ?? 0)) > KNK_HOLD_TTL) {
            $data["holds"][$i]["status"] = "expired";
            $data["holds"][$i]["expired_at"] = $now;
            $dirty = true;
        }
    }
    if ($dirty) {
        bookings_save($fp, $data);
    } else {
        bookings_close($fp);
    }
    $out = $data["holds"];
    usort($out, function ($a, $b) { return ($b["created_at"] ?? 0) <=> ($a["created_at"] ?? 0); });
    return $out;
}

/**
 * Manually block a range of dates for a given room (e.g. maintenance).
 * Creates a confirmed hold with guest.name = $reason (defaults to "Blocked").
 * Returns the created hold or throws on overlap.
 */
function bookings_manual_block(string $room, string $checkin, string $checkout, string $reason = "Blocked"): array {
    $hold = bookings_create_hold([
        "room"                => $room,
        "checkin"             => $checkin,
        "checkout"            => $checkout,
        "price_vnd_per_night" => 0,
        "guest"               => [
            "name"    => $reason,
            "email"   => "",
            "phone"   => "",
            "message" => "Manually blocked via admin panel.",
        ],
    ]);
    // Flip straight to confirmed (skip the pending phase)
    return bookings_set_status_by_token($hold["token"], "confirm") ?? $hold;
}

/** Delete a hold by id (used for unblocking manual blocks or cleaning up). Returns true if deleted. */
function bookings_delete_by_id(string $id): bool {
    [$fp, $data] = bookings_open();
    try {
        $filtered = [];
        $deleted = false;
        foreach ($data["holds"] as $h) {
            if (($h["id"] ?? "") === $id) { $deleted = true; continue; }
            $filtered[] = $h;
        }
        if ($deleted) {
            $data["holds"] = $filtered;
            bookings_save($fp, $data);
        } else {
            bookings_close($fp);
        }
        return $deleted;
    } catch (Throwable $e) {
        bookings_close($fp);
        throw $e;
    }
}

/** Find a hold by id. Returns [hold, index] or null. */
function bookings_find_by_id(string $id): ?array {
    [$fp, $data] = bookings_open();
    foreach ($data["holds"] as $i => $h) {
        if (($h["id"] ?? "") === $id) {
            bookings_close($fp);
            return [$h, $i];
        }
    }
    bookings_close($fp);
    return null;
}

/** Flip status by id (admin use — token not required). action=confirm|decline. */
function bookings_set_status_by_id(string $id, string $action): ?array {
    [$fp, $data] = bookings_open();
    try {
        foreach ($data["holds"] as $i => $h) {
            if (($h["id"] ?? "") !== $id) continue;
            $newStatus = $action === "confirm" ? "confirmed" : "declined";
            $data["holds"][$i]["status"] = $newStatus;
            $data["holds"][$i]["actioned_at"] = time();
            bookings_save($fp, $data);
            $hold = $data["holds"][$i];
            knk_bookings_after_status_change($hold, $action);
            return $hold;
        }
        bookings_close($fp);
        return null;
    } catch (Throwable $e) {
        bookings_close($fp);
        throw $e;
    }
}

/** Update hold status by token. action = confirm|decline. Returns updated hold, or null if not found. */
function bookings_set_status_by_token(string $token, string $action): ?array {
    [$fp, $data] = bookings_open();
    try {
        foreach ($data["holds"] as $i => $h) {
            if (!isset($h["token"]) || !hash_equals($h["token"], $token)) continue;
            $newStatus = $action === "confirm" ? "confirmed" : "declined";
            $data["holds"][$i]["status"] = $newStatus;
            $data["holds"][$i]["actioned_at"] = time();
            bookings_save($fp, $data);
            $hold = $data["holds"][$i];
            knk_bookings_after_status_change($hold, $action);
            return $hold;
        }
        bookings_close($fp);
        return null;
    } catch (Throwable $e) {
        bookings_close($fp);
        throw $e;
    }
}

/**
 * Side-effect hook called AFTER a hold's status changes.
 * Currently: pushes confirmed bookings to Beds24 so it can
 * propagate the room-night occupancy to Booking.com / Airbnb.
 * Stores the returned beds24_booking_id back on the hold so
 * a future cancel can identify the right Beds24 booking.
 *
 * Defensive — never throws. Beds24 outage is an error_log
 * entry, not a user-facing failure.
 */
function knk_bookings_after_status_change(array $hold, string $action): void {
    if ($action !== "confirm") {
        /* Decline path: if we already pushed this hold, cancel it. */
        $existing = (int)($hold["beds24_booking_id"] ?? 0);
        if ($existing > 0 && function_exists("knk_beds24_cancel")) {
            knk_beds24_cancel($existing);
        }
        return;
    }

    if (!function_exists("knk_beds24_push_confirmed")) return;
    $bookingId = knk_beds24_push_confirmed($hold);
    if ($bookingId <= 0) return;

    /* Persist the Beds24 bookingId back onto the hold so we
     * can match it for future cancellations and avoid
     * double-pushing on retry. */
    [$fp, $data] = bookings_open();
    try {
        foreach ($data["holds"] as $i => $h) {
            if (($h["id"] ?? "") !== ($hold["id"] ?? "")) continue;
            $data["holds"][$i]["beds24_booking_id"] = $bookingId;
            $data["holds"][$i]["beds24_pushed_at"]  = time();
            bookings_save($fp, $data);
            return;
        }
        bookings_close($fp);
    } catch (Throwable $e) {
        bookings_close($fp);
        error_log("KnK bookings: failed to persist beds24_booking_id: " . $e->getMessage());
    }
}

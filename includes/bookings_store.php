<?php
/*
 * KnK Inn — bookings store (flat-file, flock-protected).
 *
 * bookings.json schema:
 * {
 *   "holds": [
 *     { "id": "b_abc123", "token": "tok_...", "room": "f2-vip",
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
 * Includes confirmed bookings and non-expired pending holds.
 * checkout date is EXCLUSIVE (guest leaves that morning).
 */
function bookings_blocked_dates(string $room): array {
    [$fp, $data] = bookings_open();
    $now = time();
    $blocked = [];
    foreach ($data["holds"] as $h) {
        if (($h["room"] ?? "") !== $room) continue;
        $status = $h["status"] ?? "pending";
        if ($status === "declined" || $status === "expired") continue;
        if ($status === "pending" && ($now - ($h["created_at"] ?? 0)) > KNK_HOLD_TTL) continue;
        // expand range [checkin, checkout)
        $start = strtotime($h["checkin"]);
        $end   = strtotime($h["checkout"]);
        if (!$start || !$end) continue;
        for ($t = $start; $t < $end; $t += 86400) {
            $blocked[] = date("Y-m-d", $t);
        }
    }
    bookings_close($fp);
    return array_values(array_unique($blocked));
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

        $now = time();
        foreach ($data["holds"] as $h) {
            if (($h["room"] ?? "") !== $room) continue;
            $status = $h["status"] ?? "pending";
            if ($status === "declined" || $status === "expired") continue;
            if ($status === "pending" && ($now - ($h["created_at"] ?? 0)) > KNK_HOLD_TTL) continue;
            $hs = strtotime($h["checkin"]);
            $he = strtotime($h["checkout"]);
            if ($start < $he && $end > $hs) {
                throw new RuntimeException("Dates already held for this room");
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
        $data["holds"][] = $hold;
        bookings_save($fp, $data);
        return $hold;
    } catch (Throwable $e) {
        bookings_close($fp);
        throw $e;
    }
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
            return $data["holds"][$i];
        }
        bookings_close($fp);
        return null;
    } catch (Throwable $e) {
        bookings_close($fp);
        throw $e;
    }
}

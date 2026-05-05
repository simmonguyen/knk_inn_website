<?php
/*
 * KnK Inn — Beds24 v2 API client.
 *
 * Outbound sync: when a hold flips to "confirmed" in our local
 * bookings store, push it to Beds24 as a confirmed booking.
 * Beds24 then propagates the room-night occupancy out to all
 * connected OTAs (Booking.com, Airbnb, etc.) so we don't get
 * double-booked.
 *
 * This file is defensive — every public function is wrapped in
 * try/catch so a Beds24 outage never breaks the user-facing
 * booking flow. Failures are logged via error_log and the local
 * hold still completes; staff can manually re-push via /bookings.php.
 *
 * AUTH MODEL (Beds24 v2):
 *   1. Operator generates a long-lived `refreshToken` in Beds24
 *      admin (Settings → Account → API).
 *   2. Each call exchanges that for a short-lived `accessToken`
 *      via GET /authentication/token  (returns ~24h-valid token).
 *   3. We cache the accessToken in /tmp/.knk_beds24_token.json
 *      (gitignored, mode 0600) so we don't burn the refresh token
 *      on every booking event.
 *
 * CONFIG required in config.php:
 *   "beds24" => [
 *       "refresh_token" => "<long string from Beds24 admin>",
 *       "property_id"   => 325504,
 *       "room_map"      => [
 *           "vip"               => 675740,  // Premium
 *           "standard-balcony"  => 676694,  // Superior
 *           "standard-nowindow" => 676693,  // Standard
 *           // Basic (675750) needs a slug once we add it to
 *           // bookings_store inventory — currently absent.
 *       ],
 *   ],
 *
 * Leave refresh_token empty to disable the integration cleanly.
 */

declare(strict_types=1);

if (!defined("KNK_BEDS24_BASE"))      define("KNK_BEDS24_BASE", "https://api.beds24.com/v2");
if (!defined("KNK_BEDS24_TOKEN_PATH")) define("KNK_BEDS24_TOKEN_PATH", sys_get_temp_dir() . "/.knk_beds24_token.json");
/* Refresh access token if it has less than this many seconds left. */
if (!defined("KNK_BEDS24_TOKEN_REFRESH_THRESHOLD")) define("KNK_BEDS24_TOKEN_REFRESH_THRESHOLD", 600);

/**
 * Pull the integration config slice from config.php.
 * Returns the array or null when the integration is disabled.
 */
function knk_beds24_config(): ?array {
    static $cached = null;
    if ($cached !== null) return $cached === false ? null : $cached;
    $cfgPath = __DIR__ . "/../config.php";
    if (!is_file($cfgPath)) {
        $cached = false;
        return null;
    }
    $cfg = include $cfgPath;
    $b24 = is_array($cfg) ? ($cfg["beds24"] ?? null) : null;
    if (!is_array($b24) || empty($b24["refresh_token"]) || empty($b24["property_id"])) {
        $cached = false;
        return null;
    }
    $cached = $b24;
    return $b24;
}

/** Quick check used by callers to short-circuit when integration isn't configured. */
function knk_beds24_enabled(): bool {
    return knk_beds24_config() !== null;
}

/**
 * Map an internal room slug to the Beds24 numeric roomId.
 * Returns 0 if the slug isn't mapped (caller should treat as "skip push").
 */
function knk_beds24_room_id(string $slug): int {
    $cfg = knk_beds24_config();
    if (!$cfg) return 0;
    $map = $cfg["room_map"] ?? [];
    return (int)($map[$slug] ?? 0);
}

/**
 * Get a valid access token, refreshing from disk cache if usable.
 * Returns the token string, or throws on auth failure.
 */
function knk_beds24_access_token(): string {
    $cfg = knk_beds24_config();
    if (!$cfg) {
        throw new RuntimeException("Beds24 integration not configured");
    }

    /* Try cached token first. */
    if (is_file(KNK_BEDS24_TOKEN_PATH)) {
        $raw = @file_get_contents(KNK_BEDS24_TOKEN_PATH);
        $cached = $raw ? json_decode($raw, true) : null;
        if (is_array($cached)
            && !empty($cached["access_token"])
            && (int)($cached["expires_at"] ?? 0) > time() + KNK_BEDS24_TOKEN_REFRESH_THRESHOLD) {
            return (string)$cached["access_token"];
        }
    }

    /* Refresh. */
    $resp = knk_beds24_http(
        "GET",
        "/authentication/token",
        null,
        ["refreshToken: " . $cfg["refresh_token"]]
    );
    $access = $resp["token"] ?? "";
    $ttl    = (int)($resp["expiresIn"] ?? 86400);
    if (!$access) {
        throw new RuntimeException("Beds24 returned no access token");
    }

    @file_put_contents(KNK_BEDS24_TOKEN_PATH, json_encode([
        "access_token" => $access,
        "expires_at"   => time() + max(60, $ttl - 30),
    ]));
    @chmod(KNK_BEDS24_TOKEN_PATH, 0600);
    return $access;
}

/**
 * Low-level HTTP wrapper. Returns decoded JSON on 2xx, throws on non-2xx.
 * $body is JSON-encoded automatically when not null.
 */
function knk_beds24_http(string $method, string $path, ?array $body = null, array $headers = []): array {
    $url = KNK_BEDS24_BASE . $path;
    $ch  = curl_init();
    $hdr = array_merge(["Accept: application/json", "Content-Type: application/json"], $headers);
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $hdr,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException("Beds24 HTTP error: $err");
    }
    $data = json_decode((string)$raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($data) ? json_encode($data) : (string)$raw;
        throw new RuntimeException("Beds24 HTTP $code: $msg");
    }
    return is_array($data) ? $data : [];
}

/**
 * Push a confirmed local hold to Beds24 as a confirmed booking.
 *
 * On success: returns the Beds24 bookingId (int).
 * On any error: logs to error_log and returns 0 (the caller should
 * treat 0 as "not pushed" and may retry later via /bookings.php).
 *
 * The local hold MUST already be in "confirmed" status — we don't
 * push pending holds because they may be declined.
 *
 * Idempotency: if the hold has a `beds24_booking_id` set, we skip
 * (a second confirm shouldn't double-book). Caller is responsible
 * for storing the returned bookingId on the hold.
 */
function knk_beds24_push_confirmed(array $hold): int {
    if (!knk_beds24_enabled()) return 0;

    $existing = (int)($hold["beds24_booking_id"] ?? 0);
    if ($existing > 0) return $existing;

    if (($hold["status"] ?? "") !== "confirmed") {
        error_log("KnK Beds24: refusing to push non-confirmed hold {$hold["id"]} status={$hold["status"]}");
        return 0;
    }

    $slug   = (string)($hold["room"] ?? "");
    $roomId = knk_beds24_room_id($slug);
    if ($roomId === 0) {
        error_log("KnK Beds24: no roomId mapping for slug '$slug' — skipping push for {$hold["id"]}");
        return 0;
    }

    $cfg   = knk_beds24_config();
    $guest = $hold["guest"] ?? [];
    $name  = trim((string)($guest["name"] ?? ""));
    $space = strpos($name, " ");
    $first = $space !== false ? substr($name, 0, $space) : $name;
    $last  = $space !== false ? substr($name, $space + 1) : "";

    $nights = max(1, (int)($hold["nights"] ?? 1));
    $price  = (int)($hold["price_vnd_per_night"] ?? 0) * $nights;

    $payload = [[
        "roomId"     => $roomId,
        "status"     => "confirmed",
        "arrival"    => (string)($hold["checkin"]  ?? ""),
        "departure"  => (string)($hold["checkout"] ?? ""),
        "firstName"  => $first ?: "Guest",
        "lastName"   => $last,
        "email"      => (string)($guest["email"]   ?? ""),
        "phone"      => (string)($guest["phone"]   ?? ""),
        "numAdult"   => max(1, (int)($guest["guests"] ?? 1)),
        "numChild"   => 0,
        "price"      => $price > 0 ? $price : null,
        "referer"    => "Direct - knkinn.com",
        "notes"      => trim((string)($guest["message"] ?? "")) ?: null,
        "apiSourceId"=> (string)$hold["id"],  /* dedup key on Beds24's side */
    ]];

    try {
        $token = knk_beds24_access_token();
        $resp  = knk_beds24_http("POST", "/bookings", $payload, ["token: $token"]);
        /* Beds24 v2 returns an array of result objects keyed by index. */
        $first = is_array($resp) ? ($resp[0] ?? $resp) : [];
        $bookingId = (int)($first["new"]["bookingId"] ?? $first["bookingId"] ?? 0);
        if ($bookingId === 0) {
            error_log("KnK Beds24: push succeeded but no bookingId in response for {$hold["id"]}: " . json_encode($resp));
        }
        return $bookingId;
    } catch (Throwable $e) {
        error_log("KnK Beds24: push failed for {$hold["id"]}: " . $e->getMessage());
        return 0;
    }
}

/**
 * Mark a Beds24 booking as cancelled (used when a hold is declined
 * AFTER it was already pushed, or when staff manually cancels in
 * /bookings.php). Idempotent: returns true if cancelled or already
 * cancelled, false on hard error.
 */
function knk_beds24_cancel(int $beds24BookingId): bool {
    if (!knk_beds24_enabled() || $beds24BookingId <= 0) return false;
    try {
        $token = knk_beds24_access_token();
        knk_beds24_http("POST", "/bookings", [[
            "id"     => $beds24BookingId,
            "status" => "cancelled",
        ]], ["token: $token"]);
        return true;
    } catch (Throwable $e) {
        error_log("KnK Beds24: cancel failed for #$beds24BookingId: " . $e->getMessage());
        return false;
    }
}

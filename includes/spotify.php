<?php
/*
 * KnK Inn — Spotify Connect integration.
 *
 * Sits as the optional tier-2 ambient layer between guest YouTube
 * requests (tier 1) and the radio stream fallback (tier 3) in the
 * jukebox player. Only devices launched with `tv.php?spotify=1`
 * actually engage this layer; ground floor (no `?spotify=1`) keeps
 * the existing YouTube → Radio behaviour untouched.
 *
 * Architecture:
 *   * Spotify desktop / Connect app runs on F5's Windows machine,
 *     signed into the venue account, sitting in the background as
 *     a Connect playback target.
 *   * tv.php in the browser on F5 doesn't host audio — it just
 *     sends remote-control commands (play/pause/health-check) to
 *     the local Spotify app via api.spotify.com.
 *   * Access tokens (1-hour life) are minted server-side from the
 *     refresh token. The browser never sees the long-lived secret;
 *     it only ever talks to /api/spotify_proxy.php.
 *
 * Storage (all on jukebox_config, see migration 030):
 *   spotify_client_id, spotify_client_secret  — Developer app creds
 *   spotify_refresh_token                     — captured during OAuth
 *   spotify_device_id, spotify_device_name    — F5's Connect target
 *   spotify_default_playlist_uri              — ambient playlist
 *   spotify_volume_pct                        — ambient volume 0-100
 *   spotify_last_ok_at                        — diagnostics only
 *
 * PHP 7.4 — no match, no nullsafe (?->), no enums, no readonly.
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";

/** OAuth scopes we need.
 *
 *   user-read-playback-state      — list available Connect devices
 *   user-modify-playback-state    — play / pause / volume / queue
 *   user-read-currently-playing   — surface "now playing" later
 *   playlist-read-private         — let admin pick from venue playlists
 *
 * Adding more scopes later is harmless except that it forces the
 * user to re-authorise. Start lean. */
const KNK_SPOTIFY_SCOPES = "user-read-playback-state user-modify-playback-state user-read-currently-playing playlist-read-private";

/** OAuth redirect URI. Must EXACTLY match what's registered in the
 *  Spotify Developer app — case-sensitive, scheme-sensitive, trailing
 *  slash sensitive. Cloudflare strips the trailing slash; mat bao keeps
 *  it. We use no trailing slash to match Cloudflare's normalised form. */
function knk_spotify_redirect_uri(): string {
    return "https://knkinn.com/api/spotify_oauth_callback.php";
}

/* ==========================================================
 * CONFIG ACCESS
 * ======================================================== */

/** Subset of jukebox_config the admin form is allowed to edit
 *  directly. Token and device fields are written only by the OAuth
 *  callback, never by the Save Settings button. */
function knk_spotify_admin_fields(): array {
    return [
        "spotify_client_id",
        "spotify_client_secret",
        "spotify_default_playlist_uri",
        "spotify_volume_pct",
    ];
}

/** All Spotify-related fields, used by callback / proxy. */
function knk_spotify_all_fields(): array {
    return array_merge(knk_spotify_admin_fields(), [
        "spotify_refresh_token",
        "spotify_device_id",
        "spotify_device_name",
        "spotify_last_ok_at",
    ]);
}

/** Read the Spotify slice of jukebox_config. Returns blanks if the
 *  row doesn't exist yet. */
function knk_spotify_config(): array {
    $row = knk_db()->query("SELECT * FROM jukebox_config WHERE id = 1 LIMIT 1")->fetch();
    if (!$row) {
        return [];
    }
    $out = [];
    foreach (knk_spotify_all_fields() as $f) {
        $out[$f] = isset($row[$f]) ? $row[$f] : "";
    }
    return $out;
}

/** Generic update of a few jukebox_config fields. Used by the OAuth
 *  callback to persist refresh_token / device_id without going via
 *  the admin allowlist. */
function knk_spotify_config_update(array $fields): void {
    if (empty($fields)) return;
    $allowed = knk_spotify_all_fields();
    $sets = [];
    $vals = [];
    foreach ($fields as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        $sets[] = "`{$k}` = ?";
        $vals[] = $v;
    }
    if (empty($sets)) return;
    $sql = "UPDATE jukebox_config SET " . implode(", ", $sets) . " WHERE id = 1";
    $stmt = knk_db()->prepare($sql);
    $stmt->execute($vals);
    if (knk_db()->query("SELECT 1 FROM jukebox_config WHERE id = 1")->fetchColumn() === false) {
        knk_db()->exec("INSERT IGNORE INTO jukebox_config (id) VALUES (1)");
        $stmt->execute($vals);
    }
}

/** True when admin has filled in client_id + client_secret. */
function knk_spotify_has_app_creds(): bool {
    $cfg = knk_spotify_config();
    return !empty($cfg["spotify_client_id"]) && !empty($cfg["spotify_client_secret"]);
}

/** True when the venue has gone through OAuth at least once. */
function knk_spotify_is_connected(): bool {
    $cfg = knk_spotify_config();
    return knk_spotify_has_app_creds() && !empty($cfg["spotify_refresh_token"]);
}

/** True when fully ready to play (connected + device picked + playlist set). */
function knk_spotify_is_ready(): bool {
    $cfg = knk_spotify_config();
    return knk_spotify_is_connected()
        && !empty($cfg["spotify_device_id"])
        && !empty($cfg["spotify_default_playlist_uri"]);
}

/* ==========================================================
 * OAUTH FLOW
 * ======================================================== */

/** Build the URL to redirect the admin to so they can grant access.
 *  After the user clicks "Authorize" Spotify will redirect back to
 *  knk_spotify_redirect_uri() with ?code=... which the callback
 *  exchanges for a refresh_token. */
function knk_spotify_authorize_url(string $state): string {
    $cfg = knk_spotify_config();
    $params = [
        "client_id"     => $cfg["spotify_client_id"],
        "response_type" => "code",
        "redirect_uri"  => knk_spotify_redirect_uri(),
        "scope"         => KNK_SPOTIFY_SCOPES,
        "state"         => $state,
        "show_dialog"   => "true",  // force re-consent — useful when re-linking
    ];
    return "https://accounts.spotify.com/authorize?" . http_build_query($params);
}

/** POST to Spotify's token endpoint. Used by both the initial
 *  code exchange and the periodic refresh. Returns the decoded JSON
 *  on success, throws on failure. */
function knk_spotify_token_request(array $form_body): array {
    $cfg = knk_spotify_config();
    $client_id     = $cfg["spotify_client_id"];
    $client_secret = $cfg["spotify_client_secret"];
    if ($client_id === "" || $client_secret === "") {
        throw new RuntimeException("Spotify client credentials not set");
    }

    $ch = curl_init("https://accounts.spotify.com/api/token");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($form_body),
        CURLOPT_HTTPHEADER     => [
            "Authorization: Basic " . base64_encode($client_id . ":" . $client_secret),
            "Content-Type: application/x-www-form-urlencoded",
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("Spotify token request failed: " . $err);
    }
    $j = json_decode($body, true);
    if ($code < 200 || $code >= 300 || !is_array($j)) {
        $msg = is_array($j) && isset($j["error_description"]) ? $j["error_description"]
             : (is_array($j) && isset($j["error"]) ? $j["error"] : substr($body, 0, 200));
        throw new RuntimeException("Spotify token error (HTTP {$code}): " . $msg);
    }
    return $j;
}

/** Exchange the one-shot ?code from the OAuth redirect for a
 *  refresh_token. Persists refresh_token to jukebox_config. */
function knk_spotify_exchange_code(string $code): void {
    $resp = knk_spotify_token_request([
        "grant_type"   => "authorization_code",
        "code"         => $code,
        "redirect_uri" => knk_spotify_redirect_uri(),
    ]);
    if (empty($resp["refresh_token"])) {
        throw new RuntimeException("Spotify token response missing refresh_token");
    }
    knk_spotify_config_update([
        "spotify_refresh_token" => $resp["refresh_token"],
        "spotify_last_ok_at"    => date("Y-m-d H:i:s"),
    ]);
    // Cache the access token in APCu / file so the proxy doesn't refresh on every call.
    knk_spotify_cache_access_token($resp["access_token"], (int)$resp["expires_in"]);
}

/* ==========================================================
 * ACCESS TOKEN CACHE
 *
 * Spotify access tokens live ~1 hour. Refreshing on every API call
 * would be slow and burn rate limits. Cache the latest token in
 * a tmp file (APCu would be nicer but Mat Bao shared hosting may
 * not have it) and only refresh when expiry is within 60 seconds.
 * ======================================================== */

function knk_spotify_token_cache_path(): string {
    return sys_get_temp_dir() . "/knk_spotify_access_token.json";
}

function knk_spotify_cache_access_token(string $token, int $expires_in): void {
    $payload = json_encode([
        "access_token" => $token,
        "expires_at"   => time() + $expires_in - 60,  // refresh 60s early
    ]);
    @file_put_contents(knk_spotify_token_cache_path(), $payload, LOCK_EX);
}

function knk_spotify_cached_access_token(): ?string {
    $p = knk_spotify_token_cache_path();
    if (!is_file($p)) return null;
    $raw = @file_get_contents($p);
    if ($raw === false || $raw === "") return null;
    $j = json_decode($raw, true);
    if (!is_array($j) || empty($j["access_token"]) || empty($j["expires_at"])) return null;
    if ((int)$j["expires_at"] <= time()) return null;
    return (string)$j["access_token"];
}

/** Get a valid access token. Uses cached one if fresh; otherwise
 *  hits Spotify's token endpoint with the stored refresh_token. */
function knk_spotify_access_token(): string {
    $cached = knk_spotify_cached_access_token();
    if ($cached !== null) return $cached;

    $cfg = knk_spotify_config();
    if (empty($cfg["spotify_refresh_token"])) {
        throw new RuntimeException("Spotify not connected — run OAuth first");
    }

    $resp = knk_spotify_token_request([
        "grant_type"    => "refresh_token",
        "refresh_token" => $cfg["spotify_refresh_token"],
    ]);
    if (empty($resp["access_token"])) {
        throw new RuntimeException("Spotify refresh missing access_token");
    }
    knk_spotify_cache_access_token($resp["access_token"], (int)($resp["expires_in"] ?? 3600));
    // Spotify *can* rotate refresh tokens. Persist if they did.
    if (!empty($resp["refresh_token"]) && $resp["refresh_token"] !== $cfg["spotify_refresh_token"]) {
        knk_spotify_config_update(["spotify_refresh_token" => $resp["refresh_token"]]);
    }
    knk_spotify_config_update(["spotify_last_ok_at" => date("Y-m-d H:i:s")]);
    return (string)$resp["access_token"];
}

/* ==========================================================
 * GENERIC API CALLER
 *
 * Used by /api/spotify_proxy.php for browser-driven calls and by
 * server-side helpers (device discovery, health checks).
 * ======================================================== */

/**
 * Make a Spotify Web API call.
 *
 * @param string      $method  HTTP method (GET/PUT/POST/DELETE)
 * @param string      $path    Path under api.spotify.com/v1, with or without leading slash
 * @param array|null  $body    JSON body for PUT/POST; null for GET
 * @param array       $query   Query-string params
 * @return array               Decoded JSON, or ["_status"=>204] for empty success responses
 */
function knk_spotify_api(string $method, string $path, ?array $body = null, array $query = []): array {
    $token = knk_spotify_access_token();
    $url   = "https://api.spotify.com/v1/" . ltrim($path, "/");
    if (!empty($query)) {
        $url .= (strpos($url, "?") === false ? "?" : "&") . http_build_query($query);
    }

    $ch = curl_init($url);
    $headers = ["Authorization: Bearer " . $token];
    if ($body !== null) {
        $headers[] = "Content-Type: application/json";
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("Spotify API curl failed: " . $err);
    }

    // 204 No Content is a normal success for play/pause/etc.
    if ($code === 204 || $raw === "") {
        return ["_status" => $code];
    }

    $j = json_decode($raw, true);
    if (!is_array($j)) {
        // Spotify sometimes returns plain text on error.
        throw new RuntimeException("Spotify API HTTP {$code}: " . substr($raw, 0, 200));
    }
    if ($code < 200 || $code >= 300) {
        $msg = isset($j["error"]["message"]) ? $j["error"]["message"]
             : (isset($j["error"]) ? json_encode($j["error"]) : "unknown");
        throw new RuntimeException("Spotify API HTTP {$code}: " . $msg);
    }
    return $j;
}

/* ==========================================================
 * DEVICE / HEALTH HELPERS
 * ======================================================== */

/** List Connect-capable devices on the venue account. Each item:
 *  ["id" => ..., "name" => ..., "type" => "Computer", "is_active" => bool, ...] */
function knk_spotify_devices(): array {
    $j = knk_spotify_api("GET", "me/player/devices");
    return isset($j["devices"]) && is_array($j["devices"]) ? $j["devices"] : [];
}

/** True if the configured device ID is currently visible to Spotify
 *  (i.e. F5's Spotify app is running and online). Used by tv.php
 *  before deciding to start ambient playback. */
function knk_spotify_device_healthy(): bool {
    $cfg = knk_spotify_config();
    if (empty($cfg["spotify_device_id"])) return false;
    try {
        $devs = knk_spotify_devices();
    } catch (Throwable $e) {
        return false;
    }
    foreach ($devs as $d) {
        if (isset($d["id"]) && $d["id"] === $cfg["spotify_device_id"]) {
            return true;
        }
    }
    return false;
}

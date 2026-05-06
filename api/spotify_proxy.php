<?php
/*
 * KnK Inn — /api/spotify_proxy.php
 *
 * Server-side proxy for Spotify Connect Web API calls. The browser
 * (tv.php) never sees the access token; it just POSTs an action
 * here and we forward to api.spotify.com using the venue's stored
 * refresh token.
 *
 * This endpoint is unauthenticated by user login on purpose — the
 * F5 TV's browser running tv.php?spotify=1 doesn't have a logged-in
 * staff session, it's just a kiosk. Trust boundary instead is:
 *   * The Spotify access lives entirely server-side; this script
 *     only ever returns metadata (health, current device) or
 *     causes effectful changes on the venue's own Spotify account
 *     (play/pause/volume). Worst case if abused: a stranger
 *     pauses or skips ambient music. Acceptable.
 *   * Optional CSRF-lite via Origin/Referer check (see below)
 *     limits abuse to people who can serve pages on knkinn.com.
 *
 * Actions (POST'd):
 *   action=health                     → { healthy: bool, device: ..., reason: ... }
 *   action=play                       → start playing the configured playlist on the configured device
 *   action=pause                      → pause whatever's playing on our device
 *   action=volume     value=N         → set volume (0-100)
 *   action=now_playing                → { is_playing, item: {name, artists}, ... }
 *
 * Always returns JSON. Never echoes the access token.
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/spotify.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

// Lightweight origin check. Accept same-origin POSTs only — keeps
// random pages on the internet from controlling our Spotify.
$ref = isset($_SERVER["HTTP_REFERER"]) ? (string)$_SERVER["HTTP_REFERER"] : "";
if ($ref !== "" && stripos($ref, "://knkinn.com") === false && stripos($ref, "://www.knkinn.com") === false) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "bad_referer"]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false, "error" => "method_not_allowed"]);
    exit;
}

$action = isset($_POST["action"]) ? (string)$_POST["action"] : "";

try {
    if (!knk_spotify_is_connected()) {
        echo json_encode(["ok" => false, "error" => "not_connected"]);
        exit;
    }

    $cfg       = knk_spotify_config();
    $device_id = (string)($cfg["spotify_device_id"] ?? "");
    $playlist  = (string)($cfg["spotify_default_playlist_uri"] ?? "");

    if ($action === "health") {
        if ($device_id === "") {
            echo json_encode(["ok" => true, "healthy" => false, "reason" => "no_device"]);
            exit;
        }
        $devs = [];
        try { $devs = knk_spotify_devices(); } catch (Throwable $e) {
            echo json_encode(["ok" => true, "healthy" => false, "reason" => "api_error", "detail" => $e->getMessage()]);
            exit;
        }
        $found = null;
        foreach ($devs as $d) {
            if (isset($d["id"]) && $d["id"] === $device_id) { $found = $d; break; }
        }
        if ($found === null) {
            echo json_encode(["ok" => true, "healthy" => false, "reason" => "device_offline"]);
            exit;
        }
        echo json_encode([
            "ok"      => true,
            "healthy" => true,
            "device"  => [
                "id"        => $device_id,
                "name"      => isset($found["name"]) ? $found["name"] : "",
                "is_active" => !empty($found["is_active"]),
                "volume"    => isset($found["volume_percent"]) ? (int)$found["volume_percent"] : null,
            ],
            "playlist_set" => $playlist !== "",
        ]);
        exit;
    }

    if ($action === "play") {
        if ($device_id === "" || $playlist === "") {
            echo json_encode(["ok" => false, "error" => "not_ready"]);
            exit;
        }
        // Set ambient volume first (best-effort; ignore errors so a
        // failed volume call doesn't block playback).
        $vol = (int)($cfg["spotify_volume_pct"] ?? 50);
        try {
            knk_spotify_api("PUT", "me/player/volume", null, [
                "volume_percent" => max(0, min(100, $vol)),
                "device_id"      => $device_id,
            ]);
        } catch (Throwable $e) { /* swallow */ }

        // Loop the playlist context so the bar doesn't run out of music.
        try {
            knk_spotify_api("PUT", "me/player/repeat", null, [
                "state"     => "context",
                "device_id" => $device_id,
            ]);
        } catch (Throwable $e) { /* swallow */ }

        knk_spotify_api("PUT", "me/player/play", [
            "context_uri" => $playlist,
        ], [
            "device_id" => $device_id,
        ]);
        echo json_encode(["ok" => true]);
        exit;
    }

    if ($action === "pause") {
        if ($device_id === "") {
            echo json_encode(["ok" => false, "error" => "no_device"]);
            exit;
        }
        try {
            knk_spotify_api("PUT", "me/player/pause", null, [
                "device_id" => $device_id,
            ]);
        } catch (Throwable $e) {
            // Spotify returns 403 when nothing is playing — treat as success.
            $msg = $e->getMessage();
            if (stripos($msg, "403") === false && stripos($msg, "Restriction") === false) {
                throw $e;
            }
        }
        echo json_encode(["ok" => true]);
        exit;
    }

    if ($action === "volume") {
        $vol = (int)($_POST["value"] ?? -1);
        if ($vol < 0 || $vol > 100 || $device_id === "") {
            echo json_encode(["ok" => false, "error" => "bad_value"]);
            exit;
        }
        knk_spotify_api("PUT", "me/player/volume", null, [
            "volume_percent" => $vol,
            "device_id"      => $device_id,
        ]);
        echo json_encode(["ok" => true]);
        exit;
    }

    if ($action === "now_playing") {
        try {
            $j = knk_spotify_api("GET", "me/player");
        } catch (Throwable $e) {
            echo json_encode(["ok" => true, "is_playing" => false]);
            exit;
        }
        if (!is_array($j) || empty($j)) {
            echo json_encode(["ok" => true, "is_playing" => false]);
            exit;
        }
        $item = isset($j["item"]) ? $j["item"] : null;
        $artists = [];
        if (is_array($item) && isset($item["artists"]) && is_array($item["artists"])) {
            foreach ($item["artists"] as $a) {
                if (isset($a["name"])) $artists[] = (string)$a["name"];
            }
        }
        echo json_encode([
            "ok"         => true,
            "is_playing" => !empty($j["is_playing"]),
            "title"      => is_array($item) && isset($item["name"]) ? (string)$item["name"] : "",
            "artists"    => $artists,
            "device_id"  => isset($j["device"]["id"]) ? (string)$j["device"]["id"] : "",
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "unknown_action"]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "exception", "detail" => $e->getMessage()]);
}

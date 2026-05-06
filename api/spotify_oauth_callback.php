<?php
/*
 * KnK Inn — /api/spotify_oauth_callback.php
 *
 * Spotify sends the user back here after they click Authorize on
 * accounts.spotify.com. The querystring carries either:
 *
 *   ?code=...&state=...                  on success
 *   ?error=access_denied&state=...       on user cancel / failure
 *
 * Flow:
 *   1. Validate `state` against the value we stashed in
 *      spotify_oauth_start.php (CSRF / replay protection).
 *   2. Exchange `code` for a refresh_token via Spotify's token
 *      endpoint.
 *   3. Capture the F5 Spotify app's device_id so tv.php can target
 *      it. The device shows up in /me/player/devices once the
 *      Spotify app is signed in and online.
 *   4. Redirect back to /jukebox-admin.php with a flash message.
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/spotify.php";

knk_require_permission("jukebox");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$expected_state = isset($_SESSION["spotify_oauth_state"]) ? (string)$_SESSION["spotify_oauth_state"] : "";
$got_state      = isset($_GET["state"]) ? (string)$_GET["state"] : "";

// Single-use: clear immediately whether or not it matches.
unset($_SESSION["spotify_oauth_state"], $_SESSION["spotify_oauth_created_at"]);

function knk_spotify_oauth_redirect_back(string $kind, string $msg): void {
    $url = "/jukebox-admin.php?spotify_" . $kind . "=" . urlencode($msg) . "#spotify";
    header("Location: " . $url, true, 302);
    exit;
}

if ($expected_state === "" || $got_state === "" || !hash_equals($expected_state, $got_state)) {
    knk_spotify_oauth_redirect_back("error", "OAuth state mismatch — try again from Connect button.");
}

if (!empty($_GET["error"])) {
    knk_spotify_oauth_redirect_back("error", "Spotify denied authorisation: " . (string)$_GET["error"]);
}

$code = isset($_GET["code"]) ? (string)$_GET["code"] : "";
if ($code === "") {
    knk_spotify_oauth_redirect_back("error", "No authorisation code returned from Spotify.");
}

try {
    knk_spotify_exchange_code($code);
} catch (Throwable $e) {
    knk_spotify_oauth_redirect_back("error", "Token exchange failed: " . $e->getMessage());
}

// Now try to capture the device_id. The Spotify app on F5 must be
// running for this to populate; if not, admin can hit "Detect device"
// later from jukebox-admin.
$captured_msg = "Connected to Spotify.";
try {
    $devs = knk_spotify_devices();
    if (!empty($devs)) {
        // Prefer the active device if any; otherwise take the first.
        $pick = null;
        foreach ($devs as $d) {
            if (!empty($d["is_active"])) { $pick = $d; break; }
        }
        if ($pick === null) $pick = $devs[0];
        if (!empty($pick["id"])) {
            knk_spotify_config_update([
                "spotify_device_id"   => (string)$pick["id"],
                "spotify_device_name" => isset($pick["name"]) ? (string)$pick["name"] : "",
            ]);
            $captured_msg = "Connected. Device: " . (isset($pick["name"]) ? $pick["name"] : "unknown");
        }
    } else {
        $captured_msg = "Connected, but no Spotify devices found yet. Open the Spotify app on F5 and click 'Detect device' in admin.";
    }
} catch (Throwable $e) {
    $captured_msg = "Connected, but device discovery failed: " . $e->getMessage();
}

knk_spotify_oauth_redirect_back("ok", $captured_msg);

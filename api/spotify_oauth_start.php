<?php
/*
 * KnK Inn — /api/spotify_oauth_start.php
 *
 * Admin lands here from the "Connect Spotify" button on
 * jukebox-admin.php. We mint a one-shot state token, stash it in
 * the session, then redirect the browser to Spotify's authorize
 * endpoint. Spotify will redirect back to spotify_oauth_callback.php
 * with ?code=... + the same ?state=... we sent.
 *
 * Requires: jukebox permission (= bartender or higher), AND
 * client_id/secret already saved in jukebox-admin.
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/spotify.php";

knk_require_permission("jukebox");

if (!knk_spotify_has_app_creds()) {
    http_response_code(400);
    echo "Spotify client_id / secret not yet saved. Save them in Jukebox admin first.";
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// CSRF / replay protection. Verified in the callback.
$state = bin2hex(random_bytes(16));
$_SESSION["spotify_oauth_state"] = $state;
$_SESSION["spotify_oauth_created_at"] = time();

header("Location: " . knk_spotify_authorize_url($state), true, 302);
exit;

-- ============================================================
-- 030 — Jukebox Spotify ambient layer
--
-- Adds Spotify Connect support as an opt-in tier 2 fallback in the
-- jukebox: between guest YouTube requests (tier 1) and the radio
-- stream (tier 3). When the YouTube queue empties, devices launched
-- with `tv.php?spotify=1` will resume an ambient Spotify playlist
-- via the Spotify Connect Web API; if Spotify is unreachable they
-- fall through to the existing radio behaviour.
--
-- Design notes:
--   * One Spotify account, one Developer app — KnK is small enough
--     that two accounts (one per floor) isn't worth the admin cost.
--     The downside (consumer Spotify only allows one stream at a
--     time) is acceptable since the floors rarely overlap, and the
--     URL flag (`?spotify=1` only on F5) means ground floor never
--     even tries to grab the stream from F5.
--   * Client secret + refresh token are stored in plaintext. They
--     never reach the browser — server-side proxy only — so DB
--     access is the trust boundary.
--   * Device ID is captured during the OAuth flow (the Spotify app
--     running on F5 announces itself via /me/player/devices). Stored
--     once, reused forever (Spotify device IDs are stable per app
--     install).
--
-- All columns nullable / blank-defaulting so the migration is safe
-- on a live row, and so an admin can save partial config (e.g.
-- paste credentials before running OAuth) without blowing up.
--
-- Idempotent. MariaDB 10.6 supports `ADD COLUMN IF NOT EXISTS`.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

ALTER TABLE jukebox_config
    -- OAuth app credentials. Filled in via jukebox-admin once the
    -- developer.spotify.com app is registered.
    ADD COLUMN IF NOT EXISTS spotify_client_id VARCHAR(64) NOT NULL DEFAULT ''
        AFTER radio_url,
    ADD COLUMN IF NOT EXISTS spotify_client_secret VARCHAR(128) NOT NULL DEFAULT ''
        AFTER spotify_client_id,

    -- Long-lived refresh token from the OAuth flow. We exchange it
    -- for short-lived access tokens server-side every ~50 minutes.
    -- Empty until the venue runs the OAuth flow once on F5.
    ADD COLUMN IF NOT EXISTS spotify_refresh_token VARCHAR(255) NOT NULL DEFAULT ''
        AFTER spotify_client_secret,

    -- F5 Spotify app's Connect device ID. Captured automatically
    -- after OAuth by hitting /me/player/devices and finding the
    -- device whose name matches the venue machine.
    ADD COLUMN IF NOT EXISTS spotify_device_id VARCHAR(64) NOT NULL DEFAULT ''
        AFTER spotify_refresh_token,
    ADD COLUMN IF NOT EXISTS spotify_device_name VARCHAR(128) NOT NULL DEFAULT ''
        AFTER spotify_device_id,

    -- Spotify URI of the playlist that plays when YouTube queue is
    -- empty. e.g. spotify:playlist:37i9dQZF1DXcBWIGoYBM5M
    -- Empty = Spotify disabled even if `?spotify=1` is in the URL
    -- (graceful: tv.php silently falls to radio).
    ADD COLUMN IF NOT EXISTS spotify_default_playlist_uri VARCHAR(255) NOT NULL DEFAULT ''
        AFTER spotify_device_name,

    -- Volume for ambient Spotify playback. 0-100. We keep ambient
    -- slightly quieter than guest YouTube requests so the foreground
    -- track always feels louder.
    ADD COLUMN IF NOT EXISTS spotify_volume_pct TINYINT UNSIGNED NOT NULL DEFAULT 50
        AFTER spotify_default_playlist_uri,

    -- Last successful health-check / token-refresh timestamp.
    -- tv.php uses this for diagnostics only; no behavioural impact.
    ADD COLUMN IF NOT EXISTS spotify_last_ok_at DATETIME NULL
        AFTER spotify_volume_pct;

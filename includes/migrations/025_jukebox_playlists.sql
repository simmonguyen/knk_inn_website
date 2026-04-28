-- ============================================================
-- 025 — jukebox_playlists  (per-guest saved track lists)
--
-- Each guest can build a personal playlist while at the bar:
--   • from the "Recently played at KnK" wall
--   • from songs they've queued themselves
--   • via Add-to-playlist next to a YouTube search result
--
-- Storage is one row per (owner_email, video_id). owner_email
-- works for anon-…@anon.knkinn.com identities too — the claim
-- flow re-keys these rows to the real email when a guest claims
-- their profile (see knk_jukebox_playlist_rekey_email below in
-- includes/jukebox.php).
--
-- "Play all" plays the rows in shuffle order; tapping an
-- individual track plays from that point in saved order. The
-- merge logic (when two guests trigger Play-all simultaneously)
-- happens at queue insertion time — no schema work here.
--
-- Idempotent — safe to re-run.
-- ============================================================

SET NAMES utf8mb4;

-- ---------- jukebox_playlists ----------
-- One row per (owner, track). UNIQUE on (owner_email, video_id)
-- so Add-to-playlist is a no-op when a track's already saved.
CREATE TABLE IF NOT EXISTS jukebox_playlists (
    id                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    owner_email         VARCHAR(190)     NOT NULL,
    sort_order          INT              NOT NULL DEFAULT 0,
    -- YouTube ids are 11 chars; VARCHAR(20) gives a little room
    -- without bloating the index. Same shape as jukebox_queue.
    youtube_video_id    VARCHAR(20)      NOT NULL,
    -- Title / channel / thumbnail / duration are snapshotted at
    -- add-time so the playlist still renders if the YouTube row
    -- gets deleted upstream — same trade-off as jukebox_queue.
    youtube_title       VARCHAR(300)     NOT NULL DEFAULT '',
    youtube_channel     VARCHAR(200)     NOT NULL DEFAULT '',
    duration_seconds    INT UNSIGNED     NOT NULL DEFAULT 0,
    thumbnail_url       VARCHAR(400)     NOT NULL DEFAULT '',
    -- Where the guest added it from. Useful for the future
    -- "smart suggestions" panel ("you keep adding from search —
    -- want us to auto-add things you queue?"). Optional.
    source              ENUM('search','queue','recent','manual') NOT NULL DEFAULT 'manual',
    added_at            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_jpl_owner_video (owner_email, youtube_video_id),
    KEY idx_jpl_owner_sort (owner_email, sort_order),
    KEY idx_jpl_added (added_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- jukebox_playlist_state ----------
-- One row per owner. Tracks how their last "Play all" run is
-- progressing through the queue, so resuming after a network
-- blip / page reload picks up where it left off rather than
-- shuffling all over again.
CREATE TABLE IF NOT EXISTS jukebox_playlist_state (
    owner_email         VARCHAR(190)     NOT NULL,
    -- Comma-separated list of jukebox_playlists.id values the
    -- "Play all" shuffle pass laid down. Iterating left-to-right
    -- gives the intended order; cursor position lives in
    -- next_idx. Empty = no active play-all run.
    shuffle_order       TEXT             NULL,
    next_idx            INT UNSIGNED     NOT NULL DEFAULT 0,
    -- 'shuffle' = Play-all-random tap. 'sequence' = guest tapped
    -- a specific track, plays from there in saved order.
    mode                ENUM('shuffle','sequence') NOT NULL DEFAULT 'shuffle',
    started_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (owner_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- jukebox_queue.playlist_owner_email ----------
-- Tag each queued row with the playlist owner who triggered it
-- (NULL when a guest queued it manually via the song-request
-- form). Makes the "merge" alternation work — when alice's
-- play-all is alternating with bob's, the queue planner can see
-- which row belongs to which owner and balance the next pick.
ALTER TABLE jukebox_queue
    ADD COLUMN IF NOT EXISTS playlist_owner_email VARCHAR(190) NULL
    AFTER requester_email;

ALTER TABLE jukebox_queue
    ADD INDEX IF NOT EXISTS idx_jq_playlist_owner (playlist_owner_email);

-- ============================================================
-- KnK Inn — migration 021: jukebox lyric offsets (persisted)
-- ============================================================
-- The TV's lyric ticker has ‹/› nudge buttons (and [/] keys) that
-- shift the lyric timing for the playing song. Until now those
-- offsets only lived in the TV's localStorage — which meant if the
-- TV was reloaded, or if a different guest queued the same song
-- months later, the staff member who originally synced it had to
-- re-nudge.
--
-- This table stores the shift per YouTube video id so the
-- correction sticks for everyone, forever.
--
-- One row per video. updated_at exists so admin pages can show
-- the most-recently-synced songs and so we can prune stale
-- offsets if a song was re-encoded with different timing.
--
-- Idempotent — safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS jukebox_lyric_offsets (
    youtube_video_id  VARCHAR(20)      NOT NULL,
    -- YouTube IDs are 11 chars but VARCHAR(20) gives a little
    -- room without bloating the index.
    offset_sec        DECIMAL(6,2)     NOT NULL DEFAULT 0,
    -- Range allowed: roughly ±9999.99 seconds. We clamp at the
    -- application layer to ±30s, well inside that range.
    updated_by_user_id INT UNSIGNED    NULL,
    -- Set when the nudge came from the staff TV (signed-in
    -- super-admin). NULL when it was an anonymous TV nudge.
    updated_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (youtube_video_id),
    KEY idx_jbo_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

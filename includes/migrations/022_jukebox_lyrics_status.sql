-- ============================================================
-- 022 — jukebox_queue.lyrics_status
--
-- Records whether LRCLIB returned synced lyrics for a played song.
-- Powers the new "Lyrics?" column on /jukebox-admin so Ben can
-- spot songs that need a manual lyrics paste once that feature
-- ships. Set when the TV's lyric fetch resolves.
--
-- Values:
--   'unknown'  default — never tried (or song hasn't played yet)
--   'synced'   LRCLIB returned synced LRC lines
--   'plain'    LRCLIB returned plain text only (no timestamps)
--   'missing'  LRCLIB had nothing
--
-- Idempotent — safe to re-run.
-- ============================================================

ALTER TABLE jukebox_queue
    ADD COLUMN IF NOT EXISTS lyrics_status
        ENUM('unknown','synced','plain','missing')
        NOT NULL DEFAULT 'unknown'
        AFTER status;

ALTER TABLE jukebox_queue
    ADD INDEX IF NOT EXISTS idx_jq_lyrics_status (lyrics_status);

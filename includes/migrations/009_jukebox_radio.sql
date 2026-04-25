-- ============================================================
-- 009 — Jukebox radio fallback
--
-- Adds two columns to jukebox_config so that when the queue is
-- empty AND nothing is currently playing, the player TV can fall
-- back to a live MP3 radio stream — keeping the bar from going
-- silent between requests.
--
-- Default stream is Triple J (Australia, ABC youth network) over
-- HTTPS so it works on the live https://knkinn.com site without
-- mixed-content blocking.
--
-- Idempotent. MariaDB 10.6 supports `ADD COLUMN IF NOT EXISTS`.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

ALTER TABLE jukebox_config
    ADD COLUMN IF NOT EXISTS radio_enabled TINYINT(1) NOT NULL DEFAULT 1
        AFTER board_poll_seconds,
    ADD COLUMN IF NOT EXISTS radio_url VARCHAR(400) NOT NULL
        DEFAULT 'https://live-radio01.mediahubaustralia.com/6TJW/mp3/'
        AFTER radio_enabled;

-- Backfill existing rows in case they were inserted before the
-- DEFAULT was in place. (Idempotent: only sets when blank.)
UPDATE jukebox_config
   SET radio_url = 'https://live-radio01.mediahubaustralia.com/6TJW/mp3/'
 WHERE id = 1 AND (radio_url IS NULL OR radio_url = '');

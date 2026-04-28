-- ============================================================
-- 023 — darts_lobby.hostess_alerted_at
--
-- Adds a single nullable timestamp to darts_lobby that records
-- when (if ever) we emailed the hostess about this looker.
--
-- The cron job /cron/darts_lobby_alert.php scans rows once every
-- 5 minutes; any looker that's been waiting >10 minutes with no
-- incoming challenges and no hostess_alerted_at gets one email
-- and the column is stamped so we don't spam the bar inbox.
--
-- Idempotent — safe to re-run.
-- ============================================================

ALTER TABLE darts_lobby
    ADD COLUMN IF NOT EXISTS hostess_alerted_at DATETIME NULL
    AFTER expires_at;

ALTER TABLE darts_lobby
    ADD INDEX IF NOT EXISTS idx_dl_alerted (hostess_alerted_at);

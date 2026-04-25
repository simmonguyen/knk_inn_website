-- ============================================================
-- KnK Inn — migration 016: user language preference
-- ============================================================
-- Adds a per-user default language for the staff area UI.
-- 'en' for Ben + reception, 'vi' for Simmo + Vietnamese-speaking
-- staff. Anyone can override on the fly with the EN/VI toggle in
-- the staff nav (saved on the session, not the user row), so
-- this is just the language they land on at login.
--
-- Idempotent: MariaDB supports ADD COLUMN IF NOT EXISTS, which
-- makes re-running this migration a no-op.
-- ============================================================

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS `language` ENUM('en','vi') NOT NULL DEFAULT 'en' AFTER active;

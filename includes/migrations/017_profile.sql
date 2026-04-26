-- ============================================================
-- KnK Inn — migration 017: guest profile page (Phase 1)
-- ============================================================
-- The profile page (/profile.php and /bar.php?tab=profile) lets
-- a guest see their own activity (drinks orders, song requests,
-- darts games), edit a display name, and optionally claim with
-- a real email so the same history follows them across devices.
--
-- All activity is keyed off the email column on each table.
-- Orders already have guest_email. Bookings already have
-- guest_email. The two outliers — jukebox_queue and darts_players
-- — only knew the guest by IP/cookie, so we add an email column
-- to each so they can join the same identity graph.
--
-- The new guests columns are NULL until set:
--   display_name             — the friendly name shown on the
--                              profile header. Defaults at
--                              render-time to "Guest <token>"
--                              for anon-* emails.
--   claim_token              — one-shot magic-link token written
--                              when an anon guest asks to claim
--                              with a real email. Cleared on use.
--   claim_token_expires_at   — 30-min TTL. Past this we treat
--                              the token as invalid.
--   claim_pending_email      — the real email the token will
--                              promote the anon profile to,
--                              once the guest clicks the link
--                              from their inbox.
--
-- Idempotent: every column add uses IF NOT EXISTS, every index
-- via ADD INDEX IF NOT EXISTS. Re-running this migration is
-- a safe no-op.
-- ============================================================

-- ---------- guests: profile + claim columns ----------
ALTER TABLE guests
    ADD COLUMN IF NOT EXISTS `display_name`           VARCHAR(60)  NULL          AFTER name,
    ADD COLUMN IF NOT EXISTS `claim_token`            CHAR(40)     NULL          AFTER notes,
    ADD COLUMN IF NOT EXISTS `claim_token_expires_at` DATETIME     NULL          AFTER claim_token,
    ADD COLUMN IF NOT EXISTS `claim_pending_email`    VARCHAR(190) NULL          AFTER claim_token_expires_at;

-- Lookups for token validation. UNIQUE because a token always
-- maps to at most one guest row.
ALTER TABLE guests
    ADD UNIQUE INDEX IF NOT EXISTS uk_guests_claim_token (claim_token);

-- ---------- jukebox_queue: requester_email ----------
ALTER TABLE jukebox_queue
    ADD COLUMN IF NOT EXISTS `requester_email` VARCHAR(190) NOT NULL DEFAULT '' AFTER requester_ip;

ALTER TABLE jukebox_queue
    ADD INDEX IF NOT EXISTS idx_jukebox_queue_email (requester_email, submitted_at);

-- ---------- darts_players: guest_email ----------
ALTER TABLE darts_players
    ADD COLUMN IF NOT EXISTS `guest_email` VARCHAR(190) NOT NULL DEFAULT '' AFTER session_token;

ALTER TABLE darts_players
    ADD INDEX IF NOT EXISTS idx_darts_players_email (guest_email);

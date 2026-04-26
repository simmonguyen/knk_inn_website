-- ============================================================
-- KnK Inn — migration 019: social share-rally taps
-- ============================================================
-- New table backing the /share.php "Crash the Market for Cheap
-- Drinks" experience.
--
-- Mechanic recap (so the schema makes sense):
--   - Guest taps one of three platform buttons on /share.php:
--       facebook       → tier 1 crash (small)
--       google         → tier 2 crash (medium)   -- review
--       tripadvisor    → tier 3 crash (large)    -- review
--   - Each tap is recorded as a row in social_share_taps.
--   - Within a 10-min rolling window, additional unique-platform
--     taps escalate the next crash by tier.
--   - 24h cooldown per (guest_email, platform) so a single
--     guest can't farm the same platform repeatedly for cheap
--     drinks.
--
-- Identity is the same lower-cased email used everywhere else
-- in the profile system (anon-…@anon.knkinn.com counts).
--
-- Idempotent — safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS social_share_taps (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    guest_email   VARCHAR(190)    NOT NULL,
    platform      VARCHAR(24)     NOT NULL,
    -- Allow free-form for forward-compat (zalo / whatsapp / tiktok
    -- could be added later via settings without a schema change).
    -- The application layer enforces the current allow-list.
    ip            VARCHAR(45)     NULL,
    user_agent    VARCHAR(255)    NULL,
    tier          TINYINT UNSIGNED NOT NULL DEFAULT 1,
    -- Tier this tap fired AT. Useful in audit so you can see
    -- whether the rally was warming up or peaking when the crash
    -- went off. 1..N.
    drop_bp       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    -- The basis-point drop this tap actually applied (e.g. 1000
    -- = 10%). Snapshotted in case the config changes later.
    duration_min  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    -- Same — the duration in minutes that was applied.
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_share_email_plat_ts (guest_email, platform, created_at),
    KEY idx_share_email_ts      (guest_email, created_at),
    KEY idx_share_ts            (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

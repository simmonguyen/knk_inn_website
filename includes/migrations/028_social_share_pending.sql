-- ============================================================
-- 028 — Social share pending-crash queue
--
-- Before: a guest tapping a share button on /share.php fired the
-- market crash immediately, then they walked off to post — by the
-- time the post was actually live, the crash had often already
-- unwound. Felt disconnected.
--
-- After: the tap enqueues a row here with a per-platform delay
-- (~45s Facebook, ~90s Google, ~150s Tripadvisor — roughly the
-- time a real post takes). The existing /cron/market_tick.php
-- drains the queue every 5 minutes; due rows fire the crash and
-- get marked. Now the crash *appears* to fire because of the post.
--
-- Idempotent — safe to re-run.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS social_share_pending_crashes (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    guest_email     VARCHAR(190)     NOT NULL,
    platform        VARCHAR(40)      NOT NULL,
    tier            TINYINT UNSIGNED NOT NULL,
    drop_pct        TINYINT UNSIGNED NOT NULL,
    duration_min    SMALLINT UNSIGNED NOT NULL,
    due_at          DATETIME         NOT NULL,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fired_at        DATETIME         NULL,                    -- when the drainer actually applied the crash
    fired_items     VARCHAR(255)     NULL,                    -- comma-list of item codes that crashed
    PRIMARY KEY (id),
    KEY idx_sspc_due (due_at, fired_at),
    KEY idx_sspc_email (guest_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

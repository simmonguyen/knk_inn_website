-- ============================================================
-- 004 — Beer Stock Market (Phase 2)
--
-- Live, market-priced drinks on a big-board TV at /market.php
-- and on /order.php. The menu itself still lives in menu_drinks
-- (Phase 1, migration 003) — base prices come from there. This
-- migration only adds market-specific tables, all prefixed
-- market_ so a clean rollback is a matter of dropping those
-- three tables and reverting the Phase 2 PHP files.
--
-- Tables:
--   market_config   — one row, all global knobs (bands, demand,
--                     crash cadence, caps, eligibility, cron,
--                     kill switch).
--   market_pinned   — up to 2 items pinned onto the Big Board
--                     regardless of order volume (slot='beer' or
--                     slot='owner'). NULL item_code = slot empty.
--   market_events   — append-only log of every price change.
--                     Current price for an item = the latest
--                     event row. Also drives the sparkline.
--
-- Why no market_prices table? Fewer moving parts. Current price
-- is a SELECT ... ORDER BY created_at DESC LIMIT 1 per item,
-- which is cheap with the index on (item_code, created_at).
--
-- Why no price_lock table? The order.php page stamps a unix ts
-- and per-item snapshot prices into hidden form fields. On POST
-- we recompute; if stamp is older than market_config.price_lock_seconds
-- OR any item's price moved, we bounce to a "prices updated"
-- confirmation. No DB locks required — PHP-friendly on Matbao.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- ---------- market_config ----------
-- Single-row table. id is always 1. Admin page reads/writes
-- these fields; cron tick + engine read them. Reset-to-defaults
-- rewrites every column on this row with the recommended values.
CREATE TABLE IF NOT EXISTS market_config (
    id                        TINYINT UNSIGNED NOT NULL,

    -- Kill switch. 0 = market disabled, order.php + Big Board
    -- fall back to plain menu prices, cron tick is a no-op.
    enabled                   TINYINT(1)       NOT NULL DEFAULT 0,

    -- Time bands. Times are 24-hour HH:MM strings in +07:00.
    -- Multipliers are stored as INT basis-points (100 = 1.00x)
    -- so we stay out of float territory.
    happy_start               VARCHAR(5)       NOT NULL DEFAULT '16:00',
    happy_end                 VARCHAR(5)       NOT NULL DEFAULT '19:00',
    happy_mult_bp             SMALLINT UNSIGNED NOT NULL DEFAULT 85,

    peak_start                VARCHAR(5)       NOT NULL DEFAULT '20:00',
    peak_end                  VARCHAR(5)       NOT NULL DEFAULT '23:00',
    peak_mult_bp              SMALLINT UNSIGNED NOT NULL DEFAULT 110,

    default_mult_bp           SMALLINT UNSIGNED NOT NULL DEFAULT 100,

    -- Demand engine.
    -- Rolling window of minutes used to compute "orders per hour"
    -- for each eligible item, compared against baseline_orders_per_hour.
    -- Result is clamped to [demand_min_bp, demand_max_bp].
    demand_window_minutes     SMALLINT UNSIGNED NOT NULL DEFAULT 45,
    baseline_orders_per_hour  SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    demand_min_bp             SMALLINT UNSIGNED NOT NULL DEFAULT 90,
    demand_max_bp             SMALLINT UNSIGNED NOT NULL DEFAULT 130,

    -- Auto-crash cadence. A tick rolls the dice: if no crash has
    -- happened in the last crash_cadence_min minutes (uniformly
    -- sampled min..max), one fires on up to crash_items_max
    -- eligible items (skipping any with an active crash or
    -- per-item cooldown).
    crash_cadence_min         SMALLINT UNSIGNED NOT NULL DEFAULT 45,
    crash_cadence_max         SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    crash_item_cooldown_min   SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    crash_items_max           TINYINT  UNSIGNED NOT NULL DEFAULT 2,

    -- Crash magnitude + duration. Drop in basis-points (15 = -15%)
    -- and duration in minutes. Engine clamps final price to caps.
    crash_drop_min_bp         SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    crash_drop_max_bp         SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    crash_duration_min_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 2,
    crash_duration_max_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5,

    -- Hard caps. Final price is clamped to [floor_bp, ceiling_bp]
    -- of the menu base price. 70 = 0.70x, 200 = 2.00x.
    cap_floor_bp              SMALLINT UNSIGNED NOT NULL DEFAULT 70,
    cap_ceiling_bp            SMALLINT UNSIGNED NOT NULL DEFAULT 200,

    -- Big Board eligibility: top-N drinks by order volume in the
    -- last `window_days` days, excluding drinks with fewer than
    -- `min_orders` orders in that window. Pinned slots are always
    -- included and don't count against top_n.
    eligibility_window_days   SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    eligibility_top_n         SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    eligibility_min_orders    SMALLINT UNSIGNED NOT NULL DEFAULT 5,

    -- Order page lock: if the page was rendered more than this
    -- many seconds before submit, we bounce to a re-confirm.
    price_lock_seconds        SMALLINT UNSIGNED NOT NULL DEFAULT 15,

    -- Fair-play guards against gaming crashes.
    fairplay_max_market_items SMALLINT UNSIGNED NOT NULL DEFAULT 2,
    fairplay_cooldown_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 120,

    -- Big Board polling. JS on /market.php re-fetches
    -- /api/market_state.php this often.
    board_poll_seconds        SMALLINT UNSIGNED NOT NULL DEFAULT 5,

    -- Bookkeeping.
    updated_by                INT UNSIGNED     NULL,
    updated_at                DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at                DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    CONSTRAINT fk_market_config_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the single row with defaults. INSERT IGNORE so re-running
-- the migration doesn't clobber Simmo's tuning.
INSERT IGNORE INTO market_config (id) VALUES (1);

-- ---------- market_pinned ----------
-- Two fixed slots: 'beer' (a pinned beer) and 'owner'
-- (Simmo's pick of the night). Each row may have NULL item_code
-- meaning the slot is currently empty.
CREATE TABLE IF NOT EXISTS market_pinned (
    slot        ENUM('beer','owner') NOT NULL,
    item_code   VARCHAR(80)         NULL,
    updated_by  INT UNSIGNED        NULL,
    updated_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (slot),
    CONSTRAINT fk_market_pinned_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed both slots empty. Simmo assigns them in /market-admin.php.
INSERT IGNORE INTO market_pinned (slot) VALUES ('beer'), ('owner');

-- ---------- market_events ----------
-- Append-only price history. The "current price" of an item is
-- the newest event row with that item_code. Rows also act as the
-- audit trail: who fired this crash, when, why.
--
-- source values:
--   'band'        — scheduled cron tick, time-of-day band shift
--   'demand'      — scheduled cron tick, demand multiplier change
--   'crash_auto'  — cron-fired crash
--   'crash_staff' — one-tap "Social Crash" button in admin
--   'manual'      — force-price override via admin UI
--   'bootstrap'   — first event for a newly-eligible item (1.0x)
--   'reset'       — market-wide reset back to base prices
CREATE TABLE IF NOT EXISTS market_events (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    item_code       VARCHAR(80)      NOT NULL,
    old_price_vnd   BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    new_price_vnd   BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    base_price_vnd  BIGINT UNSIGNED  NOT NULL DEFAULT 0,  -- snapshot of menu_drinks.price_vnd at event time
    multiplier_bp   SMALLINT UNSIGNED NOT NULL DEFAULT 100,  -- combined final multiplier
    source          ENUM('band','demand','crash_auto','crash_staff','manual','bootstrap','reset') NOT NULL,
    actor_user_id   INT UNSIGNED     NULL,

    -- If set, the tick will not overwrite this price until
    -- UNIX_TIMESTAMP(NOW()) > locked_until. Used by manual
    -- force-price and by active crashes.
    locked_until    INT UNSIGNED     NULL,

    -- If set, this row represents an active crash that should
    -- auto-unwind at this unix ts (tick rewrites back to the
    -- non-crash price).
    crash_until     INT UNSIGNED     NULL,

    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_market_events_item_time (item_code, created_at),
    KEY idx_market_events_crash (crash_until),
    CONSTRAINT fk_market_events_user FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

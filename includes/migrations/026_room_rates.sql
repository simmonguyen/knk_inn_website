-- ============================================================
-- 026 — Room rates (per-room, per-date pricing)
--
-- KnK Inn now has a hotel licence (granted 2026-04-23) and is
-- listing on Airbnb / Booking.com / Tripadvisor. Those platforms
-- want per-listing nightly rates, not the flat-by-room-type
-- pricing that bookings.price_vnd_per_night currently captures.
--
-- This migration sets up:
--
--   1. rooms              — registry of physical rooms (7 rows
--                            seeded). Each has a slug like
--                            'vip-3a' that the booking flow can
--                            reference.
--
--   2. room_rate_seasons  — named price tiers ('low','shoulder',
--                            'high','peak') with admin-only
--                            colours for calendar UI rendering.
--
--   3. room_rates         — one row per (room_slug, stay_date).
--                            Sparse by design — only dates with
--                            an explicit rate get a row, and the
--                            booking flow falls back to the room's
--                            default rate when a date is missing.
--
--   4. rooms.default_vnd_per_night for the fallback.
--
-- The bookings table itself is left alone. The booking flow will
-- compute total_vnd by summing per-night rates from this table
-- and store the snapshot in bookings.total_vnd at confirm time —
-- that way historic bookings still reflect the price they were
-- quoted, even if rates change later.
--
-- Idempotent — safe to re-run.
-- ============================================================

SET NAMES utf8mb4;

-- ---------- rooms (physical room registry) ----------
-- Slug pattern matches the per-room photo subpages:
--   standard-nowindow-1
--   standard-balcony-2 / -3 / -4   (one per floor)
--   vip-2 / -3 / -4                (one per floor)
--
-- room_type matches bookings.room values so existing data joins.
-- floor is 1..4. is_active=0 takes a room out of the booking
-- engine without deleting historical rows.
CREATE TABLE IF NOT EXISTS rooms (
    id                       INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    slug                     VARCHAR(40)      NOT NULL,
    room_type                VARCHAR(40)      NOT NULL,   -- standard-nowindow / standard-balcony / vip
    display_name             VARCHAR(120)     NOT NULL,
    floor                    TINYINT UNSIGNED NOT NULL DEFAULT 1,
    sort_order               SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    -- The fall-back nightly rate when room_rates has no row for
    -- a date. Keep this in sync with the OTA "rack rate" so
    -- one missed calendar day doesn't undercut the channel.
    default_vnd_per_night    BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    is_active                TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at               DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_rooms_slug (slug),
    KEY idx_rooms_type (room_type),
    KEY idx_rooms_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- room_rate_seasons (named price tiers) ----------
-- Tier names line up with hotel-industry convention (low,
-- shoulder, high, peak). is_default marks the season we drop
-- into when the calendar has no entry for a date.
CREATE TABLE IF NOT EXISTS room_rate_seasons (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(30)      NOT NULL,
    display_name    VARCHAR(80)      NOT NULL,
    color_hex       VARCHAR(9)       NOT NULL DEFAULT '#888888',
    sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_default      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_rrs_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- room_rates (per-room, per-date) ----------
-- Sparse — only dates with an explicit override get a row.
-- season_slug is informational (lets the admin calendar colour-
-- code dates by tier) but the rate engine reads vnd_amount
-- directly. NULL season_slug = "manually overridden, no tier".
CREATE TABLE IF NOT EXISTS room_rates (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    room_slug       VARCHAR(40)      NOT NULL,
    stay_date       DATE             NOT NULL,
    vnd_amount      BIGINT UNSIGNED  NOT NULL,
    season_slug     VARCHAR(30)      NULL,
    note            VARCHAR(160)     NULL,           -- e.g. 'Tet eve' / 'Christmas'
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_rr_room_date (room_slug, stay_date),
    KEY idx_rr_date (stay_date),
    KEY idx_rr_season (season_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Seed seasons ----------
-- Default tier names + warm-to-hot colour ramp. Sales Admin can
-- rename these freely from /room-rates.php.
INSERT IGNORE INTO room_rate_seasons (slug, display_name, color_hex, sort_order, is_default) VALUES
    ('low',      'Low season',      '#7aa56a', 1, 1),
    ('shoulder', 'Shoulder',        '#c9aa71', 2, 0),
    ('high',     'High season',     '#d97a5a', 3, 0),
    ('peak',     'Peak (Tet/NYE)',  '#a9437d', 4, 0);

-- ---------- Seed rooms ----------
-- 1 standard-nowindow on F1, 3 standard-balcony on F2/3/4,
-- 3 VIP (with tub) on F2/3/4. Default rates are placeholder —
-- Simmo / sales admin will tune these once the listings go live.
INSERT IGNORE INTO rooms
    (slug, room_type, display_name, floor, sort_order, default_vnd_per_night) VALUES
    ('standard-nowindow-1', 'standard-nowindow', 'Standard (no window) — F1', 1, 10,  650000),
    ('standard-balcony-2',  'standard-balcony',  'Standard with balcony — F2', 2, 20,  850000),
    ('standard-balcony-3',  'standard-balcony',  'Standard with balcony — F3', 3, 21,  850000),
    ('standard-balcony-4',  'standard-balcony',  'Standard with balcony — F4', 4, 22,  850000),
    ('vip-2',               'vip',               'VIP with tub — F2',          2, 30, 1250000),
    ('vip-3',               'vip',               'VIP with tub — F3',          3, 31, 1250000),
    ('vip-4',               'vip',               'VIP with tub — F4',          4, 32, 1250000);

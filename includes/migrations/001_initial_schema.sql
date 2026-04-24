-- ============================================================
-- KnK Inn — V2 initial schema (MariaDB / MySQL)
-- ============================================================
-- Designed for the V2 build: real staff accounts + roles,
-- unified guest profiles, linked bills (room + drinks),
-- photo management, sales dashboards, Super Admin toggles.
--
-- Safe to re-run: all CREATE TABLE and seed INSERTs are
-- idempotent (IF NOT EXISTS / INSERT IGNORE).
--
-- Money is stored in VND (no cents — Vietnamese dong has
-- no fractional unit), matching the existing JSON stores.
--
-- Conventions:
--   - slug columns hold the legacy-style short IDs ('b_abc', 'o_abc')
--     that are already used in URLs, emails, and confirm links.
--   - created_at / updated_at are always DATETIME in Matbao's
--     server time (set by 'timezone' application-side if needed).
--   - ENUMs are used for closed sets (role, status) — widen with
--     ALTER TABLE ... MODIFY when new values are needed.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';  -- Saigon; has no effect on CURRENT_TIMESTAMP from client, informational only.

-- ---------- users (staff accounts) ----------
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    email          VARCHAR(190)     NOT NULL,
    name           VARCHAR(120)     NOT NULL,
    password_hash  VARCHAR(255)     NOT NULL,            -- bcrypt / password_hash()
    role           ENUM('super_admin','owner','reception','bartender') NOT NULL,
    active         TINYINT(1)       NOT NULL DEFAULT 1,
    created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at  DATETIME         NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- user_sessions (persistent login, esp. bartender "stay logged in") ----------
CREATE TABLE IF NOT EXISTS user_sessions (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED     NOT NULL,
    token_hash      CHAR(64)         NOT NULL,          -- sha256 of a 32-byte random token
    stay_logged_in  TINYINT(1)       NOT NULL DEFAULT 0,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME         NOT NULL,
    last_seen_at    DATETIME         NULL,
    user_agent      VARCHAR(255)     NULL,
    ip_address      VARCHAR(45)      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_us_token (token_hash),
    KEY idx_us_user (user_id),
    CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- guests (unified profile, keyed by email) ----------
CREATE TABLE IF NOT EXISTS guests (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    email           VARCHAR(190)     NOT NULL,
    name            VARCHAR(120)     NULL,
    phone           VARCHAR(40)      NULL,
    first_seen_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at    DATETIME         NULL,
    -- Cached / derived counters. Recomputed by a refresh job,
    -- also updated opportunistically on write.
    visits_count    INT UNSIGNED     NOT NULL DEFAULT 0,
    orders_count    INT UNSIGNED     NOT NULL DEFAULT 0,
    bookings_count  INT UNSIGNED     NOT NULL DEFAULT 0,
    total_vnd       BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    favourite_item  VARCHAR(120)     NULL,   -- cached
    favourite_day   VARCHAR(10)      NULL,   -- 'Mon','Tue',...
    notes           TEXT             NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_guests_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- bookings (rooms) ----------
CREATE TABLE IF NOT EXISTS bookings (
    id                   INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    slug                 VARCHAR(32)      NOT NULL,     -- 'b_abc123' (legacy-compatible)
    guest_id             INT UNSIGNED     NULL,
    room                 VARCHAR(40)      NOT NULL,     -- 'standard-nowindow','standard-balcony','vip'
    checkin              DATE             NOT NULL,
    checkout             DATE             NOT NULL,
    nights               SMALLINT UNSIGNED NOT NULL,
    guest_name           VARCHAR(120)     NULL,
    guest_email          VARCHAR(190)     NULL,
    guest_phone          VARCHAR(40)      NULL,
    message              TEXT             NULL,
    status               ENUM('pending','confirmed','declined','expired','cancelled','completed')
                                          NOT NULL DEFAULT 'pending',
    source               VARCHAR(40)      NOT NULL DEFAULT 'website',  -- website / airbnb / booking_com / walk_in
    external_ref         VARCHAR(120)     NULL,         -- OTA booking id (Airbnb, etc.)
    price_vnd_per_night  BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    total_vnd            BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    token                VARCHAR(80)      NULL,         -- confirm/decline token (legacy email flow)
    created_at           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_bookings_slug (slug),
    KEY idx_bookings_guest (guest_id),
    KEY idx_bookings_dates (checkin, checkout),
    KEY idx_bookings_status (status),
    CONSTRAINT fk_bookings_guest FOREIGN KEY (guest_id) REFERENCES guests(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- orders (drinks) ----------
CREATE TABLE IF NOT EXISTS orders (
    id                 INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    slug               VARCHAR(32)      NOT NULL,    -- 'o_abc123'
    guest_id           INT UNSIGNED     NULL,
    booking_id         INT UNSIGNED     NULL,        -- links this drinks order to a room → combined bill
    guest_email        VARCHAR(190)     NULL,
    location           VARCHAR(40)      NOT NULL,    -- 'rooftop','floor-5','floor-1','room'
    room_number        VARCHAR(16)      NULL,
    subtotal_vnd       BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    vat_vnd            BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    total_vnd          BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    notes              TEXT             NULL,
    status             ENUM('pending','received','paid','cancelled') NOT NULL DEFAULT 'pending',
    bartender_user_id  INT UNSIGNED     NULL,
    token              VARCHAR(80)      NULL,
    created_at         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    received_at        DATETIME         NULL,
    paid_at            DATETIME         NULL,
    updated_at         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_orders_slug (slug),
    KEY idx_orders_guest (guest_id),
    KEY idx_orders_booking (booking_id),
    KEY idx_orders_created (created_at),
    KEY idx_orders_status (status),
    CONSTRAINT fk_orders_guest     FOREIGN KEY (guest_id)          REFERENCES guests(id)   ON DELETE SET NULL,
    CONSTRAINT fk_orders_booking   FOREIGN KEY (booking_id)        REFERENCES bookings(id) ON DELETE SET NULL,
    CONSTRAINT fk_orders_bartender FOREIGN KEY (bartender_user_id) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- order_items (line items) ----------
CREATE TABLE IF NOT EXISTS order_items (
    id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    order_id     INT UNSIGNED     NOT NULL,
    item_code    VARCHAR(80)      NULL,            -- 'beer.tiger' (stable ref into drinks.html)
    item_name    VARCHAR(120)     NOT NULL,        -- snapshot at order time
    quantity     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    unit_vnd     BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    line_vnd     BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    notes        VARCHAR(255)     NULL,
    PRIMARY KEY (id),
    KEY idx_oi_order (order_id),
    CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- photo_slots (homepage photo manifest) ----------
CREATE TABLE IF NOT EXISTS photo_slots (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    section     VARCHAR(40)      NOT NULL,
    slot_index  TINYINT UNSIGNED NOT NULL,     -- 1-based position within section
    label       VARCHAR(80)      NULL,         -- e.g. 'Coffee', 'Wine' for Four Things
    filename    VARCHAR(255)     NOT NULL,
    alt_text    VARCHAR(255)     NULL,
    caption     VARCHAR(255)     NULL,
    updated_by  INT UNSIGNED     NULL,
    updated_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_photo_slot (section, slot_index),
    CONSTRAINT fk_ps_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- settings (key/value, Super Admin toggles) ----------
CREATE TABLE IF NOT EXISTS settings (
    `key`       VARCHAR(80)      NOT NULL,
    value       TEXT             NULL,
    updated_by  INT UNSIGNED     NULL,
    updated_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`),
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- audit_log (for Super Admin oversight) ----------
CREATE TABLE IF NOT EXISTS audit_log (
    id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED     NULL,
    action      VARCHAR(60)      NOT NULL,  -- 'login','user.create','photo.update','order.paid',...
    entity      VARCHAR(40)      NULL,      -- 'user','photo_slot','booking','order'
    entity_id   VARCHAR(80)      NULL,
    details     TEXT             NULL,      -- JSON blob
    ip_address  VARCHAR(45)      NULL,
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_action (action),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Seed data
-- ============================================================

-- Default Super Admin toggles. Marketing Reminders and Owner
-- order notifications default to ON — Simmo can turn them off.
INSERT IGNORE INTO settings (`key`, value) VALUES
    ('marketing_reminders_enabled',       '1'),
    ('marketing_reminder_days_before',    '7'),
    ('owner_order_notifications_enabled', '1'),
    ('owner_notification_email',          ''),   -- falls back to owner user's email if blank
    ('schema_version',                    '1');

-- Seed the homepage photo slots (22 slots across 7 sections).
-- Filenames here are the current on-disk photos; Phase 2 photo
-- admin UI will let Simmo replace them without touching HTML.
INSERT IGNORE INTO photo_slots (section, slot_index, label, filename) VALUES
    ('home_carousel',      1, NULL,          'ex_06.jpg'),
    ('home_carousel',      2, NULL,          'nw_26.jpg'),
    ('home_carousel',      3, NULL,          'nw_51.jpg'),
    ('home_carousel',      4, NULL,          'nw_62.jpg'),
    ('piece_of_home',      1, 'Top',         'nw_26.jpg'),
    ('piece_of_home',      2, 'Bottom',      'nw_51.jpg'),
    ('four_things',        1, 'Coffee',      'nw_69.jpg'),
    ('four_things',        2, 'Wine',        'nw_56.jpg'),
    ('four_things',        3, 'Sports Bar',  're_15.jpg'),
    ('four_things',        4, 'Rooms',       'rm_23.jpg'),
    ('up_above',           1, NULL,          'rm_12.jpg'),
    ('drinks',             1, NULL,          'nw_69.jpg'),
    ('drinks',             2, NULL,          'nw_56.jpg'),
    ('sports_look_around', 1, NULL,          'ex_06.jpg'),
    ('sports_look_around', 2, NULL,          'ex_07.jpg'),
    ('sports_look_around', 3, NULL,          'nw_03.jpg'),
    ('sports_look_around', 4, NULL,          'nw_12.jpg'),
    ('sports_look_around', 5, NULL,          'nw_25.jpg'),
    ('sports_look_around', 6, NULL,          'nw_30.jpg'),
    ('sports_look_around', 7, NULL,          'nw_52.jpg'),
    ('sports_look_around', 8, NULL,          'nw_69.jpg'),
    ('find_us',            1, NULL,          'nw_05.jpg');

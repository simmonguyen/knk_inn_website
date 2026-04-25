-- ============================================================
-- 008 — Jukebox (Phase 3 / proof-of-concept)
--
-- Bar guests scan a QR code, type Artist + Song Title, server
-- searches YouTube via the Data API v3, picks the top match and
-- queues it. The bar laptop opens /jukebox-player.php on the TV
-- (full-screen Chrome) and plays each video in turn via the
-- YouTube IFrame Player API.
--
-- Tables (all prefixed jukebox_ for a clean rollback):
--   jukebox_config     — single-row knobs (kill switch, caps,
--                        cooldowns, auto-approve toggle).
--   jukebox_queue      — every request ever made + its lifecycle
--                        status. The "now playing" row is whichever
--                        has status='playing'.
--   jukebox_blocklist  — videoIds and keywords staff have banned
--                        (e.g. that one Wagon Wheel that gets
--                        requested every Friday).
--
-- Idempotent: re-running the migration is a no-op once the rows
-- and tables exist.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- ---------- jukebox_config ----------
-- Single-row table. id is always 1. Admin page reads/writes
-- these fields.
CREATE TABLE IF NOT EXISTS jukebox_config (
    id                          TINYINT UNSIGNED NOT NULL,

    -- Kill switch. 0 = jukebox closed, guest page shows a quiet
    -- "closed" message and the player TV shows the closed card.
    enabled                     TINYINT(1)       NOT NULL DEFAULT 0,

    -- Auto-approve mode.
    --   1 = guest requests go straight into the queue (chill pub).
    --   0 = requests land as 'pending' and a staffer must approve
    --       from /jukebox-admin.php (or any phone open to it).
    auto_approve                TINYINT(1)       NOT NULL DEFAULT 1,

    -- Hard cap on a single video's length. Stops someone queueing
    -- a 1-hour DJ mix or a 2-hour podcast. Default 7 minutes.
    max_duration_seconds        INT UNSIGNED     NOT NULL DEFAULT 420,

    -- Per-IP cooldown between requests so one phone can't carpet
    -- the queue. Default 5 minutes.
    per_ip_cooldown_seconds     INT UNSIGNED     NOT NULL DEFAULT 300,

    -- Optionally force guests to enter their table number.
    require_table_no            TINYINT(1)       NOT NULL DEFAULT 0,

    -- Don't accept new requests if there are already this many
    -- songs queued ahead.
    max_queue_length            SMALLINT UNSIGNED NOT NULL DEFAULT 50,

    -- How often the player TV / admin page polls /api/jukebox_state.php.
    board_poll_seconds          SMALLINT UNSIGNED NOT NULL DEFAULT 5,

    -- Bookkeeping.
    updated_by                  INT UNSIGNED     NULL,
    updated_at                  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at                  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    CONSTRAINT fk_jukebox_config_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO jukebox_config (id) VALUES (1);

-- ---------- jukebox_queue ----------
-- Every request ever made. The lifecycle is encoded in `status`:
--   pending   — auto_approve=0 and a staffer hasn't approved yet
--   queued    — waiting to play
--   playing   — currently on the TV (one row at a time)
--   played    — finished playing naturally
--   skipped   — staff or end-of-night skip
--   rejected  — staff rejected (auto_approve=0 mode)
CREATE TABLE IF NOT EXISTS jukebox_queue (
    id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,

    -- What the guest typed.
    artist_text         VARCHAR(200)     NOT NULL DEFAULT '',
    title_text          VARCHAR(200)     NOT NULL DEFAULT '',

    -- What we resolved on YouTube (filled at submit time).
    youtube_video_id    VARCHAR(20)      NOT NULL,
    youtube_title       VARCHAR(300)     NOT NULL DEFAULT '',
    youtube_channel     VARCHAR(200)     NOT NULL DEFAULT '',
    duration_seconds    INT UNSIGNED     NOT NULL DEFAULT 0,
    thumbnail_url       VARCHAR(400)     NOT NULL DEFAULT '',

    -- Optional, for accountability and "From: Tom (T7)" overlays.
    requester_name      VARCHAR(80)      NOT NULL DEFAULT '',
    table_no            VARCHAR(20)      NOT NULL DEFAULT '',
    requester_ip        VARCHAR(45)      NOT NULL DEFAULT '',

    status              ENUM('pending','queued','playing','played','skipped','rejected') NOT NULL DEFAULT 'queued',
    rejection_reason    VARCHAR(200)     NOT NULL DEFAULT '',

    -- Whichever staffer skipped/approved/rejected (if any).
    actor_user_id       INT UNSIGNED     NULL,

    submitted_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    played_at           DATETIME         NULL,

    PRIMARY KEY (id),
    KEY idx_jukebox_queue_status (status, submitted_at),
    KEY idx_jukebox_queue_ip (requester_ip, submitted_at),
    CONSTRAINT fk_jukebox_queue_user FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- jukebox_blocklist ----------
-- Two kinds of bans:
--   'video'   — a specific YouTube videoId is banned.
--   'keyword' — case-insensitive substring match on the resolved
--               youtube_title or the artist_text/title_text.
-- Staff add/remove entries via /jukebox-admin.php.
CREATE TABLE IF NOT EXISTS jukebox_blocklist (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    kind            ENUM('video','keyword') NOT NULL,
    value           VARCHAR(200)     NOT NULL,
    reason          VARCHAR(200)     NOT NULL DEFAULT '',
    blocked_by      INT UNSIGNED     NULL,
    blocked_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_jukebox_blocklist (kind, value),
    CONSTRAINT fk_jukebox_blocklist_user FOREIGN KEY (blocked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

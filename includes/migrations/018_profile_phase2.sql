-- ============================================================
-- KnK Inn — migration 018: guest profile Phase 2
-- ============================================================
-- Phase 2 adds three things on top of the Phase 1 profile system:
--
--   1) follows           — one-sided social graph. follower_email
--                          chooses to follow followee_email; the
--                          followee doesn't have to accept. Mirrors
--                          the "follow" model (Instagram, Twitter)
--                          rather than the "friend request" model
--                          (Facebook). Casual + low-friction, fits
--                          a small bar.
--
--   2) notifications     — in-app notifications, scoped per-email.
--                          Phase 2 only emits one kind: 'new_follower'
--                          (somebody followed you). Future kinds can
--                          slot in without schema changes via the
--                          payload_json column.
--
--   3) guests.avatar_path — per-guest profile photo. Stores a
--                          server-relative path like
--                          /uploads/avatars/12-abc123.jpg. NULL =
--                          fall back to the gold-circle initial.
--
-- Both new tables are keyed off email (consistent with the rest of
-- the profile system) so a guest's social graph + notifications can
-- be re-keyed cleanly when they claim an anon profile with a real
-- email — same merge logic that already moves orders / songs / darts.
--
-- Idempotent — every column add uses IF NOT EXISTS, every index
-- via ADD INDEX IF NOT EXISTS, every CREATE TABLE uses IF NOT
-- EXISTS. Re-running this migration is a safe no-op.
-- ============================================================

-- ---------- guests: avatar_path ----------
ALTER TABLE guests
    ADD COLUMN IF NOT EXISTS `avatar_path` VARCHAR(255) NULL AFTER display_name;

-- ---------- follows ----------
-- Each row = follower_email follows followee_email.
-- UNIQUE (follower, followee) means a duplicate follow is a no-op.
-- We do NOT FK to guests.email because email is not the PK there
-- (id is) and historically the row may not exist yet on the moment
-- of follow (e.g. an anon visitor who's never opened a profile but
-- gets followed because they appeared on someone's "at the bar"
-- panel).
CREATE TABLE IF NOT EXISTS follows (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    follower_email  VARCHAR(190)    NOT NULL,
    followee_email  VARCHAR(190)    NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_follows_pair (follower_email, followee_email),
    KEY idx_follows_follower  (follower_email, created_at),
    KEY idx_follows_followee  (followee_email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- notifications ----------
-- recipient_email — who should see the bell-icon dot.
-- kind            — vocab is open. Phase 2 only writes 'new_follower'.
-- actor_email     — who caused it (NULL for system notifications).
-- payload_json    — extra context, e.g. {"display_name":"Tâm"} so we
--                   don't need to re-look-up the actor at render time.
-- read_at         — NULL means unread. Set to NOW() on dismiss.
CREATE TABLE IF NOT EXISTS notifications (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient_email  VARCHAR(190)    NOT NULL,
    kind             VARCHAR(40)     NOT NULL,
    actor_email      VARCHAR(190)    NULL,
    payload_json     TEXT            NULL,
    read_at          DATETIME        NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_recipient (recipient_email, read_at, created_at),
    KEY idx_notif_actor     (actor_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

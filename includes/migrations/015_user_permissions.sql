-- ============================================================
-- 015 — Per-user permission toggles
--
-- Until now, what a staff member could see was hard-wired to
-- their role (super_admin / owner / reception / bartender) by
-- knk_role_nav() in auth.php. Ben wants finer control: each
-- user gets their own on/off switches for the nine staff
-- sections, with sensible defaults pre-filled from their role.
--
-- Permission keys (must stay in sync with knk_permissions() in
-- auth.php):
--   bookings · orders · guests · sales · menu · market ·
--   jukebox  · darts  · photos
--
-- Settings and Users are intentionally NOT toggleable. They
-- stay locked to super_admin in code so a bad permission row
-- can never escalate someone into the user-management screen.
--
-- Default matrix (rows = permission, cols = role):
--          super_admin  owner  reception  bartender
-- bookings     Y          Y       Y          N
-- orders       Y          Y       Y          Y
-- guests       Y          N       N          N
-- sales        Y          N       N          N
-- menu         Y          Y       N          N
-- market       Y          N       N          N
-- jukebox      Y          N       N          N
-- darts        Y          N       N          N
-- photos       Y          Y       N          N
--
-- Idempotent — re-running is a no-op (CREATE IF NOT EXISTS,
-- INSERT IGNORE).
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS user_permissions (
    user_id     INT UNSIGNED  NOT NULL,
    permission  VARCHAR(40)   NOT NULL,
    granted     TINYINT(1)    NOT NULL DEFAULT 0,
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, permission),
    CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Backfill defaults for any user without a row ----------
-- For each existing user, insert the nine permission rows derived
-- from the default matrix above. INSERT IGNORE means we never
-- overwrite a toggle Ben has already changed by hand.

INSERT IGNORE INTO user_permissions (user_id, permission, granted)
SELECT u.id, d.permission, d.granted
FROM users u
INNER JOIN (
    -- super_admin
    SELECT 'super_admin' AS role, 'bookings' AS permission, 1 AS granted UNION ALL
    SELECT 'super_admin', 'orders',   1 UNION ALL
    SELECT 'super_admin', 'guests',   1 UNION ALL
    SELECT 'super_admin', 'sales',    1 UNION ALL
    SELECT 'super_admin', 'menu',     1 UNION ALL
    SELECT 'super_admin', 'market',   1 UNION ALL
    SELECT 'super_admin', 'jukebox',  1 UNION ALL
    SELECT 'super_admin', 'darts',    1 UNION ALL
    SELECT 'super_admin', 'photos',   1 UNION ALL
    -- owner
    SELECT 'owner', 'bookings', 1 UNION ALL
    SELECT 'owner', 'orders',   1 UNION ALL
    SELECT 'owner', 'guests',   0 UNION ALL
    SELECT 'owner', 'sales',    0 UNION ALL
    SELECT 'owner', 'menu',     1 UNION ALL
    SELECT 'owner', 'market',   0 UNION ALL
    SELECT 'owner', 'jukebox',  0 UNION ALL
    SELECT 'owner', 'darts',    0 UNION ALL
    SELECT 'owner', 'photos',   1 UNION ALL
    -- reception
    SELECT 'reception', 'bookings', 1 UNION ALL
    SELECT 'reception', 'orders',   1 UNION ALL
    SELECT 'reception', 'guests',   0 UNION ALL
    SELECT 'reception', 'sales',    0 UNION ALL
    SELECT 'reception', 'menu',     0 UNION ALL
    SELECT 'reception', 'market',   0 UNION ALL
    SELECT 'reception', 'jukebox',  0 UNION ALL
    SELECT 'reception', 'darts',    0 UNION ALL
    SELECT 'reception', 'photos',   0 UNION ALL
    -- bartender (a.k.a. Hostess)
    SELECT 'bartender', 'bookings', 0 UNION ALL
    SELECT 'bartender', 'orders',   1 UNION ALL
    SELECT 'bartender', 'guests',   0 UNION ALL
    SELECT 'bartender', 'sales',    0 UNION ALL
    SELECT 'bartender', 'menu',     0 UNION ALL
    SELECT 'bartender', 'market',   0 UNION ALL
    SELECT 'bartender', 'jukebox',  0 UNION ALL
    SELECT 'bartender', 'darts',    0 UNION ALL
    SELECT 'bartender', 'photos',   0
) d ON u.role = d.role;

-- ============================================================
-- KnK Inn — migration 020: darts looking-for-opponent + challenges
-- ============================================================
-- Two new tables for the bar.php?tab=darts open-lobby flow:
--
--   darts_lobby
--     One row per guest who's actively looking for an opponent.
--     Auto-expires 30 min after creation so we don't leak stale
--     "looking" rows when a guest closes the tab. The application
--     code refreshes expires_at every time the looker re-pings
--     the tab (page load or active-state poll), so an open tab
--     stays in the lobby indefinitely.
--
--   darts_challenges
--     One row per challenge fired at a looker. Status moves
--     pending -> accepted | declined | cancelled. On accept, the
--     game_id of the freshly-created lobby/playing game is stored
--     so the both players can be redirected to it.
--
-- Identity is the same lower-cased email used everywhere else
-- in the profile system (anon emails first-class).
--
-- Idempotent — safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS darts_lobby (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    looker_email  VARCHAR(190)    NOT NULL,
    display_name  VARCHAR(60)     NULL,
    -- Snapshot of the looker's display name at the time they
    -- entered the lobby, so other guests see it without a
    -- guests-table join.
    board_pref    INT UNSIGNED    NULL,
    -- Optional: the looker prefers this specific board id.
    -- NULL = any board.
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at    DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_lobby_email (looker_email),
    KEY idx_lobby_expires     (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS darts_challenges (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    challenger_email   VARCHAR(190)    NOT NULL,
    looker_email       VARCHAR(190)    NOT NULL,
    -- The looker is the one who originally tapped "Looking for
    -- opponent". The challenger is the one who tapped Challenge.
    status             VARCHAR(16)     NOT NULL DEFAULT 'pending',
    -- pending | accepted | declined | cancelled
    game_id            BIGINT UNSIGNED NULL,
    -- Set when the challenge is accepted; points at the
    -- darts_games row both players have been routed into.
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at       DATETIME        NULL,
    PRIMARY KEY (id),
    KEY idx_chal_looker_status (looker_email, status, created_at),
    KEY idx_chal_challenger    (challenger_email, created_at),
    KEY idx_chal_created       (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

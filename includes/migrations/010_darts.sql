-- ============================================================
-- 010 — Darts scoring
--
-- Bar guests in the darts room scan a QR on a board, the first
-- phone becomes the host, picks a game type (501/301/Cricket/
-- Around the Clock/Killer/Halve-It), picks player count + format,
-- then a join QR + 6-letter code is shown so the rest of the group
-- can join from their phones. The phone of whoever's turn it is
-- shows a per-dart numpad; everyone else's phone shows the
-- live scoreboard. Server is the source of truth — clients are
-- dumb and just call /api/darts_*.php to drive state.
--
-- Tables (all prefixed darts_ for a clean rollback):
--   darts_boards   — physical dartboards in the darts room
--                    (Board 1, Board 2 seeded). One active game per board.
--   darts_games    — every game ever played + its lifecycle.
--   darts_players  — player slots in a game (1–4, with team for doubles).
--   darts_throws   — every dart thrown. The whole scoreboard can be
--                    reconstructed by replaying these in order.
--
-- Idempotent: re-running the migration is a no-op once the
-- rows + tables exist.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- ---------- darts_boards ----------
-- Physical boards in the darts room. Two seeded by default. Staff can
-- toggle `enabled=0` to take a board offline (broken flights,
-- being moved, etc.).
CREATE TABLE IF NOT EXISTS darts_boards (
    id              TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(40)      NOT NULL,
    enabled         TINYINT(1)       NOT NULL DEFAULT 1,
    sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_darts_boards_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO darts_boards (id, name, sort_order) VALUES
    (1, 'Board 1', 1),
    (2, 'Board 2', 2);

-- ---------- darts_games ----------
-- Lifecycle:
--   lobby     — host has created the game, players are joining
--   playing   — host has hit Start, throws are being recorded
--   finished  — naturally ended (winner declared)
--   abandoned — staff force-ended (or auto-cleanup of stale games)
CREATE TABLE IF NOT EXISTS darts_games (
    id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    board_id            TINYINT UNSIGNED NOT NULL,

    -- The five supported game types. Adding a new one = MODIFY ENUM.
    game_type           ENUM('501','301','cricket','aroundclock','killer','halveit') NOT NULL,

    -- Singles = each player on their own. Doubles = teams of 2 (P1+P3 vs P2+P4).
    format              ENUM('singles','doubles') NOT NULL DEFAULT 'singles',

    player_count        TINYINT UNSIGNED NOT NULL DEFAULT 2,

    status              ENUM('lobby','playing','finished','abandoned') NOT NULL DEFAULT 'lobby',

    -- The slot of the host (almost always 1). Stored as slot_no so it
    -- survives if the host re-joins on a different device.
    host_slot_no        TINYINT UNSIGNED NOT NULL DEFAULT 1,

    -- Short code shown in the lobby for manual join (the QR encodes
    -- /darts.php?join=<code>). 6 letters/digits, A–Z + 2–9 (no 0/1/I/O).
    join_code           VARCHAR(6)       NOT NULL,

    -- Game-specific knobs (e.g. cricket scoring mode, killer lives,
    -- halve-it target sequence). JSON for flexibility.
    config              TEXT             NULL,

    -- Live derived state cached as JSON so the scoreboard renders
    -- without re-replaying every throw on each poll. Updated by
    -- includes/darts.php on every record_throw / undo_throw.
    state_json          MEDIUMTEXT       NULL,

    -- Slot of the player whose turn it is right now. NULL when
    -- the game is in lobby or finished.
    current_slot_no     TINYINT UNSIGNED NULL,

    -- Rolling turn counter (1, 2, 3, ...). 1 turn = each player throws
    -- 3 darts. Used as the natural ordering key for darts_throws.
    current_turn_no     INT UNSIGNED     NOT NULL DEFAULT 0,

    -- Slot of the winner once status='finished'. NULL otherwise.
    winner_slot_no      TINYINT UNSIGNED NULL,
    -- For doubles: the winning team (1 or 2).
    winner_team_no      TINYINT UNSIGNED NULL,

    created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at          DATETIME         NULL,
    finished_at         DATETIME         NULL,
    last_throw_at       DATETIME         NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uk_darts_games_join_code (join_code),
    KEY idx_darts_games_board_status (board_id, status),
    KEY idx_darts_games_status_started (status, started_at),
    CONSTRAINT fk_darts_games_board FOREIGN KEY (board_id) REFERENCES darts_boards(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- darts_players ----------
-- A row per player slot in a game. session_token is an opaque
-- random string we put in a cookie so a phone can re-identify
-- itself across page reloads / poll requests without any login.
CREATE TABLE IF NOT EXISTS darts_players (
    id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    game_id             BIGINT UNSIGNED  NOT NULL,
    slot_no             TINYINT UNSIGNED NOT NULL,    -- 1..player_count
    name                VARCHAR(40)      NOT NULL,
    team_no             TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- 0 = singles; 1 or 2 in doubles
    is_host             TINYINT(1)       NOT NULL DEFAULT 0,
    session_token       CHAR(40)         NOT NULL,    -- random hex, kept in a cookie

    -- For Killer: which number the player must hit to become a killer.
    -- Filled at game-start. NULL for non-killer games.
    killer_number       TINYINT UNSIGNED NULL,

    -- Final ranking for finished games. 1 = winner.
    finishing_position  TINYINT UNSIGNED NULL,

    joined_at           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uk_darts_players_slot (game_id, slot_no),
    KEY idx_darts_players_token (session_token),
    CONSTRAINT fk_darts_players_game FOREIGN KEY (game_id) REFERENCES darts_games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- darts_throws ----------
-- Append-only log of every dart. The scoreboard is *always*
-- reconstructable by replaying throws in (turn_no, dart_no) order.
-- `voided=1` means an undo has logically removed this throw — we
-- keep the row for audit but ignore it in scoring.
--
-- segment encoding (uppercase, fixed 4 chars max):
--   S1..S20  — single (outer or inner)
--   D1..D20  — double ring
--   T1..T20  — treble ring
--   SBULL    — outer bull (25)
--   DBULL    — inner bull (50)
--   MISS     — anywhere off the scoring area
--   BUST     — internal marker; written by the engine when a 501/301
--              turn busts (so we don't lose audit, but we don't score it)
CREATE TABLE IF NOT EXISTS darts_throws (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    game_id         BIGINT UNSIGNED  NOT NULL,
    player_id       BIGINT UNSIGNED  NOT NULL,
    slot_no         TINYINT UNSIGNED NOT NULL,
    turn_no         INT UNSIGNED     NOT NULL,
    dart_no         TINYINT UNSIGNED NOT NULL,        -- 1, 2, or 3
    segment         VARCHAR(8)       NOT NULL,
    value           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    voided          TINYINT(1)       NOT NULL DEFAULT 0,
    thrown_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_darts_throws_game_turn (game_id, turn_no, dart_no),
    KEY idx_darts_throws_player (player_id, turn_no, dart_no),
    CONSTRAINT fk_darts_throws_game   FOREIGN KEY (game_id)   REFERENCES darts_games(id)   ON DELETE CASCADE,
    CONSTRAINT fk_darts_throws_player FOREIGN KEY (player_id) REFERENCES darts_players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- darts_config ----------
-- Single-row table for the global kill switch + tunable defaults.
-- Mirrors jukebox_config / market_config for consistency.
CREATE TABLE IF NOT EXISTS darts_config (
    id                          TINYINT UNSIGNED NOT NULL,
    enabled                     TINYINT(1)       NOT NULL DEFAULT 1,
    -- Auto-abandon any game still in 'lobby' or 'playing' for this many
    -- minutes since last activity. Stops the system clogging up with
    -- forgotten games.
    stale_after_minutes         SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    -- How often phones poll /api/darts_state.php during a game.
    poll_seconds                SMALLINT UNSIGNED NOT NULL DEFAULT 2,
    updated_by                  INT UNSIGNED     NULL,
    updated_at                  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at                  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_darts_config_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO darts_config (id) VALUES (1);

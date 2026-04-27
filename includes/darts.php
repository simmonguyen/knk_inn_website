<?php
/*
 * KnK Inn — Darts scoring engine.
 *
 * Server is the single source of truth. Phones submit one dart at
 * a time via /api/darts_throw.php; this lib appends a row to
 * darts_throws, recomputes the live state, writes the snapshot to
 * darts_games.state_json, and advances the turn cursor when 3 darts
 * have been thrown.
 *
 * Game types implemented:
 *   501 / 301      — start at N, subtract, finish on a double or DBULL.
 *   cricket        — close 15..20 + bull, then score on opponents.
 *   aroundclock    — hit 1..20 in order, finish on the bull.
 *   killer         — become a killer, take opponents' lives via doubles.
 *   halveit        — six fixed targets; miss them all in a round and
 *                    your score is halved.
 *
 * Adding a new game type:
 *   1. Add to ENUM in migration.
 *   2. Add a knk_darts_eval_<type>($state, $players, $throws_by_turn)
 *      function below.
 *   3. Add it to knk_darts_eval() and knk_darts_default_state().
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";

/* ==========================================================
 * CONSTANTS
 * ======================================================== */

const KNK_DARTS_GAME_TYPES = ['501', '301', 'cricket', 'aroundclock', 'killer', 'halveit'];
const KNK_DARTS_FORMATS    = ['singles', 'doubles'];
const KNK_DARTS_MAX_PLAYERS = 4;

// The six rounds of Halve-It (in order). Each round's "hit?" rule is
// implemented in knk_darts_halveit_match(). Tweak labels here only.
const KNK_DARTS_HALVEIT_TARGETS = [
    ['label' => 'Twenties',   'rule' => 'any20'],
    ['label' => 'Sixteens',   'rule' => 'any16'],
    ['label' => 'Any double', 'rule' => 'anydouble'],
    ['label' => 'Seventeens', 'rule' => 'any17'],
    ['label' => 'Any treble', 'rule' => 'anytreble'],
    ['label' => 'Bullseye',   'rule' => 'anybull'],
];

/* ==========================================================
 * CONFIG
 * ======================================================== */

function knk_darts_config(): array {
    $row = knk_db()->query("SELECT * FROM darts_config WHERE id = 1 LIMIT 1")->fetch();
    if (!$row) {
        knk_db()->exec("INSERT IGNORE INTO darts_config (id) VALUES (1)");
        $row = knk_db()->query("SELECT * FROM darts_config WHERE id = 1 LIMIT 1")->fetch();
    }
    return $row ?: ['enabled' => 1, 'poll_seconds' => 2, 'stale_after_minutes' => 60];
}

function knk_darts_enabled(): bool {
    $cfg = knk_darts_config();
    return !empty($cfg['enabled']);
}

function knk_darts_boards(): array {
    $st = knk_db()->query("SELECT * FROM darts_boards ORDER BY sort_order, id");
    return $st->fetchAll();
}

/* ==========================================================
 * SEGMENT MATH
 * ======================================================== */

/**
 * Parse a segment code (e.g. "T20", "S5", "DBULL", "MISS") into
 * [base, multiplier, value]. Returns null for unknown codes.
 *
 *   base       = 1..20 for numbered segments, 25 for any bull
 *   multiplier = 1 single, 2 double, 3 treble (bull is always 1 or 2)
 *   value      = the points (e.g. T20 = 60, DBULL = 50, MISS = 0)
 */
function knk_darts_parse_segment(string $seg): ?array {
    $seg = strtoupper(trim($seg));
    if ($seg === 'MISS' || $seg === '') {
        return ['base' => 0, 'mult' => 0, 'value' => 0, 'is_double' => false, 'is_treble' => false, 'is_bull' => false];
    }
    if ($seg === 'SBULL') {
        return ['base' => 25, 'mult' => 1, 'value' => 25, 'is_double' => false, 'is_treble' => false, 'is_bull' => true];
    }
    if ($seg === 'DBULL') {
        return ['base' => 25, 'mult' => 2, 'value' => 50, 'is_double' => true,  'is_treble' => false, 'is_bull' => true];
    }
    if (preg_match('/^([SDT])(\d{1,2})$/', $seg, $m)) {
        $n = (int)$m[2];
        if ($n < 1 || $n > 20) return null;
        $mult = ['S' => 1, 'D' => 2, 'T' => 3][$m[1]];
        return [
            'base'      => $n,
            'mult'      => $mult,
            'value'     => $n * $mult,
            'is_double' => $m[1] === 'D',
            'is_treble' => $m[1] === 'T',
            'is_bull'   => false,
        ];
    }
    return null;
}

/* ==========================================================
 * BOARDS / GAMES — LIFECYCLE
 * ======================================================== */

/**
 * Find the active game (status in lobby/playing) on a given board, or
 * null if the board is free.
 */
function knk_darts_active_game_on_board(int $board_id): ?array {
    $st = knk_db()->prepare(
        "SELECT * FROM darts_games
          WHERE board_id = ? AND status IN ('lobby','playing')
          ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$board_id]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * 6-letter join code, A–Z + 2–9, no 0/1/I/O for clarity on a phone.
 * Loops on the (extremely rare) collision.
 */
function knk_darts_make_join_code(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $alen = strlen($alphabet);
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $code = '';
        for ($i = 0; $i < 6; $i++) $code .= $alphabet[random_int(0, $alen - 1)];
        $st = knk_db()->prepare("SELECT 1 FROM darts_games WHERE join_code = ? AND status IN ('lobby','playing')");
        $st->execute([$code]);
        if (!$st->fetch()) return $code;
    }
    // Wildly unlikely; fall through with a timestamp suffix to break the tie.
    return strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

/**
 * Auto-abandon stale games (no activity for stale_after_minutes).
 * Called opportunistically before showing a board picker so freed
 * boards become visible.
 */
function knk_darts_cleanup_stale(): void {
    $cfg = knk_darts_config();
    $mins = max(5, (int)$cfg['stale_after_minutes']);
    knk_db()->prepare(
        "UPDATE darts_games
            SET status = 'abandoned', finished_at = NOW()
          WHERE status IN ('lobby','playing')
            AND COALESCE(last_throw_at, started_at, created_at) < (NOW() - INTERVAL ? MINUTE)"
    )->execute([$mins]);
}

/**
 * Host creates a game. Adds them as slot 1.
 *
 * Returns [game_row, host_player_row].
 */
function knk_darts_create_game(int $board_id, string $game_type, string $format, int $player_count, string $host_name, string $guest_email = ''): array {
    if (!in_array($game_type, KNK_DARTS_GAME_TYPES, true)) {
        throw new RuntimeException("Unknown game type.");
    }
    if (!in_array($format, KNK_DARTS_FORMATS, true)) {
        throw new RuntimeException("Unknown format.");
    }
    $player_count = max(1, min(KNK_DARTS_MAX_PLAYERS, $player_count));
    $host_name = trim($host_name);
    if ($host_name === '') $host_name = 'Host';
    if (mb_strlen($host_name) > 40) $host_name = mb_substr($host_name, 0, 40);

    if ($format === 'doubles' && $player_count !== 4) {
        throw new RuntimeException("Doubles requires exactly 4 players.");
    }

    knk_darts_cleanup_stale();
    if (knk_darts_active_game_on_board($board_id) !== null) {
        throw new RuntimeException("That board already has an active game.");
    }

    $code = knk_darts_make_join_code();

    $pdo = knk_db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO darts_games (board_id, game_type, format, player_count, status, host_slot_no, join_code, config, state_json)
             VALUES (?, ?, ?, ?, 'lobby', 1, ?, ?, ?)"
        )->execute([
            $board_id, $game_type, $format, $player_count, $code,
            json_encode(knk_darts_default_config($game_type), JSON_UNESCAPED_UNICODE),
            json_encode(knk_darts_default_state($game_type, $player_count, $format), JSON_UNESCAPED_UNICODE),
        ]);
        $game_id = (int)$pdo->lastInsertId();
        $token = bin2hex(random_bytes(20));
        $team_no = ($format === 'doubles') ? 1 : 0;
        /* Optional guest_email (added in migration 017). Lets the
         * profile page show the guest their own darts game history.
         * Empty string is fine — the column is NOT NULL DEFAULT ''. */
        $email = strtolower(trim($guest_email));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
        $pdo->prepare(
            "INSERT INTO darts_players (game_id, slot_no, name, team_no, is_host, session_token, guest_email)
             VALUES (?, 1, ?, ?, 1, ?, ?)"
        )->execute([$game_id, $host_name, $team_no, $token, mb_substr($email, 0, 190)]);
        $host_id = (int)$pdo->lastInsertId();
        $pdo->commit();

        /* Adopt the typed name onto the guest profile so the rest of
         * the system stops calling them "Guest XXXX". Only overwrites
         * the auto-generated placeholder, never a real /profile.php
         * name. Best-effort — if it fails we still return the host
         * row, the game's already created. */
        if ($email !== '' && $host_name !== '' && function_exists('knk_profile_adopt_typed_name')) {
            try { knk_profile_adopt_typed_name($email, $host_name); }
            catch (Throwable $e) { error_log("darts host name-adopt: " . $e->getMessage()); }
        }

        $game = $pdo->prepare("SELECT * FROM darts_games WHERE id = ?");
        $game->execute([$game_id]);
        $game = $game->fetch();
        $host = $pdo->prepare("SELECT * FROM darts_players WHERE id = ?");
        $host->execute([$host_id]);
        $host = $host->fetch();
        return [$game, $host];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * A non-host joins by code. Returns the new player row.
 *
 * Throws if the game is full, already started, or the slot the
 * client is asking for is taken. We always assign the lowest
 * available slot_no and the matching team_no for doubles
 * (P1+P3 = team 1, P2+P4 = team 2).
 */
function knk_darts_join_game(int $game_id, string $name, string $guest_email = ''): array {
    $pdo = knk_db();
    $name = trim($name);
    if ($name === '') throw new RuntimeException("Tell us your name.");
    if (mb_strlen($name) > 40) $name = mb_substr($name, 0, 40);

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM darts_games WHERE id = ? FOR UPDATE");
        $st->execute([$game_id]);
        $game = $st->fetch();
        if (!$game) throw new RuntimeException("Game not found.");
        if ($game['status'] !== 'lobby') throw new RuntimeException("That game has already started.");

        $st = $pdo->prepare("SELECT slot_no FROM darts_players WHERE game_id = ? ORDER BY slot_no");
        $st->execute([$game_id]);
        // Cast to int — Matbao's mysqlnd build returns INT columns as
        // strings even with PDO::ATTR_EMULATE_PREPARES = false, which
        // makes the strict in_array() check below silently disagree
        // with the loop's int $s. That bug surfaced as a SQLSTATE 1062
        // 'Duplicate entry "X-1"' on the second player joining.
        $taken = array_map('intval', array_column($st->fetchAll(), 'slot_no'));
        $next_slot = null;
        for ($s = 1; $s <= (int)$game['player_count']; $s++) {
            if (!in_array($s, $taken, true)) { $next_slot = $s; break; }
        }
        if ($next_slot === null) throw new RuntimeException("Lobby is full.");

        // Doubles: odd slot = team 1, even slot = team 2 (P1+P3 vs P2+P4).
        $team_no = ($game['format'] === 'doubles') ? (($next_slot % 2 === 1) ? 1 : 2) : 0;
        $token = bin2hex(random_bytes(20));
        /* Optional guest_email — see knk_darts_create_game above. */
        $email = strtolower(trim($guest_email));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
        $pdo->prepare(
            "INSERT INTO darts_players (game_id, slot_no, name, team_no, is_host, session_token, guest_email)
             VALUES (?, ?, ?, ?, 0, ?, ?)"
        )->execute([$game_id, $next_slot, $name, $team_no, $token, mb_substr($email, 0, 190)]);
        $pid = (int)$pdo->lastInsertId();
        $pdo->commit();

        /* Same name-adoption pattern as create_game — promotes the
         * typed name to the guest profile if it's still on the auto
         * "Guest XXXX" placeholder. */
        if ($email !== '' && $name !== '' && function_exists('knk_profile_adopt_typed_name')) {
            try { knk_profile_adopt_typed_name($email, $name); }
            catch (Throwable $e) { error_log("darts join name-adopt: " . $e->getMessage()); }
        }

        $st = $pdo->prepare("SELECT * FROM darts_players WHERE id = ?");
        $st->execute([$pid]);
        return $st->fetch();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Host hits Start. Locks in the roster, builds the initial state
 * (incl. random Killer numbers), sets current_slot_no = 1.
 */
function knk_darts_start_game(int $game_id): void {
    $pdo = knk_db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM darts_games WHERE id = ? FOR UPDATE");
        $st->execute([$game_id]);
        $game = $st->fetch();
        if (!$game) throw new RuntimeException("Game not found.");
        if ($game['status'] !== 'lobby') throw new RuntimeException("Game already started.");

        $players = knk_darts_load_players($game_id);
        if (count($players) < 1) throw new RuntimeException("No players in lobby.");

        // Killer: assign random numbers (11..20, no repeats).
        if ($game['game_type'] === 'killer') {
            $pool = range(11, 20);
            shuffle($pool);
            foreach ($players as $i => $p) {
                $num = $pool[$i] ?? null;
                if ($num !== null) {
                    $pdo->prepare("UPDATE darts_players SET killer_number = ? WHERE id = ?")
                        ->execute([$num, $p['id']]);
                }
            }
            $players = knk_darts_load_players($game_id);
        }

        $state = knk_darts_default_state(
            $game['game_type'],
            count($players),
            $game['format'],
            $players
        );

        $pdo->prepare(
            "UPDATE darts_games
                SET status = 'playing',
                    started_at = NOW(),
                    last_throw_at = NOW(),
                    current_slot_no = 1,
                    current_turn_no = 1,
                    state_json = ?,
                    player_count = ?
              WHERE id = ?"
        )->execute([json_encode($state, JSON_UNESCAPED_UNICODE), count($players), $game_id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function knk_darts_load_players(int $game_id): array {
    $st = knk_db()->prepare("SELECT * FROM darts_players WHERE game_id = ? ORDER BY slot_no");
    $st->execute([$game_id]);
    return $st->fetchAll();
}

function knk_darts_load_throws(int $game_id, bool $include_voided = false): array {
    $sql = "SELECT * FROM darts_throws WHERE game_id = ?";
    if (!$include_voided) $sql .= " AND voided = 0";
    $sql .= " ORDER BY turn_no, slot_no, dart_no, id";
    $st = knk_db()->prepare($sql);
    $st->execute([$game_id]);
    return $st->fetchAll();
}

/* ==========================================================
 * THROW RECORDING
 * ======================================================== */

/**
 * Record one dart for the current player. Re-evaluates state, advances
 * the turn cursor if 3 darts thrown, and may finish the game.
 *
 * Returns the fresh state array.
 */
function knk_darts_record_throw(int $game_id, int $player_id, string $segment): array {
    $parsed = knk_darts_parse_segment($segment);
    if ($parsed === null) throw new RuntimeException("Unknown segment: $segment");

    $pdo = knk_db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM darts_games WHERE id = ? FOR UPDATE");
        $st->execute([$game_id]);
        $game = $st->fetch();
        if (!$game)                       throw new RuntimeException("Game not found.");
        if ($game['status'] !== 'playing') throw new RuntimeException("Game is not in progress.");

        $st = $pdo->prepare("SELECT * FROM darts_players WHERE id = ? AND game_id = ?");
        $st->execute([$player_id, $game_id]);
        $player = $st->fetch();
        if (!$player) throw new RuntimeException("Player not in this game.");
        if ((int)$player['slot_no'] !== (int)$game['current_slot_no']) {
            throw new RuntimeException("It's not your turn.");
        }

        // Which dart number is this within the current turn?
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM darts_throws
              WHERE game_id = ? AND turn_no = ? AND slot_no = ? AND voided = 0"
        );
        $st->execute([$game_id, $game['current_turn_no'], $game['current_slot_no']]);
        $already = (int)$st->fetchColumn();
        if ($already >= 3) throw new RuntimeException("You've already thrown 3 darts this turn.");
        $dart_no = $already + 1;

        // Append the throw.
        $pdo->prepare(
            "INSERT INTO darts_throws (game_id, player_id, slot_no, turn_no, dart_no, segment, value)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $game_id, $player_id, $player['slot_no'],
            $game['current_turn_no'], $dart_no,
            strtoupper($segment), (int)$parsed['value'],
        ]);

        // Re-evaluate the whole game state from scratch — the easiest
        // way to keep state and throws perfectly consistent.
        $players = knk_darts_load_players($game_id);
        $throws  = knk_darts_load_throws($game_id, false);

        $eval = knk_darts_eval($game, $players, $throws);
        // $eval = ['state' => ..., 'next_slot' => int|null, 'next_turn' => int, 'finished' => bool, 'winner_slot' => int|null, 'winner_team' => int|null]

        // Update game row.
        if ($eval['finished']) {
            $pdo->prepare(
                "UPDATE darts_games
                    SET state_json = ?, status = 'finished', finished_at = NOW(),
                        last_throw_at = NOW(),
                        current_slot_no = NULL,
                        winner_slot_no = ?, winner_team_no = ?
                  WHERE id = ?"
            )->execute([
                json_encode($eval['state'], JSON_UNESCAPED_UNICODE),
                $eval['winner_slot'], $eval['winner_team'],
                $game_id,
            ]);
            // Stamp finishing positions.
            knk_darts_finalise_positions($game_id, $players, $eval);
        } else {
            $pdo->prepare(
                "UPDATE darts_games
                    SET state_json = ?, last_throw_at = NOW(),
                        current_slot_no = ?, current_turn_no = ?
                  WHERE id = ?"
            )->execute([
                json_encode($eval['state'], JSON_UNESCAPED_UNICODE),
                $eval['next_slot'], $eval['next_turn'],
                $game_id,
            ]);
        }

        $pdo->commit();
        return $eval['state'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Bust the current player's round — voids any darts they've already
 * thrown this turn and records three MISS darts to fill the round at
 * 0 points, then advances to the next player.
 *
 * Only meaningful in x01 games (501 / 301) where bust = "you went
 * over, round counts as zero, lose your turn." For other game types
 * the caller shouldn't expose the button — the API still rejects it
 * defensively.
 *
 * Allowed by the host or the player whose turn it is.
 */
function knk_darts_bust_round(int $game_id, int $by_player_id): array {
    $pdo = knk_db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM darts_games WHERE id = ? FOR UPDATE");
        $st->execute([$game_id]);
        $game = $st->fetch();
        if (!$game)                        throw new RuntimeException("Game not found.");
        if ($game['status'] !== 'playing') throw new RuntimeException("Game is not in progress.");

        $type = (string)$game['game_type'];
        if ($type !== '501' && $type !== '301') {
            throw new RuntimeException("Bust only applies to 501 / 301.");
        }

        $st = $pdo->prepare("SELECT * FROM darts_players WHERE id = ? AND game_id = ?");
        $st->execute([$by_player_id, $game_id]);
        $by = $st->fetch();
        if (!$by) throw new RuntimeException("Not in this game.");

        $current_slot = (int)$game['current_slot_no'];
        if (!$by['is_host'] && (int)$by['slot_no'] !== $current_slot) {
            throw new RuntimeException("Only the host or the current thrower can bust.");
        }

        // Find the current player row — the bust applies to their
        // turn, not the caller's.
        $st = $pdo->prepare(
            "SELECT * FROM darts_players WHERE game_id = ? AND slot_no = ?"
        );
        $st->execute([$game_id, $current_slot]);
        $current_player = $st->fetch();
        if (!$current_player) throw new RuntimeException("Active slot not found.");

        $turn_no = (int)$game['current_turn_no'];

        // Void any darts the current player has already thrown this turn.
        $pdo->prepare(
            "UPDATE darts_throws
                SET voided = 1
              WHERE game_id = ? AND slot_no = ? AND turn_no = ? AND voided = 0"
        )->execute([$game_id, $current_slot, $turn_no]);

        // Record 3 MISS darts so the round explicitly closes at 0.
        // Going via INSERT direct (not knk_darts_record_throw) so the
        // turn-cursor update happens once at the end via knk_darts_eval,
        // not three times.
        $ins = $pdo->prepare(
            "INSERT INTO darts_throws (game_id, player_id, slot_no, turn_no, dart_no, segment, value)
             VALUES (?, ?, ?, ?, ?, 'MISS', 0)"
        );
        for ($d = 1; $d <= 3; $d++) {
            $ins->execute([
                $game_id, (int)$current_player['id'], $current_slot,
                $turn_no, $d,
            ]);
        }

        // Re-evaluate game state and advance.
        $players = knk_darts_load_players($game_id);
        $throws  = knk_darts_load_throws($game_id, false);
        $eval = knk_darts_eval($game, $players, $throws);

        $pdo->prepare(
            "UPDATE darts_games
                SET state_json = ?, last_throw_at = NOW(),
                    current_slot_no = ?, current_turn_no = ?
              WHERE id = ?"
        )->execute([
            json_encode($eval['state'], JSON_UNESCAPED_UNICODE),
            $eval['next_slot'], $eval['next_turn'],
            $game_id,
        ]);

        $pdo->commit();
        return $eval['state'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Mark the most recent (non-voided) throw as voided, and roll the
 * cursor back if needed. Allowed by the host or the player who threw it.
 */
function knk_darts_undo_throw(int $game_id, int $by_player_id): array {
    $pdo = knk_db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM darts_games WHERE id = ? FOR UPDATE");
        $st->execute([$game_id]);
        $game = $st->fetch();
        if (!$game) throw new RuntimeException("Game not found.");
        if ($game['status'] !== 'playing') throw new RuntimeException("Game is not in progress.");

        $st = $pdo->prepare(
            "SELECT * FROM darts_throws
              WHERE game_id = ? AND voided = 0
              ORDER BY turn_no DESC, slot_no DESC, dart_no DESC, id DESC LIMIT 1"
        );
        $st->execute([$game_id]);
        $last = $st->fetch();
        if (!$last) throw new RuntimeException("Nothing to undo.");

        $st = $pdo->prepare("SELECT * FROM darts_players WHERE id = ? AND game_id = ?");
        $st->execute([$by_player_id, $game_id]);
        $by = $st->fetch();
        if (!$by) throw new RuntimeException("Not in this game.");
        if (!$by['is_host'] && (int)$by['id'] !== (int)$last['player_id']) {
            throw new RuntimeException("Only the host or the thrower can undo.");
        }

        $pdo->prepare("UPDATE darts_throws SET voided = 1 WHERE id = ?")->execute([$last['id']]);

        $players = knk_darts_load_players($game_id);
        $throws  = knk_darts_load_throws($game_id, false);
        $eval = knk_darts_eval($game, $players, $throws);

        $pdo->prepare(
            "UPDATE darts_games
                SET state_json = ?, last_throw_at = NOW(),
                    current_slot_no = ?, current_turn_no = ?,
                    status = 'playing', finished_at = NULL,
                    winner_slot_no = NULL, winner_team_no = NULL
              WHERE id = ?"
        )->execute([
            json_encode($eval['state'], JSON_UNESCAPED_UNICODE),
            $eval['next_slot'] ?? (int)$last['slot_no'],
            $eval['next_turn'] ?? (int)$last['turn_no'],
            $game_id,
        ]);
        $pdo->commit();
        return $eval['state'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function knk_darts_force_finish(int $game_id): void {
    knk_db()->prepare(
        "UPDATE darts_games
            SET status = 'abandoned', finished_at = NOW(), current_slot_no = NULL
          WHERE id = ? AND status IN ('lobby','playing')"
    )->execute([$game_id]);
}

function knk_darts_finalise_positions(int $game_id, array $players, array $eval): void {
    // We don't compute a full 1..N ordering for every game type — it's
    // not always meaningful. Just flag the winner for now.
    if (!empty($eval['winner_slot'])) {
        knk_db()->prepare(
            "UPDATE darts_players SET finishing_position = 1
              WHERE game_id = ? AND slot_no = ?"
        )->execute([$game_id, (int)$eval['winner_slot']]);
    }
    if (!empty($eval['winner_team'])) {
        knk_db()->prepare(
            "UPDATE darts_players SET finishing_position = 1
              WHERE game_id = ? AND team_no = ?"
        )->execute([$game_id, (int)$eval['winner_team']]);
    }
}

/* ==========================================================
 * STATE EVALUATION (PER GAME TYPE)
 * ======================================================== */

function knk_darts_default_config(string $game_type): array {
    if ($game_type === 'killer')  return ['lives' => 3];
    if ($game_type === 'cricket') return ['mode'  => 'standard']; // future: 'cutthroat'
    return [];
}

function knk_darts_default_state(string $game_type, int $player_count, string $format, array $players = []): array {
    $base = ['type' => $game_type, 'format' => $format, 'players' => []];

    // Pre-seed an entry per player slot so the scoreboard renders even
    // before the first throw. We may not have player rows yet (called
    // from create_game before the host is inserted) — fall back to slot
    // numbers in that case.
    $slots = [];
    if (!empty($players)) {
        foreach ($players as $p) $slots[] = (int)$p['slot_no'];
    } else {
        for ($i = 1; $i <= $player_count; $i++) $slots[] = $i;
    }

    if ($game_type === '501' || $game_type === '301') {
        $start = $game_type === '501' ? 501 : 301;
        if ($format === 'doubles') {
            $base['team_remaining'] = [1 => $start, 2 => $start];
            $base['team_last_turn_total'] = [1 => 0, 2 => 0];
        } else {
            foreach ($slots as $s) {
                $base['players'][$s] = ['remaining' => $start, 'last_turn_total' => 0, 'busted_last' => false];
            }
        }
        return $base;
    }

    if ($game_type === 'cricket') {
        $targets = [15, 16, 17, 18, 19, 20, 25];
        if ($format === 'doubles') {
            $base['team_marks'] = [];
            $base['team_score'] = [];
            foreach ([1, 2] as $t) {
                $base['team_marks'][$t] = [];
                foreach ($targets as $n) $base['team_marks'][$t][$n] = 0;
                $base['team_score'][$t] = 0;
            }
        } else {
            foreach ($slots as $s) {
                $marks = [];
                foreach ($targets as $n) $marks[$n] = 0;
                $base['players'][$s] = ['marks' => $marks, 'score' => 0];
            }
        }
        return $base;
    }

    if ($game_type === 'aroundclock') {
        foreach ($slots as $s) {
            $base['players'][$s] = ['target' => 1, 'finished' => false];
        }
        return $base;
    }

    if ($game_type === 'killer') {
        $lives = 3;
        foreach ($slots as $s) {
            $base['players'][$s] = ['lives' => $lives, 'killer' => false, 'eliminated' => false];
        }
        return $base;
    }

    if ($game_type === 'halveit') {
        foreach ($slots as $s) {
            $base['players'][$s] = ['score' => 0];
        }
        $base['round'] = 1;
        $base['targets'] = KNK_DARTS_HALVEIT_TARGETS;
        return $base;
    }

    return $base;
}

/**
 * Group throws by (turn_no, slot_no) for replay.
 *
 * Returns a list ordered by (turn_no, slot_no) where each item is:
 *   ['turn' => int, 'slot' => int, 'darts' => [throw_row, throw_row, throw_row]]
 */
function knk_darts_group_throws(array $throws): array {
    $bins = [];
    foreach ($throws as $t) {
        $key = (int)$t['turn_no'] . ':' . (int)$t['slot_no'];
        if (!isset($bins[$key])) {
            $bins[$key] = ['turn' => (int)$t['turn_no'], 'slot' => (int)$t['slot_no'], 'darts' => []];
        }
        $bins[$key]['darts'][] = $t;
    }
    $out = array_values($bins);
    usort($out, function ($a, $b) {
        if ($a['turn'] !== $b['turn']) return $a['turn'] - $b['turn'];
        return $a['slot'] - $b['slot'];
    });
    return $out;
}

/**
 * Master evaluator. Dispatches to the per-game-type function.
 *
 * Every game-type evaluator returns:
 *   [
 *     'state'        => array,    // for state_json
 *     'next_slot'    => ?int,     // who throws next (NULL if game over)
 *     'next_turn'    => int,
 *     'finished'     => bool,
 *     'winner_slot'  => ?int,
 *     'winner_team'  => ?int,
 *   ]
 */
function knk_darts_eval(array $game, array $players, array $throws): array {
    $type = $game['game_type'];
    $cfg  = json_decode((string)$game['config'], true) ?: [];

    if ($type === '501' || $type === '301') return knk_darts_eval_x01($game, $players, $throws, $cfg);
    if ($type === 'cricket')                return knk_darts_eval_cricket($game, $players, $throws, $cfg);
    if ($type === 'aroundclock')            return knk_darts_eval_aroundclock($game, $players, $throws, $cfg);
    if ($type === 'killer')                 return knk_darts_eval_killer($game, $players, $throws, $cfg);
    if ($type === 'halveit')                return knk_darts_eval_halveit($game, $players, $throws, $cfg);

    throw new RuntimeException("Unknown game type: $type");
}

/* ----- 501 / 301 ----- */

function knk_darts_eval_x01(array $game, array $players, array $throws, array $cfg): array {
    $start = $game['game_type'] === '501' ? 501 : 301;
    $format = $game['format'];

    $slots = array_map(function ($p) { return (int)$p['slot_no']; }, $players);
    $slot_team = [];
    foreach ($players as $p) $slot_team[(int)$p['slot_no']] = (int)$p['team_no'];

    // Track remaining per scoring unit (player or team).
    if ($format === 'doubles') {
        $rem = [1 => $start, 2 => $start];
    } else {
        $rem = [];
        foreach ($slots as $s) $rem[$s] = $start;
    }
    $last_turn_total = [];
    foreach (array_keys($rem) as $k) $last_turn_total[$k] = 0;

    $finished = false;
    $winner_slot = null;
    $winner_team = null;

    $groups = knk_darts_group_throws($throws);
    foreach ($groups as $g) {
        $slot = $g['slot'];
        $unit = $format === 'doubles' ? $slot_team[$slot] : $slot;
        $turn_total = 0;
        $bust = false;
        $win = false;
        $remaining_at_turn_start = $rem[$unit];
        $last_dart = null;
        foreach ($g['darts'] as $t) {
            $parsed = knk_darts_parse_segment($t['segment']);
            if (!$parsed) continue;
            $turn_total += $parsed['value'];
            $last_dart = $parsed;
            $candidate = $remaining_at_turn_start - $turn_total;
            if ($candidate < 0 || $candidate === 1) {
                $bust = true; break;
            }
            if ($candidate === 0) {
                if ($parsed['is_double']) { $win = true; break; }
                $bust = true; break;
            }
        }
        if ($win) {
            $rem[$unit] -= $turn_total;
            $last_turn_total[$unit] = $turn_total;
            $finished = true;
            if ($format === 'doubles') $winner_team = $unit; else $winner_slot = $unit;
            break;
        }
        if ($bust) {
            $last_turn_total[$unit] = 0; // turn voided, score reverts (rem unchanged).
        } else {
            $rem[$unit] -= $turn_total;
            $last_turn_total[$unit] = $turn_total;
        }
    }

    // Build state snapshot.
    $state = ['type' => $game['game_type'], 'format' => $format];
    if ($format === 'doubles') {
        $state['team_remaining'] = $rem;
        $state['team_last_turn_total'] = $last_turn_total;
    } else {
        $state['players'] = [];
        foreach ($slots as $s) {
            $state['players'][$s] = [
                'remaining'        => $rem[$s],
                'last_turn_total'  => $last_turn_total[$s],
            ];
        }
    }

    // Next cursor.
    $next = knk_darts_next_cursor($game, $players, $throws);

    return [
        'state'       => $state,
        'next_slot'   => $finished ? null : $next['slot'],
        'next_turn'   => $next['turn'],
        'finished'    => $finished,
        'winner_slot' => $winner_slot,
        'winner_team' => $winner_team,
    ];
}

/* ----- Cricket (standard scoring) ----- */

function knk_darts_eval_cricket(array $game, array $players, array $throws, array $cfg): array {
    $format = $game['format'];
    $targets = [15, 16, 17, 18, 19, 20, 25];

    $slots = array_map(function ($p) { return (int)$p['slot_no']; }, $players);
    $slot_team = [];
    foreach ($players as $p) $slot_team[(int)$p['slot_no']] = (int)$p['team_no'];
    $units = $format === 'doubles' ? [1, 2] : $slots;

    $marks = [];
    $score = [];
    foreach ($units as $u) {
        $marks[$u] = [];
        foreach ($targets as $n) $marks[$u][$n] = 0;
        $score[$u] = 0;
    }

    foreach ($throws as $t) {
        $parsed = knk_darts_parse_segment($t['segment']);
        if (!$parsed) continue;
        $base = $parsed['base'];
        if (!in_array($base, $targets, true)) continue;
        $unit = $format === 'doubles' ? $slot_team[(int)$t['slot_no']] : (int)$t['slot_no'];
        $hits = $parsed['mult']; // S=1, D=2, T=3 (bull is 1 or 2)
        for ($k = 0; $k < $hits; $k++) {
            if ($marks[$unit][$base] < 3) {
                $marks[$unit][$base]++;
            } else {
                // Already closed by us — does anyone else have it open?
                $someone_open = false;
                foreach ($units as $u2) {
                    if ($u2 === $unit) continue;
                    if ($marks[$u2][$base] < 3) { $someone_open = true; break; }
                }
                if ($someone_open) $score[$unit] += $base;
            }
        }
    }

    // Win condition: a unit has all 7 targets closed AND highest score
    // (or tied for highest).
    $finished = false;
    $winner_slot = null;
    $winner_team = null;
    foreach ($units as $u) {
        $all_closed = true;
        foreach ($targets as $n) if ($marks[$u][$n] < 3) { $all_closed = false; break; }
        if (!$all_closed) continue;
        $is_high = true;
        foreach ($units as $v) {
            if ($v === $u) continue;
            if ($score[$v] > $score[$u]) { $is_high = false; break; }
        }
        if ($is_high) {
            $finished = true;
            if ($format === 'doubles') $winner_team = $u; else $winner_slot = $u;
            break;
        }
    }

    $state = ['type' => 'cricket', 'format' => $format];
    if ($format === 'doubles') {
        $state['team_marks'] = $marks;
        $state['team_score'] = $score;
    } else {
        $state['players'] = [];
        foreach ($slots as $s) {
            $state['players'][$s] = ['marks' => $marks[$s], 'score' => $score[$s]];
        }
    }

    $next = knk_darts_next_cursor($game, $players, $throws);
    return [
        'state' => $state,
        'next_slot' => $finished ? null : $next['slot'],
        'next_turn' => $next['turn'],
        'finished' => $finished,
        'winner_slot' => $winner_slot,
        'winner_team' => $winner_team,
    ];
}

/* ----- Around the Clock ----- */

function knk_darts_eval_aroundclock(array $game, array $players, array $throws, array $cfg): array {
    $slots = array_map(function ($p) { return (int)$p['slot_no']; }, $players);
    $target = [];
    foreach ($slots as $s) $target[$s] = 1; // 1..20, then 25 (bull) finishes
    $finished = false;
    $winner_slot = null;

    foreach ($throws as $t) {
        $parsed = knk_darts_parse_segment($t['segment']);
        if (!$parsed) continue;
        $slot = (int)$t['slot_no'];
        if (!isset($target[$slot])) continue;
        if ($target[$slot] > 20) continue; // already finished
        $expected = $target[$slot];
        if ($expected <= 20) {
            if ($parsed['base'] === $expected) $target[$slot]++;
        }
        if ($target[$slot] === 21) {
            // Now they need a bull to finish — but we use 21 as "needs bull".
        }
        // Bull check: if currently waiting for bull (target = 21)
        if ($target[$slot] === 21 && $parsed['is_bull']) {
            $target[$slot] = 22; // 22 means "won"
            $finished = true; $winner_slot = $slot;
            break;
        }
    }

    $state = ['type' => 'aroundclock', 'format' => $game['format'], 'players' => []];
    foreach ($slots as $s) {
        $needs = $target[$s] <= 20 ? (string)$target[$s] : ($target[$s] === 21 ? 'BULL' : 'WON');
        $state['players'][$s] = ['needs' => $needs, 'progress' => min(20, $target[$s] - 1)];
    }
    $next = knk_darts_next_cursor($game, $players, $throws);
    return [
        'state' => $state,
        'next_slot' => $finished ? null : $next['slot'],
        'next_turn' => $next['turn'],
        'finished' => $finished,
        'winner_slot' => $winner_slot,
        'winner_team' => null,
    ];
}

/* ----- Killer ----- */

function knk_darts_eval_killer(array $game, array $players, array $throws, array $cfg): array {
    $lives_default = (int)($cfg['lives'] ?? 3);
    $slots = array_map(function ($p) { return (int)$p['slot_no']; }, $players);
    $slot_to_num = [];
    $num_to_slot = [];
    foreach ($players as $p) {
        $slot_to_num[(int)$p['slot_no']] = (int)$p['killer_number'];
        if ($p['killer_number'] !== null) $num_to_slot[(int)$p['killer_number']] = (int)$p['slot_no'];
    }
    $lives = [];
    $is_killer = [];
    $eliminated = [];
    foreach ($slots as $s) {
        $lives[$s] = $lives_default;
        $is_killer[$s] = false;
        $eliminated[$s] = false;
    }

    foreach ($throws as $t) {
        $parsed = knk_darts_parse_segment($t['segment']);
        if (!$parsed) continue;
        $slot = (int)$t['slot_no'];
        if ($eliminated[$slot]) continue;
        if (!$parsed['is_double']) continue;
        $base = $parsed['base'];
        if (!isset($num_to_slot[$base])) continue; // hit a double of a non-killer number
        $owner = $num_to_slot[$base];
        if ($owner === $slot) {
            // Hit your own double. Become a killer (if not already), or +1 life if you are.
            if (!$is_killer[$slot]) $is_killer[$slot] = true;
            else $lives[$slot] = min($lives_default + 2, $lives[$slot] + 1);
        } else {
            // Hit someone else's double. Only counts if you're already a killer.
            if (!$is_killer[$slot]) continue;
            if ($eliminated[$owner]) continue;
            $lives[$owner] = max(0, $lives[$owner] - 1);
            if ($lives[$owner] === 0) $eliminated[$owner] = true;
        }
    }

    // Last one standing.
    $alive = [];
    foreach ($slots as $s) if (!$eliminated[$s]) $alive[] = $s;
    $finished = false;
    $winner_slot = null;
    if (count($alive) === 1 && count($slots) > 1) {
        $finished = true;
        $winner_slot = $alive[0];
    }

    $state = ['type' => 'killer', 'format' => $game['format'], 'players' => []];
    foreach ($slots as $s) {
        $state['players'][$s] = [
            'number'     => $slot_to_num[$s],
            'lives'      => $lives[$s],
            'killer'     => $is_killer[$s],
            'eliminated' => $eliminated[$s],
        ];
    }
    $next = knk_darts_next_cursor($game, $players, $throws, $eliminated);
    return [
        'state' => $state,
        'next_slot' => $finished ? null : $next['slot'],
        'next_turn' => $next['turn'],
        'finished' => $finished,
        'winner_slot' => $winner_slot,
        'winner_team' => null,
    ];
}

/* ----- Halve-It ----- */

function knk_darts_halveit_match(array $parsed, string $rule): bool {
    if ($rule === 'any20')     return $parsed['base'] === 20;
    if ($rule === 'any16')     return $parsed['base'] === 16;
    if ($rule === 'any17')     return $parsed['base'] === 17;
    if ($rule === 'anydouble') return $parsed['is_double'];
    if ($rule === 'anytreble') return $parsed['is_treble'];
    if ($rule === 'anybull')   return $parsed['is_bull'];
    return false;
}

function knk_darts_eval_halveit(array $game, array $players, array $throws, array $cfg): array {
    $slots = array_map(function ($p) { return (int)$p['slot_no']; }, $players);
    $rounds = KNK_DARTS_HALVEIT_TARGETS;
    $score = [];
    foreach ($slots as $s) $score[$s] = 0;

    // Group by (slot, turn). The "round" for that turn is determined
    // by how many turns this slot has had so far.
    $turns_by_slot = []; // slot => list of turn arrays
    $groups = knk_darts_group_throws($throws);
    foreach ($groups as $g) {
        $turns_by_slot[$g['slot']][] = $g;
    }
    foreach ($slots as $s) {
        $list = $turns_by_slot[$s] ?? [];
        $running = 0;
        foreach ($list as $idx => $g) {
            if ($idx >= count($rounds)) break;
            $rule = $rounds[$idx]['rule'];
            $turn_score = 0;
            $any_hit = false;
            foreach ($g['darts'] as $t) {
                $parsed = knk_darts_parse_segment($t['segment']);
                if (!$parsed) continue;
                if (knk_darts_halveit_match($parsed, $rule)) {
                    $turn_score += $parsed['value'];
                    $any_hit = true;
                }
            }
            if ($any_hit) $running += $turn_score;
            else          $running = (int)floor($running / 2);
        }
        $score[$s] = $running;
    }

    // Game ends when every slot has played all 6 rounds.
    $rounds_done = [];
    foreach ($slots as $s) $rounds_done[$s] = count($turns_by_slot[$s] ?? []);
    $all_done = true;
    foreach ($slots as $s) if ($rounds_done[$s] < count($rounds)) { $all_done = false; break; }

    $finished = false;
    $winner_slot = null;
    if ($all_done) {
        $finished = true;
        $best = -1;
        foreach ($slots as $s) {
            if ($score[$s] > $best) { $best = $score[$s]; $winner_slot = $s; }
        }
    }

    $state = ['type' => 'halveit', 'format' => $game['format'], 'players' => [], 'targets' => $rounds];
    foreach ($slots as $s) {
        $state['players'][$s] = [
            'score' => $score[$s],
            'round' => min(count($rounds), ($rounds_done[$s] + 1)),
        ];
    }
    $next = knk_darts_next_cursor($game, $players, $throws);
    return [
        'state' => $state,
        'next_slot' => $finished ? null : $next['slot'],
        'next_turn' => $next['turn'],
        'finished' => $finished,
        'winner_slot' => $winner_slot,
        'winner_team' => null,
    ];
}

/* ==========================================================
 * TURN CURSOR
 *
 * Given the current throw log, work out whose turn comes next.
 * General rule: order is slot 1, 2, 3, 4, then turn += 1, slot 1...
 * Skip eliminated slots (Killer).
 * ======================================================== */

function knk_darts_next_cursor(array $game, array $players, array $throws, array $eliminated = []): array {
    $slots = array_map(function ($p) { return (int)$p['slot_no']; }, $players);
    if (empty($slots)) return ['slot' => 1, 'turn' => 1];

    if (empty($throws)) {
        return ['slot' => $slots[0], 'turn' => 1];
    }

    // Build the (turn, slot) of each finished or in-flight turn.
    $bins = []; // key: turn:slot => count
    foreach ($throws as $t) {
        $key = (int)$t['turn_no'] . ':' . (int)$t['slot_no'];
        if (!isset($bins[$key])) $bins[$key] = 0;
        $bins[$key]++;
    }

    // Find the most recent incomplete (less than 3 darts) turn-slot.
    // If the latest slot's count < 3, stay on it.
    $last = end($throws);
    $key = (int)$last['turn_no'] . ':' . (int)$last['slot_no'];
    if (($bins[$key] ?? 0) < 3) {
        return ['slot' => (int)$last['slot_no'], 'turn' => (int)$last['turn_no']];
    }

    // Otherwise advance to the next non-eliminated slot.
    $turn = (int)$last['turn_no'];
    $idx  = array_search((int)$last['slot_no'], $slots, true);
    if ($idx === false) $idx = -1;
    for ($step = 1; $step <= count($slots); $step++) {
        $next_idx = ($idx + $step) % count($slots);
        $next_slot = $slots[$next_idx];
        if (!empty($eliminated[$next_slot])) continue;
        if ($next_idx <= $idx) $turn++; // wrapped around
        return ['slot' => $next_slot, 'turn' => $turn];
    }
    // All eliminated except current — game's over upstream.
    return ['slot' => null, 'turn' => $turn];
}

/* ==========================================================
 * PUBLIC: BUILD STATE FOR THE CLIENT
 * ======================================================== */

/**
 * Returns the JSON-shape state the API + UI use:
 *   game, players, scoreboard (decoded state_json), my_slot (if token).
 */
function knk_darts_view_state(int $game_id, ?string $session_token = null): array {
    $st = knk_db()->prepare("SELECT * FROM darts_games WHERE id = ?");
    $st->execute([$game_id]);
    $game = $st->fetch();
    if (!$game) throw new RuntimeException("Game not found.");

    $players = knk_darts_load_players($game_id);
    $scoreboard = $game['state_json'] ? json_decode((string)$game['state_json'], true) : null;

    $my_slot = null;
    $my_player_id = null;
    $is_host = false;
    if ($session_token) {
        foreach ($players as $p) {
            if (hash_equals((string)$p['session_token'], $session_token)) {
                $my_slot = (int)$p['slot_no'];
                $my_player_id = (int)$p['id'];
                $is_host = !empty($p['is_host']);
                break;
            }
        }
    }

    // Throw count for current player's current turn (so the UI knows
    // dart 1 / 2 / 3).
    $current_dart_no = 0;
    if ($game['status'] === 'playing' && $game['current_slot_no']) {
        $st = knk_db()->prepare(
            "SELECT COUNT(*) FROM darts_throws
              WHERE game_id = ? AND turn_no = ? AND slot_no = ? AND voided = 0"
        );
        $st->execute([$game_id, $game['current_turn_no'], $game['current_slot_no']]);
        $current_dart_no = (int)$st->fetchColumn() + 1;
    }

    // Strip session_token from player list before exposing.
    $public_players = [];
    foreach ($players as $p) {
        $public_players[] = [
            'id'                 => (int)$p['id'],
            'slot_no'            => (int)$p['slot_no'],
            'name'               => $p['name'],
            'team_no'            => (int)$p['team_no'],
            'is_host'            => !empty($p['is_host']),
            'killer_number'      => $p['killer_number'] !== null ? (int)$p['killer_number'] : null,
            'finishing_position' => $p['finishing_position'] !== null ? (int)$p['finishing_position'] : null,
        ];
    }

    return [
        'game' => [
            'id'              => (int)$game['id'],
            'board_id'        => (int)$game['board_id'],
            'game_type'       => $game['game_type'],
            'format'          => $game['format'],
            'player_count'    => (int)$game['player_count'],
            'status'          => $game['status'],
            'host_slot_no'    => (int)$game['host_slot_no'],
            'join_code'       => $game['join_code'],
            'current_slot_no' => $game['current_slot_no'] !== null ? (int)$game['current_slot_no'] : null,
            'current_turn_no' => (int)$game['current_turn_no'],
            'current_dart_no' => $current_dart_no,
            'winner_slot_no'  => $game['winner_slot_no'] !== null ? (int)$game['winner_slot_no'] : null,
            'winner_team_no'  => $game['winner_team_no'] !== null ? (int)$game['winner_team_no'] : null,
        ],
        'players'    => $public_players,
        'scoreboard' => $scoreboard,
        'me'         => [
            'slot_no'   => $my_slot,
            'player_id' => $my_player_id,
            'is_host'   => $is_host,
        ],
    ];
}

function knk_darts_player_by_token(int $game_id, string $token): ?array {
    if ($token === '') return null;
    $st = knk_db()->prepare("SELECT * FROM darts_players WHERE game_id = ?");
    $st->execute([$game_id]);
    foreach ($st->fetchAll() as $p) {
        if (hash_equals((string)$p['session_token'], $token)) return $p;
    }
    return null;
}

function knk_darts_game_by_join_code(string $code): ?array {
    $st = knk_db()->prepare(
        "SELECT * FROM darts_games WHERE join_code = ? AND status IN ('lobby','playing') ORDER BY id DESC LIMIT 1"
    );
    $st->execute([strtoupper(trim($code))]);
    $row = $st->fetch();
    return $row ?: null;
}

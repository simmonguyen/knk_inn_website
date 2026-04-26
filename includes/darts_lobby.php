<?php
/*
 * KnK Inn — darts looking-for-opponent lobby store.
 *
 * Open-lobby model:
 *   1. Guest taps "I'm looking for an opponent" on bar.php?tab=darts.
 *      knk_darts_lobby_set_looking() upserts a row into darts_lobby
 *      with a 30-min expiry that the active tab keeps refreshing.
 *
 *   2. Other guests on the same tab see the lobby list (rendered by
 *      knk_darts_lobby_active_lookers) with a "Challenge" button per
 *      looker.
 *
 *   3. Tapping Challenge calls knk_darts_lobby_challenge_create() —
 *      inserts a pending darts_challenges row and fires a
 *      'darts_challenge' notification at the looker so their bar-shell
 *      avatar lights up the red-dot.
 *
 *   4. The looker accepts or declines via
 *      knk_darts_lobby_challenge_respond(). On accept we create a
 *      fresh 1v1 501 game on the first free board (or the looker's
 *      preferred board if free), with the LOOKER as host and the
 *      CHALLENGER joining. Both players are then redirected to that
 *      game id.
 *
 * Identity is the same lower-cased email used everywhere else in
 * the profile system. Anon emails (anon-…@anon.knkinn.com) are
 * first-class — bar guests can rally and challenge without claiming.
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/darts.php";
require_once __DIR__ . "/notifications_store.php";
require_once __DIR__ . "/profile_store.php";
require_once __DIR__ . "/guests_store.php";

/* =========================================================
   CONFIG
   ========================================================= */

if (!defined("KNK_DARTS_LOBBY_TTL_SEC")) {
    /** Lobby row lifetime (refreshed on each set_looking). */
    define("KNK_DARTS_LOBBY_TTL_SEC", 30 * 60);
}
if (!defined("KNK_DARTS_CHALLENGE_TTL_SEC")) {
    /** A pending challenge auto-expires after this many seconds. */
    define("KNK_DARTS_CHALLENGE_TTL_SEC", 5 * 60);
}

/* =========================================================
   LOBBY — set / clear / list
   ========================================================= */

/**
 * Add or refresh this guest's row in the darts lobby. Returns the
 * row's id on success, null on failure.
 *
 * board_pref is optional — pass an int board id if the looker wants
 * to play on a specific board, otherwise null = any board.
 *
 * Display name is snapshotted into the row so other guests see it
 * without a guests-table join. We resolve it via knk_profile_display_name_for
 * the same way the bar shell header does.
 */
function knk_darts_lobby_set_looking(string $email, ?int $board_pref = null): ?int {
    $email = strtolower(trim($email));
    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

    $row = knk_guest_find_by_email($email);
    $disp = knk_profile_display_name_for($email, $row);

    $expires = date("Y-m-d H:i:s", time() + KNK_DARTS_LOBBY_TTL_SEC);

    try {
        $pdo = knk_db();
        // Use INSERT … ON DUPLICATE KEY so refresh-on-poll is one
        // round-trip. uk_lobby_email (UNIQUE) drives the upsert.
        $pdo->prepare(
            "INSERT INTO darts_lobby (looker_email, display_name, board_pref, expires_at)
                  VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                  display_name = VALUES(display_name),
                  board_pref   = VALUES(board_pref),
                  expires_at   = VALUES(expires_at)"
        )->execute([$email, mb_substr($disp, 0, 60), $board_pref, $expires]);
        $st = $pdo->prepare("SELECT id FROM darts_lobby WHERE looker_email = ?");
        $st->execute([$email]);
        $id = (int)($st->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        error_log("knk_darts_lobby_set_looking: " . $e->getMessage());
        return null;
    }
}

/** Remove this guest from the lobby. Returns true if a row was deleted. */
function knk_darts_lobby_clear(string $email): bool {
    $email = strtolower(trim($email));
    if ($email === "") return false;
    try {
        $stmt = knk_db()->prepare("DELETE FROM darts_lobby WHERE looker_email = ?");
        $stmt->execute([$email]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log("knk_darts_lobby_clear: " . $e->getMessage());
        return false;
    }
}

/**
 * Active (non-expired) lookers, excluding $self_email. Newest-first.
 * Returns rows: [email, display_name, avatar_path, board_pref,
 * created_at, expires_at].
 */
function knk_darts_lobby_active_lookers(string $self_email = "", int $limit = 25): array {
    $self_email = strtolower(trim($self_email));
    $limit = max(1, min(100, $limit));
    try {
        $base = "SELECT l.looker_email AS email,
                        l.display_name,
                        l.board_pref,
                        l.created_at,
                        l.expires_at,
                        g.avatar_path
                   FROM darts_lobby l
              LEFT JOIN guests g ON g.email = l.looker_email
                  WHERE l.expires_at > NOW()";
        if ($self_email !== "") {
            $sql = $base . " AND l.looker_email <> ?
                          ORDER BY l.created_at DESC
                             LIMIT {$limit}";
            $stmt = knk_db()->prepare($sql);
            $stmt->execute([$self_email]);
        } else {
            $sql = $base . " ORDER BY l.created_at DESC LIMIT {$limit}";
            $stmt = knk_db()->prepare($sql);
            $stmt->execute();
        }
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log("knk_darts_lobby_active_lookers: " . $e->getMessage());
        return [];
    }
}

/** True if this email is currently in the lobby (and not expired). */
function knk_darts_lobby_is_looking(string $email): bool {
    $email = strtolower(trim($email));
    if ($email === "") return false;
    try {
        $stmt = knk_db()->prepare(
            "SELECT 1 FROM darts_lobby
              WHERE looker_email = ? AND expires_at > NOW()
              LIMIT 1"
        );
        $stmt->execute([$email]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/* =========================================================
   CHALLENGES
   ========================================================= */

/**
 * Fire a challenge from $challenger to $looker. Returns the new
 * challenge id on success, null on validation/db failure.
 *
 * Drops a 'darts_challenge' notification on the looker so the bar
 * shell avatar shows the red unread dot, and the challenge card on
 * /bar.php?tab=darts surfaces with Accept/Decline buttons.
 */
function knk_darts_lobby_challenge_create(string $challenger, string $looker): ?int {
    $challenger = strtolower(trim($challenger));
    $looker     = strtolower(trim($looker));
    if ($challenger === "" || $looker === "")            return null;
    if (!filter_var($challenger, FILTER_VALIDATE_EMAIL)) return null;
    if (!filter_var($looker, FILTER_VALIDATE_EMAIL))     return null;
    if ($challenger === $looker)                         return null;
    if (!knk_darts_lobby_is_looking($looker))            return null;

    try {
        $pdo = knk_db();
        // Cancel any prior pending challenges from the same
        // challenger to the same looker so a refresh-spam doesn't
        // pile up notifications.
        $pdo->prepare(
            "UPDATE darts_challenges
                SET status = 'cancelled', responded_at = NOW()
              WHERE challenger_email = ?
                AND looker_email = ?
                AND status = 'pending'"
        )->execute([$challenger, $looker]);

        $stmt = $pdo->prepare(
            "INSERT INTO darts_challenges (challenger_email, looker_email, status)
             VALUES (?, ?, 'pending')"
        );
        $stmt->execute([$challenger, $looker]);
        $id = (int)$pdo->lastInsertId();

        // Notification → red dot on the looker's bar-shell avatar.
        $row = knk_guest_find_by_email($challenger);
        $disp = knk_profile_display_name_for($challenger, $row);
        knk_notify($looker, "darts_challenge", $challenger, [
            "challenge_id"  => $id,
            "display_name"  => (string)$disp,
        ]);
        return $id;
    } catch (Throwable $e) {
        error_log("knk_darts_lobby_challenge_create: " . $e->getMessage());
        return null;
    }
}

/**
 * Pending incoming challenges for $email (the looker side). Returns
 * rows: [id, challenger_email, challenger_display, challenger_avatar,
 * created_at]. Newest first.
 */
function knk_darts_lobby_pending_challenges(string $email, int $limit = 5): array {
    $email = strtolower(trim($email));
    if ($email === "") return [];
    $limit = max(1, min(20, $limit));
    $cutoff = date("Y-m-d H:i:s", time() - KNK_DARTS_CHALLENGE_TTL_SEC);
    try {
        $stmt = knk_db()->prepare(
            "SELECT c.id,
                    c.challenger_email,
                    c.created_at,
                    g.display_name AS challenger_display,
                    g.avatar_path  AS challenger_avatar
               FROM darts_challenges c
          LEFT JOIN guests g ON g.email = c.challenger_email
              WHERE c.looker_email = ?
                AND c.status = 'pending'
                AND c.created_at >= ?
           ORDER BY c.created_at DESC
              LIMIT {$limit}"
        );
        $stmt->execute([$email, $cutoff]);
        $rows = $stmt->fetchAll();
        // Resolve display name with the same helper the rest of the
        // profile system uses so anon-XXXX guests render as "Guest XXXX".
        foreach ($rows as &$r) {
            if (empty($r["challenger_display"])) {
                $gr = knk_guest_find_by_email((string)$r["challenger_email"]);
                $r["challenger_display"] = knk_profile_display_name_for(
                    (string)$r["challenger_email"], $gr
                );
            }
        }
        unset($r);
        return $rows;
    } catch (Throwable $e) {
        error_log("knk_darts_lobby_pending_challenges: " . $e->getMessage());
        return [];
    }
}

/**
 * Looker accepts / declines a challenge. Returns
 *   ["ok" => bool, "game_id" => ?int, "error" => ?string]
 *
 * On accept: we pick a free board (looker's board_pref if set and
 * free, otherwise the first enabled+free board), create a 1v1 501
 * game with the LOOKER as host, then add the CHALLENGER as the
 * second player. Both look_email and challenger_email are written
 * onto darts_players.guest_email so the profile feed sees the game.
 *
 * On decline: status flips to 'declined' and that's it.
 */
function knk_darts_lobby_challenge_respond(int $challenge_id, string $email, bool $accept): array {
    $email = strtolower(trim($email));
    if ($challenge_id <= 0 || $email === "") {
        return ["ok" => false, "game_id" => null, "error" => "Bad request."];
    }
    try {
        $pdo = knk_db();
        $stmt = $pdo->prepare(
            "SELECT * FROM darts_challenges
              WHERE id = ? AND looker_email = ? AND status = 'pending'
              LIMIT 1"
        );
        $stmt->execute([$challenge_id, $email]);
        $c = $stmt->fetch();
        if (!$c) {
            return ["ok" => false, "game_id" => null, "error" => "That challenge isn't pending anymore."];
        }

        if (!$accept) {
            $pdo->prepare(
                "UPDATE darts_challenges
                    SET status = 'declined', responded_at = NOW()
                  WHERE id = ?"
            )->execute([$challenge_id]);
            return ["ok" => true, "game_id" => null, "error" => null];
        }

        // ----- ACCEPT — create a 1v1 501 game -----
        $challenger = (string)$c["challenger_email"];

        // Pick a board. Prefer the looker's board_pref if it's free.
        $pref_stmt = $pdo->prepare(
            "SELECT board_pref FROM darts_lobby WHERE looker_email = ? LIMIT 1"
        );
        $pref_stmt->execute([$email]);
        $pref_id = (int)($pref_stmt->fetchColumn() ?: 0);

        $chosen_board = 0;
        $boards = knk_darts_boards();
        if ($pref_id > 0) {
            foreach ($boards as $b) {
                if ((int)$b["id"] === $pref_id
                    && !empty($b["enabled"])
                    && knk_darts_active_game_on_board((int)$b["id"]) === null) {
                    $chosen_board = $pref_id;
                    break;
                }
            }
        }
        if ($chosen_board === 0) {
            foreach ($boards as $b) {
                if (!empty($b["enabled"])
                    && knk_darts_active_game_on_board((int)$b["id"]) === null) {
                    $chosen_board = (int)$b["id"];
                    break;
                }
            }
        }
        if ($chosen_board === 0) {
            return [
                "ok" => false, "game_id" => null,
                "error" => "All boards are busy right now.",
            ];
        }

        // Resolve display names so the players show with their
        // chosen profile names, not just email-locals.
        $looker_row     = knk_guest_find_by_email($email);
        $looker_disp    = knk_profile_display_name_for($email, $looker_row);
        $chal_row       = knk_guest_find_by_email($challenger);
        $chal_disp      = knk_profile_display_name_for($challenger, $chal_row);

        // Create the game with the LOOKER as host AND the CHALLENGER as
        // player 2 in a single transaction. We don't use the regular
        // knk_darts_create_game + knk_darts_join_game pair because that
        // splits the work across two separate transactions; the second
        // transaction's slot-finding SELECT was occasionally not seeing
        // the host row committed by the first (suspected stacked-cursor
        // weirdness on Matbao's MariaDB), which surfaced as a bogus
        // SQLSTATE 1062 'Duplicate entry "X-1"' when join_game tried to
        // insert player 2 into slot 1.
        $game_id = 0;
        try {
            $game_id = knk_darts_lobby_create_2p_game(
                $chosen_board, $looker_disp, $email, $chal_disp, $challenger
            );
        } catch (Throwable $e) {
            // Roll back the game so the board frees up. The single
            // transaction above auto-rolls-back on throw — this is just
            // a safety net for any rows that might've leaked through
            // (e.g. if the engine stored partial state in another table
            // before the failure).
            if ($game_id > 0) {
                try {
                    $pdo->prepare("DELETE FROM darts_players WHERE game_id = ?")
                        ->execute([$game_id]);
                    $pdo->prepare("DELETE FROM darts_games WHERE id = ?")
                        ->execute([$game_id]);
                } catch (Throwable $cleanup) {
                    error_log("knk_darts_lobby_challenge_respond/cleanup: "
                            . $cleanup->getMessage());
                }
            }
            error_log("knk_darts_lobby_challenge_respond/create_2p: "
                    . $e->getMessage());
            return [
                "ok"      => false,
                "game_id" => null,
                "error"   => "Couldn't start the game — try again. "
                           . "(" . $e->getMessage() . ")",
            ];
        }

        // Mark the challenge accepted with the new game_id.
        $pdo->prepare(
            "UPDATE darts_challenges
                SET status = 'accepted', responded_at = NOW(), game_id = ?
              WHERE id = ?"
        )->execute([$game_id, $challenge_id]);

        // Both players leave the lobby — neither is "looking" anymore.
        knk_darts_lobby_clear($email);
        knk_darts_lobby_clear($challenger);

        return ["ok" => true, "game_id" => $game_id, "error" => null];
    } catch (Throwable $e) {
        error_log("knk_darts_lobby_challenge_respond: " . $e->getMessage());
        return ["ok" => false, "game_id" => null, "error" => $e->getMessage()];
    }
}

/* =========================================================
   ATOMIC 2-PLAYER GAME CREATION (lobby challenge accept path)
   ========================================================= */

/**
 * Insert a fresh 1v1 501 singles game with BOTH players in a single
 * transaction. Used by the lobby's challenge-accept flow to avoid
 * the split-transaction race where knk_darts_join_game's slot-finding
 * SELECT didn't always see the host row committed by an earlier
 * knk_darts_create_game call on the same connection.
 *
 * Returns the new game_id.  Throws on any failure; caller is
 * expected to surface the error to the looker's UI.
 */
function knk_darts_lobby_create_2p_game(
    int $board_id,
    string $host_name, string $host_email,
    string $p2_name,   string $p2_email
): int {
    $host_name = mb_substr(trim($host_name) ?: 'Host',  0, 40);
    $p2_name   = mb_substr(trim($p2_name)   ?: 'Guest', 0, 40);

    $host_email = strtolower(trim($host_email));
    $p2_email   = strtolower(trim($p2_email));
    if (!filter_var($host_email, FILTER_VALIDATE_EMAIL)) $host_email = '';
    if (!filter_var($p2_email,   FILTER_VALIDATE_EMAIL)) $p2_email   = '';

    /* Drain any lingering cursors before we start a new transaction
     * so the upcoming INSERTs get a clean connection state. */
    knk_darts_cleanup_stale();
    if (knk_darts_active_game_on_board($board_id) !== null) {
        throw new RuntimeException("That board already has an active game.");
    }

    $code = knk_darts_make_join_code();
    $cfg  = knk_darts_default_config('501');
    $st   = knk_darts_default_state('501', 2, 'singles');

    $pdo = knk_db();
    $pdo->beginTransaction();
    try {
        // 1. Game row.
        $pdo->prepare(
            "INSERT INTO darts_games
                (board_id, game_type, format, player_count, status,
                 host_slot_no, join_code, config, state_json)
             VALUES (?, '501', 'singles', 2, 'lobby', 1, ?, ?, ?)"
        )->execute([
            $board_id, $code,
            json_encode($cfg, JSON_UNESCAPED_UNICODE),
            json_encode($st,  JSON_UNESCAPED_UNICODE),
        ]);
        $game_id = (int)$pdo->lastInsertId();

        // 2. Host (slot 1).
        $hostTok = bin2hex(random_bytes(20));
        $pdo->prepare(
            "INSERT INTO darts_players
                (game_id, slot_no, name, team_no, is_host,
                 session_token, guest_email)
             VALUES (?, 1, ?, 0, 1, ?, ?)"
        )->execute([
            $game_id, $host_name, $hostTok,
            mb_substr($host_email, 0, 190),
        ]);

        // 3. Player 2 (slot 2).
        $p2Tok = bin2hex(random_bytes(20));
        $pdo->prepare(
            "INSERT INTO darts_players
                (game_id, slot_no, name, team_no, is_host,
                 session_token, guest_email)
             VALUES (?, 2, ?, 0, 0, ?, ?)"
        )->execute([
            $game_id, $p2_name, $p2Tok,
            mb_substr($p2_email, 0, 190),
        ]);

        $pdo->commit();
        return $game_id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* =========================================================
   RECENT GAMES — for the "history" panel under the board grid
   ========================================================= */

/**
 * Recently-finished darts games across all boards. Returns rows
 * with: game_id, game_type, format, board_name, finished_at,
 * winner_name, runner_up_name, players_csv. Newest first.
 *
 * Used by the pick_board view to give the bar a "what's been
 * happening on the boards" feel. Last 10 by default.
 */
function knk_darts_recent_games_compact(int $limit = 10): array {
    $limit = max(1, min(50, $limit));
    try {
        $sql = "SELECT g.id           AS game_id,
                       g.game_type,
                       g.format,
                       g.finished_at,
                       g.winner_slot_no,
                       g.winner_team_no,
                       b.name         AS board_name
                  FROM darts_games g
             LEFT JOIN darts_boards b ON b.id = g.board_id
                 WHERE g.status = 'finished'
                   AND g.finished_at IS NOT NULL
              ORDER BY g.finished_at DESC
                 LIMIT {$limit}";
        $stmt = knk_db()->query($sql);
        $games = $stmt->fetchAll();
        if (empty($games)) return [];

        // Pull all the players for these games in one round-trip.
        $game_ids = array_map(function ($g) { return (int)$g["game_id"]; }, $games);
        $place    = implode(",", array_fill(0, count($game_ids), "?"));
        $pStmt = knk_db()->prepare(
            "SELECT game_id, slot_no, team_no, name, finishing_position
               FROM darts_players
              WHERE game_id IN ({$place})
           ORDER BY game_id, slot_no"
        );
        $pStmt->execute($game_ids);
        $by_game = [];
        while ($r = $pStmt->fetch()) {
            $by_game[(int)$r["game_id"]][] = $r;
        }

        $out = [];
        foreach ($games as $g) {
            $players = $by_game[(int)$g["game_id"]] ?? [];
            $names = [];
            $winner = "";
            $runner = "";
            foreach ($players as $p) {
                $names[] = (string)$p["name"];
                if ((int)$p["slot_no"] === (int)$g["winner_slot_no"]) {
                    $winner = (string)$p["name"];
                }
                if ((int)$p["finishing_position"] === 2) {
                    $runner = (string)$p["name"];
                }
            }
            $out[] = [
                "game_id"      => (int)$g["game_id"],
                "game_type"    => (string)$g["game_type"],
                "format"       => (string)$g["format"],
                "board_name"   => (string)($g["board_name"] ?? ""),
                "finished_at"  => (string)$g["finished_at"],
                "finished_ts"  => $g["finished_at"] ? strtotime((string)$g["finished_at"]) : 0,
                "winner_name"  => $winner,
                "runner_name"  => $runner,
                "players"      => $names,
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log("knk_darts_recent_games_compact: " . $e->getMessage());
        return [];
    }
}

/* =========================================================
   CLAIM-FLOW HOOK — re-key emails
   ========================================================= */

/**
 * Rewrite every lobby + challenges row that pointed at $from so it
 * now points at $to. Used by knk_profile_apply_claim() when an anon
 * profile is promoted to a real email.
 *
 * Lobby has UNIQUE(looker_email) so we pre-delete any conflict rows
 * before the bulk UPDATE. Challenges are append-only and don't have
 * cross-row uniqueness, so the UPDATEs are safe as-is.
 */
function knk_darts_lobby_rekey_email(string $from, string $to): void {
    $from = strtolower(trim($from));
    $to   = strtolower(trim($to));
    if ($from === "" || $to === "" || $from === $to) return;
    try {
        $pdo = knk_db();
        // If the merged guest is in the lobby under both emails,
        // keep the $to row and drop the $from row.
        $pdo->prepare(
            "DELETE l1 FROM darts_lobby l1
              JOIN darts_lobby l2 ON l2.looker_email = ?
             WHERE l1.looker_email = ?"
        )->execute([$to, $from]);
        $pdo->prepare("UPDATE darts_lobby SET looker_email = ? WHERE looker_email = ?")
            ->execute([$to, $from]);

        $pdo->prepare("UPDATE darts_challenges SET challenger_email = ? WHERE challenger_email = ?")
            ->execute([$to, $from]);
        $pdo->prepare("UPDATE darts_challenges SET looker_email = ? WHERE looker_email = ?")
            ->execute([$to, $from]);
    } catch (Throwable $e) {
        error_log("knk_darts_lobby_rekey_email: " . $e->getMessage());
    }
}

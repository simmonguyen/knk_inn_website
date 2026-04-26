<?php
/*
 * KnK Inn — darts stats helpers.
 *
 * Read-only aggregations powering the three "what's been going on
 * at the boards" panels on /bar.php?tab=darts pick_board view:
 *
 *   - knk_darts_top_rounds_this_week()
 *       Best 3-dart turns over the last 7 days, x01 only.
 *
 *   - knk_darts_leaderboard()
 *       Top win-rate players (min 3 games) over all finished games.
 *
 *   - knk_darts_game_type_distribution()
 *       Game type → game count, used to render the pie chart.
 *
 * No schema changes — pure SELECTs over the existing darts_games /
 * darts_players / darts_throws tables.
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";

/**
 * Top N highest-scoring 3-dart rounds in the last 7 days, across
 * 501 and 301 games (cricket / around-clock / killer / halve-it
 * don't have a "round score" in the same sense). Returns rows with:
 *   round_total, player_name, game_id, game_type, finished_at
 *
 * Filters out voided throws + any incomplete turns (<3 darts).
 * "Last 7 days" measured against the GAME's created_at so a round
 * thrown last Tuesday in a still-open game still counts.
 */
function knk_darts_top_rounds_this_week(int $limit = 5): array {
    $limit = max(1, min(20, $limit));
    try {
        $stmt = knk_db()->prepare(
            "SELECT t.game_id,
                    t.slot_no,
                    t.turn_no,
                    SUM(t.value) AS round_total,
                    g.game_type,
                    g.finished_at,
                    p.name AS player_name
               FROM darts_throws t
               JOIN darts_games g ON g.id = t.game_id
               JOIN darts_players p
                 ON p.game_id = t.game_id AND p.slot_no = t.slot_no
              WHERE t.voided = 0
                AND g.game_type IN ('501','301')
                AND g.created_at >= (NOW() - INTERVAL 7 DAY)
              GROUP BY t.game_id, t.slot_no, t.turn_no,
                       g.game_type, g.finished_at, p.name
             HAVING COUNT(*) = 3
              ORDER BY round_total DESC, t.game_id DESC
              LIMIT {$limit}"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log("knk_darts_top_rounds_this_week: " . $e->getMessage());
        return [];
    }
}

/**
 * Top players by win-rate, minimum $min_games to keep one-and-done
 * 100%-ers from gaming the board. Returns rows with:
 *   email (the guest_email key we group by — can be anon-…@…),
 *   display_name (the most-recent name they played under),
 *   games, wins, losses, win_rate (0..100, rounded int).
 *
 * A player wins a game when:
 *   - winner_slot_no equals their slot, OR
 *   - winner_team_no equals their team_no (doubles)
 *
 * We only count finished games. Players without a guest_email
 * (older test rows pre-Phase 1 profile work) are excluded — without
 * a stable id we can't aggregate them safely.
 */
function knk_darts_leaderboard(int $limit = 10, int $min_games = 3): array {
    $limit     = max(1, min(50, $limit));
    $min_games = max(1, min(20, $min_games));
    try {
        $stmt = knk_db()->prepare(
            "SELECT p.guest_email AS email,
                    SUBSTRING_INDEX(GROUP_CONCAT(p.name ORDER BY p.id DESC SEPARATOR '\\n'), '\\n', 1) AS display_name,
                    COUNT(*) AS games,
                    SUM(
                      CASE
                        WHEN g.winner_slot_no IS NOT NULL AND g.winner_slot_no = p.slot_no THEN 1
                        WHEN g.winner_team_no IS NOT NULL AND g.winner_team_no = p.team_no THEN 1
                        ELSE 0
                      END
                    ) AS wins
               FROM darts_players p
               JOIN darts_games g ON g.id = p.game_id
              WHERE g.status = 'finished'
                AND p.guest_email <> ''
              GROUP BY p.guest_email
             HAVING games >= ?
              ORDER BY (wins / games) DESC, wins DESC, games DESC
              LIMIT {$limit}"
        );
        $stmt->execute([$min_games]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $games = (int)$r["games"];
            $wins  = (int)$r["wins"];
            $r["games"]    = $games;
            $r["wins"]     = $wins;
            $r["losses"]   = max(0, $games - $wins);
            $r["win_rate"] = $games > 0 ? (int)round(($wins / $games) * 100) : 0;
        }
        unset($r);
        return $rows;
    } catch (Throwable $e) {
        error_log("knk_darts_leaderboard: " . $e->getMessage());
        return [];
    }
}

/**
 * How many games of each type have been started (any status).
 * Returns rows: [game_type, n], sorted by count desc. Empty array
 * if no games yet.
 */
function knk_darts_game_type_distribution(): array {
    try {
        $stmt = knk_db()->prepare(
            "SELECT game_type, COUNT(*) AS n
               FROM darts_games
              GROUP BY game_type
              ORDER BY n DESC"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r["n"] = (int)$r["n"];
        }
        unset($r);
        return $rows;
    } catch (Throwable $e) {
        error_log("knk_darts_game_type_distribution: " . $e->getMessage());
        return [];
    }
}

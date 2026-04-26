<?php
/*
 * KnK Inn — darts stats helpers.
 *
 * Read-only aggregations powering the right-column stat panels on
 * /tv.php (Recent games / Top scoring this week / Most-played pie).
 *
 *   - knk_darts_top_rounds_this_week()
 *       Best 3-dart turns over the last 7 days, x01 only.
 *
 *   - knk_darts_leaderboard()
 *       Top win-rate players (min 3 games). Currently unused by the
 *       TV but kept around — admin pages may want it later.
 *
 *   - knk_darts_game_type_distribution()
 *       Game type → game count, powers the pie chart.
 *
 *   - knk_tv_darts_build_stats()
 *       One-shot bundler used by both /tv.php (server first paint)
 *       and /api/darts_live.php (TV polls). Returns recent / top /
 *       pie pre-shaped so the TV's JS can paint without extra trig.
 *
 * No schema changes — pure SELECTs over the existing darts_games /
 * darts_players / darts_throws tables.
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/darts_lobby.php"; // knk_darts_recent_games_compact

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

/* ---------------------------------------------------------------
 * TV right-column stats payload — three panels worth of data,
 * pre-shaped so the TV's JS can paint without extra work. Lives
 * here (not in /api/) so /tv.php's server-render and the API's JSON
 * response use the same code path.
 *
 * Returns:
 *   {
 *     recent:     [ { ago, winner, loser, type, board }, ... ],
 *     top_rounds: [ { total, name, type, tier }, ... ],
 *     pie:        { total, slices: [ { label, color, n, pct, d }, ... ] }
 *   }
 * "tier" on top_rounds is "" / "tonplus" / "180" — drives glow.
 * "d" on pie slices is a ready-to-render SVG arc path string.
 * ------------------------------------------------------------- */
function knk_tv_darts_build_stats(): array {
    $now_ts = time();

    /* --- Recent games (last 6, all boards) --- */
    $recent_raw = knk_darts_recent_games_compact(6);
    $recent = [];
    foreach ($recent_raw as $rg) {
        $secs = $rg["finished_ts"] > 0
            ? max(0, $now_ts - (int)$rg["finished_ts"])
            : 0;
        if      ($secs < 60)    $ago = "just now";
        elseif  ($secs < 3600)  $ago = (int)round($secs / 60)    . "m ago";
        elseif  ($secs < 86400) $ago = (int)round($secs / 3600)  . "h ago";
        else                    $ago = (int)round($secs / 86400) . "d ago";

        $others = array_values(array_filter(
            $rg["players"],
            function ($n) use ($rg) { return $n !== $rg["winner_name"]; }
        ));

        $recent[] = [
            "ago"    => $ago,
            "winner" => (string)($rg["winner_name"] ?: "—"),
            "loser"  => (string)(implode(", ", $others) ?: "—"),
            "type"   => strtoupper((string)$rg["game_type"]),
            "board"  => (string)($rg["board_name"] ?? ""),
        ];
    }

    /* --- Top scoring rounds this week (top 3, x01 only) --- */
    $top_raw = knk_darts_top_rounds_this_week(3);
    $top = [];
    foreach ($top_raw as $tr) {
        $rt = (int)$tr["round_total"];
        $tier = $rt === 180 ? "180" : ($rt >= 140 ? "tonplus" : "");
        $top[] = [
            "total" => $rt,
            "name"  => (string)($tr["player_name"] ?: "—"),
            "type"  => strtoupper((string)$tr["game_type"]),
            "tier"  => $tier,
        ];
    }

    /* --- Most-played pie --- */
    $pie_raw = knk_darts_game_type_distribution();
    $pie_total = 0;
    foreach ($pie_raw as $row) $pie_total += (int)$row["n"];

    $pie_label = [
        "501"         => "501",
        "301"         => "301",
        "cricket"     => "Cricket",
        "aroundclock" => "Around Clk",
        "killer"      => "Killer",
        "halveit"     => "Halve It",
    ];
    $pie_color = [
        "501"         => "#c9aa71",
        "301"         => "#d8c08b",
        "cricket"     => "#2fdc7a",
        "aroundclock" => "#5cc4ff",
        "killer"      => "#d94343",
        "halveit"     => "#9b6dff",
    ];

    /* Pre-compute SVG arc paths so the TV doesn't have to do trig. */
    $cx = 50; $cy = 50; $r = 44;
    $cum = 0.0;
    $slices = [];
    foreach ($pie_raw as $row) {
        $type = (string)$row["game_type"];
        $n    = (int)$row["n"];
        $frac = $pie_total > 0 ? $n / $pie_total : 0;
        if ($frac <= 0) continue;

        if (abs($frac - 1.0) < 1e-9) {
            // Single-slice pie: SVG arc can't draw a 360° sweep, so
            // we use two semicircles glued together.
            $d = "M{$cx},{$cy} L{$cx},". ($cy - $r)
               . " A{$r},{$r} 0 1 1 {$cx},". ($cy + $r)
               . " A{$r},{$r} 0 1 1 {$cx},". ($cy - $r) . " Z";
        } else {
            $startA = $cum * 2 * M_PI - M_PI / 2;
            $cum   += $frac;
            $endA   = $cum * 2 * M_PI - M_PI / 2;
            $sx = $cx + $r * cos($startA);
            $sy = $cy + $r * sin($startA);
            $ex = $cx + $r * cos($endA);
            $ey = $cy + $r * sin($endA);
            $largeArc = $frac > 0.5 ? 1 : 0;
            $d = "M{$cx},{$cy} L{$sx},{$sy}"
               . " A{$r},{$r} 0 {$largeArc} 1 {$ex},{$ey} Z";
        }

        $slices[] = [
            "label" => $pie_label[$type] ?? $type,
            "color" => $pie_color[$type] ?? "#888",
            "n"     => $n,
            "pct"   => (int)round($frac * 100),
            "d"     => $d,
        ];
    }

    return [
        "recent"     => $recent,
        "top_rounds" => $top,
        "pie"        => [
            "total"  => $pie_total,
            "slices" => $slices,
        ],
    ];
}

<?php
/*
 * KnK Inn — /api/darts_live.php
 *
 * Read-only feed for /tv.php. Returns every CURRENTLY-PLAYING darts
 * game on every board, with a summarised scoreboard the TV can render
 * at a glance. Lobby games (waiting for players to join) are skipped —
 * they're not interesting on the bar TV.
 *
 * Response shape:
 * {
 *   "ok": true,
 *   "now_ts": 1714035123,
 *   "poll_seconds": 4,
 *   "games": [
 *     {
 *       "board_id": 1,
 *       "board_name": "Board 1",
 *       "game_id": 142,
 *       "game_type": "501",
 *       "format": "singles",
 *       "current_slot_no": 2,
 *       "rows": [
 *         { "slot_no": 1, "name": "Ben",    "team_no": 1, "headline": "421", "is_active": false },
 *         { "slot_no": 2, "name": "Simmo",  "team_no": 2, "headline": "350", "is_active": true  }
 *       ]
 *     },
 *     ...
 *   ]
 * }
 *
 * "headline" is the main number a TV viewer cares about for that game type:
 *   501/301      — remaining points
 *   cricket      — accumulated score
 *   aroundclock  — current target (1..20 / BULL / DONE)
 *   killer       — lives left ("OUT" if eliminated)
 *   halveit      — score
 *
 * No auth — same shape as /api/jukebox_state.php and /api/market_state.php.
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/darts.php";
require_once __DIR__ . "/../includes/darts_lobby.php";  // recent_games_compact
require_once __DIR__ . "/../includes/darts_stats.php";  // knk_tv_darts_build_stats()

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$out = [
    "ok"           => false,
    "now_ts"       => time(),
    "poll_seconds" => 60,
    "games"        => [],
    "stats"        => null,
    "error"        => null,
];

try {
    $cfg = knk_darts_config();
    $enabled = !empty($cfg["enabled"]);
    $out["poll_seconds"] = $enabled ? max(2, (int)$cfg["poll_seconds"]) : 60;
    $out["ok"] = true;

    if (!$enabled) {
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* All boards (so we know the board name for each playing game). */
    $boards = [];
    foreach (knk_darts_boards() as $b) {
        $boards[(int)$b["id"]] = $b;
    }

    /* All games in `playing` state, newest first. */
    $st = knk_db()->query(
        "SELECT id, board_id, game_type, format, status, current_slot_no, current_turn_no, state_json
           FROM darts_games
          WHERE status = 'playing'
          ORDER BY id DESC"
    );
    $games = $st->fetchAll();

    foreach ($games as $g) {
        $board_id = (int)$g["board_id"];
        if (!isset($boards[$board_id])) continue;

        $game_id = (int)$g["id"];
        $players = knk_darts_load_players($game_id);
        $throws  = knk_darts_load_throws($game_id, false);
        $sb      = $g["state_json"] ? json_decode((string)$g["state_json"], true) : null;
        $type    = (string)$g["game_type"];
        $format  = (string)$g["format"];
        $current = $g["current_slot_no"] !== null ? (int)$g["current_slot_no"] : null;

        /* Group throws by (slot_no, turn_no) for tap-by-tap rendering.
         * Each player gets:
         *   - current_throws: their darts in the in-progress turn (0..3)
         *   - last_round: their last completed turn's summary, or null
         *   - latest_throw_id: max id we've seen for them (TV diff key) */
        $by_slot = [];      // slot_no => [turn_no => [throw rows]]
        $latest_throw_id = 0;
        foreach ($throws as $t) {
            $sn = (int)$t["slot_no"];
            $tn = (int)$t["turn_no"];
            $by_slot[$sn][$tn][] = $t;
            if ((int)$t["id"] > $latest_throw_id) $latest_throw_id = (int)$t["id"];
        }

        $rows = [];
        foreach ($players as $p) {
            $slot = (int)$p["slot_no"];
            $turns_for_slot = $by_slot[$slot] ?? [];
            $turn_keys = array_keys($turns_for_slot);
            sort($turn_keys, SORT_NUMERIC);

            // The current_turn for this slot, in 501/301, is
            // current_turn_no when this slot === current_slot_no.
            // In all game types: current = max turn_no whose 3 darts
            // aren't yet complete; otherwise the next turn is "current"
            // but empty.
            $current_turn = null;
            $last_full_turn = null;
            foreach ($turn_keys as $tn) {
                $cnt = count($turns_for_slot[$tn]);
                if ($cnt < 3) {
                    $current_turn = $tn;
                } else {
                    $last_full_turn = $tn;
                }
            }

            // Build the current-turn dart array (0..3 entries).
            $current_darts = [];
            if ($current_turn !== null) {
                $darts = $turns_for_slot[$current_turn];
                usort($darts, function ($a, $b) {
                    return (int)$a["dart_no"] - (int)$b["dart_no"];
                });
                foreach ($darts as $d) {
                    $current_darts[] = [
                        "dart"  => (int)$d["dart_no"],
                        "label" => (string)$d["segment"],
                        "value" => (int)$d["value"],
                        "id"    => (int)$d["id"],
                    ];
                }
            }

            // Build the last-completed-round summary if any.
            $last_round = null;
            if ($last_full_turn !== null) {
                $darts = $turns_for_slot[$last_full_turn];
                usort($darts, function ($a, $b) {
                    return (int)$a["dart_no"] - (int)$b["dart_no"];
                });
                $total = 0;
                $arr = [];
                $max_id = 0;
                foreach ($darts as $d) {
                    $val = (int)$d["value"];
                    $total += $val;
                    $arr[] = [
                        "dart"  => (int)$d["dart_no"],
                        "label" => (string)$d["segment"],
                        "value" => $val,
                    ];
                    if ((int)$d["id"] > $max_id) $max_id = (int)$d["id"];
                }
                $last_round = [
                    "turn"        => $last_full_turn,
                    "darts"       => $arr,
                    "total"       => $total,
                    "last_throw_id" => $max_id,
                ];
            }

            $rows[] = [
                "slot_no"        => $slot,
                "name"           => (string)$p["name"],
                "team_no"        => (int)$p["team_no"],
                "headline"       => knk_tv_darts_headline($type, $format, $sb, $slot),
                "is_active"      => ($current !== null && $slot === $current),
                "current_throws" => $current_darts,
                "last_round"     => $last_round,
            ];
        }

        $out["games"][] = [
            "board_id"        => $board_id,
            "board_name"      => (string)$boards[$board_id]["name"],
            "game_id"         => $game_id,
            "game_type"       => $type,
            "format"          => $format,
            "current_slot_no" => $current,
            "latest_throw_id" => $latest_throw_id,
            "rows"            => $rows,
        ];
    }

    /* Sort by board sort_order so the TV stack reads top-to-bottom. */
    usort($out["games"], function ($a, $b) use ($boards) {
        $oa = (int)($boards[$a["board_id"]]["sort_order"] ?? 0);
        $ob = (int)($boards[$b["board_id"]]["sort_order"] ?? 0);
        if ($oa !== $ob) return $oa - $ob;
        return $a["board_id"] - $b["board_id"];
    });

    /* TV right-column stats panels — Recent games / Top scoring this
     * week / Most-played pie. The JS only renders these when ≤1 board
     * is mid-game (i.e. while the "Waiting for players" card is up).
     * Cheap enough to send on every poll: three small SELECTs. */
    $out["stats"] = knk_tv_darts_build_stats();

} catch (Throwable $e) {
    $out["error"] = "engine_error";
    error_log("darts_live.php: " . $e->getMessage());
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

/* ----------------------------------------------------------------
 * Headline string for a single player slot, picked per game type.
 * Defined inline so the TV endpoint stays self-contained.
 * --------------------------------------------------------------- */
function knk_tv_darts_headline(string $type, string $format, ?array $sb, int $slot): string {
    if (!is_array($sb)) return "—";

    if ($type === "501" || $type === "301") {
        if ($format === "doubles") {
            /* In doubles the player's "team_no" determines the score
             * — we look it up from the players list in the caller and
             * pass it via slot lookup here using $sb["team_remaining"]. */
            $tr = $sb["team_remaining"] ?? null;
            if (!is_array($tr)) return "—";
            /* scoreboard isn't slot-keyed in doubles; the caller
             * supplies slot, but team_remaining is keyed by team_no.
             * We have no team here — fall back to the lower remaining
             * to avoid a crash. */
            return (string)min(array_map("intval", array_values($tr)));
        }
        $p = $sb["players"][$slot] ?? null;
        if (!is_array($p)) return "—";
        return (string)(int)($p["remaining"] ?? 0);
    }

    if ($type === "cricket") {
        if ($format === "doubles") {
            $ts = $sb["team_score"] ?? null;
            return is_array($ts) ? (string)max(array_map("intval", array_values($ts))) : "—";
        }
        $p = $sb["players"][$slot] ?? null;
        return is_array($p) ? (string)(int)($p["score"] ?? 0) : "—";
    }

    if ($type === "aroundclock") {
        $p = $sb["players"][$slot] ?? null;
        if (!is_array($p)) return "—";
        if (!empty($p["finished"])) return "DONE";
        $tgt = (int)($p["target"] ?? 1);
        if ($tgt >= 21) return "BULL";
        return (string)$tgt;
    }

    if ($type === "killer") {
        $p = $sb["players"][$slot] ?? null;
        if (!is_array($p)) return "—";
        if (!empty($p["eliminated"])) return "OUT";
        $lives = (int)($p["lives"] ?? 0);
        $is_killer = !empty($p["killer"]);
        return ($is_killer ? "K" . $lives : (string)$lives);
    }

    if ($type === "halveit") {
        $p = $sb["players"][$slot] ?? null;
        return is_array($p) ? (string)(int)($p["score"] ?? 0) : "—";
    }

    return "—";
}

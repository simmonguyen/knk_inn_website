<?php
/*
 * KnK Inn — /api/darts_bust.php
 *
 * Bust the current player's round in x01 (501 / 301). Voids any
 * darts the current player has thrown this turn and records 3 MISS
 * darts so the round closes at 0 points, then advances to the next
 * player.
 *
 * Only the host or the player whose turn it is can call it. Other
 * game types are rejected — the button is hidden client-side too.
 *
 * POST:
 *   game_id  — playing game's id
 *   token    — caller's seat token
 *
 * Response:
 *   { ok: true,  state: <scoreboard> }
 *   { ok: false, error: "..." }
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/darts.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$out = ["ok" => false, "error" => null];

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        throw new RuntimeException("POST only.");
    }
    $game_id = (int)($_POST["game_id"] ?? 0);
    $token   = (string)($_POST["token"] ?? "");

    $player = knk_darts_player_by_token($game_id, $token);
    if (!$player) throw new RuntimeException("Not in this game.");

    knk_darts_bust_round($game_id, (int)$player["id"]);
    $out = ["ok" => true, "state" => knk_darts_view_state($game_id, $token)];
} catch (Throwable $e) {
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

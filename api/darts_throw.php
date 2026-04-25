<?php
/*
 * KnK Inn — /api/darts_throw.php
 *
 * Whoever's turn it is taps a segment on their phone and we POST it
 * here. The engine validates (game in progress + your turn + <=3
 * darts), appends to darts_throws, recomputes state, and either
 * advances the cursor or finishes the game.
 *
 * POST:
 *   game_id  — int
 *   token    — caller's session token
 *   segment  — S1..S20, D1..D20, T1..T20, SBULL, DBULL, MISS
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/darts.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$out = ['ok' => false, 'error' => null];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException("POST only.");

    $game_id = (int)($_POST['game_id'] ?? 0);
    $token   = (string)($_POST['token'] ?? '');
    $segment = (string)($_POST['segment'] ?? '');

    if ($game_id <= 0) throw new RuntimeException("Missing game id.");
    $player = knk_darts_player_by_token($game_id, $token);
    if (!$player) throw new RuntimeException("Not in this game.");

    knk_darts_record_throw($game_id, (int)$player['id'], $segment);
    $out = ['ok' => true, 'state' => knk_darts_view_state($game_id, $token)];
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

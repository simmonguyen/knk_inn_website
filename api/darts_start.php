<?php
/*
 * KnK Inn — /api/darts_start.php
 *
 * Host POSTs here with their session token to start the game once
 * the lobby's ready. We lock the roster, assign Killer numbers if
 * needed, and set status='playing'.
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
    if ($game_id <= 0) throw new RuntimeException("Missing game id.");

    $player = knk_darts_player_by_token($game_id, $token);
    if (!$player) throw new RuntimeException("Not in this game.");
    if (empty($player['is_host'])) throw new RuntimeException("Only the host can start.");

    knk_darts_start_game($game_id);
    $out = ['ok' => true, 'state' => knk_darts_view_state($game_id, $token)];
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

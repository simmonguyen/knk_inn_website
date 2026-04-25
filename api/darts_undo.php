<?php
/*
 * KnK Inn — /api/darts_undo.php
 *
 * Voids the most recent dart and rolls the cursor back. Allowed by
 * the host or the player who threw it.
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

    $player = knk_darts_player_by_token($game_id, $token);
    if (!$player) throw new RuntimeException("Not in this game.");

    knk_darts_undo_throw($game_id, (int)$player['id']);
    $out = ['ok' => true, 'state' => knk_darts_view_state($game_id, $token)];
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

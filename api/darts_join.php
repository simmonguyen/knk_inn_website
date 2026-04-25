<?php
/*
 * KnK Inn — /api/darts_join.php
 *
 * A non-host phone POSTs here with a join code (typed in or scanned)
 * and a name. We assign them the lowest free slot and return their
 * session token + the game state.
 *
 * POST:
 *   code       — 6-letter join code (case insensitive)
 *   name       — player display name
 *   game_id    — alternative to code (if scanned QR which has the id)
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/darts.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$out = ['ok' => false, 'error' => null];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException("POST only.");
    if (!knk_darts_enabled()) throw new RuntimeException("Darts is closed right now.");

    $code     = trim((string)($_POST['code']    ?? ''));
    $name     = trim((string)($_POST['name']    ?? ''));
    $game_id  = (int)($_POST['game_id'] ?? 0);

    $game = null;
    if ($game_id > 0) {
        $st = knk_db()->prepare("SELECT * FROM darts_games WHERE id = ?");
        $st->execute([$game_id]);
        $game = $st->fetch();
    } elseif ($code !== '') {
        $game = knk_darts_game_by_join_code($code);
    }
    if (!$game) throw new RuntimeException("Game not found.");
    if ($game['status'] !== 'lobby') throw new RuntimeException("That game has already started.");

    $player = knk_darts_join_game((int)$game['id'], $name);
    $out = [
        'ok'             => true,
        'game_id'        => (int)$game['id'],
        'session_token'  => $player['session_token'],
        'state'          => knk_darts_view_state((int)$game['id'], $player['session_token']),
    ];
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

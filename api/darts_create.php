<?php
/*
 * KnK Inn — /api/darts_create.php
 *
 * The first phone on a board POSTs here to create a new game and
 * become its host (slot 1). We respond with the join_code, the host's
 * session token (which the client immediately stores in a cookie),
 * and the full state.
 *
 * POST body (form-encoded):
 *   board_id     — 1 or 2 (or anything in darts_boards)
 *   game_type    — 501|301|cricket|aroundclock|killer|halveit
 *   format       — singles|doubles
 *   player_count — 1..4 (must be 4 if format=doubles)
 *   host_name    — shown on the scoreboard
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . "/../includes/darts.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$out = ['ok' => false, 'error' => null];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException("POST only.");
    if (!knk_darts_enabled()) throw new RuntimeException("Darts is closed right now.");

    $board_id     = (int)($_POST['board_id'] ?? 0);
    $game_type    = (string)($_POST['game_type'] ?? '');
    $format       = (string)($_POST['format'] ?? 'singles');
    $player_count = (int)($_POST['player_count'] ?? 2);
    $host_name    = (string)($_POST['host_name'] ?? '');
    /* Pass the bar-shell guest identity (anon or claimed) so the game
     * shows up on the host's profile history. The phone's session cookie
     * carries it from /bar.php → /api/darts_create.php. */
    $guest_email  = (string)($_SESSION['order_email'] ?? '');

    if ($board_id <= 0) throw new RuntimeException("Pick a board.");

    list($game, $host) = knk_darts_create_game($board_id, $game_type, $format, $player_count, $host_name, $guest_email);

    $out = [
        'ok'             => true,
        'game_id'        => (int)$game['id'],
        'join_code'      => $game['join_code'],
        'session_token'  => $host['session_token'],
        'state'          => knk_darts_view_state((int)$game['id'], $host['session_token']),
    ];
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

<?php
/*
 * KnK Inn — /api/darts_state.php
 *
 * Read-only poll endpoint. Phones in a darts game hit this every
 * couple of seconds to refresh the scoreboard. No auth — the
 * session_token (if provided) just lets us tell the caller which
 * slot is "theirs" so the UI can show the keypad on the right phone.
 *
 * Query string:
 *   game=<id>          — game id required
 *   token=<hex>        — optional player token
 *
 * Response: see knk_darts_view_state(); plus a top-level "config" with
 * poll_seconds for the client.
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/darts.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$out = ['ok' => false, 'error' => null];

try {
    $game_id = (int)($_GET['game'] ?? 0);
    if ($game_id <= 0) throw new RuntimeException("Missing game id.");
    $token = (string)($_GET['token'] ?? '');

    $cfg = knk_darts_config();
    $view = knk_darts_view_state($game_id, $token);

    $out = array_merge(['ok' => true], $view, [
        'config' => [
            'enabled'      => !empty($cfg['enabled']),
            'poll_seconds' => max(1, (int)$cfg['poll_seconds']),
        ],
    ]);
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

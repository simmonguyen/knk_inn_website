<?php
/*
 * KnK Inn — /api/darts_change_type.php
 *
 * Host swaps the game type on a lobby game. Only valid before
 * status flips from 'lobby' to 'playing'. Resets the scoreboard
 * state to defaults for the new type since rules differ.
 *
 * POST:
 *   game_id    — the lobby game's id
 *   token      — caller's seat token (must be the host)
 *   game_type  — one of: 501 / 301 / cricket / aroundclock / killer / halveit
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
    $game_id   = (int)($_POST["game_id"]   ?? 0);
    $token     = (string)($_POST["token"]     ?? "");
    $new_type  = (string)($_POST["game_type"] ?? "");

    $player = knk_darts_player_by_token($game_id, $token);
    if (!$player) throw new RuntimeException("Not in this game.");

    knk_darts_change_game_type($game_id, (int)$player["id"], $new_type);
    $out = ["ok" => true, "state" => knk_darts_view_state($game_id, $token)];
} catch (Throwable $e) {
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

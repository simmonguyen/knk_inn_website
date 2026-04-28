<?php
/*
 * KnK Inn — /api/darts_confirm_round.php
 *
 * Player taps "Confirm round" after the 3rd dart of an x01 turn.
 * Clears awaiting_confirm and advances the cursor to the next
 * player. Allowed by the host or the thrower.
 *
 * POST: game_id + token
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

    knk_darts_confirm_round($game_id, (int)$player["id"]);
    $out = ["ok" => true, "state" => knk_darts_view_state($game_id, $token)];
} catch (Throwable $e) {
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

<?php
/*
 * KnK Inn — /api/darts_end.php
 *
 * Player-initiated "end game". Anyone holding a valid seat token
 * for a playing game can mark it abandoned — for the case where
 * someone has to leave mid-match and the rest of the table doesn't
 * want it staying live forever (or auto-abandoning hours later).
 *
 * The game's status flips to 'abandoned'. No winner is recorded —
 * which keeps the leaderboard honest. The TV stops showing the
 * card on its next poll. Staff retain a separate "Force end" button
 * on /darts-admin.php for situations where no token is to hand.
 *
 * POST:
 *   game_id  — the playing game's id
 *   token    — the player's seat token
 *
 * Response:
 *   { ok: true }
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
    if ($game_id <= 0 || $token === "") {
        throw new RuntimeException("Bad request.");
    }

    /* Confirm the caller actually has a seat in this game. */
    $player = knk_darts_player_by_token($game_id, $token);
    if (!$player) {
        throw new RuntimeException("Not in this game.");
    }

    /* Only end games that are actually mid-play. If it's already
     * finished/abandoned the call is a no-op (idempotent — handy for
     * race conditions where two phones tap End at once). */
    $st = knk_db()->prepare(
        "SELECT status FROM darts_games WHERE id = ? LIMIT 1"
    );
    $st->execute([$game_id]);
    $status = (string)$st->fetchColumn();
    if ($status === "" )                  throw new RuntimeException("Game not found.");
    if ($status === "finished")           throw new RuntimeException("Game already finished.");

    /* Lobby OR playing → abandoned. Same helper /darts-admin.php uses
     * via its "Force end" button. */
    knk_darts_force_finish($game_id);

    $out = ["ok" => true];
} catch (Throwable $e) {
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

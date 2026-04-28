<?php
/*
 * KnK Inn — /api/lyric_status.php
 *
 * Records the result of a TV-side LRCLIB lyric fetch onto the
 * matching jukebox_queue row. Powers the "Lyrics?" column on
 * /jukebox-admin so Ben can spot songs that need a manual paste
 * once that feature ships.
 *
 * Fire-and-forget POST from /tv.php:
 *   queue_id — the playing row's id
 *   status   — synced | plain | missing
 *
 * No auth — same posture as other TV-poll endpoints. The TV's the
 * only thing that cares; staff don't need to set this manually.
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$out = ["ok" => false, "error" => null];

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        throw new RuntimeException("POST only.");
    }

    $queue_id = (int)($_POST["queue_id"] ?? 0);
    $status   = (string)($_POST["status"] ?? "");
    if ($queue_id <= 0) throw new RuntimeException("Bad queue_id.");
    if (!in_array($status, ["synced", "plain", "missing"], true)) {
        throw new RuntimeException("Bad status.");
    }

    $st = knk_db()->prepare(
        "UPDATE jukebox_queue
            SET lyrics_status = ?
          WHERE id = ?"
    );
    $st->execute([$status, $queue_id]);

    $out = ["ok" => true];
} catch (Throwable $e) {
    $out["error"] = $e->getMessage();
    error_log("lyric_status.php: " . $e->getMessage());
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

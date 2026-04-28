<?php
/*
 * KnK Inn — /api/playlist_remove.php
 *
 * Drop a track from the bar guest's playlist. Owner-scoped: a
 * row_id that doesn't belong to the current bar session is a
 * silent no-op (knk_playlist_remove enforces the WHERE clause).
 *
 * POST: row_id — jukebox_playlists.id
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/jukebox_playlists.php";

if (session_status() === PHP_SESSION_NONE) session_start();

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$out = ["ok" => false, "error" => null];

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        throw new RuntimeException("POST only.");
    }
    $email = strtolower(trim((string)($_SESSION["order_email"] ?? "")));
    if ($email === "") {
        throw new RuntimeException("Open /bar.php first.");
    }
    $row_id = (int)($_POST["row_id"] ?? 0);
    if ($row_id <= 0) throw new RuntimeException("Bad row_id.");

    $removed = knk_playlist_remove($email, $row_id);
    $out = [
        "ok"      => true,
        "removed" => $removed,
        "count"   => knk_playlist_count($email),
    ];
} catch (Throwable $e) {
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

<?php
/*
 * KnK Inn — /api/playlist_list.php
 *
 * Read-only feed of the bar guest's playlist. Used by the
 * /bar.php?tab=music "My playlist" tab on first paint and after
 * any add/remove/reorder action.
 *
 * GET (no params needed — owner is derived from the bar session).
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/jukebox_playlists.php";

if (session_status() === PHP_SESSION_NONE) session_start();

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$out = ["ok" => false, "tracks" => [], "count" => 0, "error" => null];

try {
    $email = strtolower(trim((string)($_SESSION["order_email"] ?? "")));
    if ($email === "") {
        // Empty session = empty playlist, not an error. Lets the bar
        // tab render the "Sign in to save tracks" empty state without
        // a noisy 4xx.
        $out["ok"] = true;
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $tracks = knk_playlist_list($email);
    $out = [
        "ok"     => true,
        "tracks" => $tracks,
        "count"  => count($tracks),
        "error"  => null,
    ];
} catch (Throwable $e) {
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

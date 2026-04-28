<?php
/*
 * KnK Inn — /api/playlist_add.php
 *
 * Add a track to the bar guest's playlist. Idempotent — adding
 * the same video_id twice keeps a single row (UNIQUE constraint).
 *
 * Auth: $_SESSION["order_email"] (bar session). No staff login.
 *
 * POST:
 *   video_id   — YouTube id (required)
 *   title      — snapshot of YT title at add-time
 *   channel    — snapshot of YT channel name
 *   duration   — int seconds
 *   thumbnail  — full URL
 *   source     — search | queue | recent | manual (defaults to manual)
 *
 * Response:
 *   { ok: true,  id: <row id>, count: <playlist length> }
 *   { ok: false, error: "..." }
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
        throw new RuntimeException("Open /bar.php first so we know who you are.");
    }

    $track = [
        "video_id"  => (string)($_POST["video_id"]  ?? ""),
        "title"     => (string)($_POST["title"]     ?? ""),
        "channel"   => (string)($_POST["channel"]   ?? ""),
        "duration"  => (int)   ($_POST["duration"]  ?? 0),
        "thumbnail" => (string)($_POST["thumbnail"] ?? ""),
    ];
    $source = (string)($_POST["source"] ?? "manual");

    if (trim($track["video_id"]) === "") {
        throw new RuntimeException("Missing video_id.");
    }

    $id = knk_playlist_add($email, $track, $source);
    if ($id === null) {
        throw new RuntimeException("Couldn't save — try again.");
    }
    $out = [
        "ok"    => true,
        "id"    => $id,
        "count" => knk_playlist_count($email),
    ];
} catch (Throwable $e) {
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

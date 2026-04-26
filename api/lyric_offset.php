<?php
/*
 * KnK Inn — /api/lyric_offset.php
 *
 * Persist a staff-synced lyric offset for a YouTube video. Called
 * by tv.php whenever the ‹/› buttons or [/] keys nudge the lyric
 * timing — the TV fires-and-forgets so the next play of the same
 * video (anywhere, by anyone) inherits the corrected sync.
 *
 * POST body (form-encoded):
 *   video_id    — the YouTube video id (≤20 chars)
 *   offset_sec  — float, clamped to ±30 by the store layer
 *
 * Response:
 *   { ok: true,  offset_sec: <saved value> }
 *   { ok: false, error: "..." }
 *
 * No auth — same posture as the rest of the jukebox API. The TV
 * lives on a private screen behind the bar; if a guest somehow
 * POSTs here they're capped to ±30s anyway and staff can wipe
 * the row from /jukebox-admin.php (later) if anyone abuses it.
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/jukebox.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$out = ["ok" => false, "error" => null, "offset_sec" => null];

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        throw new RuntimeException("POST only.");
    }
    $vid = trim((string)($_POST["video_id"] ?? ""));
    if ($vid === "" || strlen($vid) > 20) {
        throw new RuntimeException("Bad video_id.");
    }
    if (!array_key_exists("offset_sec", $_POST)) {
        throw new RuntimeException("Missing offset_sec.");
    }
    $off = (float)$_POST["offset_sec"];
    if (!is_finite($off)) {
        throw new RuntimeException("Bad offset_sec.");
    }
    $saved = knk_jukebox_lyric_offset_set($vid, $off, null);
    if ($saved === null) {
        throw new RuntimeException("Couldn't save the offset — try again.");
    }
    $out = ["ok" => true, "error" => null, "offset_sec" => $saved];
} catch (Throwable $e) {
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

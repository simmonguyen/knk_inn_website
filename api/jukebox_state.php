<?php
/*
 * KnK Inn — /api/jukebox_state.php
 *
 * JSON feed for the player TV (/jukebox-player.php) and the admin
 * page to poll. No auth, read-only.
 *
 * Response shape:
 * {
 *   "enabled": true,
 *   "auto_approve": true,
 *   "poll_seconds": 5,
 *   "now_playing": null | { id, video_id, title, channel, duration, thumb, name, table_no, started_at },
 *   "up_next": [ { id, video_id, title, channel, duration, thumb, name, table_no }, ... ],
 *   "queue_length": N
 * }
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/jukebox.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$out = [
    "enabled"      => false,
    "auto_approve" => true,
    "poll_seconds" => 60,
    "now_playing"  => null,
    "up_next"      => [],
    "queue_length" => 0,
    "error"        => null,
];

try {
    $cfg = knk_jukebox_config();
    $out["enabled"]      = !empty($cfg["enabled"]);
    $out["auto_approve"] = !empty($cfg["auto_approve"]);
    $out["poll_seconds"] = $out["enabled"]
        ? max(2, (int)$cfg["board_poll_seconds"])
        : 60;

    $now = knk_jukebox_now_playing();
    if ($now) {
        $out["now_playing"] = [
            "id"         => (int)$now["id"],
            "video_id"   => (string)$now["youtube_video_id"],
            "title"      => (string)$now["youtube_title"],
            "channel"    => (string)$now["youtube_channel"],
            "duration"   => (int)$now["duration_seconds"],
            "thumb"      => (string)$now["thumbnail_url"],
            "name"       => (string)$now["requester_name"],
            "table_no"   => (string)$now["table_no"],
        ];
    }

    foreach (knk_jukebox_up_next(10) as $r) {
        $out["up_next"][] = [
            "id"       => (int)$r["id"],
            "video_id" => (string)$r["youtube_video_id"],
            "title"    => (string)$r["youtube_title"],
            "channel"  => (string)$r["youtube_channel"],
            "duration" => (int)$r["duration_seconds"],
            "thumb"    => (string)$r["thumbnail_url"],
            "name"     => (string)$r["requester_name"],
            "table_no" => (string)$r["table_no"],
        ];
    }
    $out["queue_length"] = knk_jukebox_queue_length();
} catch (Throwable $e) {
    $out["error"] = "engine_error";
    error_log("jukebox_state.php: " . $e->getMessage());
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

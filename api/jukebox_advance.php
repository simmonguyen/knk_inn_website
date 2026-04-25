<?php
/*
 * KnK Inn — /api/jukebox_advance.php
 *
 * Player TV calls this when the current video ends (or errors), or
 * when starting up with nothing currently playing. We mark the
 * current row 'played' (idempotent on current_id) and promote the
 * next 'queued' row to 'playing'.
 *
 * Request:  POST current_id=<int|empty>
 * Response: {
 *   "ok": true,
 *   "next": null | { id, video_id, title, channel, duration, thumb, name, table_no },
 *   "state": { now_playing, up_next }   // for sidebar refresh
 * }
 *
 * No auth. The endpoint is idempotent on current_id so redundant or
 * external calls won't break the queue.
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/jukebox.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false, "error" => "POST required"]);
    exit;
}

$current_id_raw = $_POST["current_id"] ?? "";
$current_id     = $current_id_raw === "" ? null : (int)$current_id_raw;

$out = ["ok" => false, "next" => null, "state" => null];

try {
    $next = knk_jukebox_advance($current_id);

    if ($next) {
        $out["next"] = [
            "id"       => (int)$next["id"],
            "video_id" => (string)$next["youtube_video_id"],
            "title"    => (string)$next["youtube_title"],
            "channel"  => (string)$next["youtube_channel"],
            "duration" => (int)$next["duration_seconds"],
            "thumb"    => (string)$next["thumbnail_url"],
            "name"     => (string)$next["requester_name"],
            "table_no" => (string)$next["table_no"],
        ];
    }

    // Snapshot the freshly-updated state for the sidebar.
    $now = knk_jukebox_now_playing();
    $upn = knk_jukebox_up_next(10);
    $out["state"] = [
        "now_playing" => $now ? [
            "id"       => (int)$now["id"],
            "video_id" => (string)$now["youtube_video_id"],
            "title"    => (string)$now["youtube_title"],
            "channel"  => (string)$now["youtube_channel"],
            "duration" => (int)$now["duration_seconds"],
            "thumb"    => (string)$now["thumbnail_url"],
            "name"     => (string)$now["requester_name"],
            "table_no" => (string)$now["table_no"],
        ] : null,
        "up_next" => array_map(function($r){
            return [
                "id"       => (int)$r["id"],
                "video_id" => (string)$r["youtube_video_id"],
                "title"    => (string)$r["youtube_title"],
                "channel"  => (string)$r["youtube_channel"],
                "duration" => (int)$r["duration_seconds"],
                "thumb"    => (string)$r["thumbnail_url"],
                "name"     => (string)$r["requester_name"],
                "table_no" => (string)$r["table_no"],
            ];
        }, $upn),
    ];

    $out["ok"] = true;
} catch (Throwable $e) {
    error_log("jukebox_advance.php: " . $e->getMessage());
    $out["error"] = "engine_error";
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

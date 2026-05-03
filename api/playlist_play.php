<?php
/*
 * KnK Inn — /api/playlist_play.php
 *
 * Push tracks from the bar guest's saved playlist straight into the
 * jukebox queue without going through YouTube search again — the
 * playlist row already has snapshotted metadata (title, channel,
 * duration, thumb), so we hand that to knk_jukebox_enqueue_track()
 * and skip the API quota hit.
 *
 * Two modes:
 *   - row_id=<int>   → enqueue a single track from the playlist
 *   - mode=all       → enqueue every track in the playlist (shuffled)
 *
 * Owner-scoped via $_SESSION["order_email"] — guests can only play
 * their own list. The IP cooldown is bypassed inside the helper so
 * "Play all" can fire 20+ inserts in one go.
 *
 * Each enqueued row gets playlist_owner_email set, which the queue
 * merger will eventually use to alternate fairly between guests who
 * are all "Play all"-ing at the same time.
 *
 * POST:
 *   row_id   — int (single-track mode)
 *   mode     — "all" (whole-playlist mode)
 *   shuffle  — "1" (default) | "0" — only meaningful in mode=all
 *
 * Response:
 *   { ok: true,  added: <int>, skipped: <int>, errors: [..], queue_count: <int> }
 *   { ok: false, error: "..." }
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/jukebox.php";
require_once __DIR__ . "/../includes/jukebox_playlists.php";

if (session_status() === PHP_SESSION_NONE) session_start();

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$out = ["ok" => false, "added" => 0, "skipped" => 0, "errors" => [], "error" => null];

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        throw new RuntimeException("POST only.");
    }
    $email = strtolower(trim((string)($_SESSION["order_email"] ?? "")));
    if ($email === "") {
        throw new RuntimeException("Open /bar.php first so we know whose playlist this is.");
    }
    if (!knk_jukebox_enabled()) {
        throw new RuntimeException("Jukebox is closed right now.");
    }

    $ip   = knk_real_client_ip();
    $mode = strtolower(trim((string)($_POST["mode"] ?? "")));
    $tracks = knk_playlist_list($email);
    if (empty($tracks)) throw new RuntimeException("Your playlist is empty.");

    /* Build the list of tracks to enqueue based on mode. */
    $to_play = [];
    if ($mode === "all") {
        $shuffle = (string)($_POST["shuffle"] ?? "1") !== "0";
        $to_play = $tracks;
        if ($shuffle) shuffle($to_play);
    } else {
        $row_id = (int)($_POST["row_id"] ?? 0);
        if ($row_id <= 0) throw new RuntimeException("Missing row_id.");
        foreach ($tracks as $t) {
            if ((int)$t["id"] === $row_id) { $to_play = [$t]; break; }
        }
        if (empty($to_play)) throw new RuntimeException("That track isn't on your playlist.");
    }

    /* Enqueue each one. We catch per-track failures (queue full,
     * duplicate-in-queue if the jukebox config rejects it later, etc.)
     * so a "Play all" doesn't abort halfway and leave the user
     * wondering which tracks actually made it. */
    $added = 0; $skipped = 0; $errors = [];
    foreach ($to_play as $t) {
        try {
            $track = [
                "video_id"  => (string)$t["video_id"],
                "title"     => (string)$t["title"],
                "channel"   => (string)$t["channel"],
                "duration"  => (int)   $t["duration"],
                "thumbnail" => (string)$t["thumbnail"],
            ];
            knk_jukebox_enqueue_track($track, $email, $email, $ip);
            $added++;
        } catch (Throwable $e) {
            $skipped++;
            $errors[] = [
                "video_id" => (string)$t["video_id"],
                "title"    => (string)$t["title"],
                "error"    => $e->getMessage(),
            ];
            /* Queue-full is terminal — no point trying the rest. */
            if (stripos($e->getMessage(), "queue is full") !== false) break;
        }
    }

    $out = [
        "ok"          => $added > 0,
        "added"       => $added,
        "skipped"     => $skipped,
        "errors"      => $errors,
        "queue_count" => knk_jukebox_queue_length(),
        "error"       => $added === 0
            ? ($errors[0]["error"] ?? "Nothing was added.")
            : null,
    ];
} catch (Throwable $e) {
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

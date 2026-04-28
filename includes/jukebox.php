<?php
/*
 * KnK Inn — Jukebox engine (Phase 3 / proof-of-concept).
 *
 * Bar-guest jukebox. Guest scans a QR code, types Artist + Song
 * Title, this lib calls the YouTube Data API v3, finds a match,
 * queues it. The bar laptop runs /jukebox-player.php on the TV
 * and plays each video via the YouTube IFrame Player API.
 *
 * Storage:
 *   jukebox_config     — single-row knobs (see migration 008).
 *   jukebox_queue      — every request + its lifecycle status.
 *                        "Now playing" is whichever row has
 *                        status='playing' (zero or one at a time).
 *   jukebox_blocklist  — banned videoIds and keywords.
 *
 * YouTube quota cost:
 *   search.list  = 100 units
 *   videos.list  =   1 unit  (any number of ids in one call)
 *   So ~101 units per request → ~99 requests / day on the free
 *   10 000-unit daily quota. Plenty for a pub.
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";

/* ==========================================================
 * CONFIG
 * ======================================================== */

/** Fields the admin form is allowed to write. */
function knk_jukebox_config_fields(): array {
    return [
        "enabled",
        "auto_approve",
        "max_duration_seconds",
        "per_ip_cooldown_seconds",
        "require_table_no",
        "max_queue_length",
        "board_poll_seconds",
        "radio_enabled",
        "radio_url",
    ];
}

function knk_jukebox_defaults(): array {
    return [
        "enabled"                 => 0,
        "auto_approve"            => 1,
        "max_duration_seconds"    => 420,
        "per_ip_cooldown_seconds" => 300,
        "require_table_no"        => 0,
        "max_queue_length"        => 50,
        "board_poll_seconds"      => 5,
        "radio_enabled"           => 1,
        "radio_url"               => "https://live-radio01.mediahubaustralia.com/6TJW/mp3/",
    ];
}

function knk_jukebox_config(): array {
    $row = knk_db()->query("SELECT * FROM jukebox_config WHERE id = 1 LIMIT 1")->fetch();
    if (!$row) {
        // First run after migration but before INSERT IGNORE — seed defaults.
        knk_db()->exec("INSERT IGNORE INTO jukebox_config (id) VALUES (1)");
        $row = knk_db()->query("SELECT * FROM jukebox_config WHERE id = 1 LIMIT 1")->fetch();
    }
    return $row ?: knk_jukebox_defaults();
}

function knk_jukebox_config_update(array $fields, ?int $by_user = null): void {
    if (empty($fields)) return;
    $allowed = knk_jukebox_config_fields();
    $sets = [];
    $vals = [];
    foreach ($fields as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        $sets[] = "`{$k}` = ?";
        $vals[] = is_bool($v) ? (int)$v : $v;
    }
    if (empty($sets)) return;
    $sets[] = "updated_by = ?";
    $vals[] = $by_user;
    $sql = "UPDATE jukebox_config SET " . implode(", ", $sets) . " WHERE id = 1";
    knk_db()->prepare($sql)->execute($vals);
}

function knk_jukebox_enabled(): bool {
    $cfg = knk_jukebox_config();
    return !empty($cfg["enabled"]);
}

function knk_jukebox_api_key(): string {
    $cfg = knk_config();
    return (string)($cfg["youtube_api_key"] ?? "");
}

/* ==========================================================
 * BLOCKLIST
 * ======================================================== */

function knk_jukebox_blocklist_list(): array {
    return knk_db()->query(
        "SELECT id, kind, value, reason, blocked_by, blocked_at
         FROM jukebox_blocklist ORDER BY blocked_at DESC"
    )->fetchAll();
}

function knk_jukebox_blocklist_add(string $kind, string $value, string $reason = "", ?int $by_user = null): int {
    if ($kind !== "video" && $kind !== "keyword") {
        throw new RuntimeException("Unknown blocklist kind: {$kind}");
    }
    $value = trim($value);
    if ($value === "") throw new RuntimeException("Empty blocklist value.");
    if ($kind === "keyword") $value = strtolower($value);
    if (mb_strlen($value) > 200) $value = mb_substr($value, 0, 200);

    $stmt = knk_db()->prepare(
        "INSERT INTO jukebox_blocklist (kind, value, reason, blocked_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE reason = VALUES(reason), blocked_by = VALUES(blocked_by), blocked_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([$kind, $value, mb_substr($reason, 0, 200), $by_user]);
    return (int)knk_db()->lastInsertId();
}

function knk_jukebox_blocklist_remove(int $id): void {
    knk_db()->prepare("DELETE FROM jukebox_blocklist WHERE id = ?")->execute([$id]);
}

function knk_jukebox_video_blocked(string $video_id): bool {
    $stmt = knk_db()->prepare(
        "SELECT 1 FROM jukebox_blocklist WHERE kind = 'video' AND value = ? LIMIT 1"
    );
    $stmt->execute([$video_id]);
    return (bool)$stmt->fetchColumn();
}

function knk_jukebox_text_blocked(string $text): bool {
    $text = strtolower($text);
    if ($text === "") return false;
    $stmt = knk_db()->query("SELECT value FROM jukebox_blocklist WHERE kind = 'keyword'");
    foreach ($stmt as $row) {
        $kw = (string)$row["value"];
        if ($kw !== "" && strpos($text, $kw) !== false) return true;
    }
    return false;
}

/* ==========================================================
 * QUEUE READS
 * ======================================================== */

function knk_jukebox_now_playing(): ?array {
    $row = knk_db()->query(
        "SELECT * FROM jukebox_queue WHERE status = 'playing' ORDER BY submitted_at ASC LIMIT 1"
    )->fetch();
    return $row ?: null;
}

function knk_jukebox_up_next(int $limit = 10): array {
    $limit = max(1, min(100, $limit));
    $stmt = knk_db()->prepare(
        "SELECT * FROM jukebox_queue WHERE status = 'queued'
         ORDER BY submitted_at ASC LIMIT {$limit}"
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

function knk_jukebox_pending(int $limit = 50): array {
    $limit = max(1, min(200, $limit));
    return knk_db()->query(
        "SELECT * FROM jukebox_queue WHERE status = 'pending'
         ORDER BY submitted_at ASC LIMIT {$limit}"
    )->fetchAll();
}

function knk_jukebox_recent(int $limit = 20): array {
    $limit = max(1, min(200, $limit));
    /* LEFT JOIN guests on requester_email so /jukebox-admin's "Who"
     * column can fall back to the guest's display_name when no
     * requester_name was typed. The bar shell skipped the name/table
     * form to keep the QR-scan flow short, so most rows have a blank
     * requester_name but a real email. The COALESCE in the SELECT
     * gives admin pages a single column to render, and the original
     * requester_name is preserved on the row for any caller that
     * still wants it. */
    return knk_db()->query(
        "SELECT q.*,
                COALESCE(NULLIF(TRIM(q.requester_name), ''),
                         g.display_name,
                         '') AS who_name
           FROM jukebox_queue q
      LEFT JOIN guests g ON g.email = q.requester_email
          WHERE q.status IN ('played','skipped','rejected')
       ORDER BY COALESCE(q.played_at, q.submitted_at) DESC
          LIMIT {$limit}"
    )->fetchAll();
}

function knk_jukebox_queue_length(): int {
    return (int)knk_db()->query(
        "SELECT COUNT(*) FROM jukebox_queue WHERE status IN ('queued','playing')"
    )->fetchColumn();
}

function knk_jukebox_queue_position(int $id): int {
    // 1-based position of this id in the up-next queue (0 if not queued).
    $stmt = knk_db()->prepare(
        "SELECT COUNT(*) FROM jukebox_queue
         WHERE status = 'queued'
           AND submitted_at <= (SELECT submitted_at FROM jukebox_queue WHERE id = ?)"
    );
    $stmt->execute([$id]);
    $n = (int)$stmt->fetchColumn();
    return $n;
}

/* ==========================================================
 * REQUEST FLOW (guest submit)
 * ======================================================== */

/**
 * Returns 0 if this IP is allowed to submit right now, otherwise
 * the number of seconds remaining on the cooldown.
 */
function knk_jukebox_ip_cooldown_remaining(string $ip): int {
    if ($ip === "") return 0;
    $cfg = knk_jukebox_config();
    $cooldown = (int)$cfg["per_ip_cooldown_seconds"];
    if ($cooldown <= 0) return 0;

    $stmt = knk_db()->prepare(
        "SELECT TIMESTAMPDIFF(SECOND, submitted_at, NOW()) AS age
         FROM jukebox_queue
         WHERE requester_ip = ?
           AND status IN ('queued','playing','played','pending')
         ORDER BY submitted_at DESC LIMIT 1"
    );
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    if (!$row) return 0;
    $age = (int)$row["age"];
    $remaining = $cooldown - $age;
    return $remaining > 0 ? $remaining : 0;
}

/**
 * Server-side YouTube search. Returns the chosen match metadata
 * or throws RuntimeException with a guest-friendly message.
 */
function knk_jukebox_search(string $artist, string $title): array {
    $key = knk_jukebox_api_key();
    if ($key === "") {
        throw new RuntimeException("Jukebox isn't set up yet — staff need to add a YouTube API key.");
    }

    $artist = trim($artist);
    $title  = trim($title);
    $q = trim($artist . " " . $title);
    if ($q === "") {
        throw new RuntimeException("Type an artist and a song title.");
    }
    if (mb_strlen($q) > 250) {
        throw new RuntimeException("That's a very long search — shorten it a bit.");
    }

    $cfg    = knk_jukebox_config();
    $maxDur = (int)$cfg["max_duration_seconds"];

    // 1) search.list — top 5 candidates.
    $params = http_build_query([
        "key"             => $key,
        "q"               => $q,
        "part"            => "snippet",
        "type"            => "video",
        "maxResults"      => 5,
        "videoEmbeddable" => "true",
        "safeSearch"      => "none",
    ]);
    $resp = knk_jukebox_http_get("https://www.googleapis.com/youtube/v3/search?" . $params);
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        throw new RuntimeException("Couldn't reach YouTube. Try again in a moment.");
    }
    if (isset($data["error"])) {
        $msg = (string)($data["error"]["message"] ?? "unknown");
        // Don't leak the raw API error to guests — log and show a friendly version.
        error_log("jukebox search error: " . $msg);
        throw new RuntimeException("YouTube search failed. Try again in a moment.");
    }
    if (!isset($data["items"]) || !is_array($data["items"]) || count($data["items"]) === 0) {
        throw new RuntimeException("Couldn't find that on YouTube. Check the spelling and try again.");
    }

    $candidates = [];
    foreach ($data["items"] as $item) {
        $vid = (string)($item["id"]["videoId"] ?? "");
        if ($vid === "") continue;
        $sn = $item["snippet"] ?? [];
        $thumb = (string)(
            $sn["thumbnails"]["medium"]["url"]
                ?? $sn["thumbnails"]["high"]["url"]
                ?? $sn["thumbnails"]["default"]["url"]
                ?? ""
        );
        // YouTube returns titles/channels with HTML entities already encoded
        // (e.g. `She said &quot;hi&quot;` or `Drake &amp; Future`). If we
        // store them as-is the htmlspecialchars() on render double-encodes
        // (`&amp;quot;`) and the literal `&quot;` shows up in the UI. Decode
        // once on the way in so the DB holds the human-readable form. */
        $candidates[$vid] = [
            "video_id" => $vid,
            "title"    => html_entity_decode((string)($sn["title"]        ?? ""), ENT_QUOTES | ENT_HTML5, "UTF-8"),
            "channel"  => html_entity_decode((string)($sn["channelTitle"] ?? ""), ENT_QUOTES | ENT_HTML5, "UTF-8"),
            "thumb"    => $thumb,
        ];
    }
    if (empty($candidates)) {
        throw new RuntimeException("No usable results.");
    }

    // 2) videos.list — duration + embeddable status.
    $vparams = http_build_query([
        "key"  => $key,
        "id"   => implode(",", array_keys($candidates)),
        "part" => "contentDetails,status",
    ]);
    $vresp = knk_jukebox_http_get("https://www.googleapis.com/youtube/v3/videos?" . $vparams);
    $vdata = json_decode($vresp, true);
    if (!is_array($vdata) || !isset($vdata["items"])) {
        throw new RuntimeException("YouTube lookup failed.");
    }
    foreach ($vdata["items"] as $item) {
        $vid = (string)($item["id"] ?? "");
        if ($vid === "" || !isset($candidates[$vid])) continue;
        $candidates[$vid]["duration"]   = knk_jukebox_iso8601_to_seconds((string)($item["contentDetails"]["duration"] ?? ""));
        $candidates[$vid]["embeddable"] = !empty($item["status"]["embeddable"]);
        $candidates[$vid]["resolved"]   = true;
    }

    // Pick the first candidate (in YouTube's relevance order) that
    // is resolvable, embeddable, has a sane duration, and is not
    // blocklisted.
    foreach ($candidates as $vid => $c) {
        if (empty($c["resolved"]))   continue;
        if (empty($c["embeddable"])) continue;
        $d = (int)($c["duration"] ?? 0);
        if ($d <= 0) continue;
        if ($maxDur > 0 && $d > $maxDur) continue;
        if (knk_jukebox_video_blocked($vid)) continue;
        if (knk_jukebox_text_blocked($c["title"])) continue;
        if (knk_jukebox_text_blocked($artist . " " . $title)) continue;
        return [
            "video_id" => $vid,
            "title"    => $c["title"],
            "channel"  => $c["channel"],
            "duration" => $d,
            "thumb"    => $c["thumb"],
        ];
    }

    // Help the guest understand why nothing went through.
    $reasons = [];
    foreach ($candidates as $vid => $c) {
        if (empty($c["resolved"]) || empty($c["embeddable"])) {
            $reasons["embed"] = true; continue;
        }
        $d = (int)($c["duration"] ?? 0);
        if ($maxDur > 0 && $d > $maxDur) { $reasons["long"] = true; continue; }
        if (knk_jukebox_video_blocked($vid) || knk_jukebox_text_blocked($c["title"])) {
            $reasons["blocked"] = true; continue;
        }
    }
    if (!empty($reasons["long"])) {
        $minutes = (int)ceil($maxDur / 60);
        throw new RuntimeException("Top results were longer than the {$minutes}-minute cap. Try a single song version.");
    }
    if (!empty($reasons["embed"])) {
        throw new RuntimeException("Top results aren't allowed to be embedded. Try a different version.");
    }
    if (!empty($reasons["blocked"])) {
        throw new RuntimeException("Staff have blocked this song. Try something else.");
    }
    throw new RuntimeException("No suitable match. Try a different search.");
}

/**
 * Submit a guest request. Runs cooldown + queue-length checks,
 * then knk_jukebox_search(), then writes the row.
 *
 * Returns: ["id"=>..., "video_id"=>..., "youtube_title"=>..., "position"=>N, "status"=>"queued"|"pending"]
 */
function knk_jukebox_request_submit(array $in, string $ip): array {
    if (!knk_jukebox_enabled()) {
        throw new RuntimeException("Jukebox is closed right now.");
    }

    $cfg = knk_jukebox_config();
    if (!empty($cfg["require_table_no"]) && trim((string)($in["table_no"] ?? "")) === "") {
        throw new RuntimeException("Please add your table number.");
    }

    $remaining = knk_jukebox_ip_cooldown_remaining($ip);
    if ($remaining > 0) {
        $mins = (int)ceil($remaining / 60);
        throw new RuntimeException("You've just queued one. Try again in {$mins} min.");
    }

    if (knk_jukebox_queue_length() >= (int)$cfg["max_queue_length"]) {
        throw new RuntimeException("The queue is full. Try again in a bit.");
    }

    $artist = trim((string)($in["artist"] ?? ""));
    $title  = trim((string)($in["title"]  ?? ""));
    if ($artist === "" || $title === "") {
        throw new RuntimeException("Type an artist and a song title.");
    }

    $match = knk_jukebox_search($artist, $title);

    $status = !empty($cfg["auto_approve"]) ? "queued" : "pending";

    /* Optional requester_email (added in migration 017). Lets the
     * profile page show the guest their own song history. Empty
     * string is a fine default — the column is NOT NULL DEFAULT ''. */
    $email = strtolower(trim((string)($in["email"] ?? "")));
    if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = "";

    $stmt = knk_db()->prepare(
        "INSERT INTO jukebox_queue
         (artist_text, title_text, youtube_video_id, youtube_title, youtube_channel,
          duration_seconds, thumbnail_url, requester_name, table_no, requester_ip,
          requester_email, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $typed_name = trim((string)($in["name"] ?? ""));
    $stmt->execute([
        mb_substr($artist, 0, 200),
        mb_substr($title, 0, 200),
        mb_substr((string)$match["video_id"], 0, 20),
        mb_substr((string)$match["title"], 0, 300),
        mb_substr((string)$match["channel"], 0, 200),
        (int)$match["duration"],
        mb_substr((string)$match["thumb"], 0, 400),
        mb_substr($typed_name, 0, 80),
        mb_substr(trim((string)($in["table_no"] ?? "")), 0, 20),
        mb_substr($ip, 0, 45),
        mb_substr($email, 0, 190),
        $status,
    ]);
    $id = (int)knk_db()->lastInsertId();

    /* Promote the typed name to the guest profile when both an email
     * and a name were supplied — same logic the darts join uses. Only
     * overwrites the auto "Guest XXXX" placeholder, never a real
     * /profile.php-set name. */
    if ($email !== "" && $typed_name !== "" && function_exists('knk_profile_adopt_typed_name')) {
        try { knk_profile_adopt_typed_name($email, $typed_name); }
        catch (Throwable $e) { error_log("jukebox name-adopt: " . $e->getMessage()); }
    }

    return [
        "id"            => $id,
        "video_id"      => $match["video_id"],
        "youtube_title" => $match["title"],
        "channel"       => $match["channel"],
        "duration"      => (int)$match["duration"],
        "thumb"         => $match["thumb"],
        "position"      => $status === "queued" ? knk_jukebox_queue_position($id) : 0,
        "status"        => $status,
    ];
}

/**
 * Enqueue a track we already have full metadata for — used by the
 * "Play" / "Play all" buttons on the bar's My-playlist card. Skips
 * the YouTube search step (which costs API quota) since the
 * playlist row already snapshotted title / channel / duration /
 * thumb at add-time. Bypasses the IP cooldown for the same reason
 * — the cooldown protects a guest from spamming the queue with
 * search-form submits, but a Play-all over a saved list is the
 * intended use case.
 *
 * Still respects max_queue_length and the kill-switch.
 *
 * Returns the new row's id, position, and status. Throws on
 * validation / queue-full failures.
 */
function knk_jukebox_enqueue_track(array $track, string $requester_email = '', string $playlist_owner = '', string $ip = ''): array {
    if (!knk_jukebox_enabled()) {
        throw new RuntimeException("Jukebox is closed right now.");
    }
    $cfg = knk_jukebox_config();
    if (knk_jukebox_queue_length() >= (int)$cfg["max_queue_length"]) {
        throw new RuntimeException("The queue is full. Try again in a bit.");
    }

    $vid = mb_substr(trim((string)($track["video_id"] ?? "")), 0, 20);
    if ($vid === "") throw new RuntimeException("Missing video_id.");

    $title    = mb_substr((string)($track["title"]     ?? ""), 0, 300);
    $channel  = mb_substr((string)($track["channel"]   ?? ""), 0, 200);
    $duration = (int)($track["duration"] ?? 0);
    $thumb    = mb_substr((string)($track["thumbnail"] ?? ""), 0, 400);

    $email = strtolower(trim($requester_email));
    if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = "";
    $owner = strtolower(trim($playlist_owner));
    if ($owner !== "" && !filter_var($owner, FILTER_VALIDATE_EMAIL)) $owner = "";

    $status = !empty($cfg["auto_approve"]) ? "queued" : "pending";

    $stmt = knk_db()->prepare(
        "INSERT INTO jukebox_queue
            (artist_text, title_text, youtube_video_id, youtube_title, youtube_channel,
             duration_seconds, thumbnail_url, requester_name, table_no, requester_ip,
             requester_email, playlist_owner_email, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->execute([
        // artist_text + title_text — best-effort split for legacy
        // logging; the real metadata is in the youtube_* columns.
        mb_substr($channel !== "" ? $channel : "—", 0, 200),
        mb_substr($title, 0, 200),
        $vid, $title, $channel, $duration, $thumb,
        "", // requester_name — name lives on the guests profile via email
        "",
        mb_substr($ip, 0, 45),
        mb_substr($email, 0, 190),
        $owner !== "" ? mb_substr($owner, 0, 190) : null,
        $status,
    ]);
    $id = (int)knk_db()->lastInsertId();

    return [
        "id"       => $id,
        "video_id" => $vid,
        "position" => $status === "queued" ? knk_jukebox_queue_position($id) : 0,
        "status"   => $status,
    ];
}

/* ==========================================================
 * PLAYBACK FLOW
 * ======================================================== */

/**
 * Player flow:
 *   • If `current_id` is the row that's actually 'playing', mark it
 *     'played' and promote the oldest 'queued' row to 'playing'.
 *   • If `current_id` is null, do nothing destructive — only promote
 *     the next queued row if there's nothing currently playing.
 *
 * This means a stale (or stranger-supplied) `current_id` can't be
 * used to skip songs: the UPDATE only matches the actual playing
 * row, and we re-check 'playing' before promoting.
 *
 * Returns the row that's now 'playing' (or null when the queue is
 * empty and nothing was playing).
 */
function knk_jukebox_advance(?int $current_id = null): ?array {
    $pdo = knk_db();
    $pdo->beginTransaction();
    try {
        // 1. Close out the currently-playing row, but only if the
        //    caller's id matches it.
        if ($current_id !== null && $current_id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE jukebox_queue
                 SET status = 'played', played_at = NOW()
                 WHERE id = ? AND status = 'playing'"
            );
            $stmt->execute([$current_id]);
        }

        // 2. If something is still playing, return it (no-op advance).
        $still = $pdo->query(
            "SELECT * FROM jukebox_queue WHERE status = 'playing'
             ORDER BY submitted_at ASC LIMIT 1"
        )->fetch();
        if ($still) {
            $pdo->commit();
            return $still;
        }

        // 3. No-one's playing — promote the oldest 'queued' row.
        $next = $pdo->query(
            "SELECT id FROM jukebox_queue WHERE status = 'queued'
             ORDER BY submitted_at ASC LIMIT 1"
        )->fetch();

        if ($next) {
            $upd = $pdo->prepare(
                "UPDATE jukebox_queue SET status = 'playing' WHERE id = ? AND status = 'queued'"
            );
            $upd->execute([(int)$next["id"]]);
            $check = $pdo->prepare("SELECT * FROM jukebox_queue WHERE id = ? LIMIT 1");
            $check->execute([(int)$next["id"]]);
            $next = $check->fetch() ?: null;
        }

        $pdo->commit();
        return $next ? $next : null;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function knk_jukebox_skip(int $id, ?int $by_user = null): void {
    $stmt = knk_db()->prepare(
        "UPDATE jukebox_queue
         SET status = 'skipped', played_at = NOW(), actor_user_id = ?
         WHERE id = ? AND status IN ('queued','playing','pending')"
    );
    $stmt->execute([$by_user, $id]);
}

function knk_jukebox_approve(int $id, ?int $by_user = null): void {
    $stmt = knk_db()->prepare(
        "UPDATE jukebox_queue
         SET status = 'queued', actor_user_id = ?
         WHERE id = ? AND status = 'pending'"
    );
    $stmt->execute([$by_user, $id]);
}

function knk_jukebox_reject(int $id, string $reason, ?int $by_user = null): void {
    $stmt = knk_db()->prepare(
        "UPDATE jukebox_queue
         SET status = 'rejected', rejection_reason = ?, played_at = NOW(), actor_user_id = ?
         WHERE id = ? AND status IN ('pending','queued')"
    );
    $stmt->execute([mb_substr($reason, 0, 200), $by_user, $id]);
}

/* ==========================================================
 * LYRIC OFFSETS (persisted per youtube_video_id)
 * ========================================================== */

/**
 * Get the staff-synced lyric offset for a YouTube video, or null
 * if no offset has been saved. Returned as a float in seconds —
 * positive = lyrics later, negative = lyrics earlier.
 */
function knk_jukebox_lyric_offset_get(string $video_id): ?float {
    $video_id = trim($video_id);
    if ($video_id === "") return null;
    try {
        $stmt = knk_db()->prepare(
            "SELECT offset_sec FROM jukebox_lyric_offsets
              WHERE youtube_video_id = ? LIMIT 1"
        );
        $stmt->execute([$video_id]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (float)$v;
    } catch (Throwable $e) {
        error_log("knk_jukebox_lyric_offset_get: " . $e->getMessage());
        return null;
    }
}

/**
 * Upsert the lyric offset for a video. Clamps to ±30s so a stuck
 * key or fat-fingered nudge can't write a nonsense value. Returns
 * the value actually saved, or null on failure.
 */
function knk_jukebox_lyric_offset_set(string $video_id, float $offset_sec, ?int $by_user = null): ?float {
    $video_id = trim($video_id);
    if ($video_id === "" || strlen($video_id) > 20) return null;
    if ($offset_sec >  30.0) $offset_sec =  30.0;
    if ($offset_sec < -30.0) $offset_sec = -30.0;
    // Round to 2dp so the column's DECIMAL(6,2) gets a clean store.
    $offset_sec = round($offset_sec, 2);
    try {
        $stmt = knk_db()->prepare(
            "INSERT INTO jukebox_lyric_offsets
                (youtube_video_id, offset_sec, updated_by_user_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                offset_sec = VALUES(offset_sec),
                updated_by_user_id = VALUES(updated_by_user_id)"
        );
        $stmt->execute([$video_id, $offset_sec, $by_user]);
        return $offset_sec;
    } catch (Throwable $e) {
        error_log("knk_jukebox_lyric_offset_set: " . $e->getMessage());
        return null;
    }
}

/**
 * "Recently played at the bar" — finished plays, all guests, all
 * time. Powers the public history list at the bottom of
 * /bar.php?tab=music. Status='played' only (skipped / rejected /
 * cancelled songs don't belong on the social wall). Newest first.
 *
 * Limit capped at 100. Indexed lookup on (status, played_at).
 */
function knk_jukebox_recent_played(int $limit = 25): array {
    $limit = max(1, min(100, $limit));
    try {
        /* Same join-and-fallback pattern as knk_jukebox_recent — most
         * bar-shell rows have a blank requester_name but a known
         * requester_email, so we resolve display_name via the guests
         * table. requester_name field is still selected for backwards
         * compatibility with anywhere that reads it directly. */
        $stmt = knk_db()->prepare(
            "SELECT q.id, q.youtube_video_id, q.youtube_title, q.youtube_channel,
                    q.thumbnail_url, q.requester_name, q.played_at,
                    COALESCE(NULLIF(TRIM(q.requester_name), ''),
                             g.display_name,
                             '') AS who_name
               FROM jukebox_queue q
          LEFT JOIN guests g ON g.email = q.requester_email
              WHERE q.status = 'played'
                AND q.played_at IS NOT NULL
           ORDER BY q.played_at DESC
              LIMIT {$limit}"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log("knk_jukebox_recent_played: " . $e->getMessage());
        return [];
    }
}

/* ==========================================================
 * GUEST-SIDE LIST + CANCEL (bar.php?tab=music "Your songs")
 * ========================================================== */

/**
 * Recent songs requested by this email — used to populate the
 * "Your songs" panel on /bar.php?tab=music. Newest first. Active
 * songs (status pending/queued/playing) come ahead of historical
 * (played/skipped/rejected) so cancel-able rows stay at the top.
 *
 * Capped at $limit; default 8 keeps the panel short on mobile.
 */
function knk_jukebox_songs_for_email(string $email, int $limit = 8): array {
    $email = strtolower(trim($email));
    if ($email === "") return [];
    $limit = max(1, min(50, $limit));
    try {
        $stmt = knk_db()->prepare(
            "SELECT id, youtube_video_id, youtube_title, youtube_channel,
                    thumbnail_url, duration_seconds, status,
                    submitted_at, played_at
               FROM jukebox_queue
              WHERE requester_email = ?
           ORDER BY
                CASE status
                  WHEN 'playing' THEN 1
                  WHEN 'queued'  THEN 2
                  WHEN 'pending' THEN 3
                  ELSE 4
                END,
                submitted_at DESC
              LIMIT {$limit}"
        );
        $stmt->execute([$email]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log("knk_jukebox_songs_for_email: " . $e->getMessage());
        return [];
    }
}

/**
 * Guest cancels their own song request. Returns true if a row
 * actually transitioned to 'skipped' (matched all the gates):
 *   - id > 0
 *   - requester_email matches the calling guest exactly
 *   - status is currently 'pending' or 'queued' (you can't recall
 *     a song that's already on the TV — at that point staff or
 *     the auto-advance handles it)
 *
 * Status flips to 'skipped' so it falls out of the queue + stops
 * counting against the per-IP cooldown. We also stamp played_at
 * so admin views show when the cancel happened.
 */
function knk_jukebox_cancel_by_guest(int $id, string $email): bool {
    $email = strtolower(trim($email));
    if ($id <= 0 || $email === "") return false;
    try {
        $stmt = knk_db()->prepare(
            "UPDATE jukebox_queue
                SET status = 'skipped',
                    played_at = NOW(),
                    rejection_reason = 'cancelled by guest'
              WHERE id = ?
                AND requester_email = ?
                AND status IN ('pending','queued')"
        );
        $stmt->execute([$id, $email]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log("knk_jukebox_cancel_by_guest: " . $e->getMessage());
        return false;
    }
}

/* ==========================================================
 * UTILITIES
 * ======================================================== */

/** ISO 8601 duration "PT4M13S" → 253. Returns 0 on parse failure. */
function knk_jukebox_iso8601_to_seconds(string $iso): int {
    if ($iso === "" || $iso[0] !== "P") return 0;
    if (!preg_match('/^P(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $iso, $m)) return 0;
    $h  = isset($m[1]) ? (int)$m[1] : 0;
    $mi = isset($m[2]) ? (int)$m[2] : 0;
    $s  = isset($m[3]) ? (int)$m[3] : 0;
    return $h * 3600 + $mi * 60 + $s;
}

/** Tiny "3:42" formatter for the UI. */
function knk_jukebox_fmt_duration(int $seconds): string {
    $seconds = max(0, $seconds);
    $m = (int)floor($seconds / 60);
    $s = $seconds % 60;
    return $m . ":" . str_pad((string)$s, 2, "0", STR_PAD_LEFT);
}

/** Best-effort client IP (proxy-aware). Used for cooldown only. */
function knk_jukebox_client_ip(): string {
    $candidates = [
        $_SERVER["HTTP_CF_CONNECTING_IP"] ?? "",
        $_SERVER["HTTP_X_FORWARDED_FOR"] ?? "",
        $_SERVER["REMOTE_ADDR"] ?? "",
    ];
    foreach ($candidates as $c) {
        $c = trim(explode(",", (string)$c)[0]);
        if ($c !== "" && filter_var($c, FILTER_VALIDATE_IP)) return $c;
    }
    return "";
}

/**
 * GET wrapper. cURL when available (Matbao's PHP 7.4 ships it),
 * file_get_contents fallback otherwise.
 *
 * One automatic retry on transient connect failures — Ben saw
 * "Resolving timed out after 4004 milliseconds" intermittently from
 * Matbao while reaching googleapis.com. The retry covers the common
 * case (DNS hiccup) without making a fast-path search slow.
 *
 * Connect timeout bumped from 4s → 10s, total from 8s → 15s. The
 * earlier values were tight: a slow Matbao → Google link (Vietnam
 * sometimes routes via Singapore, adding latency) could TCP-handshake
 * in 5-6 seconds and miss the 4s window.
 */
function knk_jukebox_http_get(string $url): string {
    $tries = 0;
    $last_err = "";
    while ($tries < 2) {
        $tries++;
        if (function_exists("curl_init")) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, "KnKInn-Jukebox/1.0");
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp !== false && $code < 500) {
                return (string)$resp;
            }
            if ($code >= 500) {
                throw new RuntimeException("YouTube returned {$code}.");
            }
            $last_err = $err !== "" ? $err : "unknown error";
            // Fall through to retry. Tiny delay so a 100% borked
            // upstream doesn't get hammered; one second is enough
            // for a transient DNS / route flap to settle.
            if ($tries < 2) {
                usleep(800 * 1000);
                continue;
            }
            throw new RuntimeException("Network error: " . $last_err);
        }
        // file_get_contents fallback. No retry here — the configurable
        // bits are limited; if it fails once, fail loud.
        $ctx = stream_context_create([
            "http" => ["timeout" => 15, "ignore_errors" => true],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) throw new RuntimeException("Network error talking to YouTube.");
        return $resp;
    }
    throw new RuntimeException("Network error: " . $last_err);
}

/* ==========================================================
 * RADIO ALT-STREAM SCHEDULE
 * ========================================================== */

/**
 * Triple J runs talk-heavy shows that Ben actively dislikes. During
 * those windows we swap the bar's radio fallback to the Hottest 100
 * stream — same brand vibe, no chat. All times in Australia/Sydney
 * so AEST/AEDT switches automatically, regardless of Saigon's UTC+7.
 *
 * Schedule (Sydney local):
 *   Core         — Tuesday   21:00 – 23:00
 *   Prism        — Wednesday 21:00 – 23:00
 *   The Hook Up  — Sunday    21:00 – 22:00
 *   Hack         — Monday–Friday 17:30 – 18:00
 *
 * Returns true if NOW falls inside any of those windows.
 * Cheap to call (one DateTime construct, integer comparisons).
 */
function knk_radio_alt_stream_active(): bool {
    try {
        $now = new DateTime("now", new DateTimeZone("Australia/Sydney"));
    } catch (Throwable $e) {
        // If DateTimeZone barfs (TZ data missing on the host), default
        // to "no swap" so the configured stream keeps playing.
        return false;
    }
    $dow_iso  = (int)$now->format("N");      // 1=Mon, 7=Sun
    $minutes  = ((int)$now->format("H")) * 60 + (int)$now->format("i");

    // Each row: [DOW or '*', start_min, end_min].
    $windows = [
        // Hack — Mon–Fri 17:30 – 18:00
        [1, 17 * 60 + 30, 18 * 60],
        [2, 17 * 60 + 30, 18 * 60],
        [3, 17 * 60 + 30, 18 * 60],
        [4, 17 * 60 + 30, 18 * 60],
        [5, 17 * 60 + 30, 18 * 60],
        // Core — Tue 21:00 – 23:00
        [2, 21 * 60, 23 * 60],
        // Prism — Wed 21:00 – 23:00
        [3, 21 * 60, 23 * 60],
        // The Hook Up — Sun 21:00 – 22:00
        [7, 21 * 60, 22 * 60],
    ];
    foreach ($windows as $w) {
        if ($dow_iso === $w[0] && $minutes >= $w[1] && $minutes < $w[2]) {
            return true;
        }
    }
    return false;
}

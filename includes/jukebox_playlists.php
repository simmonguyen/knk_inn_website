<?php
/*
 * KnK Inn — guest playlist store.
 *
 * Per-guest saved track lists, backed by the jukebox_playlists
 * table (migration 025). Each guest has one playlist keyed on
 * their email — anon emails are first-class so guests don't have
 * to claim a profile to use this. The claim flow rekeys their
 * rows when they upgrade.
 *
 * The store is intentionally narrow — list / add / remove /
 * reorder. The "Play all" + queue-merge logic lives in
 * jukebox.php so it can share the existing queue insertion path.
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";

/* ==========================================================
 * READ
 * ========================================================== */

/**
 * Return all tracks in this guest's playlist, in saved order.
 * Each row: { id, video_id, title, channel, duration, thumbnail,
 * source, added_at }.
 */
function knk_playlist_list(string $email): array {
    $email = strtolower(trim($email));
    if ($email === "") return [];
    try {
        $stmt = knk_db()->prepare(
            "SELECT id, youtube_video_id AS video_id,
                    youtube_title AS title, youtube_channel AS channel,
                    duration_seconds AS duration, thumbnail_url AS thumbnail,
                    source, added_at, sort_order
               FROM jukebox_playlists
              WHERE owner_email = ?
           ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute([$email]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r["id"]         = (int)$r["id"];
            $r["duration"]   = (int)$r["duration"];
            $r["sort_order"] = (int)$r["sort_order"];
        }
        unset($r);
        return $rows;
    } catch (Throwable $e) {
        error_log("knk_playlist_list: " . $e->getMessage());
        return [];
    }
}

/**
 * Track count — cheap helper for "Your playlist (N)" headers.
 */
function knk_playlist_count(string $email): int {
    $email = strtolower(trim($email));
    if ($email === "") return 0;
    try {
        $st = knk_db()->prepare(
            "SELECT COUNT(*) FROM jukebox_playlists WHERE owner_email = ?"
        );
        $st->execute([$email]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/* ==========================================================
 * WRITE
 * ========================================================== */

/**
 * Add a track. UNIQUE on (owner_email, video_id) makes this a
 * no-op when the track's already in the list. Returns the row's
 * id (existing or new) on success, null on validation/db error.
 *
 * $track shape (matches the jukebox queue / search result):
 *   video_id, title, channel, duration, thumbnail
 */
function knk_playlist_add(string $email, array $track, string $source = "manual"): ?int {
    $email = strtolower(trim($email));
    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    $vid = mb_substr(trim((string)($track["video_id"] ?? "")), 0, 20);
    if ($vid === "") return null;

    $title    = mb_substr((string)($track["title"]     ?? ""), 0, 300);
    $channel  = mb_substr((string)($track["channel"]   ?? ""), 0, 200);
    $duration = (int)($track["duration"] ?? 0);
    $thumb    = mb_substr((string)($track["thumbnail"] ?? ""), 0, 400);

    $allowed_sources = ["search","queue","recent","manual"];
    if (!in_array($source, $allowed_sources, true)) $source = "manual";

    try {
        $pdo = knk_db();
        // Pick a sort_order that lands at the END of the list — keep
        // existing entries above the newly added one so reorder is
        // optional, not required.
        $st = $pdo->prepare(
            "SELECT COALESCE(MAX(sort_order), 0) + 10
               FROM jukebox_playlists WHERE owner_email = ?"
        );
        $st->execute([$email]);
        $next_order = (int)$st->fetchColumn();
        if ($next_order < 10) $next_order = 10;

        $pdo->prepare(
            "INSERT INTO jukebox_playlists
               (owner_email, sort_order, youtube_video_id,
                youtube_title, youtube_channel, duration_seconds,
                thumbnail_url, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                youtube_title    = VALUES(youtube_title),
                youtube_channel  = VALUES(youtube_channel),
                duration_seconds = VALUES(duration_seconds),
                thumbnail_url    = VALUES(thumbnail_url),
                source           = VALUES(source)"
        )->execute([
            $email, $next_order, $vid,
            $title, $channel, $duration, $thumb, $source,
        ]);

        // Resolve the row id (insert OR existing — last_insert_id
        // doesn't help on the duplicate-key path).
        $st = $pdo->prepare(
            "SELECT id FROM jukebox_playlists
              WHERE owner_email = ? AND youtube_video_id = ?"
        );
        $st->execute([$email, $vid]);
        $id = (int)($st->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        error_log("knk_playlist_add: " . $e->getMessage());
        return null;
    }
}

/** Remove one track. Returns true if a row was deleted. */
function knk_playlist_remove(string $email, int $row_id): bool {
    $email = strtolower(trim($email));
    if ($email === "" || $row_id <= 0) return false;
    try {
        $st = knk_db()->prepare(
            "DELETE FROM jukebox_playlists WHERE id = ? AND owner_email = ?"
        );
        $st->execute([$row_id, $email]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        error_log("knk_playlist_remove: " . $e->getMessage());
        return false;
    }
}

/**
 * Save a new order. $row_ids must be a list of jukebox_playlists.id
 * values that all belong to $email — anything else is silently
 * dropped. Step is 10 so a future "drag between two existing rows"
 * has room to land between them without renumbering everything.
 */
function knk_playlist_reorder(string $email, array $row_ids): bool {
    $email = strtolower(trim($email));
    if ($email === "") return false;
    $ids = [];
    foreach ($row_ids as $rid) {
        $rid = (int)$rid;
        if ($rid > 0) $ids[] = $rid;
    }
    if (empty($ids)) return true;

    try {
        $pdo = knk_db();
        $pdo->beginTransaction();
        $up = $pdo->prepare(
            "UPDATE jukebox_playlists
                SET sort_order = ?
              WHERE id = ? AND owner_email = ?"
        );
        $order = 10;
        foreach ($ids as $rid) {
            $up->execute([$order, $rid, $email]);
            $order += 10;
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if (knk_db()->inTransaction()) knk_db()->rollBack();
        error_log("knk_playlist_reorder: " . $e->getMessage());
        return false;
    }
}

/* ==========================================================
 * CLAIM-FLOW HOOK
 * ========================================================== */

/**
 * When a guest claims their anon profile, point any playlist
 * rows they had under the anon email at the new real email.
 * Mirrors the existing rekey hooks in darts_lobby + follows.
 *
 * UNIQUE on (owner_email, video_id) means a video the user had
 * BOTH under both emails would collide on UPDATE — so we DELETE
 * the duplicates from the source side first.
 */
function knk_playlist_rekey_email(string $from, string $to): void {
    $from = strtolower(trim($from));
    $to   = strtolower(trim($to));
    if ($from === "" || $to === "" || $from === $to) return;
    try {
        $pdo = knk_db();
        // Drop duplicates: where the same video_id is on both sides,
        // keep the $to row (it's the "real" identity going forward).
        $pdo->prepare(
            "DELETE p1 FROM jukebox_playlists p1
              JOIN jukebox_playlists p2
                ON p2.owner_email = ?
               AND p2.youtube_video_id = p1.youtube_video_id
             WHERE p1.owner_email = ?"
        )->execute([$to, $from]);

        // Then move the rest across.
        $pdo->prepare(
            "UPDATE jukebox_playlists
                SET owner_email = ?
              WHERE owner_email = ?"
        )->execute([$to, $from]);

        // State row is single-keyed on owner_email. Same dedupe
        // pattern: delete the $to row if both exist, then move.
        $pdo->prepare(
            "DELETE FROM jukebox_playlist_state WHERE owner_email = ?
                AND EXISTS (SELECT 1 FROM jukebox_playlists WHERE owner_email = ?)"
        )->execute([$from, $to]);  // safety: no-op when $to has no rows
        $pdo->prepare(
            "UPDATE jukebox_playlist_state SET owner_email = ?
              WHERE owner_email = ?"
        )->execute([$to, $from]);
    } catch (Throwable $e) {
        error_log("knk_playlist_rekey_email: " . $e->getMessage());
    }
}

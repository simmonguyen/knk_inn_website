<?php
/*
 * KnK Inn — follows store (profile Phase 2).
 *
 * One-sided "follow" social graph, exactly like Twitter/Instagram:
 *
 *   - knk_follow($a, $b)       — $a starts following $b. No accept
 *                                step; the new connection is live
 *                                immediately. Drops a 'new_follower'
 *                                notification for $b so they see a
 *                                red dot on their avatar.
 *   - knk_unfollow($a, $b)     — undo. Notification is left in place
 *                                (read or not) — historical record.
 *   - knk_is_following()       — cheap point check.
 *   - knk_followers_for($x)    — emails that follow $x.
 *   - knk_followees_for($x)    — emails that $x follows.
 *   - knk_follow_counts($x)    — ['followers' => N, 'following' => M]
 *
 * Identity model:
 *   The follow graph is keyed off lower-cased emails — same as the
 *   rest of the profile system. Anon emails ("anon-…@anon.knkinn.com")
 *   are first-class. When someone claims an anon profile with a real
 *   email, knk_follows_rekey_email() rewrites every row that pointed
 *   at the anon address so the social graph survives the merge.
 *
 * No FKs to the guests table — at follow time the followee row may
 * not exist yet (e.g. someone tapped follow on a guest who's only
 * shown up on the "at the bar" panel and never opened a profile).
 *
 * Errors are swallowed and surfaced through return values so write
 * paths (the bar-shell avatar, the feed) don't 500 on a bad query.
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/notifications_store.php";
require_once __DIR__ . "/guests_store.php";

/* =========================================================
   FOLLOW / UNFOLLOW
   ========================================================= */

/**
 * $follower starts following $followee. Returns true on success
 * (including the no-op case where the row already existed).
 *
 * Validation:
 *   - both emails must be present + valid
 *   - you cannot follow yourself (returns false)
 *
 * Side effects:
 *   - INSERT IGNORE into follows (idempotent)
 *   - if the row was newly inserted, drop a 'new_follower'
 *     notification for the followee so the bell-icon dot appears
 */
function knk_follow(string $follower, string $followee): bool {
    $a = strtolower(trim($follower));
    $b = strtolower(trim($followee));
    if ($a === "" || $b === "")                       return false;
    if (!filter_var($a, FILTER_VALIDATE_EMAIL))       return false;
    if (!filter_var($b, FILTER_VALIDATE_EMAIL))       return false;
    if ($a === $b)                                    return false;

    try {
        $pdo  = knk_db();
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO follows (follower_email, followee_email)
             VALUES (?, ?)"
        );
        $stmt->execute([$a, $b]);
        $newly = ($stmt->rowCount() === 1);

        // Only fire a notification on a fresh follow — don't re-spam
        // the followee if the same person re-clicks "follow" repeatedly.
        if ($newly) {
            // Look up the follower's display name so the notification
            // text can be pre-rendered ("Tâm followed you") without an
            // extra lookup at render time.
            $row    = knk_guest_find_by_email($a);
            $disp   = function_exists("knk_profile_display_name_for")
                ? knk_profile_display_name_for($a, $row)
                : ($row["display_name"] ?? $a);
            knk_notify($b, "new_follower", $a, [
                "display_name" => (string)$disp,
            ]);
        }
        return true;
    } catch (Throwable $e) {
        error_log("knk_follow: " . $e->getMessage());
        return false;
    }
}

/**
 * Undo a follow. Returns true on success (also true if there was
 * nothing to undo — same end state).
 *
 * We deliberately leave the original 'new_follower' notification
 * in place; it represents a real moment in time. If the user wants
 * to clear it from their feed they tap the dismiss control.
 */
function knk_unfollow(string $follower, string $followee): bool {
    $a = strtolower(trim($follower));
    $b = strtolower(trim($followee));
    if ($a === "" || $b === "") return false;
    try {
        knk_db()->prepare(
            "DELETE FROM follows
              WHERE follower_email = ? AND followee_email = ?"
        )->execute([$a, $b]);
        return true;
    } catch (Throwable $e) {
        error_log("knk_unfollow: " . $e->getMessage());
        return false;
    }
}

/** Cheap "does $a follow $b right now?" check. */
function knk_is_following(string $follower, string $followee): bool {
    $a = strtolower(trim($follower));
    $b = strtolower(trim($followee));
    if ($a === "" || $b === "" || $a === $b) return false;
    try {
        $stmt = knk_db()->prepare(
            "SELECT 1 FROM follows
              WHERE follower_email = ? AND followee_email = ?
              LIMIT 1"
        );
        $stmt->execute([$a, $b]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log("knk_is_following: " . $e->getMessage());
        return false;
    }
}

/* =========================================================
   READ — list followers / followees
   ========================================================= */

/**
 * Emails that follow $email. Newest first. Capped at $limit.
 * Joins the guests table so callers can render a display name +
 * avatar_path without a second query per row.
 */
function knk_followers_for(string $email, int $limit = 100): array {
    $email = strtolower(trim($email));
    if ($email === "") return [];
    $limit = max(1, min(500, $limit));
    try {
        $sql = "SELECT f.follower_email AS email,
                       f.created_at,
                       g.display_name,
                       g.avatar_path,
                       g.last_seen_at
                  FROM follows f
                  LEFT JOIN guests g ON g.email = f.follower_email
                 WHERE f.followee_email = ?
              ORDER BY f.created_at DESC
                 LIMIT {$limit}";
        $stmt = knk_db()->prepare($sql);
        $stmt->execute([$email]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log("knk_followers_for: " . $e->getMessage());
        return [];
    }
}

/**
 * Emails that $email follows. Newest first. Capped at $limit.
 * Same shape as knk_followers_for() so the UI can reuse render code.
 */
function knk_followees_for(string $email, int $limit = 100): array {
    $email = strtolower(trim($email));
    if ($email === "") return [];
    $limit = max(1, min(500, $limit));
    try {
        $sql = "SELECT f.followee_email AS email,
                       f.created_at,
                       g.display_name,
                       g.avatar_path,
                       g.last_seen_at
                  FROM follows f
                  LEFT JOIN guests g ON g.email = f.followee_email
                 WHERE f.follower_email = ?
              ORDER BY f.created_at DESC
                 LIMIT {$limit}";
        $stmt = knk_db()->prepare($sql);
        $stmt->execute([$email]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log("knk_followees_for: " . $e->getMessage());
        return [];
    }
}

/**
 * Just the email addresses $email follows — used by the feed
 * query, where we need a fast IN(...) list and don't care about
 * display names. Returns ints/strings as a numerically-indexed
 * array.
 */
function knk_followee_emails(string $email): array {
    $email = strtolower(trim($email));
    if ($email === "") return [];
    try {
        $stmt = knk_db()->prepare(
            "SELECT followee_email FROM follows WHERE follower_email = ?"
        );
        $stmt->execute([$email]);
        $out = [];
        while ($row = $stmt->fetch()) {
            $out[] = (string)$row["followee_email"];
        }
        return $out;
    } catch (Throwable $e) {
        error_log("knk_followee_emails: " . $e->getMessage());
        return [];
    }
}

/**
 * { followers: N, following: M } — counts only, fast.
 */
function knk_follow_counts(string $email): array {
    $email = strtolower(trim($email));
    if ($email === "") return ["followers" => 0, "following" => 0];
    try {
        $pdo = knk_db();
        $f = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE followee_email = ?");
        $f->execute([$email]);
        $followers = (int)$f->fetchColumn();
        $g = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE follower_email = ?");
        $g->execute([$email]);
        $following = (int)$g->fetchColumn();
        return ["followers" => $followers, "following" => $following];
    } catch (Throwable $e) {
        error_log("knk_follow_counts: " . $e->getMessage());
        return ["followers" => 0, "following" => 0];
    }
}

/* =========================================================
   CLAIM-FLOW HOOK — re-key emails
   ========================================================= */

/**
 * Rewrite every follow row that points at $from so it now points
 * at $to. Used by knk_profile_apply_claim() when an anon profile
 * is promoted to a real email — the social graph follows along.
 *
 * Care has to be taken to avoid duplicate-key collisions on the
 * (follower_email, followee_email) UNIQUE index — if a row exists
 * for both ($X, $from) and ($X, $to), naive UPDATE blows up. So we
 * pre-delete any conflicting rows first.
 */
function knk_follows_rekey_email(string $from, string $to): void {
    $from = strtolower(trim($from));
    $to   = strtolower(trim($to));
    if ($from === "" || $to === "" || $from === $to) return;
    try {
        $pdo = knk_db();

        // Self-follow guard: if $X follows themselves under the
        // anon→real merge (i.e. $X follows $from and $X is also $to,
        // or $from follows $to), kill those rows up front.
        $pdo->prepare(
            "DELETE FROM follows
              WHERE (follower_email = ? AND followee_email = ?)
                 OR (follower_email = ? AND followee_email = ?)"
        )->execute([$from, $to, $to, $from]);

        // Conflicts where $X already follows $to AND $X follows $from
        // — keep the $to row, drop the $from row.
        $pdo->prepare(
            "DELETE f1 FROM follows f1
              JOIN follows f2
                ON f2.follower_email = f1.follower_email
               AND f2.followee_email = ?
             WHERE f1.followee_email = ?"
        )->execute([$to, $from]);

        // Conflicts where $from follows $X AND $to also follows $X.
        $pdo->prepare(
            "DELETE f1 FROM follows f1
              JOIN follows f2
                ON f2.followee_email = f1.followee_email
               AND f2.follower_email = ?
             WHERE f1.follower_email = ?"
        )->execute([$to, $from]);

        // Now the bulk re-key is safe.
        $pdo->prepare("UPDATE follows SET follower_email = ? WHERE follower_email = ?")
            ->execute([$to, $from]);
        $pdo->prepare("UPDATE follows SET followee_email = ? WHERE followee_email = ?")
            ->execute([$to, $from]);
    } catch (Throwable $e) {
        error_log("knk_follows_rekey_email: " . $e->getMessage());
    }
}

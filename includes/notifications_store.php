<?php
/*
 * KnK Inn — notifications store (profile Phase 2).
 *
 * Per-email in-app notifications. Each row has a recipient,
 * an open-vocabulary "kind", an optional actor (the email that
 * caused it), and a JSON payload for kind-specific data.
 *
 * Read state lives on the row itself — read_at NULL means unread.
 *
 * Phase 2 only emits one kind, 'new_follower'. The schema is
 * deliberately open so future kinds can slot in without migration:
 *   - 'friend_played_darts'    payload {game_id, game_type}
 *   - 'friend_queued_song'     payload {song_title}
 *   - 'staff_called_you_over'  payload {message}
 *
 * Identity model: same as the rest of the profile system —
 * email-keyed, anon emails first-class, claim-flow re-keys via
 * knk_notifications_rekey_email().
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";

/* =========================================================
   WRITE
   ========================================================= */

/**
 * Drop a notification for $recipient. Returns the new id, or null
 * on failure / validation reject.
 *
 * Anti-spam:
 *   - Self-notifications are rejected ($actor === $recipient).
 *   - Caller is responsible for idempotency at the kind level —
 *     e.g. follows_store guards against duplicate 'new_follower'
 *     rows by only firing on a fresh INSERT.
 */
function knk_notify(string $recipient, string $kind, ?string $actor = null, array $payload = []): ?int {
    $r = strtolower(trim($recipient));
    $k = trim($kind);
    $a = $actor !== null ? strtolower(trim($actor)) : null;
    if ($r === "" || $k === "")                  return null;
    if (!filter_var($r, FILTER_VALIDATE_EMAIL))  return null;
    if (mb_strlen($k) > 40)                      $k = mb_substr($k, 0, 40);
    if ($a !== null) {
        if ($a === "" || !filter_var($a, FILTER_VALIDATE_EMAIL)) {
            $a = null;
        } elseif ($a === $r) {
            // Don't notify yourself about your own actions.
            return null;
        }
    }

    $payload_json = !empty($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;
    if ($payload_json !== null && strlen($payload_json) > 4000) {
        // Soft cap. payload_json column is TEXT (~64K) so this is
        // just a sanity guard against pathological input.
        $payload_json = substr($payload_json, 0, 4000);
    }

    try {
        $pdo  = knk_db();
        $stmt = $pdo->prepare(
            "INSERT INTO notifications
                (recipient_email, kind, actor_email, payload_json)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$r, $k, $a, $payload_json]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log("knk_notify: " . $e->getMessage());
        return null;
    }
}

/* =========================================================
   READ
   ========================================================= */

/**
 * Return up to $limit recent notifications for $email, newest first.
 * payload_json is decoded into a "payload" key; raw column is dropped.
 */
function knk_notifications_for(string $email, int $limit = 30): array {
    $email = strtolower(trim($email));
    if ($email === "") return [];
    $limit = max(1, min(200, $limit));
    try {
        $stmt = knk_db()->prepare(
            "SELECT id, recipient_email, kind, actor_email,
                    payload_json, read_at, created_at
               FROM notifications
              WHERE recipient_email = ?
           ORDER BY created_at DESC
              LIMIT {$limit}"
        );
        $stmt->execute([$email]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r["payload"] = [];
            if (!empty($r["payload_json"])) {
                $decoded = json_decode((string)$r["payload_json"], true);
                if (is_array($decoded)) $r["payload"] = $decoded;
            }
            unset($r["payload_json"]);
        }
        unset($r);
        return $rows;
    } catch (Throwable $e) {
        error_log("knk_notifications_for: " . $e->getMessage());
        return [];
    }
}

/** How many unread notifications does $email have? Capped at 99. */
function knk_notifications_unread_count(string $email): int {
    $email = strtolower(trim($email));
    if ($email === "") return 0;
    try {
        $stmt = knk_db()->prepare(
            "SELECT COUNT(*) FROM notifications
              WHERE recipient_email = ? AND read_at IS NULL"
        );
        $stmt->execute([$email]);
        $n = (int)$stmt->fetchColumn();
        // Cap at 99 to keep the UI badge sane; "99+" is the clamp value.
        return $n > 99 ? 99 : $n;
    } catch (Throwable $e) {
        error_log("knk_notifications_unread_count: " . $e->getMessage());
        return 0;
    }
}

/* =========================================================
   MARK-READ
   ========================================================= */

/**
 * Mark all unread notifications for $email as read.
 * Returns the number of rows affected.
 */
function knk_notifications_mark_all_read(string $email): int {
    $email = strtolower(trim($email));
    if ($email === "") return 0;
    try {
        $stmt = knk_db()->prepare(
            "UPDATE notifications
                SET read_at = NOW()
              WHERE recipient_email = ? AND read_at IS NULL"
        );
        $stmt->execute([$email]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log("knk_notifications_mark_all_read: " . $e->getMessage());
        return 0;
    }
}

/**
 * Mark a single notification as read. The $email is checked against
 * the row's recipient_email so users can't mark each other's
 * notifications. Returns true on success (also true if there was
 * nothing to mark — same end state).
 */
function knk_notifications_mark_read(int $id, string $email): bool {
    $email = strtolower(trim($email));
    if ($id <= 0 || $email === "") return false;
    try {
        $stmt = knk_db()->prepare(
            "UPDATE notifications
                SET read_at = NOW()
              WHERE id = ? AND recipient_email = ? AND read_at IS NULL"
        );
        $stmt->execute([$id, $email]);
        return true;
    } catch (Throwable $e) {
        error_log("knk_notifications_mark_read: " . $e->getMessage());
        return false;
    }
}

/* =========================================================
   CLAIM-FLOW HOOK — re-key emails
   ========================================================= */

/**
 * Rewrite every notifications row that referenced $from so it
 * now references $to. Both recipient_email and actor_email get
 * updated in case the anon address appears on either side.
 *
 * Used by knk_profile_apply_claim() when an anon profile is
 * promoted to a real email — notifications follow the merge so
 * the user keeps their history (including the bell-icon dot if
 * they hadn't read it yet).
 */
function knk_notifications_rekey_email(string $from, string $to): void {
    $from = strtolower(trim($from));
    $to   = strtolower(trim($to));
    if ($from === "" || $to === "" || $from === $to) return;
    try {
        $pdo = knk_db();
        $pdo->prepare("UPDATE notifications SET recipient_email = ? WHERE recipient_email = ?")
            ->execute([$to, $from]);
        $pdo->prepare("UPDATE notifications SET actor_email     = ? WHERE actor_email     = ?")
            ->execute([$to, $from]);

        // Edge case: a self-notification that became one through the
        // merge (e.g. recipient was anon, actor was real, then merged
        // into the same email). Drop those — they shouldn't exist.
        $pdo->prepare("DELETE FROM notifications WHERE recipient_email = actor_email")
            ->execute();
    } catch (Throwable $e) {
        error_log("knk_notifications_rekey_email: " . $e->getMessage());
    }
}

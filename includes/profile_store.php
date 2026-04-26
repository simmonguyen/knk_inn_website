<?php
/*
 * KnK Inn — profile store (Phase 1).
 *
 * Backs /profile.php (and /bar.php?tab=profile) — a guest-facing
 * page that lets someone see their own activity and, if they want
 * it to follow them across devices, claim the profile with a real
 * email via a magic link.
 *
 * Identity model:
 *   - Default identity for a bar guest is the long-lasting cookie
 *     in /order.php's KNK_GUEST_ANON_COOKIE. The email derived
 *     from that cookie is "anon-<16hex>@anon.knkinn.com".
 *   - When the guest claims with a real email, every row keyed by
 *     the anon email gets updated to the real email, and the anon
 *     guests row is merged into (or renamed to) the real one.
 *
 * What "activity" means right now:
 *   - drinks orders   — read from orders.json by guest_email
 *   - song requests   — read from jukebox_queue by requester_email
 *   - darts games     — read from darts_players by guest_email
 *
 * Old rows that pre-date this feature won't have an email column
 * filled in, so they won't show on the profile. New rows (after
 * we wire jukebox.php / darts.php to write the email) will.
 *
 * Gating: the profile page is opt-in for guests — there's no
 * permission system to enforce. The implicit gate is "do you have
 * the cookie / are you holding the magic link?".
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/guests_store.php";
require_once __DIR__ . "/orders_store.php";
require_once __DIR__ . "/smtp_send.php";
require_once __DIR__ . "/email_template.php";
require_once __DIR__ . "/follows_store.php";
require_once __DIR__ . "/notifications_store.php";

/* The anon-email domain matches the constant defined in order.php
 * (KNK_GUEST_ANON_DOMAIN). We re-declare it here so this file is
 * usable without including order.php. */
if (!defined("KNK_PROFILE_ANON_DOMAIN")) {
    define("KNK_PROFILE_ANON_DOMAIN", "anon.knkinn.com");
}

/* Magic-link claim token lifetime — 30 minutes. Kept short on
 * purpose: the email is meant to be clicked from the same phone
 * the guest is already holding. */
if (!defined("KNK_PROFILE_CLAIM_TTL")) {
    define("KNK_PROFILE_CLAIM_TTL", 30 * 60);
}

/* =========================================================
   IDENTITY HELPERS
   ========================================================= */

/** Is this email a synthetic anon address (anon-…@anon.knkinn.com)? */
function knk_profile_is_anon_email(string $email): bool {
    $email = strtolower(trim($email));
    if ($email === "") return false;
    $suffix = "@" . KNK_PROFILE_ANON_DOMAIN;
    $len    = strlen($suffix);
    return strlen($email) > $len && substr($email, -$len) === $suffix;
}

/**
 * Friendly display name for the profile header.
 *
 *   - If guests.display_name is set, use it.
 *   - If the anon email is anon-4f3a…@anon.knkinn.com, fall back
 *     to "Guest 4f3a" (first 4 of the token).
 *   - Otherwise fall back to the local-part of the email.
 */
function knk_profile_display_name_for(string $email, ?array $guest_row = null): string {
    $email = strtolower(trim($email));
    if ($guest_row && !empty($guest_row["display_name"])) {
        return (string)$guest_row["display_name"];
    }
    if (knk_profile_is_anon_email($email)) {
        // anon-4f3a2b1c…@anon.knkinn.com  →  "Guest 4f3a"
        $local = strstr($email, "@", true);
        if ($local !== false && strpos($local, "anon-") === 0) {
            $token = substr($local, 5);
            $short = substr($token, 0, 4);
            if ($short !== "") return "Guest " . strtoupper($short);
        }
        return "Guest";
    }
    $local = strstr($email, "@", true);
    return $local !== false ? $local : $email;
}

/* =========================================================
   DISPLAY NAME — get / set
   ========================================================= */

/**
 * Update the display name on the guests row for this email.
 * Upserts the guests row if it doesn't exist yet (e.g. a brand-new
 * anon visitor that's never ordered anything).
 *
 * Returns true on success, false on failure (validation or DB).
 */
function knk_profile_set_display_name(string $email, string $display_name): bool {
    $email = strtolower(trim($email));
    $name  = trim($display_name);
    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    if ($name === "")        return false;
    if (mb_strlen($name) > 60) $name = mb_substr($name, 0, 60);

    try {
        // Make sure a row exists.
        $gid = knk_guest_upsert($email);
        if (!$gid) return false;
        $pdo = knk_db();
        $pdo->prepare("UPDATE guests SET display_name = ? WHERE id = ?")
            ->execute([$name, $gid]);
        return true;
    } catch (Throwable $e) {
        error_log("knk_profile_set_display_name: " . $e->getMessage());
        return false;
    }
}

/* =========================================================
   ACTIVITY — orders / songs / darts
   ========================================================= */

/**
 * Drinks orders for this email. Returns the same shape as
 * orders_for_email — already newest-first.
 */
function knk_profile_orders(string $email): array {
    $email = strtolower(trim($email));
    if ($email === "") return [];
    try {
        return orders_for_email($email);
    } catch (Throwable $e) {
        error_log("knk_profile_orders: " . $e->getMessage());
        return [];
    }
}

/**
 * Song requests for this email. Latest first, capped at $limit.
 *
 * Status semantics for the profile UI:
 *   - 'queued'   — still in the queue, not playing yet
 *   - 'playing'  — on TV right now
 *   - 'played'   — finished playing
 *   - 'skipped'  — staff skipped
 *   - 'rejected' — staff rejected (autoapprove=0 mode)
 *   - 'pending'  — waiting for staff approval
 *
 * Old jukebox rows that pre-date the requester_email column will
 * have an empty string in that column and won't match.
 */
function knk_profile_songs(string $email, int $limit = 50): array {
    $email = strtolower(trim($email));
    if ($email === "") return [];
    $limit = max(1, min(200, $limit));
    try {
        $sql = "SELECT id,
                       youtube_video_id,
                       youtube_title,
                       youtube_channel,
                       thumbnail_url,
                       duration_seconds,
                       status,
                       submitted_at,
                       played_at
                  FROM jukebox_queue
                 WHERE requester_email = ?
              ORDER BY submitted_at DESC
                 LIMIT {$limit}";
        $stmt = knk_db()->prepare($sql);
        $stmt->execute([$email]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log("knk_profile_songs: " . $e->getMessage());
        return [];
    }
}

/**
 * Darts games this email played in. One row per game, with the
 * player's slot/result. Latest first, capped at $limit.
 *
 * Returns rows with these keys:
 *   game_id, board_id, game_type, format, status,
 *   started_at, finished_at, slot_no, finishing_position,
 *   winner_slot_no, winner_team_no, my_team_no
 */
function knk_profile_darts(string $email, int $limit = 50): array {
    $email = strtolower(trim($email));
    if ($email === "") return [];
    $limit = max(1, min(200, $limit));
    try {
        $sql = "SELECT g.id              AS game_id,
                       g.board_id,
                       g.game_type,
                       g.format,
                       g.status,
                       g.started_at,
                       g.finished_at,
                       g.winner_slot_no,
                       g.winner_team_no,
                       p.slot_no,
                       p.team_no         AS my_team_no,
                       p.finishing_position
                  FROM darts_players p
                  JOIN darts_games   g ON g.id = p.game_id
                 WHERE p.guest_email = ?
              ORDER BY COALESCE(g.finished_at, g.started_at, g.created_at) DESC
                 LIMIT {$limit}";
        $stmt = knk_db()->prepare($sql);
        $stmt->execute([$email]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log("knk_profile_darts: " . $e->getMessage());
        return [];
    }
}

/* =========================================================
   CLAIM FLOW — request token, validate, apply
   ========================================================= */

/**
 * Generate a fresh claim token for this anon profile and write it
 * to the guests row. Caller is expected to mail the magic link.
 *
 * Returns the token (40-char hex) on success, or null on failure.
 *
 * Validations:
 *   - $anon_email must be a valid anon-*@anon.knkinn.com address
 *   - $real_email must be a valid non-anon email
 *   - Both must differ
 *
 * If the anon guest row doesn't exist yet, this upserts one so the
 * token has somewhere to live.
 */
function knk_profile_create_claim_token(string $anon_email, string $real_email): ?string {
    $anon_email = strtolower(trim($anon_email));
    $real_email = strtolower(trim($real_email));
    if (!filter_var($anon_email, FILTER_VALIDATE_EMAIL)) return null;
    if (!filter_var($real_email, FILTER_VALIDATE_EMAIL)) return null;
    if (!knk_profile_is_anon_email($anon_email))         return null;
    if (knk_profile_is_anon_email($real_email))          return null;
    if ($anon_email === $real_email)                     return null;

    try {
        $gid = knk_guest_upsert($anon_email);
        if (!$gid) return null;

        $token   = bin2hex(random_bytes(20));        // 40 hex chars
        $expires = date("Y-m-d H:i:s", time() + KNK_PROFILE_CLAIM_TTL);

        $pdo = knk_db();
        $pdo->prepare(
            "UPDATE guests
                SET claim_token            = ?,
                    claim_token_expires_at = ?,
                    claim_pending_email    = ?
              WHERE id = ?"
        )->execute([$token, $expires, $real_email, $gid]);
        return $token;
    } catch (Throwable $e) {
        error_log("knk_profile_create_claim_token: " . $e->getMessage());
        return null;
    }
}

/**
 * Validate a claim token and return the guest row if it's still
 * valid (not expired, has a pending email). Returns null otherwise.
 *
 * Read-only — does not consume the token. Use knk_profile_apply_claim
 * to actually perform the merge.
 */
function knk_profile_validate_claim_token(string $token): ?array {
    $token = trim($token);
    if (strlen($token) !== 40 || !ctype_xdigit($token)) return null;
    try {
        $stmt = knk_db()->prepare(
            "SELECT * FROM guests
              WHERE claim_token = ?
                AND claim_token_expires_at IS NOT NULL
                AND claim_token_expires_at > NOW()
                AND claim_pending_email IS NOT NULL
              LIMIT 1"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row ? $row : null;
    } catch (Throwable $e) {
        error_log("knk_profile_validate_claim_token: " . $e->getMessage());
        return null;
    }
}

/**
 * Apply the claim: re-key all activity from the anon email to the
 * real email, merge the guests row, clear the token. Returns the
 * real email on success, or null on failure.
 *
 * What gets re-keyed:
 *   - orders.json:        every order with email = anon_email
 *   - jukebox_queue:      every row with requester_email = anon_email
 *   - darts_players:      every row with guest_email = anon_email
 *   - follows:            both follower_email and followee_email
 *                          (with conflict-row pre-deletes — see
 *                          knk_follows_rekey_email)
 *   - notifications:      both recipient_email and actor_email,
 *                          with self-notifications dropped post-merge
 *
 * Bookings and the bookings.json are left alone — bookings always
 * come in with a real email via /enquire.php, so an anon guest
 * shouldn't have any.
 *
 * Guests-row merge:
 *   - If a guests row already exists for the real email, the anon
 *     row is deleted (its display name / counters get refreshed
 *     against the now-merged orders).
 *   - Otherwise the anon row is renamed to the real email and the
 *     claim columns cleared.
 */
function knk_profile_apply_claim(string $token): ?string {
    $row = knk_profile_validate_claim_token($token);
    if (!$row) return null;

    $anon_email = strtolower(trim((string)$row["email"]));
    $real_email = strtolower(trim((string)$row["claim_pending_email"]));
    if ($anon_email === "" || $real_email === "") return null;
    if (!knk_profile_is_anon_email($anon_email))  return null;
    if (knk_profile_is_anon_email($real_email))   return null;
    if ($anon_email === $real_email)              return null;

    // Step 1 — re-key orders.json (V2 transitional store).
    try {
        knk_profile_rekey_orders_json($anon_email, $real_email);
    } catch (Throwable $e) {
        error_log("knk_profile_apply_claim/orders: " . $e->getMessage());
        // keep going — the DB rekey is more important
    }

    // Step 2 — re-key jukebox_queue + darts_players.
    try {
        $pdo = knk_db();
        $pdo->prepare("UPDATE jukebox_queue SET requester_email = ? WHERE requester_email = ?")
            ->execute([$real_email, $anon_email]);
        $pdo->prepare("UPDATE darts_players SET guest_email = ? WHERE guest_email = ?")
            ->execute([$real_email, $anon_email]);
    } catch (Throwable $e) {
        error_log("knk_profile_apply_claim/db: " . $e->getMessage());
        return null;
    }

    // Step 2b — re-key the social graph + notifications.
    //
    // Follows has UNIQUE(follower_email, followee_email), so a naive
    // bulk UPDATE would 1062 if both ($X, $anon) and ($X, $real) rows
    // exist. knk_follows_rekey_email pre-deletes those conflicts and
    // then runs the UPDATEs. Failure here is non-fatal — the bar still
    // works without follows; we just log and continue. Notifications
    // is similar: we rewrite both columns and drop any self-notifs the
    // merge accidentally creates.
    try {
        knk_follows_rekey_email($anon_email, $real_email);
    } catch (Throwable $e) {
        error_log("knk_profile_apply_claim/follows: " . $e->getMessage());
    }
    try {
        knk_notifications_rekey_email($anon_email, $real_email);
    } catch (Throwable $e) {
        error_log("knk_profile_apply_claim/notifications: " . $e->getMessage());
    }

    // Step 3 — merge / rename the guests row.
    try {
        $pdo = knk_db();
        $real_existing = knk_guest_find_by_email($real_email);
        if ($real_existing) {
            // The real row already exists — pull the anon row's
            // avatar across before deleting it, but only if the real
            // row doesn't already have one. (If it does, we keep the
            // older / stable photo and let avatar_store clean up the
            // anon's file when we delete its row below.)
            $anon_row = knk_guest_find_by_email($anon_email);
            $anon_avatar  = $anon_row  ? (string)($anon_row["avatar_path"]  ?? "") : "";
            $real_avatar  = (string)($real_existing["avatar_path"] ?? "");
            if ($anon_avatar !== "" && $real_avatar === "") {
                $pdo->prepare("UPDATE guests SET avatar_path = ? WHERE id = ?")
                    ->execute([$anon_avatar, (int)$real_existing["id"]]);
                // Null it on the anon row so the file isn't deleted
                // when we drop the row.
                $pdo->prepare("UPDATE guests SET avatar_path = NULL WHERE email = ?")
                    ->execute([$anon_email]);
            }
            // Delete the anon row outright; the real row's counters
            // will be refreshed below.
            $pdo->prepare("DELETE FROM guests WHERE email = ?")
                ->execute([$anon_email]);
        } else {
            // Rename the anon row to the real email and clear the
            // claim columns. Display name (if the guest set one)
            // carries across.
            $pdo->prepare(
                "UPDATE guests
                    SET email                  = ?,
                        claim_token            = NULL,
                        claim_token_expires_at = NULL,
                        claim_pending_email    = NULL
                  WHERE email = ?"
            )->execute([$real_email, $anon_email]);
        }

        // Refresh the real-email guest's cached counters now that
        // orders point at it.
        $real = knk_guest_find_by_email($real_email);
        if ($real) {
            knk_guest_refresh_stats((int)$real["id"]);
        }
    } catch (Throwable $e) {
        error_log("knk_profile_apply_claim/merge: " . $e->getMessage());
        return null;
    }

    return $real_email;
}

/**
 * Rewrite the orders JSON so every order with email=$anon now has
 * email=$real. Uses the same lock helpers as orders_store. Internal.
 */
function knk_profile_rekey_orders_json(string $anon, string $real): void {
    if (!function_exists("orders_open") || !function_exists("orders_save")) return;
    [$fp, $data] = orders_open();
    try {
        $changed = 0;
        if (!empty($data["orders"]) && is_array($data["orders"])) {
            foreach ($data["orders"] as &$o) {
                if (strtolower((string)($o["email"] ?? "")) === $anon) {
                    $o["email"] = $real;
                    $changed++;
                }
            }
            unset($o);
        }
        if ($changed > 0) {
            orders_save($fp, $data);    // releases lock + closes
        } else {
            orders_close($fp);          // drop the lock without writing
        }
    } catch (Throwable $e) {
        // Make sure the lock is released even on error.
        @flock($fp, LOCK_UN);
        @fclose($fp);
        throw $e;
    }
}

/* =========================================================
   EMAIL — magic link
   ========================================================= */

/**
 * Send the claim magic-link email. Returns true on success.
 *
 * Pulls SMTP creds from $CFG (the same shape /enquire.php and
 * /order.php use). The link points at the public site URL — by
 * default https://knkinn.com — so the link works regardless of
 * which device the guest opens their email on.
 */
function knk_profile_send_claim_email(array $cfg, string $real_email, string $token, string $site_url = "https://knkinn.com"): bool {
    $real_email = strtolower(trim($real_email));
    if (!filter_var($real_email, FILTER_VALIDATE_EMAIL)) return false;
    if (strlen($token) !== 40)                          return false;

    $url = rtrim($site_url, "/") . "/claim-confirm.php?token=" . urlencode($token);

    $title     = "Confirm your KnK Inn account";
    $preheader = "One tap to link your KnK Inn activity to this email.";

    $btn = function_exists("knk_email_button")
        ? knk_email_button("Confirm — link my activity", $url, "primary")
        : '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, "UTF-8") . '">Confirm — link my activity</a></p>';

    $body  = '<p>G\'day from KnK Inn!</p>';
    $body .= '<p>Tap the button below to link the orders, songs and darts you\'ve been racking up at the bar to <b>' . htmlspecialchars($real_email, ENT_QUOTES, "UTF-8") . '</b>. After this, your history follows you across devices.</p>';
    $body .= $btn;
    $body .= '<p style="font-size:13px;color:#7d6a44;">This link expires in 30 minutes. If you didn\'t request this, just ignore the email — nothing changes.</p>';

    $html = function_exists("knk_email_html")
        ? knk_email_html($title, $preheader, $body, "Sent automatically by knkinn.com — please don't reply.")
        : '<html><body>' . $body . '</body></html>';

    $plain = "Confirm your KnK Inn account.\n\n"
           . "Open this link to link your bar activity to {$real_email}:\n\n"
           . "{$url}\n\n"
           . "This link expires in 30 minutes.\n";

    $err = null;
    $ok  = smtp_send([
        "host"           => (string)($cfg["smtp_host"]     ?? "smtp.gmail.com"),
        "port"           => (int)   ($cfg["smtp_port"]     ?? 465),
        "secure"         => (string)($cfg["smtp_secure"]   ?? "ssl"),
        "username"       => (string)($cfg["smtp_username"] ?? ""),
        "password"       => (string)($cfg["smtp_password"] ?? ""),
        "from_email"     => (string)($cfg["from_email"]    ?? ""),
        "from_name"      => (string)($cfg["from_name"]     ?? "KnK Inn"),
        "to"             => $real_email,
        "subject"        => $title,
        "body"           => $plain,
        "html"           => $html,
    ], $err);
    if (!$ok) error_log("knk_profile_send_claim_email: {$err}");
    return $ok;
}

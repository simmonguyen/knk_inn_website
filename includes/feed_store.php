<?php
/*
 * KnK Inn — feed store (profile Phase 2).
 *
 * Three reads that power the social side of the profile page:
 *
 *   1) knk_feed_for($me)              — "what your friends are up to"
 *                                       Merged + sorted activity from
 *                                       every email you follow:
 *                                         orders (drinks)
 *                                         jukebox_queue (song requests)
 *                                         darts_players (darts games)
 *
 *   2) knk_at_bar_recent($me, $hours) — "at the bar tonight"
 *                                       Distinct emails (≠ $me) who've
 *                                       done anything in the last few
 *                                       hours. Used to seed friend
 *                                       discovery for guests with no
 *                                       follows yet.
 *
 *   3) knk_friend_suggestions($me)    — "you might know"
 *                                       Heuristic — emails who shared
 *                                       a darts game with me OR queued
 *                                       a song within 10 minutes of
 *                                       one of mine. Self / already-
 *                                       followed are filtered out.
 *
 * All three return rows with a consistent display shape so the UI
 * can render them without per-row branching:
 *
 *   { email, display_name, avatar_path, last_activity_at,
 *     kind, summary, ts }
 *
 *   - kind/summary/ts are only set on the merged feed.
 *   - last_activity_at is set on the at-bar / suggestions lists.
 *
 * Errors are swallowed and surfaced as empty arrays.
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/follows_store.php";
require_once __DIR__ . "/orders_store.php";
require_once __DIR__ . "/guests_store.php";
require_once __DIR__ . "/profile_store.php";

/* =========================================================
   FRIEND FEED — merged orders + songs + darts
   ========================================================= */

/**
 * Newest-first feed of activity from emails that $me follows.
 *
 * Limited to $limit total entries (post-merge). Each entry is one
 * activity event. If a single follower-email has 50 orders today,
 * up to $limit of those still come through — we don't deduplicate
 * by author, by design. Future iterations can add per-author quotas.
 *
 * Row shape:
 *   email           — the actor email
 *   display_name    — pre-resolved via knk_profile_display_name_for
 *   avatar_path     — guests.avatar_path or "" if none
 *   ts              — unix timestamp (sorted DESC)
 *   kind            — "order" | "song" | "darts"
 *   summary         — short human-readable string for the feed row
 *   meta            — kind-specific extras (order id, song title, etc.)
 */
function knk_feed_for(string $me, int $limit = 30): array {
    $me = strtolower(trim($me));
    if ($me === "") return [];
    $limit = max(1, min(200, $limit));

    $followees = knk_followee_emails($me);
    if (empty($followees)) return [];

    // Per-source we pull at most $cap rows so a single noisy source
    // can't starve the merge. The post-merge sort then trims to $limit.
    $cap   = $limit * 3;
    $items = [];

    // ----- orders (JSON file) -----
    try {
        $follow_set = array_flip($followees);
        $all = function_exists("orders_all") ? orders_all() : [];
        $matched = 0;
        // orders_all() isn't sorted; collect first, sort later.
        $tmp = [];
        foreach ($all as $o) {
            $oe = strtolower((string)($o["email"] ?? ""));
            if ($oe === "" || !isset($follow_set[$oe])) continue;
            $ts = (int)($o["created_at"] ?? 0);
            if ($ts <= 0) continue;
            $tmp[] = [
                "email"   => $oe,
                "ts"      => $ts,
                "kind"    => "order",
                "summary" => knk_feed_summarise_order($o),
                "meta"    => [
                    "order_id" => (string)($o["id"] ?? ""),
                    "total"    => (int)($o["total_vnd"] ?? 0),
                ],
            ];
        }
        usort($tmp, function ($a, $b) { return $b["ts"] <=> $a["ts"]; });
        foreach (array_slice($tmp, 0, $cap) as $e) {
            $items[] = $e;
            $matched++;
        }
    } catch (Throwable $e) {
        error_log("knk_feed_for/orders: " . $e->getMessage());
    }

    // ----- songs (jukebox_queue) -----
    try {
        $placeholders = implode(",", array_fill(0, count($followees), "?"));
        $sql = "SELECT requester_email, youtube_title, youtube_channel,
                       UNIX_TIMESTAMP(submitted_at) AS ts,
                       status
                  FROM jukebox_queue
                 WHERE requester_email IN ({$placeholders})
              ORDER BY submitted_at DESC
                 LIMIT {$cap}";
        $stmt = knk_db()->prepare($sql);
        $stmt->execute($followees);
        while ($r = $stmt->fetch()) {
            $items[] = [
                "email"   => (string)$r["requester_email"],
                "ts"      => (int)$r["ts"],
                "kind"    => "song",
                "summary" => knk_feed_summarise_song(
                    (string)$r["youtube_title"],
                    (string)$r["youtube_channel"],
                    (string)$r["status"]
                ),
                "meta"    => [
                    "title"   => (string)$r["youtube_title"],
                    "channel" => (string)$r["youtube_channel"],
                    "status"  => (string)$r["status"],
                ],
            ];
        }
    } catch (Throwable $e) {
        error_log("knk_feed_for/songs: " . $e->getMessage());
    }

    // ----- darts (darts_players JOIN darts_games) -----
    try {
        $placeholders = implode(",", array_fill(0, count($followees), "?"));
        $sql = "SELECT p.guest_email,
                       g.id           AS game_id,
                       g.game_type,
                       g.format,
                       g.status,
                       g.winner_slot_no,
                       g.winner_team_no,
                       p.slot_no,
                       p.team_no       AS my_team_no,
                       p.finishing_position,
                       UNIX_TIMESTAMP(COALESCE(g.finished_at, g.started_at, g.created_at)) AS ts
                  FROM darts_players p
                  JOIN darts_games   g ON g.id = p.game_id
                 WHERE p.guest_email IN ({$placeholders})
              ORDER BY ts DESC
                 LIMIT {$cap}";
        $stmt = knk_db()->prepare($sql);
        $stmt->execute($followees);
        while ($r = $stmt->fetch()) {
            $items[] = [
                "email"   => (string)$r["guest_email"],
                "ts"      => (int)$r["ts"],
                "kind"    => "darts",
                "summary" => knk_feed_summarise_darts($r),
                "meta"    => [
                    "game_id"   => (int)$r["game_id"],
                    "game_type" => (string)$r["game_type"],
                    "format"    => (string)$r["format"],
                    "status"    => (string)$r["status"],
                ],
            ];
        }
    } catch (Throwable $e) {
        error_log("knk_feed_for/darts: " . $e->getMessage());
    }

    // ----- merge + sort + trim, then resolve guest display names -----
    usort($items, function ($a, $b) { return $b["ts"] <=> $a["ts"]; });
    $items = array_slice($items, 0, $limit);
    if (empty($items)) return [];

    // Resolve display names + avatars in one pass.
    $emails = [];
    foreach ($items as $it) $emails[$it["email"]] = true;
    $emails = array_keys($emails);
    $rows_by_email = [];
    if (!empty($emails)) {
        try {
            $placeholders = implode(",", array_fill(0, count($emails), "?"));
            $sql = "SELECT email, display_name, avatar_path
                      FROM guests
                     WHERE email IN ({$placeholders})";
            $stmt = knk_db()->prepare($sql);
            $stmt->execute($emails);
            while ($r = $stmt->fetch()) {
                $rows_by_email[strtolower((string)$r["email"])] = $r;
            }
        } catch (Throwable $e) {
            error_log("knk_feed_for/lookup: " . $e->getMessage());
        }
    }
    foreach ($items as &$it) {
        $row = $rows_by_email[$it["email"]] ?? null;
        $it["display_name"] = knk_profile_display_name_for($it["email"], $row);
        $it["avatar_path"]  = $row ? (string)($row["avatar_path"] ?? "") : "";
    }
    unset($it);

    return $items;
}

/* =========================================================
   AT-BAR — recent activity excluding $me
   ========================================================= */

/**
 * Distinct emails that have done anything (ordered, queued a song,
 * or played darts) in the last $hours hours, excluding $me.
 *
 * Returns rows with:
 *   email, display_name, avatar_path, last_activity_at (unix ts),
 *   activity_kind ("order" | "song" | "darts")
 *
 * Newest-active first. Capped at $limit.
 */
function knk_at_bar_recent(string $me, int $hours = 4, int $limit = 20): array {
    $me = strtolower(trim($me));
    $hours = max(1, min(72, $hours));
    $limit = max(1, min(50, $limit));
    $cutoff = time() - ($hours * 3600);

    $by_email = []; // email => [ts, kind]

    // ----- orders -----
    try {
        $all = function_exists("orders_all") ? orders_all() : [];
        foreach ($all as $o) {
            $ts = (int)($o["created_at"] ?? 0);
            if ($ts < $cutoff) continue;
            $oe = strtolower((string)($o["email"] ?? ""));
            if ($oe === "" || $oe === $me) continue;
            if (!isset($by_email[$oe]) || $ts > $by_email[$oe]["ts"]) {
                $by_email[$oe] = ["ts" => $ts, "kind" => "order"];
            }
        }
    } catch (Throwable $e) {
        error_log("knk_at_bar_recent/orders: " . $e->getMessage());
    }

    // ----- songs -----
    try {
        $sql = "SELECT requester_email, UNIX_TIMESTAMP(submitted_at) AS ts
                  FROM jukebox_queue
                 WHERE requester_email <> ''
                   AND submitted_at >= FROM_UNIXTIME(?)";
        $stmt = knk_db()->prepare($sql);
        $stmt->execute([$cutoff]);
        while ($r = $stmt->fetch()) {
            $em = strtolower((string)$r["requester_email"]);
            if ($em === "" || $em === $me) continue;
            $ts = (int)$r["ts"];
            if (!isset($by_email[$em]) || $ts > $by_email[$em]["ts"]) {
                $by_email[$em] = ["ts" => $ts, "kind" => "song"];
            }
        }
    } catch (Throwable $e) {
        error_log("knk_at_bar_recent/songs: " . $e->getMessage());
    }

    // ----- darts -----
    try {
        $sql = "SELECT p.guest_email,
                       UNIX_TIMESTAMP(COALESCE(g.finished_at, g.started_at, g.created_at)) AS ts
                  FROM darts_players p
                  JOIN darts_games   g ON g.id = p.game_id
                 WHERE p.guest_email <> ''
                   AND COALESCE(g.finished_at, g.started_at, g.created_at) >= FROM_UNIXTIME(?)";
        $stmt = knk_db()->prepare($sql);
        $stmt->execute([$cutoff]);
        while ($r = $stmt->fetch()) {
            $em = strtolower((string)$r["guest_email"]);
            if ($em === "" || $em === $me) continue;
            $ts = (int)$r["ts"];
            if (!isset($by_email[$em]) || $ts > $by_email[$em]["ts"]) {
                $by_email[$em] = ["ts" => $ts, "kind" => "darts"];
            }
        }
    } catch (Throwable $e) {
        error_log("knk_at_bar_recent/darts: " . $e->getMessage());
    }

    if (empty($by_email)) return [];

    // ----- sort + cap + resolve display names -----
    $rows = [];
    foreach ($by_email as $em => $info) {
        $rows[] = [
            "email"            => $em,
            "last_activity_at" => $info["ts"],
            "activity_kind"    => $info["kind"],
        ];
    }
    usort($rows, function ($a, $b) {
        return $b["last_activity_at"] <=> $a["last_activity_at"];
    });
    $rows = array_slice($rows, 0, $limit);

    $emails = array_map(function ($r) { return $r["email"]; }, $rows);
    $rows_by_email = [];
    try {
        if (!empty($emails)) {
            $placeholders = implode(",", array_fill(0, count($emails), "?"));
            $sql = "SELECT email, display_name, avatar_path
                      FROM guests
                     WHERE email IN ({$placeholders})";
            $stmt = knk_db()->prepare($sql);
            $stmt->execute($emails);
            while ($r = $stmt->fetch()) {
                $rows_by_email[strtolower((string)$r["email"])] = $r;
            }
        }
    } catch (Throwable $e) {
        error_log("knk_at_bar_recent/lookup: " . $e->getMessage());
    }
    foreach ($rows as &$r) {
        $row = $rows_by_email[$r["email"]] ?? null;
        $r["display_name"] = knk_profile_display_name_for($r["email"], $row);
        $r["avatar_path"]  = $row ? (string)($row["avatar_path"] ?? "") : "";
    }
    unset($r);

    return $rows;
}

/* =========================================================
   SUGGESTIONS — same-darts-game / adjacent-song
   ========================================================= */

/**
 * Suggest emails $me might want to follow.
 *
 * Heuristic 1: people who shared a darts game with $me (highest
 *              priority — they actually interacted).
 * Heuristic 2: people who queued a song within 10 minutes of one
 *              of $me's songs (musical taste overlap).
 *
 * Excludes self + everyone $me already follows. Returns rows with
 *   email, display_name, avatar_path, reason
 * where reason is "darts" or "music" (darts wins on tie).
 */
function knk_friend_suggestions(string $me, int $limit = 10): array {
    $me = strtolower(trim($me));
    if ($me === "") return [];
    $limit = max(1, min(20, $limit));

    $already = array_flip(knk_followee_emails($me));
    $already[$me] = true; // never suggest yourself
    $by_email = []; // email => ["reason" => "darts"|"music", "ts" => unix]

    // ----- darts: same game_id -----
    try {
        $sql = "SELECT p2.guest_email,
                       UNIX_TIMESTAMP(COALESCE(g.finished_at, g.started_at, g.created_at)) AS ts
                  FROM darts_players p1
                  JOIN darts_players p2 ON p2.game_id = p1.game_id AND p2.guest_email <> p1.guest_email
                  JOIN darts_games   g  ON g.id = p1.game_id
                 WHERE p1.guest_email = ?
                   AND p2.guest_email <> ''
              ORDER BY ts DESC
                 LIMIT 100";
        $stmt = knk_db()->prepare($sql);
        $stmt->execute([$me]);
        while ($r = $stmt->fetch()) {
            $em = strtolower((string)$r["guest_email"]);
            if ($em === "" || isset($already[$em])) continue;
            $ts = (int)$r["ts"];
            // Keep the most recent darts encounter per email.
            if (!isset($by_email[$em]) || $ts > $by_email[$em]["ts"]) {
                $by_email[$em] = ["reason" => "darts", "ts" => $ts];
            }
        }
    } catch (Throwable $e) {
        error_log("knk_friend_suggestions/darts: " . $e->getMessage());
    }

    // ----- music: queued within ±10 minutes of one of mine -----
    try {
        $sql = "SELECT q2.requester_email,
                       UNIX_TIMESTAMP(q2.submitted_at) AS ts
                  FROM jukebox_queue q1
                  JOIN jukebox_queue q2
                    ON q2.requester_email <> q1.requester_email
                   AND q2.requester_email <> ''
                   AND ABS(TIMESTAMPDIFF(MINUTE, q1.submitted_at, q2.submitted_at)) <= 10
                 WHERE q1.requester_email = ?
              ORDER BY q2.submitted_at DESC
                 LIMIT 100";
        $stmt = knk_db()->prepare($sql);
        $stmt->execute([$me]);
        while ($r = $stmt->fetch()) {
            $em = strtolower((string)$r["requester_email"]);
            if ($em === "" || isset($already[$em])) continue;
            $ts = (int)$r["ts"];
            // Don't downgrade a darts suggestion to music.
            if (isset($by_email[$em]) && $by_email[$em]["reason"] === "darts") continue;
            if (!isset($by_email[$em]) || $ts > $by_email[$em]["ts"]) {
                $by_email[$em] = ["reason" => "music", "ts" => $ts];
            }
        }
    } catch (Throwable $e) {
        error_log("knk_friend_suggestions/music: " . $e->getMessage());
    }

    if (empty($by_email)) return [];

    // ----- rank: darts ahead of music, then most-recent first -----
    $rows = [];
    foreach ($by_email as $em => $info) {
        $rows[] = [
            "email"     => $em,
            "reason"    => $info["reason"],
            "_ts"       => $info["ts"],
            "_priority" => $info["reason"] === "darts" ? 1 : 0,
        ];
    }
    usort($rows, function ($a, $b) {
        if ($a["_priority"] !== $b["_priority"]) return $b["_priority"] <=> $a["_priority"];
        return $b["_ts"] <=> $a["_ts"];
    });
    $rows = array_slice($rows, 0, $limit);

    // ----- resolve display names -----
    $emails = array_map(function ($r) { return $r["email"]; }, $rows);
    $rows_by_email = [];
    try {
        if (!empty($emails)) {
            $placeholders = implode(",", array_fill(0, count($emails), "?"));
            $sql = "SELECT email, display_name, avatar_path
                      FROM guests
                     WHERE email IN ({$placeholders})";
            $stmt = knk_db()->prepare($sql);
            $stmt->execute($emails);
            while ($r = $stmt->fetch()) {
                $rows_by_email[strtolower((string)$r["email"])] = $r;
            }
        }
    } catch (Throwable $e) {
        error_log("knk_friend_suggestions/lookup: " . $e->getMessage());
    }
    $out = [];
    foreach ($rows as $r) {
        $row = $rows_by_email[$r["email"]] ?? null;
        $out[] = [
            "email"        => $r["email"],
            "reason"       => $r["reason"],
            "display_name" => knk_profile_display_name_for($r["email"], $row),
            "avatar_path"  => $row ? (string)($row["avatar_path"] ?? "") : "",
        ];
    }
    return $out;
}

/* =========================================================
   SUMMARY HELPERS — pre-render strings for feed rows
   ========================================================= */

/** "Ordered 2× Tiger + 1× Larue (185k)". */
function knk_feed_summarise_order(array $o): string {
    $items = [];
    foreach (($o["items"] ?? []) as $it) {
        $qty  = (int)($it["qty"] ?? 1);
        $name = trim((string)($it["name"] ?? ""));
        if ($name === "") continue;
        $items[] = $qty . "× " . $name;
        if (count($items) >= 3) break;
    }
    $line = empty($items) ? "an order" : implode(" + ", $items);
    $tot = (int)($o["total_vnd"] ?? 0);
    if ($tot > 0) {
        // Compact "185k ₫" so the feed stays one-line on mobile.
        $compact = $tot >= 1000 ? round($tot / 1000) . "k ₫" : $tot . " ₫";
        $line .= " (" . $compact . ")";
    }
    return "ordered " . $line;
}

/** "queued: Wonderwall — Oasis". */
function knk_feed_summarise_song(string $title, string $channel, string $status): string {
    $title   = trim($title)   !== "" ? trim($title)   : "(untitled)";
    $channel = trim($channel);
    $verb = "queued";
    if ($status === "playing") $verb = "is playing";
    if ($status === "played")  $verb = "played";
    if ($status === "rejected" || $status === "skipped") $verb = $status;
    $s = $verb . ": " . $title;
    if ($channel !== "") $s .= " — " . $channel;
    return $s;
}

/** "won 501 (singles)". */
function knk_feed_summarise_darts(array $r): string {
    $type   = strtolower((string)($r["game_type"] ?? ""));
    $format = strtolower((string)($r["format"]    ?? "singles"));
    $status = strtolower((string)($r["status"]    ?? ""));
    $myslot = (int)($r["slot_no"] ?? 0);
    $myteam = (int)($r["my_team_no"] ?? 0);
    $winSlot = isset($r["winner_slot_no"]) && $r["winner_slot_no"] !== null ? (int)$r["winner_slot_no"] : null;
    $winTeam = isset($r["winner_team_no"]) && $r["winner_team_no"] !== null ? (int)$r["winner_team_no"] : null;
    $finPos  = isset($r["finishing_position"]) && $r["finishing_position"] !== null ? (int)$r["finishing_position"] : null;

    $verb = "played";
    if ($status === "playing")    $verb = "is playing";
    elseif ($status === "abandoned") $verb = "abandoned";
    elseif ($status === "finished") {
        if ($myteam > 0 && $winTeam !== null) {
            $verb = $winTeam === $myteam ? "won" : "lost";
        } elseif ($winSlot !== null) {
            if ($winSlot === $myslot)         $verb = "won";
            elseif ($finPos !== null)         $verb = "finished #" . $finPos . " in";
            else                              $verb = "lost";
        }
    }

    $type_pretty = $type !== "" ? $type : "darts";
    $format_pretty = $format !== "" ? $format : "singles";
    return $verb . " " . $type_pretty . " (" . $format_pretty . ")";
}

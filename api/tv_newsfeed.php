<?php
/*
 * KnK Inn — /api/tv_newsfeed.php
 *
 * Recent guest activity feed for the TV header ticker.
 * Two event types right now (per Ben's spec):
 *   - drink orders     (orders table)
 *   - queued songs     (jukebox_queue, status submitted)
 *
 * Returns the last ~12 events from the past 90 minutes, newest
 * first. Real names from the guests table (display_name, joined
 * via email). Falls back to "Someone" if a row has no email or
 * the guest hasn't set a display name.
 *
 * No auth — same posture as the other TV feeds.
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$out = [
    "ok"     => false,
    "now_ts" => time(),
    "events" => [],
    "error"  => null,
];

try {
    $pdo = knk_db();
    $events = [];

    /* --- ORDERS — last 90 minutes ---
     * Schema (migration 001): orders.guest_email + order_items.item_name.
     * No "customer_name" / "customer_email" — names come solely from the
     * profile join via guest_email. */
    try {
        $st = $pdo->prepare(
            "SELECT o.id, o.created_at,
                    o.guest_email AS email,
                    g.display_name AS profile_name,
                    GROUP_CONCAT(oi.item_name ORDER BY oi.id SEPARATOR ', ') AS drinks
               FROM orders o
          LEFT JOIN guests g ON g.email = o.guest_email
          LEFT JOIN order_items oi ON oi.order_id = o.id
              WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 90 MINUTE)
           GROUP BY o.id
           ORDER BY o.created_at DESC
              LIMIT 12"
        );
        $st->execute();
        foreach ($st->fetchAll() as $r) {
            $name = trim((string)($r["profile_name"] ?? ""));
            // Anon emails get the auto "Guest XXXX" placeholder; if
            // that's what we got, downgrade to a simple "Someone".
            if ($name === "" || preg_match('/^Guest\s+[0-9a-f]{4,}$/i', $name)) {
                $name = "Someone";
            }
            $drinks = trim((string)($r["drinks"] ?? ""));
            if ($drinks === "") continue;
            // Trim noisy extras: just first 2 items.
            $parts = array_map("trim", explode(",", $drinks));
            $head = implode(", ", array_slice($parts, 0, 2));
            if (count($parts) > 2) $head .= " + " . (count($parts) - 2) . " more";
            $events[] = [
                "ts"   => (int)strtotime((string)$r["created_at"]),
                "kind" => "order",
                "who"  => mb_substr($name, 0, 30),
                "verb" => "ordered",
                "what" => mb_substr($head, 0, 80),
            ];
        }
    } catch (Throwable $e) {
        // Orders table not present on a stripped-down deploy —
        // skip silently rather than poisoning the whole feed.
        error_log("tv_newsfeed orders: " . $e->getMessage());
    }

    /* --- QUEUED SONGS — last 90 minutes --- */
    try {
        $st = $pdo->prepare(
            "SELECT q.id, q.submitted_at,
                    q.requester_name AS typed_name,
                    q.requester_email AS email,
                    g.display_name   AS profile_name,
                    q.youtube_title  AS title,
                    q.youtube_channel AS channel
               FROM jukebox_queue q
          LEFT JOIN guests g ON g.email = q.requester_email
              WHERE q.submitted_at >= DATE_SUB(NOW(), INTERVAL 90 MINUTE)
                AND q.status IN ('queued', 'playing', 'played', 'pending')
           ORDER BY q.submitted_at DESC
              LIMIT 12"
        );
        $st->execute();
        foreach ($st->fetchAll() as $r) {
            $name = trim((string)($r["typed_name"] ?? ""));
            if ($name === "") $name = trim((string)($r["profile_name"] ?? ""));
            if ($name === "" || preg_match('/^Guest\s+[0-9a-f]{4,}$/i', $name)) {
                $name = "Someone";
            }
            // Title is a YouTube title — strip noise like " (Official Video)".
            $title = (string)$r["title"];
            $title = preg_replace('/\s*[\(\[][^\)\]]{0,40}(official|video|audio|hd|4k|live|m\/v|mv)[^\)\]]{0,40}[\)\]]\s*/i', "", $title);
            $title = trim($title);
            if ($title === "") continue;
            $events[] = [
                "ts"   => (int)strtotime((string)$r["submitted_at"]),
                "kind" => "song",
                "who"  => mb_substr($name, 0, 30),
                "verb" => "queued",
                "what" => mb_substr($title, 0, 80),
            ];
        }
    } catch (Throwable $e) {
        error_log("tv_newsfeed jukebox: " . $e->getMessage());
    }

    // Newest first across both kinds.
    usort($events, function ($a, $b) { return $b["ts"] - $a["ts"]; });
    $events = array_slice($events, 0, 12);

    $out["ok"]     = true;
    $out["events"] = $events;
} catch (Throwable $e) {
    $out["error"] = "engine_error";
    error_log("tv_newsfeed.php: " . $e->getMessage());
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

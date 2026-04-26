<?php
/*
 * KnK Inn — /api/notifications_mark_read.php
 *
 * Mark the calling guest's notifications as read.
 *
 * POST body (form-encoded):
 *   id  — (optional) single notification id to mark read
 *         If omitted, marks ALL of the caller's unread notifications.
 *
 * Response:
 *   { ok: true,  marked: N, unread: M }
 *   { ok: false, error: "..." }
 *
 * Identity: uses $_SESSION["order_email"] — same as the rest of the
 * profile system. Marking notifications you don't own is a no-op
 * because the UPDATE WHERE recipient_email = :you guards the row.
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . "/../includes/notifications_store.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$out = ["ok" => false, "error" => null];

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        throw new RuntimeException("POST only.");
    }
    $me = strtolower(trim((string)($_SESSION["order_email"] ?? "")));
    if ($me === "" || !filter_var($me, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("Sign in first.");
    }
    $id = (int)($_POST["id"] ?? 0);
    $marked = 0;
    if ($id > 0) {
        $marked = knk_notifications_mark_read($id, $me) ? 1 : 0;
    } else {
        $marked = knk_notifications_mark_all_read($me);
    }
    $out = [
        "ok"     => true,
        "marked" => $marked,
        "unread" => knk_notifications_unread_count($me),
    ];
} catch (Throwable $e) {
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

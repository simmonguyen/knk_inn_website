<?php
/*
 * KnK Inn — /api/follow.php
 *
 * Toggle a follow / unfollow relationship between the calling guest
 * (identified by $_SESSION["order_email"]) and a target email.
 *
 * POST body (form-encoded):
 *   action        — "follow" | "unfollow"
 *   target_email  — the email being (un)followed
 *
 * Response:
 *   { ok: true,  is_following: bool, target_email: string }
 *   { ok: false, error: string }
 *
 * Identity: we trust $_SESSION["order_email"] — it's set by the
 * bar shell / order.php / profile.php to either a real claimed
 * email or an anon-token@anon.knkinn.com address. Anon guests are
 * first-class follow-actors.
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . "/../includes/follows_store.php";

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
    $action = (string)($_POST["action"] ?? "");
    $target = strtolower(trim((string)($_POST["target_email"] ?? "")));
    if (!filter_var($target, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("Bad target email.");
    }
    if ($me === $target) {
        throw new RuntimeException("Can't follow yourself.");
    }
    if ($action === "follow") {
        if (!knk_follow($me, $target)) {
            throw new RuntimeException("Couldn't follow — try again.");
        }
    } elseif ($action === "unfollow") {
        if (!knk_unfollow($me, $target)) {
            throw new RuntimeException("Couldn't unfollow — try again.");
        }
    } else {
        throw new RuntimeException("Unknown action.");
    }
    $out = [
        "ok"           => true,
        "is_following" => knk_is_following($me, $target),
        "target_email" => $target,
    ];
} catch (Throwable $e) {
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

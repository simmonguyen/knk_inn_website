<?php
/*
 * KnK Inn — /api/profile_set_name.php
 *
 * Set the display_name on the guest's profile. Used by the
 * name-first prompt on /bar.php?tab=darts (and anywhere else that
 * needs to capture a typed name before letting the user proceed).
 *
 * Auth: must have $_SESSION["order_email"] (set by the bar shell
 *       when a guest scans the QR or visits /bar.php). Without it,
 *       there's no row to write to. No staff login required.
 *
 * POST body:
 *   name — 1..40 chars, trimmed.
 *
 * Response:
 *   { ok: true,  display_name: "Ben" }
 *   { ok: false, error: "..." }
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/profile_store.php";

if (session_status() === PHP_SESSION_NONE) session_start();

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$out = ["ok" => false, "error" => null];

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        throw new RuntimeException("POST only.");
    }

    $email = strtolower(trim((string)($_SESSION["order_email"] ?? "")));
    if ($email === "") {
        throw new RuntimeException("No bar session — open /bar.php first.");
    }

    $name = trim((string)($_POST["name"] ?? ""));
    if ($name === "")          throw new RuntimeException("Type a name.");
    if (mb_strlen($name) < 2)  throw new RuntimeException("Name's a bit short — try at least 2 letters.");
    if (mb_strlen($name) > 40) $name = mb_substr($name, 0, 40);

    if (!knk_profile_set_display_name($email, $name)) {
        throw new RuntimeException("Couldn't save your name — try again.");
    }

    $out = ["ok" => true, "display_name" => $name];
} catch (Throwable $e) {
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

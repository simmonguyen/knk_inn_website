<?php
/*
 * KnK Inn — /api/avatar_upload.php
 *
 * Multipart upload endpoint for the guest's profile photo. Accepts
 * a single file under the form field name "avatar". Validates,
 * crops to a 256x256 square, saves a JPEG to /uploads/avatars/,
 * cleans up the previous avatar (if any), and updates
 * guests.avatar_path.
 *
 * POST body (multipart/form-data):
 *   avatar  — image file (jpeg, png, webp), <= 6 MB
 *
 * Response:
 *   { ok: true,  avatar_url: "/uploads/avatars/12-abc123.jpg" }
 *   { ok: false, error: "..." }
 *
 * Identity: uses $_SESSION["order_email"] (anon or claimed). Anon
 * guests can have avatars too — when they later claim with a real
 * email, the avatar_path follows the merge naturally because the
 * guests row is renamed/merged in knk_profile_apply_claim().
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . "/../includes/avatar_store.php";
require_once __DIR__ . "/../includes/guests_store.php";

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
    if (empty($_FILES["avatar"])) {
        throw new RuntimeException("Pick a photo first.");
    }
    $err = knk_avatar_save_upload($me, $_FILES["avatar"]);
    if ($err !== null) {
        throw new RuntimeException($err);
    }
    $row = knk_guest_find_by_email($me);
    $url = $row ? (string)($row["avatar_path"] ?? "") : "";
    $out = ["ok" => true, "avatar_url" => $url];
} catch (Throwable $e) {
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

<?php
/*
 * KnK Inn — /api/playlist_reorder.php
 *
 * Save a new order for the bar guest's playlist. Body is a JSON
 * array of jukebox_playlists.id values in the desired order.
 *
 * Owner-scoped: ids that don't belong to this email are silently
 * dropped by the store. Step is 10 inside the store so future
 * single-item drag-betweens have room to land without a full
 * renumber.
 *
 * POST: row_ids — JSON-encoded array of ints (e.g. [42,7,19])
 *       OR repeated row_ids[] form fields.
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/jukebox_playlists.php";

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
        throw new RuntimeException("Open /bar.php first.");
    }

    /* Accept either a JSON-encoded "row_ids" string (the natural
     * shape for fetch + JSON.stringify) or a row_ids[] form-array. */
    $row_ids = [];
    if (!empty($_POST["row_ids"]) && is_string($_POST["row_ids"])) {
        $decoded = json_decode((string)$_POST["row_ids"], true);
        if (is_array($decoded)) $row_ids = $decoded;
    } elseif (!empty($_POST["row_ids"]) && is_array($_POST["row_ids"])) {
        $row_ids = $_POST["row_ids"];
    }
    if (empty($row_ids)) throw new RuntimeException("No row_ids supplied.");

    if (!knk_playlist_reorder($email, $row_ids)) {
        throw new RuntimeException("Couldn't save the new order.");
    }
    $out = ["ok" => true, "count" => knk_playlist_count($email)];
} catch (Throwable $e) {
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

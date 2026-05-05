<?php
/*
 * KnK Inn — /availability.php?room=f2-vip
 * Returns: { "room": "f2-vip", "blocked": ["2026-05-10", ...] }
 *
 * Called by booking.js on each room page to disable unavailable dates
 * in the calendar. Cached only briefly (60s) so new holds show up fast.
 */

require_once __DIR__ . "/includes/bookings_store.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: public, max-age=60");

$validRooms = ["basic","standard-nowindow","standard-balcony","vip"];
$room = $_GET["room"] ?? "";
if (!in_array($room, $validRooms, true)) {
    http_response_code(400);
    echo json_encode(["error" => "invalid room"]);
    exit;
}

try {
    $blocked = bookings_blocked_dates($room);
    sort($blocked);
    echo json_encode(["room" => $room, "blocked" => $blocked]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log("KnK availability error: " . $e->getMessage());
    echo json_encode(["error" => "server"]);
}

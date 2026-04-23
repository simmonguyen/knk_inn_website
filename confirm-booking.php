<?php
/*
 * KnK Inn — /confirm-booking.php?token=...&action=confirm|decline
 *
 * Simmo clicks a link in his booking-notification email.
 * Token is a 32-char random hex unique to the hold.
 * We flip the hold status, then show a result page.
 */

require_once __DIR__ . "/includes/bookings_store.php";

$token  = $_GET["token"]  ?? "";
$action = $_GET["action"] ?? "";

if (!preg_match('/^tok_[a-f0-9]{32}$/', $token) || !in_array($action, ["confirm", "decline"], true)) {
    http_response_code(400);
    header("Location: booking-bad-link.html");
    exit;
}

try {
    $hold = bookings_set_status_by_token($token, $action);
} catch (Throwable $e) {
    error_log("KnK confirm error: " . $e->getMessage());
    http_response_code(500);
    echo "Something went wrong. Please refresh and try again, or check bookings.json directly.";
    exit;
}

if (!$hold) {
    header("Location: booking-bad-link.html");
    exit;
}

// Success → land Simmo on a friendly page with the outcome
$qs = http_build_query([
    "action"  => $action,
    "room"    => $hold["room"],
    "checkin" => $hold["checkin"],
    "checkout"=> $hold["checkout"],
    "guest"   => $hold["guest"]["name"] ?? "",
]);
header("Location: booking-result.html?{$qs}");

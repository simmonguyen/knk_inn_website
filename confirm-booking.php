<?php
/*
 * KnK Inn — /confirm-booking.php?token=...&action=confirm|decline
 *
 * Simmo clicks a link in his booking-notification email.
 * Token is a 32-char random hex unique to the hold.
 * We flip the hold status, then show a result page.
 *
 * On confirm, we also email Simmo a calendar invite (.ics attachment) so
 * the booking lands straight in his KnKinnSaigon@gmail Google Calendar.
 */

require_once __DIR__ . "/includes/bookings_store.php";
require_once __DIR__ . "/includes/ics_builder.php";
require_once __DIR__ . "/includes/email_template.php";
require_once __DIR__ . "/includes/smtp_send.php";

$ROOM_LABELS = [
    "standard-nowindow" => "Standard (no window)",
    "standard-balcony"  => "Standard with balcony",
    "vip"               => "VIP",
];

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

/* ---------- On confirm: send Simmo a .ics calendar invite ---------- */
if ($action === "confirm") {
    $configPath = __DIR__ . "/config.php";
    if (file_exists($configPath)) {
        $CFG = require $configPath;
        $TO  = $CFG["to_email"] ?? "knkinnsaigon@gmail.com";

        $guest      = $hold["guest"] ?? [];
        $guestName  = $guest["name"] ?? "(no name)";
        $roomLabel  = $ROOM_LABELS[$hold["room"]] ?? $hold["room"];
        $checkin    = $hold["checkin"];
        $checkout   = $hold["checkout"];
        $nights     = (int)$hold["nights"];

        $icsBody = knk_ics_single($hold, $ROOM_LABELS);

        $subject = "Confirmed · {$roomLabel} · {$guestName} · {$checkin} → {$checkout}";

        $plain  = "Booking confirmed — added to your calendar.\n\n";
        $plain .= "Guest:     {$guestName}\n";
        if (!empty($guest["email"])) $plain .= "Email:     {$guest["email"]}\n";
        if (!empty($guest["phone"])) $plain .= "Phone:     {$guest["phone"]}\n";
        $plain .= "Room:      {$roomLabel}\n";
        $plain .= "Check-in:  {$checkin}\n";
        $plain .= "Check-out: {$checkout}\n";
        $plain .= "Nights:    {$nights}\n";
        $plain .= "\nHold ID: {$hold["id"]}\n";
        $plain .= "\nOpen the booking.ics attachment to add this to your calendar.\n";

        $details = array_filter([
            "Room"       => $roomLabel,
            "Check-in"   => $checkin,
            "Check-out"  => $checkout,
            "Nights"     => (string)$nights,
            "Guest"      => $guestName,
            "Email"      => $guest["email"] ?? "",
            "Phone"      => $guest["phone"] ?? "",
            "Guests"     => $guest["guests"] ?? "",
        ], function ($v) { return $v !== "" && $v !== null; });

        $btn_replyGuest = (!empty($guest["email"]))
            ? knk_email_button("Reply to " . $guestName, "mailto:" . rawurlencode($guest["email"]) . "?subject=" . rawurlencode("Your KnK Inn booking is confirmed"), "primary")
            : "";

        $html_body  = "<p style=\"margin:0 0 6px 0;font-size:12px;letter-spacing:0.2em;text-transform:uppercase;color:#7fa87f;font-weight:700;\">Booking confirmed ✓</p>";
        $html_body .= "<h1 style=\"margin:0 0 14px 0;font-family:'Archivo Black','Helvetica Neue',Arial Black,Arial,sans-serif;font-size:24px;line-height:1.15;color:#180c03;\">"
                    . htmlspecialchars($guestName, ENT_QUOTES, "UTF-8") . "'s stay is locked in</h1>";
        $html_body .= "<p style=\"margin:0 0 10px 0;color:#3d1f0d;\">A calendar invite is attached — open it to drop this straight into your Google Calendar.</p>";
        $html_body .= knk_email_details_table($details);
        if ($btn_replyGuest !== "") {
            $html_body .= knk_email_divider();
            $html_body .= "<p style=\"margin:6px 0 10px 0;color:#3d1f0d;\">Want to send the guest a welcome note?</p>";
            $html_body .= $btn_replyGuest;
        }
        $html_body .= "<p style=\"margin:22px 0 0 0;font-size:12px;color:#6e5d40;\">Hold ID: <code style=\"font-family:Menlo,Consolas,monospace;\">{$hold["id"]}</code></p>";

        $preheader = "Confirmed: {$guestName} · {$roomLabel} · {$checkin} → {$checkout}";
        $html_email = knk_email_html($subject, $preheader, $html_body, "Calendar invite attached as booking.ics");

        $smtpErr = null;
        @smtp_send([
            "host"           => $CFG["smtp"]["host"]     ?? "smtp.gmail.com",
            "port"           => $CFG["smtp"]["port"]     ?? 465,
            "secure"         => $CFG["smtp"]["secure"]   ?? "ssl",
            "username"       => $CFG["smtp"]["username"] ?? "",
            "password"       => $CFG["smtp"]["password"] ?? "",
            "from_email"     => $CFG["smtp"]["username"] ?? "",
            "from_name"      => $CFG["smtp"]["from_name"] ?? "KnK Inn Website",
            "to"             => $TO,
            "subject"        => $subject,
            "body"           => $plain,
            "html"           => $html_email,
            "attachments"    => [[
                "filename"     => "booking.ics",
                "content"      => $icsBody,
                "content_type" => "text/calendar; method=PUBLISH; charset=UTF-8",
            ]],
        ], $smtpErr);

        if ($smtpErr) error_log("KnK confirm-ics SMTP failed: {$smtpErr}");
    }
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

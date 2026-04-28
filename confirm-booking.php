<?php
/*
 * KnK Inn — /confirm-booking.php?token=...&action=confirm|decline
 *
 * Simmo clicks a link in his booking-notification email.
 * Token is a 32-char random hex unique to the hold.
 * We flip the hold status, then show a result page.
 *
 * Emails triggered as a side-effect:
 *   · confirm → Simmo gets a .ics calendar invite for his Google Calendar.
 *   · confirm → guest gets a branded "you're locked in" email + .ics attachment.
 *   · decline → guest gets a branded "those dates didn't work" email.
 *
 * Guest emails are skipped if we don't have a valid email on the hold.
 */

require_once __DIR__ . "/includes/bookings_store.php";
require_once __DIR__ . "/includes/ics_builder.php";
require_once __DIR__ . "/includes/email_template.php";
require_once __DIR__ . "/includes/smtp_send.php";

$ROOM_LABELS = [
    "standard-nowindow" => "Standard (no window)",
    "standard-balcony"  => "Standard with balcony",
    "vip"               => "VIP w/ tub",
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

/* ---------- Notifications: Simmo always, guest if we have their email ---------- */
$configPath = __DIR__ . "/config.php";
if (file_exists($configPath)) {
    $CFG = require $configPath;
    $TO  = $CFG["to_email"] ?? "gday@knkinn.com";

    $guest       = $hold["guest"] ?? [];
    $guestName   = $guest["name"] ?? "(no name)";
    $guestFirst  = $guestName !== "" ? strtok($guestName, " ") : "there";
    $guestEmail  = trim((string)($guest["email"] ?? ""));
    $roomLabel   = $ROOM_LABELS[$hold["room"]] ?? $hold["room"];
    $checkin     = $hold["checkin"];
    $checkout    = $hold["checkout"];
    $nights      = (int)$hold["nights"];

    $smtpBase = [
        "host"       => $CFG["smtp"]["host"]      ?? "smtp.gmail.com",
        "port"       => $CFG["smtp"]["port"]      ?? 465,
        "secure"     => $CFG["smtp"]["secure"]    ?? "ssl",
        "username"   => $CFG["smtp"]["username"]  ?? "",
        "password"   => $CFG["smtp"]["password"]  ?? "",
        "from_email" => $CFG["smtp"]["username"]  ?? "",
        "from_name"  => $CFG["smtp"]["from_name"] ?? "KnK Inn Website",
    ];

    /* ----- (1) On confirm: send Simmo a .ics calendar invite ----- */
    if ($action === "confirm") {
        $icsBody = knk_ics_single($hold, $ROOM_LABELS);

        $subject = "Confirmed · {$roomLabel} · {$guestName} · {$checkin} → {$checkout}";

        $plain  = "Booking confirmed — added to your calendar.\n\n";
        $plain .= "Guest:     {$guestName}\n";
        if (!empty($guestEmail))     $plain .= "Email:     {$guestEmail}\n";
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
            "Email"      => $guestEmail,
            "Phone"      => $guest["phone"] ?? "",
            "Guests"     => $guest["guests"] ?? "",
        ], function ($v) { return $v !== "" && $v !== null; });

        $btn_replyGuest = (!empty($guestEmail))
            ? knk_email_button("Reply to " . $guestName, "mailto:" . rawurlencode($guestEmail) . "?subject=" . rawurlencode("Your KnK Inn booking is confirmed"), "primary")
            : "";

        $html_body  = "<p style=\"margin:0 0 6px 0;font-size:12px;letter-spacing:0.2em;text-transform:uppercase;color:#7fa87f;font-weight:700;\">Booking confirmed ✓</p>";
        $html_body .= "<h1 style=\"margin:0 0 14px 0;font-family:'Archivo Black','Helvetica Neue',Arial Black,Arial,sans-serif;font-size:24px;line-height:1.15;color:#180c03;\">"
                    . htmlspecialchars($guestName, ENT_QUOTES, "UTF-8") . "'s stay is locked in</h1>";
        $html_body .= "<p style=\"margin:0 0 10px 0;color:#3d1f0d;\">A calendar invite is attached — open it to drop this straight into your Google Calendar.</p>";
        if (!empty($guestEmail)) {
            $html_body .= "<p style=\"margin:0 0 10px 0;color:#3d1f0d;\">" . htmlspecialchars($guestName, ENT_QUOTES, "UTF-8") . " has also been emailed a confirmation automatically.</p>";
        } else {
            $html_body .= "<p style=\"margin:0 0 10px 0;color:#3d1f0d;\"><em>No guest email on file — you'll need to let them know yourself.</em></p>";
        }
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
        @smtp_send(array_merge($smtpBase, [
            "to"          => $TO,
            "subject"     => $subject,
            "body"        => $plain,
            "html"        => $html_email,
            "attachments" => [[
                "filename"     => "booking.ics",
                "content"      => $icsBody,
                "content_type" => "text/calendar; method=PUBLISH; charset=UTF-8",
            ]],
        ]), $smtpErr);
        if ($smtpErr) error_log("KnK confirm-ics SMTP failed: {$smtpErr}");
    }

    /* ----- (2) Email the guest — on confirm AND on decline ----- */
    if ($guestEmail !== "" && filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {

        $guestDetails = array_filter([
            "Room"       => $roomLabel,
            "Check-in"   => $checkin,
            "Check-out"  => $checkout,
            "Nights"     => (string)$nights,
        ], function ($v) { return $v !== "" && $v !== null; });

        if ($action === "confirm") {
            $icsBody = isset($icsBody) ? $icsBody : knk_ics_single($hold, $ROOM_LABELS);

            $gSubject   = "Your KnK Inn booking is confirmed — {$checkin} to {$checkout}";
            $gPreheader = "You're locked in for {$nights} night" . ($nights === 1 ? "" : "s") . " at KnK Inn · {$roomLabel}";

            $gPlain  = "Hi {$guestFirst},\n\n";
            $gPlain .= "Good news — your booking at KnK Inn is confirmed.\n\n";
            $gPlain .= "Room:      {$roomLabel}\n";
            $gPlain .= "Check-in:  {$checkin}\n";
            $gPlain .= "Check-out: {$checkout}\n";
            $gPlain .= "Nights:    {$nights}\n\n";
            $gPlain .= "Finding us:\n";
            $gPlain .= "  96 Đề Thám, Cầu Ông Lãnh, District 1, HCM 70000\n";
            $gPlain .= "  https://knkinn.com\n\n";
            $gPlain .= "We've attached a calendar invite (booking.ics) — tap it to drop the\n";
            $gPlain .= "dates into your phone or Google Calendar.\n\n";
            $gPlain .= "Any questions, just reply to this email.\n\n";
            $gPlain .= "See you soon,\nKnK Inn\n";

            $gBody  = "<p style=\"margin:0 0 6px 0;font-size:12px;letter-spacing:0.2em;text-transform:uppercase;color:#7fa87f;font-weight:700;\">Booking confirmed ✓</p>";
            $gBody .= "<h1 style=\"margin:0 0 14px 0;font-family:'Archivo Black','Helvetica Neue',Arial Black,Arial,sans-serif;font-size:24px;line-height:1.15;color:#180c03;\">Hi "
                   . htmlspecialchars($guestFirst, ENT_QUOTES, "UTF-8")
                   . ", you're locked in.</h1>";
            $gBody .= "<p style=\"margin:0 0 10px 0;color:#3d1f0d;\">Thanks for choosing KnK Inn — we can't wait to host you. Your reservation details are below, and a calendar invite is attached so you can save the dates in one tap.</p>";
            $gBody .= knk_email_details_table($guestDetails);
            $gBody .= knk_email_divider();
            $gBody .= "<p style=\"margin:0 0 6px 0;font-size:12px;letter-spacing:0.2em;text-transform:uppercase;color:#6e5d40;font-weight:700;\">Finding us</p>";
            $gBody .= "<p style=\"margin:0 0 10px 0;color:#3d1f0d;\">96 Đề Thám, Cầu Ông Lãnh, District 1, HCM 70000<br>Rooftop entrance — look for the KnK signage.</p>";
            $gBody .= knk_email_button("Open in Google Maps", "https://www.google.com/maps/search/?api=1&query=" . rawurlencode("KnK Inn 96 De Tham District 1 Ho Chi Minh"), "primary");
            $gBody .= "<p style=\"margin:16px 0 0 0;color:#3d1f0d;\">Questions or changes? Just hit reply — this email goes straight to us.</p>";

            $gHtml = knk_email_html($gSubject, $gPreheader, $gBody, "Calendar invite attached as booking.ics");

            $gErr = null;
            @smtp_send(array_merge($smtpBase, [
                "to"             => $guestEmail,
                "reply_to_email" => $TO,
                "reply_to_name"  => "KnK Inn",
                "subject"        => $gSubject,
                "body"           => $gPlain,
                "html"           => $gHtml,
                "attachments"    => [[
                    "filename"     => "booking.ics",
                    "content"      => $icsBody,
                    "content_type" => "text/calendar; method=PUBLISH; charset=UTF-8",
                ]],
            ]), $gErr);
            if ($gErr) error_log("KnK guest-confirm SMTP failed for {$guestEmail}: {$gErr}");
        }

        if ($action === "decline") {
            $gSubject   = "About your KnK Inn booking request ({$checkin} → {$checkout})";
            $gPreheader = "Those dates didn't work — but we'd love to find something that does.";

            $gPlain  = "Hi {$guestFirst},\n\n";
            $gPlain .= "Thanks so much for your interest in KnK Inn. Unfortunately those dates\n";
            $gPlain .= "({$checkin} → {$checkout}, {$roomLabel}) didn't work out for us this time —\n";
            $gPlain .= "either the room is already taken or it's a bad fit on our side.\n\n";
            $gPlain .= "We'd still love to host you if you've got flexibility. If you'd like to\n";
            $gPlain .= "try different dates or a different room, just reply to this email or\n";
            $gPlain .= "send a fresh enquiry via https://knkinn.com.\n\n";
            $gPlain .= "Apologies for the shuffle, and hope to see you soon.\n\n";
            $gPlain .= "— KnK Inn\n";

            $gBody  = "<p style=\"margin:0 0 6px 0;font-size:12px;letter-spacing:0.2em;text-transform:uppercase;color:#b06b52;font-weight:700;\">Booking update</p>";
            $gBody .= "<h1 style=\"margin:0 0 14px 0;font-family:'Archivo Black','Helvetica Neue',Arial Black,Arial,sans-serif;font-size:24px;line-height:1.15;color:#180c03;\">Hi "
                   . htmlspecialchars($guestFirst, ENT_QUOTES, "UTF-8")
                   . ", those dates didn't work this time</h1>";
            $gBody .= "<p style=\"margin:0 0 10px 0;color:#3d1f0d;\">Thanks so much for your interest in KnK Inn. Unfortunately we can't confirm the dates below — the room's either already taken or it's a bad fit on our side for that window.</p>";
            $gBody .= knk_email_details_table($guestDetails);
            $gBody .= "<p style=\"margin:10px 0;color:#3d1f0d;\">If you've got any flexibility, we'd still love to host you. Reply to this email with alternate dates, or send a fresh enquiry and we'll sort it out.</p>";
            $gBody .= knk_email_button("Try different dates", "https://knkinn.com/rooms.php", "primary");
            $gBody .= "<p style=\"margin:18px 0 0 0;color:#3d1f0d;\">Apologies for the shuffle — hope to see you soon.</p>";

            $gHtml = knk_email_html($gSubject, $gPreheader, $gBody);

            $gErr = null;
            @smtp_send(array_merge($smtpBase, [
                "to"             => $guestEmail,
                "reply_to_email" => $TO,
                "reply_to_name"  => "KnK Inn",
                "subject"        => $gSubject,
                "body"           => $gPlain,
                "html"           => $gHtml,
            ]), $gErr);
            if ($gErr) error_log("KnK guest-decline SMTP failed for {$guestEmail}: {$gErr}");
        }
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

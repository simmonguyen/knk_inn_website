<?php
/*
 * KnK Inn — enquiry + booking handler
 *
 * Two entry points share this file:
 *   1. A plain enquiry (contact form)   — default path
 *   2. A per-room booking submission    — $_POST["type"] === "booking"
 *
 * Bookings create a pending hold in bookings.json, then email Simmo a
 * "Confirm / Decline" action link.  Confirming flips the hold to status
 * "confirmed" (dates stay blocked forever).  Declining flips to "declined"
 * (dates free up again).  Pending holds auto-expire after 24h.
 */

header("Content-Type: text/html; charset=UTF-8");

// --- config --------------------------------------------------------------
$configPath = __DIR__ . "/config.php";
if (!file_exists($configPath)) {
    http_response_code(500);
    error_log("KnK Inn: config.php missing on server");
    echo "Server not configured. Please email knkinnsaigon@gmail.com directly.";
    exit;
}
$CFG = require $configPath;

require_once __DIR__ . "/includes/smtp_send.php";
require_once __DIR__ . "/includes/bookings_store.php";
require_once __DIR__ . "/includes/email_template.php";

$TO_EMAIL    = $CFG["to_email"]    ?? "knkinnsaigon@gmail.com";
$SITE_URL    = "https://knkinn.com";
$MIN_SECONDS = (int)($CFG["min_seconds"] ?? 3);

// --- helpers -------------------------------------------------------------
function clean($s)      { $s = trim((string)$s); $s = str_replace(["\r","\n","%0a","%0d"], " ", $s); return substr($s, 0, 500); }
function clean_long($s) { $s = trim((string)$s); return substr(preg_replace("/[\r\n]+/", "\n", $s), 0, 4000); }
function is_email($e)   { return filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }
function is_ymd($s)     { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false; }
function vnd($n)        { return number_format((int)$n, 0, ".", ",") . " VND"; }
function fail($msg) {
    http_response_code(400);
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Oops — KnK Inn</title>";
    echo "<link rel='stylesheet' href='assets/css/styles.css'></head><body style='padding:4rem;text-align:center;'>";
    echo "<h1>Something went wrong</h1><p>$msg</p>";
    echo "<p><a href='index.html' class='btn-primary'>Back</a></p></body></html>";
    exit;
}

// --- accept only POST ----------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.html");
    exit;
}

// --- anti-spam: honeypot (renamed "hp_url" to dodge Chrome autofill) -----
if (!empty($_POST["hp_url"]) || !empty($_POST["website"])) {
    header("Location: thanks.html");
    exit;
}

// --- timing check --------------------------------------------------------
$ts = isset($_POST["ts"]) ? (int)$_POST["ts"] : 0;
if ($ts && (time() - $ts) < $MIN_SECONDS) {
    header("Location: thanks.html");
    exit;
}

$type = $_POST["type"] ?? "enquiry";

// =========================================================================
// BOOKING PATH
// =========================================================================
if ($type === "booking") {
    $roomId   = clean($_POST["room"]      ?? "");
    $name     = clean($_POST["name"]      ?? "");
    $email    = clean($_POST["email"]     ?? "");
    $phone    = clean($_POST["phone"]     ?? "");
    $checkin  = clean($_POST["checkin"]   ?? "");
    $checkout = clean($_POST["checkout"]  ?? "");
    $guests   = clean($_POST["guests"]    ?? "");
    $message  = clean_long($_POST["message"] ?? "");
    $price    = (int)($_POST["price"] ?? 0);

    $validRooms = ["standard-nowindow","standard-balcony","vip"];
    $err = [];
    if (!in_array($roomId, $validRooms, true)) $err[] = "Invalid room.";
    if ($name === "")          $err[] = "Please enter your name.";
    if (!is_email($email))     $err[] = "Please enter a valid email.";
    if (!is_ymd($checkin))     $err[] = "Please choose a check-in date.";
    if (!is_ymd($checkout))    $err[] = "Please choose a check-out date.";
    if ($checkin && $checkout && strtotime($checkout) <= strtotime($checkin))
        $err[] = "Check-out must be after check-in.";
    if ($err) fail(implode("<br>", $err));

    // Create the hold (also handles overlap detection)
    try {
        $hold = bookings_create_hold([
            "room"                => $roomId,
            "checkin"             => $checkin,
            "checkout"            => $checkout,
            "price_vnd_per_night" => $price,
            "guest" => [
                "name"    => $name,
                "email"   => $email,
                "phone"   => $phone,
                "guests"  => $guests,
                "message" => $message,
            ],
        ]);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), "already held") !== false) {
            fail("Sorry — those dates were just taken. Please go back and pick different dates.");
        }
        error_log("KnK booking error: " . $e->getMessage());
        fail("Something went wrong saving your booking. Please try again.");
    } catch (Throwable $e) {
        error_log("KnK booking error: " . $e->getMessage());
        fail("Something went wrong saving your booking. Please try again.");
    }

    // Build Simmo's email
    $roomLabels = [
        "standard-nowindow" => "Standard (no window)",
        "standard-balcony"  => "Standard with balcony",
        "vip"               => "VIP",
    ];
    $roomLabel = $roomLabels[$roomId] ?? $roomId;
    $total = $price * $hold["nights"];
    $subject = "New booking hold · {$roomLabel} · {$checkin} → {$checkout} · {$name}";

    $confirmUrl = $SITE_URL . "/confirm-booking.php?token=" . urlencode($hold["token"]) . "&action=confirm";
    $declineUrl = $SITE_URL . "/confirm-booking.php?token=" . urlencode($hold["token"]) . "&action=decline";

    // Plain-text fallback (for clients that don't render HTML)
    $body  = "NEW BOOKING REQUEST — action needed within 24 hours\n";
    $body .= str_repeat("═", 52) . "\n\n";
    $body .= "Room:       {$roomLabel} ({$roomId})\n";
    $body .= "Check-in:   {$checkin}\n";
    $body .= "Check-out:  {$checkout}\n";
    $body .= "Nights:     {$hold["nights"]}\n";
    if ($price > 0) $body .= "Price:      " . vnd($price) . "/night · total " . vnd($total) . "\n";
    $body .= "\n";
    $body .= "Guest:      {$name}\n";
    $body .= "Email:      {$email}\n";
    if ($phone)   $body .= "Phone:      {$phone}\n";
    if ($guests)  $body .= "Guests:     {$guests}\n";
    if ($message) $body .= "\nMessage from guest:\n{$message}\n";
    $body .= "\n" . str_repeat("─", 52) . "\n\n";
    $body .= "ACTION — tap one of the links below:\n\n";
    $body .= "  CONFIRM this booking (dates will be blocked):\n";
    $body .= "     {$confirmUrl}\n\n";
    $body .= "  DECLINE (dates stay open):\n";
    $body .= "     {$declineUrl}\n\n";
    $body .= str_repeat("─", 52) . "\n";
    $body .= "If you do nothing for 24h this hold auto-expires and the\n";
    $body .= "dates open back up.  Hold ID: {$hold["id"]}\n";

    // HTML version — branded template with big Confirm / Decline buttons
    $details = array_filter([
        "Room"       => $roomLabel,
        "Check-in"   => $checkin,
        "Check-out"  => $checkout,
        "Nights"     => (string)$hold["nights"],
        "Price"      => $price > 0 ? (vnd($price) . " / night · total " . vnd($total)) : "",
        "Guest"      => $name,
        "Email"      => $email,
        "Phone"      => $phone,
        "Guests"     => $guests,
    ], function ($v) { return $v !== "" && $v !== null; });

    $msg_html = $message !== ""
        ? "<p style=\"margin:18px 0 6px 0;font-size:12px;letter-spacing:0.14em;text-transform:uppercase;color:#6e5d40;\">Message from guest</p>"
          . "<div style=\"padding:14px 18px;background:#f4ede0;border-left:3px solid #c9aa71;border-radius:3px;color:#2a1408;font-style:italic;white-space:pre-wrap;\">"
          . htmlspecialchars($message, ENT_QUOTES, "UTF-8")
          . "</div>"
        : "";

    $btn_confirm = knk_email_button("Confirm booking", $confirmUrl, "primary");
    $btn_decline = knk_email_button("Decline", $declineUrl, "secondary");

    $html_body  = "<p style=\"margin:0 0 6px 0;font-size:12px;letter-spacing:0.2em;text-transform:uppercase;color:#c9aa71;font-weight:700;\">New booking · action needed</p>";
    $html_body .= "<h1 style=\"margin:0 0 14px 0;font-family:'Archivo Black','Helvetica Neue',Arial Black,Arial,sans-serif;font-size:26px;line-height:1.15;color:#180c03;letter-spacing:-0.01em;\">"
                . htmlspecialchars($name, ENT_QUOTES, "UTF-8") . " wants the " . htmlspecialchars($roomLabel, ENT_QUOTES, "UTF-8") . "</h1>";
    $html_body .= "<p style=\"margin:0 0 4px 0;color:#3d1f0d;\">{$checkin} → {$checkout} ({$hold["nights"]} nights)</p>";
    $html_body .= knk_email_details_table($details);
    $html_body .= $msg_html;
    $html_body .= knk_email_divider();
    $html_body .= "<p style=\"margin:6px 0 10px 0;color:#3d1f0d;\">Tap one below:</p>";
    $html_body .= "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\"><tr><td style=\"padding-right:12px;\">{$btn_confirm}</td><td>{$btn_decline}</td></tr></table>";
    $html_body .= "<p style=\"margin:22px 0 0 0;font-size:12px;color:#6e5d40;\">If you don't respond within 24 hours the hold auto-expires and the dates re-open. Hold ID: <code style=\"font-family:Menlo,Consolas,monospace;\">{$hold["id"]}</code></p>";

    $preheader = "New booking: {$name} · {$roomLabel} · {$checkin} → {$checkout}";
    $html_email = knk_email_html($subject, $preheader, $html_body, "This is an automated notice from the KnK Inn booking system.");

    $smtpErr = null;
    $ok = smtp_send([
        "host"           => $CFG["smtp"]["host"]     ?? "smtp.gmail.com",
        "port"           => $CFG["smtp"]["port"]     ?? 465,
        "secure"         => $CFG["smtp"]["secure"]   ?? "ssl",
        "username"       => $CFG["smtp"]["username"] ?? "",
        "password"       => $CFG["smtp"]["password"] ?? "",
        "from_email"     => $CFG["smtp"]["username"] ?? "",
        "from_name"      => $CFG["smtp"]["from_name"] ?? "KnK Inn Website",
        "to"             => $TO_EMAIL,
        "reply_to_email" => $email,
        "reply_to_name"  => $name,
        "subject"        => $subject,
        "body"           => $body,
        "html"           => $html_email,
    ], $smtpErr);

    if (!$ok) {
        error_log("KnK booking SMTP failed: {$smtpErr}");
        // still land on thanks page — hold is saved, Simmo can catch up from the json if needed
    }

    header("Location: booking-received.html?nights={$hold["nights"]}&total=" . urlencode(vnd($total)));
    exit;
}

// =========================================================================
// PLAIN ENQUIRY PATH (unchanged from before)
// =========================================================================
$name       = clean($_POST["name"]       ?? "");
$email      = clean($_POST["email"]      ?? "");
$phone      = clean($_POST["phone"]      ?? "");
$checkin    = clean($_POST["checkin"]    ?? "");
$checkout   = clean($_POST["checkout"]   ?? "");
$guests     = clean($_POST["guests"]     ?? "");
$room       = clean($_POST["room"]       ?? "");
$message    = clean_long($_POST["message"] ?? "");

$errors = [];
if ($name    === "")      $errors[] = "Please enter your name.";
if (!is_email($email))    $errors[] = "Please enter a valid email.";
if ($message === "")      $errors[] = "Please include a short message.";
if (strlen($message) < 5) $errors[] = "Message is too short.";
if ($errors) fail(implode("<br>", $errors));

$subject = "KnK Inn enquiry — $name";
if ($room) $subject .= " — $room";

// Plain-text fallback
$body  = "New enquiry from the KnK Inn website\n";
$body .= str_repeat("─", 48) . "\n\n";
$body .= "Name:     $name\n";
$body .= "Email:    $email\n";
if ($phone)    $body .= "Phone:    $phone\n";
if ($room)     $body .= "Room:     $room\n";
if ($checkin)  $body .= "Check-in: $checkin\n";
if ($checkout) $body .= "Check-out:$checkout\n";
if ($guests)   $body .= "Guests:   $guests\n";
$body .= "\nMessage:\n$message\n\n";
$body .= str_repeat("─", 48) . "\n";
$body .= "Submitted " . date("Y-m-d H:i:s") . " ICT · IP " . ($_SERVER["REMOTE_ADDR"] ?? "?") . "\n";
$body .= "$SITE_URL\n";

// HTML version
$eq_details = array_filter([
    "Name"       => $name,
    "Email"      => $email,
    "Phone"      => $phone,
    "Room"       => $room,
    "Check-in"   => $checkin,
    "Check-out"  => $checkout,
    "Guests"     => $guests,
], function ($v) { return $v !== "" && $v !== null; });

$eq_msg = "<p style=\"margin:18px 0 6px 0;font-size:12px;letter-spacing:0.14em;text-transform:uppercase;color:#6e5d40;\">Message</p>"
        . "<div style=\"padding:14px 18px;background:#f4ede0;border-left:3px solid #c9aa71;border-radius:3px;color:#2a1408;white-space:pre-wrap;\">"
        . htmlspecialchars($message, ENT_QUOTES, "UTF-8")
        . "</div>";

$replyUrl  = "mailto:" . rawurlencode($email) . "?subject=" . rawurlencode("Re: your enquiry at KnK Inn");
$btn_reply = knk_email_button("Reply to " . ($name ?: "guest"), $replyUrl, "primary");

$eq_html_body  = "<p style=\"margin:0 0 6px 0;font-size:12px;letter-spacing:0.2em;text-transform:uppercase;color:#c9aa71;font-weight:700;\">New enquiry</p>";
$eq_html_body .= "<h1 style=\"margin:0 0 14px 0;font-family:'Archivo Black','Helvetica Neue',Arial Black,Arial,sans-serif;font-size:24px;line-height:1.15;color:#180c03;\">"
              . htmlspecialchars($name, ENT_QUOTES, "UTF-8") . " sent a message</h1>";
$eq_html_body .= knk_email_details_table($eq_details);
$eq_html_body .= $eq_msg;
$eq_html_body .= knk_email_divider();
$eq_html_body .= $btn_reply;

$eq_footer = "Submitted " . htmlspecialchars(date("Y-m-d H:i:s"), ENT_QUOTES, "UTF-8") . " ICT · IP " . htmlspecialchars($_SERVER["REMOTE_ADDR"] ?? "?", ENT_QUOTES, "UTF-8");
$eq_preheader = "From {$name}" . ($room ? " about {$room}" : "");
$eq_html = knk_email_html($subject, $eq_preheader, $eq_html_body, $eq_footer);

$smtpErr = null;
$ok = smtp_send([
    "host"           => $CFG["smtp"]["host"]     ?? "smtp.gmail.com",
    "port"           => $CFG["smtp"]["port"]     ?? 465,
    "secure"         => $CFG["smtp"]["secure"]   ?? "ssl",
    "username"       => $CFG["smtp"]["username"] ?? "",
    "password"       => $CFG["smtp"]["password"] ?? "",
    "from_email"     => $CFG["smtp"]["username"] ?? "",
    "from_name"      => $CFG["smtp"]["from_name"] ?? "KnK Inn Website",
    "to"             => $TO_EMAIL,
    "reply_to_email" => $email,
    "reply_to_name"  => $name,
    "subject"        => $subject,
    "body"           => $body,
    "html"           => $eq_html,
], $smtpErr);

if ($ok) {
    header("Location: thanks.html");
    exit;
} else {
    error_log("KnK Inn enquiry SMTP failed for $email: $smtpErr");
    fail("We couldn't send your message automatically. Please email us directly at <a href='mailto:$TO_EMAIL'>$TO_EMAIL</a>.");
}

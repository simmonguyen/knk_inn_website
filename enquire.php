<?php
/*
 * KnK Inn — enquiry form handler
 * Emails submissions to knkinnsaigon@gmail.com
 * Anti-spam: honeypot "website" field + minimum-time check + basic validation.
 * No database, no dependencies — pure PHP mail().
 */

header("Content-Type: text/html; charset=UTF-8");

// --- config --------------------------------------------------------------
$TO_EMAIL    = "dev@dataknot.com";   // TESTING — switch back to knkinnsaigon@gmail.com before Simmo goes live
$FROM_EMAIL  = "enquiries@knkinn.com";   // must be on this domain for most shared-host SMTPs
$SITE_URL    = "https://knkinn.com";
$MIN_SECONDS = 3;                        // humans take > 3s to fill a form

// --- helpers -------------------------------------------------------------
function clean($s) {
    $s = trim((string)$s);
    $s = str_replace(["\r", "\n", "%0a", "%0d"], " ", $s);   // prevent header injection
    return substr($s, 0, 500);
}
function clean_long($s) {
    $s = trim((string)$s);
    return substr(preg_replace("/[\r\n]+/", "\n", $s), 0, 4000);
}
function is_email($e) {
    return filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
}
function fail($msg) {
    http_response_code(400);
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Oops — KnK Inn</title>";
    echo "<link rel='stylesheet' href='assets/css/styles.css'></head><body style='padding:4rem;text-align:center;'>";
    echo "<h1>Something went wrong</h1><p>$msg</p>";
    echo "<p><a href='index.html#contact' class='btn-primary'>Try again</a></p></body></html>";
    exit;
}

// --- accept only POST ----------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.html#contact");
    exit;
}

// --- honeypot: if "website" is filled, it's a bot ------------------------
if (!empty($_POST["website"])) {
    header("Location: thanks.html"); // silent success for bots
    exit;
}

// --- timing check --------------------------------------------------------
$ts = isset($_POST["ts"]) ? (int)$_POST["ts"] : 0;
if ($ts && (time() - $ts) < $MIN_SECONDS) {
    header("Location: thanks.html");
    exit;
}

// --- gather + validate ---------------------------------------------------
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

// --- build email ---------------------------------------------------------
$subject = "KnK Inn enquiry — $name";
if ($room) $subject .= " — $room";

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

$headers  = "From: KnK Inn Website <$FROM_EMAIL>\r\n";
$headers .= "Reply-To: $name <$email>\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "X-Mailer: KnKInn-Web\r\n";

// --- send ----------------------------------------------------------------
$ok = @mail($TO_EMAIL, $subject, $body, $headers, "-f$FROM_EMAIL");

if ($ok) {
    header("Location: thanks.html");
    exit;
} else {
    // fallback: still show a polite page, and log server-side
    error_log("KnK Inn enquiry mail() failed for " . $email);
    fail("We couldn't send your message automatically. Please email us directly at <a href='mailto:$TO_EMAIL'>$TO_EMAIL</a>.");
}

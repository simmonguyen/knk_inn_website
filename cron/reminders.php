<?php
/*
 * KnK Inn — daily marketing-reminder cron.
 *
 * Run this URL from Matbao's Cron Jobs panel once a day:
 *   curl -s "https://knkinn.com/cron/reminders.php?key=<ADMIN_PASSWORD>"
 *
 * What it does:
 *   1. Reads /assets/data/fixtures.json (same list the homepage shows).
 *   2. Finds fixtures whose kickoff is within the next N days
 *      (N = settings.marketing_reminder_days_before, default 7).
 *   3. For each one not already emailed, sends a short branded
 *      reminder to the Owner's notification email (settings.owner_notification_email,
 *      falling back to the owner user's email).
 *   4. Records each sent reminder in settings.sent_marketing_reminders
 *      so the same match never fires twice.
 *
 * Guard: same `admin_password` that migrate.php uses. The URL-gated
 * endpoint is safe to hit manually for testing.
 *
 * Output: plain-text log, one line per action. Cron captures this,
 * and Simmo never sees it.
 *
 * Kill switch: setting `marketing_reminders_enabled` = 0 short-circuits
 * the whole script (no DB writes, no emails).
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/settings_store.php";
require_once __DIR__ . "/../includes/smtp_send.php";
require_once __DIR__ . "/../includes/email_template.php";

header("Content-Type: text/plain; charset=utf-8");

/* --------------------------------------------------------------------
 * Guard — must match config.php's admin_password
 * ------------------------------------------------------------------ */
$cfg   = knk_config();
$guard = $cfg["admin_password"] ?? "";
$key   = $_GET["key"] ?? "";
if ($guard === "" || !hash_equals($guard, (string)$key)) {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

function log_line(string $s): void { echo $s . "\n"; }

log_line("KnK Inn — marketing reminders run at " . date("c"));

/* --------------------------------------------------------------------
 * Kill switch + config lookups
 * ------------------------------------------------------------------ */
if (!knk_setting_bool("marketing_reminders_enabled", true)) {
    log_line("Marketing reminders are OFF (see /settings.php). Exiting.");
    exit;
}

$days_before = knk_setting_int("marketing_reminder_days_before", 7);
if ($days_before < 1) $days_before = 1;
if ($days_before > 30) $days_before = 30;

/* --------------------------------------------------------------------
 * Resolve the recipient
 * ------------------------------------------------------------------ */
$notif_email = trim((string)knk_setting("owner_notification_email", ""));
if ($notif_email === "" || !filter_var($notif_email, FILTER_VALIDATE_EMAIL)) {
    $stmt = knk_db()->prepare(
        "SELECT email FROM users
         WHERE role = 'owner' AND active = 1
         ORDER BY id LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    $notif_email = $row ? (string)$row["email"] : "";
}
if ($notif_email === "" || !filter_var($notif_email, FILTER_VALIDATE_EMAIL)) {
    log_line("No owner notification email configured. Exiting.");
    exit;
}
log_line("Sending to: {$notif_email}");

/* --------------------------------------------------------------------
 * Load the fixtures file
 * ------------------------------------------------------------------ */
$fixtures_path = __DIR__ . "/../assets/data/fixtures.json";
if (!is_readable($fixtures_path)) {
    log_line("ERROR: fixtures file not found at {$fixtures_path}");
    http_response_code(500);
    exit;
}
$raw = file_get_contents($fixtures_path);
$data = json_decode((string)$raw, true);
if (!is_array($data) || !isset($data["fixtures"]) || !is_array($data["fixtures"])) {
    log_line("ERROR: fixtures.json is not in the expected shape.");
    http_response_code(500);
    exit;
}
$fixtures = $data["fixtures"];
log_line("Loaded " . count($fixtures) . " fixtures.");

/* --------------------------------------------------------------------
 * SMTP config lookup (same path used by order emails)
 * ------------------------------------------------------------------ */
$smtp = $cfg["smtp"] ?? null;
if (!is_array($smtp) || empty($smtp["password"]) || strpos((string)$smtp["password"], "xxxx") !== false) {
    log_line("ERROR: SMTP not configured. See config.php smtp section.");
    http_response_code(500);
    exit;
}

/* --------------------------------------------------------------------
 * Walk the list. Send an email for each fixture kicking off within
 * the next $days_before days that hasn't been sent yet.
 * ------------------------------------------------------------------ */
$now_ts = time();
$window_end_ts = $now_ts + ($days_before * 86400);

$sent = 0;
$skipped_already = 0;
$skipped_out_of_window = 0;
$skipped_no_date = 0;
$errors = 0;

foreach ($fixtures as $fx) {
    if (!is_array($fx)) continue;
    $kickoff_iso = (string)($fx["kickoff"] ?? "");
    if ($kickoff_iso === "") { $skipped_no_date++; continue; }

    $kickoff_ts = strtotime($kickoff_iso);
    if ($kickoff_ts === false) { $skipped_no_date++; continue; }

    // Only interested in fixtures that are in the future AND within
    // the reminder window. Past kickoffs are ignored so we don't spam
    // reminders for matches that have already happened.
    if ($kickoff_ts < $now_ts || $kickoff_ts > $window_end_ts) {
        $skipped_out_of_window++;
        continue;
    }

    $fx_key = knk_reminders_fixture_key($fx);
    if (knk_reminders_already_sent($fx_key)) {
        $skipped_already++;
        continue;
    }

    $ok = knk_send_reminder_email($fx, $notif_email, $smtp);
    if ($ok) {
        knk_reminders_mark_sent($fx_key);
        $sent++;
        log_line("  sent: " . ($fx["sport"] ?? "?") . " — " . ($fx["title"] ?? "?") . " (" . $fx_key . ")");
    } else {
        $errors++;
        log_line("  FAIL: " . ($fx["title"] ?? "?") . " — see error_log");
    }
}

log_line("");
log_line("Summary:");
log_line("  sent              : {$sent}");
log_line("  skipped (already) : {$skipped_already}");
log_line("  skipped (window)  : {$skipped_out_of_window}");
log_line("  skipped (no date) : {$skipped_no_date}");
log_line("  errors            : {$errors}");
log_line("Done.");

/* --------------------------------------------------------------------
 * Email builder — branded, short, mobile-friendly.
 *
 * Uses knk_email_html() for the wrapper, so the footer + header match
 * the order / booking emails Simmo already sees.
 * ------------------------------------------------------------------ */
function knk_send_reminder_email(array $fx, string $to, array $smtp): bool {
    $sport    = (string)($fx["sport"]    ?? "");
    $title    = (string)($fx["title"]    ?? "");
    $subtitle = (string)($fx["subtitle"] ?? "");
    $iso      = (string)($fx["kickoff"]  ?? "");
    $ts       = strtotime($iso);
    if ($ts === false) return false;

    // Display in Saigon time.
    $tz = new DateTimeZone("Asia/Ho_Chi_Minh");
    $dt = (new DateTime("@" . $ts))->setTimezone($tz);
    $when_full  = $dt->format("l j F Y");      // e.g. "Friday 1 May 2026"
    $when_time  = $dt->format("H:i");          // e.g. "21:00"
    $days_away  = (int)ceil(($ts - time()) / 86400);
    if ($days_away < 1) $days_away = 1;

    $sportEsc    = htmlspecialchars($sport);
    $titleEsc    = htmlspecialchars($title);
    $subtitleEsc = htmlspecialchars($subtitle);

    $body  = "<p style='margin:0 0 6px;font-size:12px;letter-spacing:0.2em;text-transform:uppercase;color:#9c7f4a;font-weight:700;'>"
           . "Upcoming fixture &middot; {$days_away} days away</p>";
    $body .= "<h1 style='margin:0 0 10px;font-family:Archivo Black,sans-serif;font-size:22px;'>"
           . "{$titleEsc}</h1>";
    if ($subtitle !== "") {
        $body .= "<p style='margin:0 0 10px;color:#6e5d40;'>{$subtitleEsc}</p>";
    }
    $body .= "<table role='presentation' width='100%' cellpadding='0' cellspacing='0'"
           . " style='border:1px solid #e7dcc2;border-radius:6px;margin-top:10px;'>"
           . "<tr><td style='padding:8px 10px;color:#6e5d40;width:110px;'>Sport</td>"
           . "<td style='padding:8px 10px;'>{$sportEsc}</td></tr>"
           . "<tr><td style='padding:8px 10px;color:#6e5d40;border-top:1px solid #e7dcc2;'>When</td>"
           . "<td style='padding:8px 10px;border-top:1px solid #e7dcc2;'>{$when_full}</td></tr>"
           . "<tr><td style='padding:8px 10px;color:#6e5d40;border-top:1px solid #e7dcc2;'>Kickoff</td>"
           . "<td style='padding:8px 10px;border-top:1px solid #e7dcc2;'>{$when_time} <span style='color:#9c7f4a;'>Saigon time</span></td></tr>"
           . "</table>";

    $body .= "<p style='margin:16px 0 4px;color:#3d1f0d;'>"
           . "Plenty of time to schedule a social post or line up a drinks special. "
           . "This reminder is sent automatically a week before each fixture."
           . "</p>";

    $html = knk_email_html(
        "KnK Inn — {$sport} fixture coming up",
        "{$title} — {$when_full} at {$when_time} Saigon time",
        $body,
        "Turn these off any time on /settings.php (Super Admin)."
    );

    $plain  = "Upcoming {$sport} fixture — {$days_away} days away\n\n";
    $plain .= "  {$title}\n";
    if ($subtitle !== "") $plain .= "  {$subtitle}\n";
    $plain .= "\n  When:    {$when_full}\n";
    $plain .= "  Kickoff: {$when_time} Saigon time\n\n";
    $plain .= "Plenty of time to schedule a social post or line up a drinks special.\n";
    $plain .= "Turn these off any time on /settings.php.\n";

    $err = null;
    $ok = smtp_send([
        "host"       => $smtp["host"],
        "port"       => (int)$smtp["port"],
        "secure"     => $smtp["secure"] ?? "ssl",
        "username"   => $smtp["username"],
        "password"   => $smtp["password"],
        "from_email" => $smtp["username"],
        "from_name"  => $smtp["from_name"] ?? "KnK Inn",
        "to"         => $to,
        "subject"    => "Coming up: {$title}",
        "body"       => $plain,
        "html"       => $html,
    ], $err);
    if (!$ok) error_log("KnK reminder email failed: " . (string)$err);
    return $ok;
}

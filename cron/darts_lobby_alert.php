<?php
/*
 * KnK Inn — "lonely looker" cron.
 *
 * Run every 5 minutes from Matbao's Cron Jobs panel:
 *   curl -s "https://knkinn.com/cron/darts_lobby_alert.php?key=<ADMIN_PASSWORD>"
 *
 * What it does:
 *   • Scans darts_lobby for guests who've been waiting >= 10 minutes
 *     for an opponent, with no incoming challenges and no prior alert.
 *   • Sends one email per such guest to the hostess (thirsty@knkinn.com
 *     by default, or whatever's configured under settings as
 *     hostess_email — falls back to config.php to_email).
 *   • Stamps the lobby row as alerted so the same guest doesn't trigger
 *     a second email during this rally.
 *
 * Same admin_password guard pattern as reminders.php / migrate.php.
 *
 * Kill switch: setting `darts_lonely_alerts_enabled` = 0 → no emails.
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/settings_store.php";
require_once __DIR__ . "/../includes/smtp_send.php";
require_once __DIR__ . "/../includes/email_template.php";
require_once __DIR__ . "/../includes/darts_lobby.php";

header("Content-Type: text/plain; charset=utf-8");

/* --------------------------------------------------------------------
 * Guard
 * ------------------------------------------------------------------ */
$cfg   = knk_config();
$guard = $cfg["admin_password"] ?? "";
$key   = $_GET["key"] ?? "";
if ($guard === "" || !hash_equals($guard, (string)$key)) {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

function ll(string $s): void { echo $s . "\n"; }

/* Kill switch — default ON when the setting is missing. */
$enabled = knk_setting_bool("darts_lonely_alerts_enabled", true);
if (!$enabled) {
    ll("darts_lonely_alerts_enabled = 0 — skipping.");
    exit;
}

/* Threshold in minutes — Ben's spec says 10. */
$threshold = max(1, min(60, (int)knk_setting("darts_lonely_alert_minutes", 10)));

$rows = knk_darts_lobby_lonely_lookers($threshold);
if (empty($rows)) {
    ll("no lonely lookers (>= {$threshold} min, no challenges, not alerted yet).");
    exit;
}

/* Hostess inbox — falls back to config to_email so a fresh deploy
 * still works before settings.hostess_email is set. */
$hostess = trim((string)knk_setting("hostess_email", ""));
if ($hostess === "") {
    $hostess = (string)($cfg["to_email"] ?? "");
}
if ($hostess === "") {
    ll("ERROR: no hostess email configured (settings.hostess_email or config.to_email).");
    http_response_code(500);
    exit;
}

$smtp = $cfg["smtp"] ?? null;
if (!is_array($smtp)) {
    ll("ERROR: smtp config missing in config.php.");
    http_response_code(500);
    exit;
}

$sent_count = 0;
$fail_count = 0;

foreach ($rows as $r) {
    $email = (string)$r["email"];
    $name  = trim((string)($r["display_name"] ?? ""));
    if ($name === "") $name = "Guest";
    $waited = (int)$r["mins_waiting"];

    $subject = "🎯 " . $name . " is waiting for a darts opponent at KnK";

    $body  = "<p style='font-size:16px;color:#2a1a08;margin:0 0 12px;'>";
    $body .= "<strong>" . htmlspecialchars($name, ENT_QUOTES, "UTF-8") . "</strong> ";
    $body .= "has been in the darts lobby for " . $waited . " minutes ";
    $body .= "and nobody's challenged them yet.</p>";
    $body .= "<p style='color:#3a230d;margin:0 0 12px;'>If the floor's quiet, head over with the spare phone — they'll spot you on the lobby list and you can challenge each other to get a game going.</p>";
    $body .= "<p style='color:#6e5d40;font-size:13px;margin:14px 0 4px;'>This is a one-time nudge — you won't get another email about this looker. Once they leave the lobby or play a game, the system goes quiet again.</p>";

    $html = knk_email_html(
        "🎯 Someone's waiting for darts",
        $name . " has been alone in the darts lobby for " . $waited . " minutes.",
        $body,
        "From the KnK Inn lobby system."
    );

    $plain = $name . " has been waiting for a darts opponent for " . $waited . " minutes.\n"
           . "Nobody's challenged them yet.\n\n"
           . "If the floor's quiet, head over with the spare phone — challenge them so they get a game.\n";

    $err = null;
    $ok = smtp_send([
        "host"       => $smtp["host"],
        "port"       => (int)$smtp["port"],
        "secure"     => $smtp["secure"] ?? "ssl",
        "username"   => $smtp["username"],
        "password"   => $smtp["password"],
        "from_email" => $smtp["username"],
        "from_name"  => $smtp["from_name"] ?? "KnK Inn",
        "to"         => $hostess,
        "subject"    => $subject,
        "body"       => $plain,
        "html"       => $html,
    ], $err);

    if ($ok) {
        knk_darts_lobby_mark_alerted($email);
        $sent_count++;
        ll("sent: {$email} ({$waited} min) → {$hostess}");
    } else {
        $fail_count++;
        ll("FAILED: {$email} → {$hostess}: " . (string)$err);
    }
}

ll("done — sent {$sent_count}, failed {$fail_count}.");

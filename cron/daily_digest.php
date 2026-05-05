<?php
/*
 * KnK Inn — daily morning digest cron.
 *
 * Runs once a day (typically 7am Saigon time). Emails Simmo a
 * one-page rundown of the day ahead and last night's numbers:
 *
 *   - Today's check-ins (room + guest name + nights)
 *   - Tomorrow's check-ins (heads-up for prep)
 *   - Pending booking enquiries older than 12h (action needed)
 *   - Yesterday's drink revenue + top-selling drink
 *   - Next-7-day forecast revenue (confirmed + pending)
 *
 * Run from DirectAdmin's Cron Jobs panel (see DEPLOY notes):
 *
 *   curl -s "https://knkinn.com/cron/daily_digest.php?key=<ADMIN_PASSWORD>"
 *
 * Cron line example (every day at 07:00 Saigon time):
 *   0 0 * * * curl -s "https://knkinn.com/cron/daily_digest.php?key=Knk@070475" > /dev/null 2>&1
 *
 * Wait — Matbao's cron runs in UTC. 7am Saigon = 0am UTC. So the
 * cron-line minute/hour above is correct (00:00 UTC = 07:00
 * Asia/Ho_Chi_Minh).
 *
 * Kill switch: setting `daily_digest_enabled` = 0. Defaults to ON.
 *
 * Recipient: settings.daily_digest_email if set, else
 * settings.owner_notification_email, else falls back to the first
 * active owner-role user.
 *
 * Output: plain-text log, one line per action. Cron captures this.
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/settings_store.php";
require_once __DIR__ . "/../includes/smtp_send.php";
require_once __DIR__ . "/../includes/email_template.php";
require_once __DIR__ . "/../includes/bookings_store.php";
require_once __DIR__ . "/../includes/orders_store.php";
require_once __DIR__ . "/../includes/sales_store.php";

header("Content-Type: text/plain; charset=utf-8");

/* --------------------------------------------------------------
 * Guard — same key the rest of the cron endpoints use.
 * -------------------------------------------------------------- */
$cfg   = knk_config();
$guard = $cfg["admin_password"] ?? "";
$key   = $_GET["key"] ?? "";
if ($guard === "" || !hash_equals($guard, (string)$key)) {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

function dd_log(string $s): void { echo $s . "\n"; }

dd_log("KnK Inn — daily digest run at " . date("c"));

/* --------------------------------------------------------------
 * Kill switch
 * -------------------------------------------------------------- */
if (!knk_setting_bool("daily_digest_enabled", true)) {
    dd_log("Daily digest is OFF (see /settings.php). Exiting.");
    exit;
}

/* --------------------------------------------------------------
 * Resolve recipient — one of (in order):
 *   settings.daily_digest_email
 *   settings.owner_notification_email
 *   first active owner-role user
 * -------------------------------------------------------------- */
$to = trim((string)knk_setting("daily_digest_email", ""));
if ($to === "" || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $to = trim((string)knk_setting("owner_notification_email", ""));
}
if ($to === "" || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $stmt = knk_db()->prepare(
        "SELECT email FROM users WHERE role = 'owner' AND active = 1 ORDER BY id LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    $to = $row ? (string)$row["email"] : "";
}
if ($to === "" || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    dd_log("No recipient configured. Exiting.");
    exit;
}
dd_log("Recipient: $to");

/* --------------------------------------------------------------
 * Today / tomorrow check-ins
 * -------------------------------------------------------------- */
$today_ymd    = date("Y-m-d");
$tomorrow_ymd = date("Y-m-d", strtotime("+1 day"));
$ROOM_LBL = [
    "basic"             => "Basic",
    "standard-nowindow" => "Standard",
    "standard-balcony"  => "Superior",
    "vip"               => "Premium",
];

[$fp, $bdata] = bookings_open();
bookings_close($fp);

$now = time();
$today_in    = [];   // confirmed check-ins today
$tomorrow_in = [];   // confirmed check-ins tomorrow
$pending_old = [];   // pending older than 12h
foreach ($bdata["holds"] as $h) {
    $status = $h["status"] ?? "pending";
    if (in_array($status, ["declined", "expired", "cancelled", "completed"], true)) continue;
    if ($status === "pending" && ($now - ($h["created_at"] ?? 0)) > KNK_HOLD_TTL) continue;
    $checkin = (string)($h["checkin"] ?? "");
    if ($checkin === "") continue;

    if ($status === "confirmed") {
        if ($checkin === $today_ymd)    $today_in[] = $h;
        if ($checkin === $tomorrow_ymd) $tomorrow_in[] = $h;
    } elseif ($status === "pending") {
        $age_h = ($now - (int)($h["created_at"] ?? 0)) / 3600;
        if ($age_h >= 12) $pending_old[] = $h;
    }
}

/* Sort each group by checkin time then name. */
$sort_h = function ($a, $b) {
    $cmp = strcmp((string)($a["checkin"] ?? ""), (string)($b["checkin"] ?? ""));
    if ($cmp !== 0) return $cmp;
    return strcmp((string)($a["guest"]["name"] ?? ""), (string)($b["guest"]["name"] ?? ""));
};
usort($today_in, $sort_h);
usort($tomorrow_in, $sort_h);
usort($pending_old, $sort_h);

dd_log("Today check-ins: " . count($today_in));
dd_log("Tomorrow check-ins: " . count($tomorrow_in));
dd_log("Pending >12h: " . count($pending_old));

/* --------------------------------------------------------------
 * Yesterday's drink revenue + top-selling drink
 * -------------------------------------------------------------- */
$yest_ymd = date("Y-m-d", strtotime("-1 day"));
$yest_start = strtotime($yest_ymd . " 00:00:00");
$yest_end   = strtotime($yest_ymd . " 23:59:59");

$drink_rev   = 0;
$drink_orders = 0;
$drink_counts = []; // name => qty
foreach (orders_all() as $o) {
    if (($o["status"] ?? "") === "cancelled") continue;
    $ts = (int)($o["created_at"] ?? 0);
    if ($ts < $yest_start || $ts > $yest_end) continue;
    $drink_orders++;
    $drink_rev += (int)($o["total_vnd"] ?? 0);
    foreach (($o["items"] ?? []) as $it) {
        $nm = trim((string)($it["name"] ?? ""));
        if ($nm === "") continue;
        $drink_counts[$nm] = ($drink_counts[$nm] ?? 0) + (int)($it["qty"] ?? 1);
    }
}
arsort($drink_counts);
$top_drink = "";
$top_qty   = 0;
foreach ($drink_counts as $nm => $qty) {
    $top_drink = $nm; $top_qty = $qty; break;
}
dd_log("Yesterday: " . $drink_orders . " orders, " . number_format($drink_rev) . " VND");

/* --------------------------------------------------------------
 * Next-7-day forecast
 * -------------------------------------------------------------- */
$forecast = knk_sales_room_forecast(7);
$f7_conf  = (int)$forecast["totals"]["confirmed"];
$f7_pend  = (int)$forecast["totals"]["pending"];
$f7_total = $f7_conf + $f7_pend;
dd_log("7-day forecast: confirmed " . number_format($f7_conf) . ", pending " . number_format($f7_pend));

/* --------------------------------------------------------------
 * Build email — plain text fallback + branded HTML.
 * -------------------------------------------------------------- */
$weekday_today = date("l, j M Y");
$subject = "KnK Inn · Daily digest · " . date("D j M");

$fmt_h = function (array $h) use ($ROOM_LBL): string {
    $room    = $ROOM_LBL[$h["room"] ?? ""] ?? ($h["room"] ?? "Room");
    $guest   = (string)($h["guest"]["name"]  ?? "Guest");
    $email   = (string)($h["guest"]["email"] ?? "");
    $phone   = (string)($h["guest"]["phone"] ?? "");
    $nights  = (int)($h["nights"] ?? 0);
    $line    = "  · " . $room . " — " . $guest . " (" . $nights . "n";
    if ($email) $line .= " · " . $email;
    if ($phone) $line .= " · " . $phone;
    $line   .= ")";
    return $line;
};

/* Plain text body. */
$body  = "GOOD MORNING, " . $weekday_today . "\n";
$body .= str_repeat("═", 56) . "\n\n";

$body .= "TODAY'S CHECK-INS (" . count($today_in) . ")\n";
$body .= str_repeat("─", 32) . "\n";
if (empty($today_in)) {
    $body .= "  Nothing scheduled — quiet day.\n";
} else {
    foreach ($today_in as $h) $body .= $fmt_h($h) . "\n";
}
$body .= "\n";

$body .= "TOMORROW'S CHECK-INS (" . count($tomorrow_in) . ")\n";
$body .= str_repeat("─", 32) . "\n";
if (empty($tomorrow_in)) {
    $body .= "  Nothing scheduled.\n";
} else {
    foreach ($tomorrow_in as $h) $body .= $fmt_h($h) . "\n";
}
$body .= "\n";

if (!empty($pending_old)) {
    $body .= "⚠  PENDING ENQUIRIES OLDER THAN 12H (" . count($pending_old) . ")\n";
    $body .= str_repeat("─", 32) . "\n";
    $body .= "  These need a Confirm or Decline:\n";
    foreach ($pending_old as $h) $body .= $fmt_h($h) . "\n";
    $body .= "\n";
}

$body .= "LAST NIGHT (" . $yest_ymd . ")\n";
$body .= str_repeat("─", 32) . "\n";
$body .= "  Drinks: " . number_format($drink_rev) . " VND across " . $drink_orders . " orders\n";
if ($top_drink !== "") {
    $body .= "  Top drink: " . $top_drink . " (" . $top_qty . ")\n";
}
$body .= "\n";

$body .= "NEXT 7 DAYS (forecast)\n";
$body .= str_repeat("─", 32) . "\n";
$body .= "  Confirmed: " . number_format($f7_conf) . " VND\n";
$body .= "  Pending:   " . number_format($f7_pend) . " VND\n";
$body .= "  Combined:  " . number_format($f7_total) . " VND\n";
$body .= "\n";

$body .= str_repeat("─", 56) . "\n";
$body .= "Open the dashboards: https://knkinn.com/sales.php?tab=forecast\n";
$body .= "Reply STOP to switch off — or toggle in /settings.php → Daily digest.\n";

/* HTML body — branded template. Build a key-value detail list per
 * section so it renders consistently. */
$html_body  = "";
$html_body .= "<h2 style=\"margin:0 0 8px 0; color:#3d1f0d;\">Good morning</h2>";
$html_body .= "<p style=\"margin:0 0 18px 0; color:#6e5d40;\">" . htmlspecialchars($weekday_today, ENT_QUOTES, "UTF-8") . "</p>";

$build_section = function (string $heading, array $rows, string $empty_msg) use ($ROOM_LBL): string {
    $h = "<p style=\"margin:18px 0 6px 0;font-size:12px;letter-spacing:0.14em;text-transform:uppercase;color:#6e5d40;\">" . htmlspecialchars($heading) . "</p>";
    if (empty($rows)) {
        $h .= "<div style=\"padding:12px 16px;background:#f4ede0;border-left:3px solid #c9aa71;color:#6e5d40;font-style:italic;\">"
            . htmlspecialchars($empty_msg) . "</div>";
        return $h;
    }
    $h .= "<table style=\"width:100%;border-collapse:collapse;font-size:14px;\">";
    foreach ($rows as $r) {
        $room   = $ROOM_LBL[$r["room"] ?? ""] ?? ($r["room"] ?? "Room");
        $guest  = (string)($r["guest"]["name"]  ?? "Guest");
        $email  = (string)($r["guest"]["email"] ?? "");
        $nights = (int)($r["nights"] ?? 0);
        $h .= "<tr style=\"border-bottom:1px solid #efe5cf;\">"
            . "<td style=\"padding:8px 6px;\"><strong>" . htmlspecialchars($room) . "</strong><br>"
            . "<span style=\"color:#6e5d40;font-size:13px;\">" . htmlspecialchars($guest)
            . ($email !== "" ? " · " . htmlspecialchars($email) : "")
            . "</span></td>"
            . "<td style=\"padding:8px 6px;text-align:right;color:#6e5d40;white-space:nowrap;\">" . $nights . "n</td>"
            . "</tr>";
    }
    $h .= "</table>";
    return $h;
};

$html_body .= $build_section("Today's check-ins", $today_in,    "Nothing scheduled — quiet day.");
$html_body .= $build_section("Tomorrow's check-ins", $tomorrow_in, "Nothing scheduled.");
if (!empty($pending_old)) {
    $html_body .= "<p style=\"margin:18px 0 6px 0;font-size:12px;letter-spacing:0.14em;text-transform:uppercase;color:#a13d2a;\">⚠ Pending enquiries older than 12h</p>";
    $html_body .= "<div style=\"padding:12px 16px;background:#fff1e8;border-left:3px solid #d97a5a;color:#7c3a23;\">These need a Confirm or Decline:</div>";
    $html_body .= $build_section("", $pending_old, "");
}

$html_body .= "<p style=\"margin:18px 0 6px 0;font-size:12px;letter-spacing:0.14em;text-transform:uppercase;color:#6e5d40;\">Last night · " . htmlspecialchars($yest_ymd) . "</p>";
$html_body .= "<table style=\"width:100%;border-collapse:collapse;font-size:14px;\">"
    . "<tr><td style=\"padding:6px 0;color:#6e5d40;\">Drinks revenue</td><td style=\"padding:6px 0;text-align:right;\"><strong>" . number_format($drink_rev) . " ₫</strong></td></tr>"
    . "<tr><td style=\"padding:6px 0;color:#6e5d40;\">Orders</td><td style=\"padding:6px 0;text-align:right;\">" . $drink_orders . "</td></tr>";
if ($top_drink !== "") {
    $html_body .= "<tr><td style=\"padding:6px 0;color:#6e5d40;\">Top drink</td><td style=\"padding:6px 0;text-align:right;\">" . htmlspecialchars($top_drink) . " (" . $top_qty . ")</td></tr>";
}
$html_body .= "</table>";

$html_body .= "<p style=\"margin:18px 0 6px 0;font-size:12px;letter-spacing:0.14em;text-transform:uppercase;color:#6e5d40;\">Next 7 days (forecast)</p>";
$html_body .= "<table style=\"width:100%;border-collapse:collapse;font-size:14px;\">"
    . "<tr><td style=\"padding:6px 0;color:#6e5d40;\">Confirmed</td><td style=\"padding:6px 0;text-align:right;\"><strong>" . number_format($f7_conf) . " ₫</strong></td></tr>"
    . "<tr><td style=\"padding:6px 0;color:#6e5d40;\">Pending</td><td style=\"padding:6px 0;text-align:right;\">" . number_format($f7_pend) . " ₫</td></tr>"
    . "<tr><td style=\"padding:6px 0;color:#6e5d40;\"><strong>Combined</strong></td><td style=\"padding:6px 0;text-align:right;\"><strong>" . number_format($f7_total) . " ₫</strong></td></tr>"
    . "</table>";

$preheader = count($today_in) . " check-in" . (count($today_in) === 1 ? "" : "s")
           . " today · " . number_format($drink_rev) . " ₫ in drinks last night.";

$html_body .= "<p style=\"margin:24px 0 6px 0;text-align:center;\">"
            . "<a href=\"https://knkinn.com/sales.php?tab=forecast\" "
            . "style=\"display:inline-block;padding:10px 22px;background:#c9aa71;color:#3d1f0d;text-decoration:none;border-radius:4px;font-weight:600;\">Open dashboards</a></p>";

$html = knk_email_html(
    $subject,
    $preheader,
    $html_body,
    "Toggle the digest off any time on /settings.php → Daily digest."
);

$smtp = $cfg["smtp"] ?? null;
if (!$smtp) {
    dd_log("No SMTP config in config.php. Exiting.");
    exit;
}

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
    "subject"    => $subject,
    "body"       => $body,
    "html"       => $html,
], $err);
dd_log($ok ? "Sent." : ("Send failed: " . (string)$err));

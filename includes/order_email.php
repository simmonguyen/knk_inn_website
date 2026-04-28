<?php
/*
 * KnK Inn — shared email helpers for the orders flow.
 *
 *   knk_email_customer_received($order)     → tell the customer their drinks are on the way
 *   knk_email_bar_new_order($order, $to)    → tell the bartender there's a new order
 *
 * Pulls SMTP config from /config.php at call time.
 */

require_once __DIR__ . "/smtp_send.php";
require_once __DIR__ . "/email_template.php";
require_once __DIR__ . "/orders_store.php";

/**
 * Find the owner's preferred notification email, or null if owner
 * alerts are turned off / no owner email is configured.
 *
 * Lookup order (first non-empty wins):
 *   1. settings.owner_notification_email   (explicit override on /settings.php)
 *   2. users.email WHERE role='owner' AND active=1  (staff-login fallback)
 *
 * Returns null when:
 *   · settings.owner_order_notifications_enabled is '0'
 *   · the DB / settings helpers aren't available (e.g. legacy JSON-only env)
 *   · no owner account exists and no override is set
 */
function knk_order_owner_cc(): ?string {
    /* Backward-compat single-string version — first item from the
     * full list. Kept so existing callers don't break. New callers
     * should prefer knk_owner_cc_list() to pick up co_owner_email
     * (Linh-the-wife's missus@ inbox). */
    $list = knk_owner_cc_list();
    return empty($list) ? null : $list[0];
}

/**
 * Return every address that should be CC'd on owner notifications.
 *
 * Resolution order:
 *   1. settings.owner_notification_email   — Simmo (gday@)
 *   2. fallback to first active owner-role user
 *   3. settings.co_owner_email             — Linh-the-wife (missus@)
 *
 * Empty array if owner_order_notifications_enabled is off, the
 * settings store isn't loadable, or nothing resolves. De-dupes
 * case-insensitively so a misconfig doesn't double-send.
 */
function knk_owner_cc_list(): array {
    $storePath = __DIR__ . "/settings_store.php";
    if (!file_exists($storePath)) return [];
    $out = [];
    try {
        require_once $storePath;
        if (!knk_setting_bool("owner_order_notifications_enabled", true)) {
            return [];
        }
        $primary = trim((string)knk_setting("owner_notification_email", ""));
        if ($primary !== "" && filter_var($primary, FILTER_VALIDATE_EMAIL)) {
            $out[] = $primary;
        } else {
            $stmt = knk_db()->prepare(
                "SELECT email FROM users
                 WHERE role = 'owner' AND active = 1
                 ORDER BY id LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row && !empty($row["email"])) $out[] = (string)$row["email"];
        }
        $co = trim((string)knk_setting("co_owner_email", ""));
        if ($co !== "" && filter_var($co, FILTER_VALIDATE_EMAIL)) {
            $out[] = $co;
        }
    } catch (Throwable $e) {
        // DB unavailable or table missing — return whatever we have.
    }
    /* De-dupe case-insensitively. */
    $seen = [];
    $deduped = [];
    foreach ($out as $addr) {
        $k = strtolower($addr);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $deduped[] = $addr;
    }
    return $deduped;
}

function knk_order_smtp_config(): ?array {
    $configPath = __DIR__ . "/../config.php";
    if (!file_exists($configPath)) return null;
    $CFG = require $configPath;
    $smtp = $CFG["smtp"] ?? null;
    if (!$smtp || empty($smtp["password"]) || strpos($smtp["password"], "xxxx") !== false) return null;
    return [
        "smtp" => $smtp,
        "to_email" => $CFG["to_email"] ?? "knkinnsaigon@gmail.com",
    ];
}

/** "Your order is on the way" → customer email. */
function knk_email_customer_received(array $order): bool {
    $cfg = knk_order_smtp_config();
    if (!$cfg) return false;

    $email = $order["email"] ?? "";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    $smtp = $cfg["smtp"];
    $locLabel = knk_location_label($order["location"], $order["room_number"] ?? null);

    $rows = "";
    foreach ($order["items"] as $it) {
        $line = (int)$it["price_vnd"] * (int)$it["qty"];
        $rows .= "<tr>"
            .  "<td style='padding:6px 8px;border-bottom:1px solid #e7dcc2;'>" . htmlspecialchars($it["name"]) . "</td>"
            .  "<td style='padding:6px 8px;border-bottom:1px solid #e7dcc2;text-align:center;'>× " . (int)$it["qty"] . "</td>"
            .  "<td style='padding:6px 8px;border-bottom:1px solid #e7dcc2;text-align:right;'>" . knk_vnd($line) . "</td>"
            .  "</tr>";
    }

    $body  = "<p style='margin:0 0 6px;font-size:12px;letter-spacing:0.2em;text-transform:uppercase;color:#9c7f4a;font-weight:700;'>Order received</p>";
    $body .= "<h1 style='margin:0 0 10px;font-family:Archivo Black,sans-serif;font-size:22px;'>It's on its way up.</h1>";
    $body .= "<p style='color:#3d1f0d;'>Hey — the bar has your order. Bringing it to <b>" . htmlspecialchars($locLabel) . "</b>.</p>";
    $body .= "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #e7dcc2;border-radius:6px;margin-top:10px;'>"
        .   $rows
        .   "<tr><td style='padding:6px 8px;color:#6e5d40;'>Subtotal</td><td></td><td style='padding:6px 8px;text-align:right;color:#6e5d40;'>" . knk_vnd((int)$order["subtotal_vnd"]) . "</td></tr>"
        .   "<tr><td style='padding:6px 8px;color:#6e5d40;'>VAT 10%</td><td></td><td style='padding:6px 8px;text-align:right;color:#6e5d40;'>" . knk_vnd((int)$order["vat_vnd"]) . "</td></tr>"
        .   "<tr><td style='padding:8px;font-weight:700;background:#180c03;color:#c9aa71;'>Total</td><td style='background:#180c03;'></td><td style='padding:8px;text-align:right;font-weight:700;background:#180c03;color:#c9aa71;'>" . knk_vnd((int)$order["total_vnd"]) . "</td></tr>"
        .   "</table>";
    $body .= "<p style='margin:16px 0 4px;color:#6e5d40;font-size:13px;'>Settle up at the bar when you're done. Cheers.</p>";

    $html = knk_email_html(
        "KnK Inn — your order is on the way",
        "The bar has your order and is bringing it to " . $locLabel . ".",
        $body,
        "Reply to this email if something's off."
    );

    $plain = "Hey,\n\nThe bar has your order and is bringing it to " . $locLabel . ".\n\n";
    foreach ($order["items"] as $it) {
        $plain .= "  " . $it["name"] . " × " . (int)$it["qty"] . "\n";
    }
    $plain .= "\nSubtotal: " . knk_vnd((int)$order["subtotal_vnd"]) . "\nVAT 10%:  " . knk_vnd((int)$order["vat_vnd"]) . "\nTotal:    " . knk_vnd((int)$order["total_vnd"]) . "\n\n";
    $plain .= "Settle up at the bar when you're done.\n\n— KnK Inn\n";

    $err = null;
    $ok = smtp_send([
        "host"       => $smtp["host"],
        "port"       => (int)$smtp["port"],
        "secure"     => $smtp["secure"] ?? "ssl",
        "username"   => $smtp["username"],
        "password"   => $smtp["password"],
        "from_email" => $smtp["username"],
        "from_name"  => $smtp["from_name"] ?? "KnK Inn",
        "to"         => $email,
        "subject"    => "Your KnK Inn order is on the way",
        "body"       => $plain,
        "html"       => $html,
    ], $err);
    if (!$ok) error_log("KnK order-received email failed: " . $err);
    return $ok;
}

/** "New rooftop order" → bartender Gmail notification, with a Mark-received link. */
function knk_email_bar_new_order(array $order, string $siteUrl = "https://knkinn.com"): bool {
    $cfg = knk_order_smtp_config();
    if (!$cfg) return false;
    $smtp = $cfg["smtp"];
    $to   = $cfg["to_email"];

    $locLabel = knk_location_label($order["location"], $order["room_number"] ?? null);

    $rows = "";
    foreach ($order["items"] as $it) {
        $line = (int)$it["price_vnd"] * (int)$it["qty"];
        $rows .= "<tr>"
            .  "<td style='padding:6px 8px;border-bottom:1px solid #e7dcc2;'>" . htmlspecialchars($it["name"]) . "</td>"
            .  "<td style='padding:6px 8px;border-bottom:1px solid #e7dcc2;text-align:center;'>&times; " . (int)$it["qty"] . "</td>"
            .  "<td style='padding:6px 8px;border-bottom:1px solid #e7dcc2;text-align:right;'>" . knk_vnd($line) . "</td>"
            .  "</tr>";
    }

    $receivedUrl = rtrim($siteUrl, "/") . "/order-received.php?token=" . urlencode($order["token"]);
    $button = knk_email_button("Mark order received", $receivedUrl, "primary");

    $notesHtml = "";
    if (!empty($order["notes"])) {
        $notesHtml = "<p style='margin:8px 0;padding:10px 12px;background:#fdf8ef;border-left:3px solid #c9aa71;'><b>Notes:</b> " . htmlspecialchars($order["notes"]) . "</p>";
    }

    $body = "<p style='margin:0 0 10px;font-size:15px;'><b>New rooftop order.</b></p>"
        .  "<p style='margin:0 0 6px;color:#6e5d40;'>From: " . htmlspecialchars($order["email"]) . "</p>"
        .  "<p style='margin:0 0 12px;color:#6e5d40;'>Deliver to: <b>" . htmlspecialchars($locLabel) . "</b></p>"
        .  "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #e7dcc2;border-radius:6px;margin:10px 0 0;'>"
        .  $rows
        .  "<tr><td style='padding:6px 8px;color:#6e5d40;'>Subtotal</td><td></td><td style='padding:6px 8px;text-align:right;color:#6e5d40;'>" . knk_vnd((int)$order["subtotal_vnd"]) . "</td></tr>"
        .  "<tr><td style='padding:6px 8px;color:#6e5d40;'>VAT 10%</td><td></td><td style='padding:6px 8px;text-align:right;color:#6e5d40;'>" . knk_vnd((int)$order["vat_vnd"]) . "</td></tr>"
        .  "<tr><td style='padding:8px;font-weight:700;background:#180c03;color:#c9aa71;'>TOTAL</td><td style='background:#180c03;'></td><td style='padding:8px;text-align:right;font-weight:700;background:#180c03;color:#c9aa71;'>" . knk_vnd((int)$order["total_vnd"]) . "</td></tr>"
        .  "</table>"
        .  $notesHtml
        .  "<p style='margin:18px 0 8px;'>Tap the button below once the order is on its way — the customer will get a note that it'll be up shortly.</p>"
        .  $button
        .  "<p style='margin:14px 0 0;font-size:12px;color:#6e5d40;'>Order ID: " . htmlspecialchars($order["id"]) . " · " . date("D j M · H:i", (int)$order["created_at"]) . "</p>";

    $html = knk_email_html(
        "KnK Inn — new order (" . $locLabel . ")",
        "New order from " . $order["email"] . " — " . knk_vnd((int)$order["total_vnd"]),
        $body,
        "Open /orders.php for the live list."
    );

    $plain = "New KnK Inn order\n"
        . "From: " . $order["email"] . "\n"
        . "To: "   . $locLabel . "\n\n";
    foreach ($order["items"] as $it) {
        $plain .= "- " . $it["name"] . " × " . (int)$it["qty"] . " = " . knk_vnd((int)$it["price_vnd"] * (int)$it["qty"]) . "\n";
    }
    $plain .= "\nSubtotal: " . knk_vnd((int)$order["subtotal_vnd"]) . "\nVAT 10%:  " . knk_vnd((int)$order["vat_vnd"]) . "\nTOTAL:    " . knk_vnd((int)$order["total_vnd"]) . "\n\n";
    if (!empty($order["notes"])) $plain .= "Notes: " . $order["notes"] . "\n\n";
    $plain .= "Mark received: " . $receivedUrl . "\n";

    // If the Owner has order alerts turned on, CC every address on
    // the owner CC list — Simmo (gday@) plus Linh-the-wife (missus@)
    // when configured. Suppress any address that duplicates the
    // primary To so we don't double-send.
    $ccList = [];
    foreach (knk_owner_cc_list() as $addr) {
        if (strcasecmp($addr, $to) !== 0) $ccList[] = $addr;
    }

    $err = null;
    $ok = smtp_send([
        "host"           => $smtp["host"],
        "port"           => (int)$smtp["port"],
        "secure"         => $smtp["secure"] ?? "ssl",
        "username"       => $smtp["username"],
        "password"       => $smtp["password"],
        "from_email"     => $smtp["username"],
        "from_name"      => $smtp["from_name"] ?? "KnK Inn Website",
        "to"             => $to,
        "cc"             => $ccList,
        "reply_to_email" => $order["email"],
        "reply_to_name"  => "KnK guest",
        "subject"        => "New order — " . $locLabel . " — " . knk_vnd((int)$order["total_vnd"]),
        "body"           => $plain,
        "html"           => $html,
    ], $err);
    if (!$ok) error_log("KnK new-order email failed: " . $err);
    return $ok;
}

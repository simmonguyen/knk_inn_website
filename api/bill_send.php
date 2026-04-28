<?php
/*
 * KnK Inn — /api/bill_send.php
 *
 * "Check bill" → email the hostess this guest's drinks tab for the
 * current bar visit. Bar visit = orders matching the guest's bar
 * session email created in the last 4 hours (the same TTL used for
 * the anon-cookie identity).
 *
 * Auth: $_SESSION["order_email"] (bar session). No staff login.
 *
 * The hostess inbox is settings.hostess_email, falling back to
 * config.php to_email so a fresh deploy still works.
 *
 * Response:
 *   { ok: true,  count: N, total_vnd: X, sent_to: "thirsty@..." }
 *   { ok: false, error: "..." }
 */

declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/settings_store.php";
require_once __DIR__ . "/../includes/profile_store.php";
require_once __DIR__ . "/../includes/smtp_send.php";
require_once __DIR__ . "/../includes/email_template.php";
require_once __DIR__ . "/../includes/orders_store.php"; // knk_vnd

if (session_status() === PHP_SESSION_NONE) session_start();

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$out = ["ok" => false, "error" => null];

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        throw new RuntimeException("POST only.");
    }

    $email = strtolower(trim((string)($_SESSION["order_email"] ?? "")));
    if ($email === "") {
        throw new RuntimeException("Open /bar.php first so we know who you are.");
    }

    /* Resolve hostess inbox + SMTP. */
    $cfg     = knk_config();
    $hostess = trim((string)knk_setting("hostess_email", ""));
    if ($hostess === "") $hostess = (string)($cfg["to_email"] ?? "");
    if ($hostess === "") throw new RuntimeException("Hostess email isn't configured.");

    $smtp = $cfg["smtp"] ?? null;
    if (!is_array($smtp)) throw new RuntimeException("SMTP isn't configured.");

    /* Pull this guest's orders from the last 4 hours. Includes
     * pending + received — anything that isn't paid or cancelled. */
    $st = knk_db()->prepare(
        "SELECT id, slug, created_at, location, room_number,
                subtotal_vnd, vat_vnd, total_vnd, status
           FROM orders
          WHERE guest_email = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL 4 HOUR)
            AND status IN ('pending','received')
       ORDER BY created_at ASC"
    );
    $st->execute([$email]);
    $orders = $st->fetchAll();

    if (empty($orders)) {
        $out = ["ok" => false, "error" => "No open orders in the last 4 hours."];
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* Items per order, single round-trip. */
    $order_ids = array_map(function ($r) { return (int)$r["id"]; }, $orders);
    $place = implode(",", array_fill(0, count($order_ids), "?"));
    $iSt = knk_db()->prepare(
        "SELECT order_id, item_name, quantity, unit_vnd, line_vnd
           FROM order_items
          WHERE order_id IN ({$place})
       ORDER BY order_id, id"
    );
    $iSt->execute($order_ids);
    $items_by_order = [];
    while ($r = $iSt->fetch()) {
        $items_by_order[(int)$r["order_id"]][] = $r;
    }

    /* Resolve guest's display name for the email header. */
    $guest_row = knk_guest_find_by_email($email);
    $disp = trim((string)($guest_row["display_name"] ?? ""));
    if ($disp === "" || preg_match('/^Guest\s+[0-9a-f]{4,}$/i', $disp)) {
        $disp = "A guest";
    }

    /* Aggregate totals. */
    $sub_total = 0; $vat_total = 0; $grand_total = 0; $line_count = 0;
    foreach ($orders as $o) {
        $sub_total   += (int)$o["subtotal_vnd"];
        $vat_total   += (int)$o["vat_vnd"];
        $grand_total += (int)$o["total_vnd"];
        $line_count  += isset($items_by_order[(int)$o["id"]])
            ? count($items_by_order[(int)$o["id"]]) : 0;
    }

    /* Build HTML body. */
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); };
    $body  = "<p style='font-size:16px;color:#2a1a08;margin:0 0 14px;'>";
    $body .= "<strong>" . $h($disp) . "</strong> would like the bill — ";
    $body .= count($orders) . " order" . (count($orders) === 1 ? "" : "s") . " · ";
    $body .= $line_count . " line" . ($line_count === 1 ? "" : "s") . ".</p>";

    foreach ($orders as $o) {
        $oid_short = mb_substr((string)$o["slug"], 0, 14);
        $when = (string)$o["created_at"];
        $loc  = (string)$o["location"];
        $room = trim((string)($o["room_number"] ?? ""));
        $loc_label = $loc === "room"
            ? ("Room " . ($room !== "" ? $room : "?"))
            : ucwords(str_replace("-", " ", $loc));

        $body .= "<table style='width:100%;border-collapse:collapse;margin:0 0 14px;border:1px solid rgba(201,170,113,0.35);border-radius:6px;overflow:hidden;'>";
        $body .= "<thead><tr style='background:rgba(201,170,113,0.12);'>";
        $body .= "<th style='text-align:left;padding:6px 10px;font-size:13px;color:#3a230d;'>"
              . $h($loc_label) . " · " . $h($when) . "</th>";
        $body .= "<th style='text-align:right;padding:6px 10px;font-size:13px;color:#3a230d;'>" . $h($oid_short) . "</th>";
        $body .= "</tr></thead><tbody>";
        $items = $items_by_order[(int)$o["id"]] ?? [];
        foreach ($items as $it) {
            $qty = (int)$it["quantity"];
            $ln  = (int)$it["line_vnd"];
            $body .= "<tr>";
            $body .= "<td style='padding:5px 10px;border-top:1px solid rgba(201,170,113,0.18);font-size:14px;color:#2a1a08;'>"
                  . $h($it["item_name"]) . " <span style='color:#6e5d40;'>× " . $qty . "</span></td>";
            $body .= "<td style='padding:5px 10px;border-top:1px solid rgba(201,170,113,0.18);text-align:right;font-size:14px;color:#2a1a08;'>"
                  . knk_vnd($ln) . "</td>";
            $body .= "</tr>";
        }
        $body .= "<tr><td style='padding:6px 10px;background:rgba(201,170,113,0.06);font-size:13px;color:#6e5d40;'>Subtotal · VAT 10%</td>";
        $body .= "<td style='padding:6px 10px;background:rgba(201,170,113,0.06);text-align:right;font-size:13px;color:#6e5d40;'>"
              . knk_vnd((int)$o["subtotal_vnd"]) . " · " . knk_vnd((int)$o["vat_vnd"]) . "</td></tr>";
        $body .= "<tr><td style='padding:8px 10px;background:rgba(201,170,113,0.18);font-size:14px;color:#2a1a08;font-weight:700;'>Order total</td>";
        $body .= "<td style='padding:8px 10px;background:rgba(201,170,113,0.18);text-align:right;font-size:14px;color:#2a1a08;font-weight:700;'>"
              . knk_vnd((int)$o["total_vnd"]) . "</td></tr>";
        $body .= "</tbody></table>";
    }

    $body .= "<p style='font-size:18px;color:#2a1a08;margin:20px 0 6px;'>";
    $body .= "<strong>Grand total: " . $h(knk_vnd($grand_total)) . "</strong></p>";
    $body .= "<p style='font-size:13px;color:#6e5d40;margin:6px 0 0;'>"
          . "Subtotal " . $h(knk_vnd($sub_total)) . " · VAT 10% " . $h(knk_vnd($vat_total)) . "</p>";
    $body .= "<p style='font-size:13px;color:#6e5d40;margin:14px 0 0;'>Settle at the bar — input into the till and mark each order paid in /orders.php.</p>";

    $html = knk_email_html(
        "🧾 Bill request — " . $disp,
        $disp . " requested the bill (" . count($orders) . " open orders, "
            . knk_vnd($grand_total) . ").",
        $body,
        "Sent from /bar.php?tab=drinks · Check Bill button."
    );

    $plain = $disp . " has requested the bill.\n\n";
    foreach ($orders as $o) {
        $plain .= "Order " . (string)$o["slug"] . " (" . (string)$o["location"] . "):\n";
        foreach (($items_by_order[(int)$o["id"]] ?? []) as $it) {
            $plain .= "  " . (string)$it["item_name"] . " x " . (int)$it["quantity"]
                    . "  " . knk_vnd((int)$it["line_vnd"]) . "\n";
        }
        $plain .= "  Total: " . knk_vnd((int)$o["total_vnd"]) . "\n\n";
    }
    $plain .= "GRAND TOTAL: " . knk_vnd($grand_total) . "\n";

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
        "subject"    => "🧾 Bill request — " . $disp . " · " . knk_vnd($grand_total),
        "body"       => $plain,
        "html"       => $html,
    ], $err);

    if (!$ok) {
        throw new RuntimeException("Email failed: " . (string)$err);
    }

    $out = [
        "ok"        => true,
        "count"     => count($orders),
        "total_vnd" => $grand_total,
        "sent_to"   => $hostess,
    ];
} catch (Throwable $e) {
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

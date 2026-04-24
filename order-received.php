<?php
/*
 * KnK Inn — /order-received.php?token=...
 *
 * Bartender taps this link in the Gmail order notification.
 * We flip the order status to "received" and email the customer:
 *   "Your order will be up shortly."
 *
 * Safe to tap twice — already-received orders just show a friendly page.
 */

require_once __DIR__ . "/includes/orders_store.php";
require_once __DIR__ . "/includes/order_email.php";

$token = $_GET["token"] ?? "";
if (!preg_match('/^tok_[a-f0-9]{32}$/', $token)) {
    http_response_code(400);
    echo knk_order_result_page("Link not valid", "<p>That order link doesn't look right. Check the Gmail message and try tapping the button again.</p>");
    exit;
}

$order = orders_find_by_token($token);
if (!$order) {
    http_response_code(404);
    echo knk_order_result_page("Order not found", "<p>Couldn't find that order. It may have been deleted or the link is old.</p>");
    exit;
}

$already = ($order["status"] ?? "") === "received" || ($order["status"] ?? "") === "paid";
if (!$already) {
    $updated = orders_set_status_by_token($token, "received");
    if ($updated) {
        $order = $updated;
        /* Email the customer */
        @knk_email_customer_received($order);
    }
}

$items_html = "";
foreach ($order["items"] as $it) {
    $items_html .= "<li>" . htmlspecialchars($it["name"]) . " × " . (int)$it["qty"] . "</li>";
}

$heading = $already ? "Already marked as received" : "Nice — customer notified";
$note    = $already
    ? "This order was already marked received. No duplicate email sent."
    : "An email has gone out to " . htmlspecialchars($order["email"]) . " — they know their drinks are on the way.";

$locLabel = knk_location_label($order["location"], $order["room_number"] ?? null);

$body  = "<h2 style='margin:0 0 4px;'>" . htmlspecialchars($heading) . "</h2>";
$body .= "<p style='color:#6e5d40;'>" . $note . "</p>";
$body .= "<div style='background:#fdf8ef;border:1px solid #e7dcc2;border-radius:8px;padding:14px 18px;margin:14px 0;'>";
$body .= "<div><b>Deliver to:</b> " . htmlspecialchars($locLabel) . "</div>";
$body .= "<div style='margin-top:6px;'><b>Items:</b></div>";
$body .= "<ul style='margin:6px 0 0 0;padding-left:18px;'>" . $items_html . "</ul>";
if (!empty($order["notes"])) {
    $body .= "<div style='margin-top:10px;'><b>Notes:</b> " . htmlspecialchars($order["notes"]) . "</div>";
}
$body .= "<div style='margin-top:10px;color:#6e5d40;'><b>Total:</b> " . knk_vnd((int)$order["total_vnd"]) . " (incl. VAT)</div>";
$body .= "</div>";
$body .= "<p><a href='order-admin.php' style='display:inline-block;background:#180c03;color:#c9aa71;padding:12px 18px;border-radius:6px;text-decoration:none;font-weight:700;'>Back to orders</a></p>";

echo knk_order_result_page($heading, $body);

/* =========================================================
   Helpers
   ========================================================= */

function knk_order_result_page(string $title, string $bodyHtml): string {
    $t = htmlspecialchars($title, ENT_QUOTES, "UTF-8");
    return <<<HTML
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>{$t} — KnK Inn</title>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  body { background: #f4ede0; color: #180c03; font-family: Inter, system-ui, sans-serif; margin: 0; padding: 40px 16px; }
  .wrap { max-width: 520px; margin: 0 auto; background: #fdf8ef; border:1px solid #e7dcc2; border-radius: 10px; padding: 28px 26px; }
  h1, h2 { font-family: 'Archivo Black', sans-serif; color: #180c03; }
  h1 { font-size: 28px; margin: 0 0 8px; }
  h2 { font-size: 22px; margin: 0 0 8px; }
  .eyebrow { font-size: 11px; letter-spacing: 0.22em; text-transform:uppercase; color: #9c7f4a; margin: 0 0 6px; }
</style>
</head><body>
<div class="wrap">
  <div class="eyebrow">KnK Inn · Bar staff</div>
  {$bodyHtml}
</div>
</body></html>
HTML;
}

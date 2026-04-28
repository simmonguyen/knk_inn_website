<?php
/*
 * KnK Inn — combined bill (room + drinks) for a single booking.
 *
 * URL:   /bill.php?slug=b_abc123
 * Role:  super_admin, owner, reception
 *
 * Pulls the booking out of bookings.json, finds every linked drink
 * order via includes/bills_store.php, and renders a clean, printable
 * bill that Simmo can hand over at checkout.
 *
 * A 'Print' button triggers window.print() — the page has dedicated
 * @media print CSS so admin chrome disappears on paper.
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/bookings_store.php";
require_once __DIR__ . "/includes/bills_store.php";

$me = knk_require_permission("bookings");

$slug = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($_GET["slug"] ?? ""));
if ($slug === "") {
    http_response_code(400);
    echo "Missing slug.";
    exit;
}

// Find the booking in bookings.json.
$booking = null;
foreach (bookings_list_all(false) as $h) {
    if (($h["id"] ?? "") === $slug) { $booking = $h; break; }
}
if (!$booking) {
    http_response_code(404);
    echo "Booking not found.";
    exit;
}

$bill = knk_booking_bill($booking);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function fmt_date(string $ymd): string {
    $t = strtotime($ymd);
    return $t ? date("D j M Y", $t) : $ymd;
}
function fmt_dt(int $ts): string {
    return $ts ? date("D j M · H:i", $ts) : "—";
}
function vnd(int $n): string {
    return number_format($n, 0, ".", ",") . " ₫";
}

$guest = $booking["guest"] ?? [];
?><!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>KnK Inn — Bill · <?= h($slug) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --ink: #2a1a08;
      --ink-soft: #5a4a3a;
      --line: #d9ccb2;
      --gold: #c9aa71;
      --cream: #fffaf0;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0; padding: 2rem 1rem 5rem;
      background: #f3ece0; color: var(--ink);
      font-family: "Inter", system-ui, sans-serif;
      font-size: 14px; line-height: 1.5;
    }
    .screen-only { display: block; }
    .bill {
      max-width: 720px; margin: 0 auto;
      background: var(--cream); padding: 2.5rem 2.5rem 3rem;
      border: 1px solid var(--line);
      box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    }
    .bill header {
      display: flex; align-items: flex-start; justify-content: space-between;
      border-bottom: 2px solid var(--ink); padding-bottom: 1.2rem;
      margin-bottom: 1.8rem; gap: 1.5rem;
    }
    .bill .brand {
      font-family: "Archivo Black", sans-serif; font-size: 1.6rem;
      letter-spacing: 0.02em; color: var(--ink);
    }
    .bill .brand em { color: var(--gold); font-style: normal; }
    .bill .addr {
      font-size: 0.78rem; color: var(--ink-soft); line-height: 1.4;
      margin-top: 0.35rem;
    }
    .bill .doc {
      text-align: right; font-size: 0.8rem; color: var(--ink-soft);
    }
    .bill .doc .doc-title {
      font-size: 1.2rem; font-weight: 700; color: var(--ink);
      letter-spacing: 0.12em; text-transform: uppercase; margin-bottom: 0.4rem;
    }

    .two-col {
      display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;
      margin-bottom: 1.5rem;
    }
    .two-col h3 {
      font-size: 0.68rem; letter-spacing: 0.14em; text-transform: uppercase;
      color: var(--ink-soft); margin: 0 0 0.4rem 0; font-weight: 600;
    }
    .two-col p { margin: 0.15rem 0; }
    .two-col .big { font-weight: 700; font-size: 1rem; color: var(--ink); }

    table.lines {
      width: 100%; border-collapse: collapse; margin: 0.6rem 0 0.4rem;
    }
    table.lines th, table.lines td {
      text-align: left; padding: 0.5rem 0.3rem;
      border-bottom: 1px solid var(--line);
    }
    table.lines th {
      font-size: 0.68rem; letter-spacing: 0.14em; text-transform: uppercase;
      color: var(--ink-soft); font-weight: 600;
    }
    table.lines td.num, table.lines th.num {
      text-align: right; font-variant-numeric: tabular-nums;
      white-space: nowrap;
    }
    table.lines td.desc-sub {
      font-size: 0.82rem; color: var(--ink-soft);
      padding-top: 0; border-top: none;
    }
    .section-title {
      font-size: 0.72rem; letter-spacing: 0.15em; text-transform: uppercase;
      color: var(--ink); font-weight: 700;
      margin: 1.8rem 0 0.2rem 0;
    }

    .totals {
      margin-top: 1.6rem; border-top: 2px solid var(--ink); padding-top: 0.9rem;
    }
    .totals .row {
      display: flex; justify-content: space-between; padding: 0.28rem 0;
      font-size: 0.95rem;
    }
    .totals .row .l { color: var(--ink-soft); }
    .totals .row.grand {
      font-size: 1.25rem; font-weight: 700; color: var(--ink);
      border-top: 1px solid var(--line); margin-top: 0.5rem; padding-top: 0.8rem;
    }
    .totals .row.grand .l { color: var(--ink); letter-spacing: 0.05em; text-transform: uppercase; font-size: 0.85rem; }

    .foot {
      margin-top: 2rem; padding-top: 1.1rem; border-top: 1px solid var(--line);
      font-size: 0.8rem; color: var(--ink-soft); text-align: center;
    }
    .foot strong { color: var(--ink); }

    .actions {
      max-width: 720px; margin: 1.2rem auto 0; display: flex; gap: 0.6rem;
      justify-content: flex-end;
    }
    .actions a, .actions button {
      padding: 0.55rem 1.1rem; border-radius: 4px; font-weight: 600;
      font-size: 0.9rem; text-decoration: none; cursor: pointer;
      border: 1px solid var(--ink); background: var(--cream); color: var(--ink);
    }
    .actions button.primary {
      background: var(--gold); border-color: var(--gold); color: var(--ink);
    }
    .actions button:hover, .actions a:hover { opacity: 0.85; }

    @media print {
      body { background: #fff; padding: 0; }
      .bill { box-shadow: none; border: none; margin: 0 auto; padding: 1.2rem; }
      .screen-only, .actions { display: none !important; }
    }
  </style>
</head>
<body>

<div class="actions screen-only">
  <a href="bookings.php">← Back to Bookings</a>
  <button class="primary" onclick="window.print()">Print</button>
</div>

<div class="bill">
  <header>
    <div>
      <div class="brand">KnK <em>Inn</em></div>
      <div class="addr">
        266 Bùi Viện, Phường Phạm Ngũ Lão<br>
        District 1, Hồ Chí Minh City<br>
        gday@knkinn.com
      </div>
    </div>
    <div class="doc">
      <div class="doc-title">Bill</div>
      <div>Reference: <strong><?= h($slug) ?></strong></div>
      <div>Printed: <?= h(date("D j M Y · H:i")) ?></div>
    </div>
  </header>

  <div class="two-col">
    <div>
      <h3>Guest</h3>
      <p class="big"><?= h($guest["name"] ?? "(no name)") ?></p>
      <?php if (!empty($guest["email"])): ?><p><?= h($guest["email"]) ?></p><?php endif; ?>
      <?php if (!empty($guest["phone"])): ?><p><?= h($guest["phone"]) ?></p><?php endif; ?>
    </div>
    <div>
      <h3>Stay</h3>
      <p class="big"><?= h($bill["room_label"]) ?></p>
      <p>
        <?= h(fmt_date((string)$booking["checkin"])) ?>
        &nbsp;→&nbsp;
        <?= h(fmt_date((string)$booking["checkout"])) ?>
      </p>
      <p><?= (int)$bill["nights"] ?> night<?= (int)$bill["nights"] === 1 ? "" : "s" ?>
        · Status: <strong><?= h($booking["status"] ?? "pending") ?></strong>
      </p>
    </div>
  </div>

  <!-- Room charges -->
  <div class="section-title">Room</div>
  <table class="lines">
    <thead>
      <tr>
        <th>Description</th>
        <th class="num">Qty</th>
        <th class="num">Unit</th>
        <th class="num">Line</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>
          <?= h($bill["room_label"]) ?>
          <div style="font-size:0.8rem;color:var(--ink-soft);">
            <?= h(fmt_date((string)$booking["checkin"])) ?> → <?= h(fmt_date((string)$booking["checkout"])) ?>
          </div>
        </td>
        <td class="num"><?= (int)$bill["nights"] ?></td>
        <td class="num"><?= h(vnd((int)$bill["price_per_night"])) ?></td>
        <td class="num"><?= h(vnd((int)$bill["room_total"])) ?></td>
      </tr>
    </tbody>
  </table>

  <!-- Drink orders -->
  <?php if (!empty($bill["orders"])): ?>
    <div class="section-title">Drinks &amp; bar</div>
    <table class="lines">
      <thead>
        <tr>
          <th>Description</th>
          <th class="num">Qty</th>
          <th class="num">Unit</th>
          <th class="num">Line</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($bill["orders"] as $o):
          $ord_items = $o["items"] ?? [];
          $ord_ts = (int)($o["created_at"] ?? 0);
          $loc = (string)($o["location"] ?? "");
          $room_no = (string)($o["room_number"] ?? "");
        ?>
          <tr>
            <td colspan="4" style="background:#f7f0e0;padding:0.35rem 0.4rem;font-size:0.78rem;color:var(--ink-soft);border-bottom:1px solid var(--line);">
              Order <code><?= h($o["id"] ?? "") ?></code>
              · <?= h(fmt_dt($ord_ts)) ?>
              <?php if ($loc !== ""): ?>
                · <?= h($loc) ?><?php if ($room_no !== ""): ?> #<?= h($room_no) ?><?php endif; ?>
              <?php endif; ?>
              <?php if (!empty($o["status"]) && $o["status"] !== "paid"): ?>
                · <strong><?= h($o["status"]) ?></strong>
              <?php endif; ?>
            </td>
          </tr>
          <?php foreach ($ord_items as $it):
            $qty  = (int)($it["qty"]  ?? 1);
            $name = (string)($it["name"] ?? "?");
            $unit = (int)($it["price_vnd"] ?? 0);
            $line_v = $qty * $unit;
          ?>
            <tr>
              <td><?= h($name) ?></td>
              <td class="num"><?= $qty ?></td>
              <td class="num"><?= h(vnd($unit)) ?></td>
              <td class="num"><?= h(vnd($line_v)) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!empty($o["notes"])): ?>
            <tr><td colspan="4" class="desc-sub">Note: <?= h($o["notes"]) ?></td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- Totals -->
  <div class="totals">
    <div class="row">
      <span class="l">Room subtotal</span>
      <span><?= h(vnd((int)$bill["room_total"])) ?></span>
    </div>
    <?php if ((int)$bill["drinks_total"] > 0): ?>
      <div class="row">
        <span class="l">Drinks subtotal (excl. VAT)</span>
        <span><?= h(vnd((int)$bill["drinks_subtotal"])) ?></span>
      </div>
      <div class="row">
        <span class="l">VAT (10%) on drinks</span>
        <span><?= h(vnd((int)$bill["drinks_vat"])) ?></span>
      </div>
      <div class="row">
        <span class="l">Drinks total</span>
        <span><?= h(vnd((int)$bill["drinks_total"])) ?></span>
      </div>
    <?php endif; ?>
    <div class="row grand">
      <span class="l">Grand total</span>
      <span><?= h(vnd((int)$bill["grand_total"])) ?></span>
    </div>
  </div>

  <div class="foot">
    Thank you for staying at <strong>KnK Inn</strong>. We hope to see you again.
  </div>
</div>

</body>
</html>

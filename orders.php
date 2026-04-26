<?php
/*
 * KnK Inn — /orders.php  (was /orders.php — renamed in #88)
 *
 * Live orders dashboard. Role-gated (super_admin, owner, bartender).
 *
 * Bartender can:
 *   · Mark a pending order as "received" (also emails the customer)
 *   · Mark an order as "paid"        (settles the tab)
 *   · Mark an order as "cancelled"   (mistake / walkout)
 */

require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/orders_store.php";
require_once __DIR__ . "/includes/order_email.php";

/* Orders dashboard — gated by the "orders" permission (see migration 015). */
$me = knk_require_permission("orders");

/* ---------- Actions: mark received / paid / cancelled ---------- */
$flash = "";
if (in_array(($_POST["action"] ?? ""), ["received", "paid", "cancelled"], true)) {
    $id = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST["id"] ?? "");
    if ($id !== "") {
        try {
            $updated = orders_set_status_by_id($id, $_POST["action"]);
            if ($updated) {
                /* Fire customer email on "received" (same as the bartender-link path) */
                if ($_POST["action"] === "received") {
                    @knk_email_customer_received($updated);
                }
                $flash = "Order #" . $id . " → " . $_POST["action"] . ".";
            } else {
                $flash = "Couldn't find that order.";
            }
        } catch (Throwable $e) {
            $flash = "Error: " . $e->getMessage();
        }
    }
    header("Location: orders.php?msg=" . urlencode($flash));
    exit;
}

/* ---------- Action: permanently delete an order (Super Admin only) ----------
 * Used to clean up test orders. The audit log row keeps a record of who
 * deleted what and the order JSON so it can be reconstructed if needed.
 */
if (($_POST["action"] ?? "") === "delete") {
    if (($me["role"] ?? "") !== "super_admin") {
        header("Location: orders.php?msg=" . urlencode("Only Super Admin can delete orders."));
        exit;
    }
    $id = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST["id"] ?? "");
    if ($id !== "") {
        try {
            $deleted = orders_delete_by_id($id);
            if ($deleted) {
                knk_audit("order.delete", "order", $id, [
                    "email"     => $deleted["email"]    ?? null,
                    "total_vnd" => $deleted["total_vnd"] ?? null,
                    "status"    => $deleted["status"]   ?? null,
                    "snapshot"  => $deleted,
                ]);
                $flash = "Deleted order #" . $id . ".";
            } else {
                $flash = "Couldn't find that order.";
            }
        } catch (Throwable $e) {
            $flash = "Error: " . $e->getMessage();
        }
    }
    $back = $_POST["filter"] ?? "all";
    if (!in_array($back, ["open", "paid", "all"], true)) $back = "all";
    header("Location: orders.php?filter=" . urlencode($back) . "&msg=" . urlencode($flash));
    exit;
}

if (isset($_GET["msg"])) $flash = (string)$_GET["msg"];

/* ---------- Data ---------- */
$all     = orders_all();
usort($all, fn($a, $b) => ($b["created_at"] ?? 0) <=> ($a["created_at"] ?? 0));

$filter = $_GET["filter"] ?? "open";
if (!in_array($filter, ["open", "paid", "all"], true)) $filter = "open";
$view = array_values(array_filter($all, function ($o) use ($filter) {
    $s = $o["status"] ?? "";
    if ($filter === "open") return $s === "pending" || $s === "received";
    if ($filter === "paid") return $s === "paid";
    return true;
}));

$counts = [
    "open"  => count(array_filter($all, fn($o) => in_array($o["status"] ?? "", ["pending", "received"], true))),
    "paid"  => count(array_filter($all, fn($o) => ($o["status"] ?? "") === "paid")),
    "all"   => count($all),
];

$openSum = 0;
foreach ($all as $o) {
    if (in_array($o["status"] ?? "", ["pending", "received"], true)) $openSum += (int)($o["total_vnd"] ?? 0);
}

?><!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Orders · KnK Inn admin</title>
<meta name="robots" content="noindex,nofollow">
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --brown-deep:#180c03; --brown-mid:#3d1f0d; --gold:#c9aa71; --gold-dark:#9c7f4a;
    --cream:#f4ede0; --cream-card:#fdf8ef; --border:#e7dcc2; --muted:#6e5d40;
  }
  body { margin:0; padding:0; background: var(--cream); color: var(--brown-deep); font-family: Inter, system-ui, sans-serif; }
  .wrap { max-width: 960px; margin: 0 auto; padding: 24px 16px 60px; }

  .bar { display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap: 12px; padding: 10px 0 18px; border-bottom: 2px solid var(--brown-deep); margin-bottom: 16px; }
  .bar .title { display:flex; flex-direction:column; }
  .bar .eyebrow { font-size: 11px; letter-spacing: 0.22em; text-transform:uppercase; color: var(--gold-dark); font-weight:700; }
  .bar h1 { font-family: 'Archivo Black', sans-serif; font-size: 30px; margin: 4px 0 0; }
  .bar h1 em { color: var(--gold-dark); font-style: normal; }
  .bar .actions { display:flex; gap: 8px; align-items:center; }
  .btn-mini { display:inline-block; background: var(--cream-card); border:1px solid var(--border); color: var(--brown-deep); padding: 6px 12px; border-radius: 6px; text-decoration:none; font-size: 13px; font-weight: 600; }
  .btn-mini:hover { background: #fff; }
  .bar .actions form button { background: var(--brown-deep); color: var(--gold); border:none; padding: 6px 12px; border-radius: 6px; cursor:pointer; font-size: 13px; font-weight:600; }

  .flash { background: #fff3d0; border:1px solid #e0c896; color: #5e4717; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }

  .totals-bar { display:flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
  .stat { background: var(--cream-card); border:1px solid var(--border); border-radius: 8px; padding: 12px 16px; min-width: 150px; }
  .stat .l { font-size: 11px; letter-spacing: 0.2em; text-transform:uppercase; color: var(--gold-dark); font-weight: 700; }
  .stat .v { font-family: 'Archivo Black', sans-serif; font-size: 22px; color: var(--brown-deep); margin-top: 2px; }

  .tabs { display:flex; gap: 4px; margin-bottom: 14px; border-bottom: 1px solid var(--border); }
  .tab { padding: 8px 14px; border-bottom: 3px solid transparent; color: var(--muted); text-decoration: none; font-weight: 600; font-size: 14px; }
  .tab.active { color: var(--brown-deep); border-bottom-color: var(--brown-deep); }
  .tab .n { display:inline-block; background: var(--cream-card); border:1px solid var(--border); color: var(--brown-mid); border-radius: 10px; padding: 1px 8px; font-size: 11px; margin-left: 4px; }

  table.orders { width:100%; border-collapse: collapse; background: var(--cream-card); border:1px solid var(--border); border-radius: 8px; overflow:hidden; }
  table.orders th, table.orders td { padding: 10px 12px; text-align: left; font-size: 14px; border-bottom: 1px solid var(--border); vertical-align: top; }
  table.orders th { background: var(--brown-deep); color: var(--gold); font-size: 11px; letter-spacing: 0.15em; text-transform: uppercase; }
  table.orders tr:last-child td { border-bottom: none; }
  table.orders tr:hover td { background: #fff; }
  .status { display:inline-block; font-size: 11px; padding: 2px 8px; border-radius: 10px; letter-spacing: 0.08em; text-transform: uppercase; font-weight:700; }
  .status.pending  { background: #f5e5c5; color: #6c511a; }
  .status.received { background: #cfe8cf; color: #1f5a1f; }
  .status.paid     { background: #d7d7d7; color: #3a3a3a; }
  .status.cancelled{ background: #e9c6c6; color: #6c1a1a; }

  .items { margin: 0; padding-left: 14px; color: var(--brown-mid); font-size: 13px; }
  .notes { margin-top: 4px; font-style: italic; color: var(--muted); font-size: 13px; }
  .email { color: var(--muted); font-size: 12px; }
  .loc   { color: var(--brown-mid); font-weight: 600; }
  .time  { color: var(--muted); font-size: 12px; white-space: nowrap; }

  .row-actions { display:flex; flex-direction: column; gap: 6px; }
  .row-actions form { margin: 0; }
  .row-actions button { background: var(--brown-deep); color: var(--gold); border:none; padding: 6px 10px; border-radius: 5px; cursor:pointer; font-size: 12px; font-weight:600; width: 100%; }
  .row-actions button.paid  { background: #1f5a1f; color: #fff; }
  .row-actions button.ghost { background: transparent; color: var(--muted); border: 1px solid var(--border); }
  .row-actions button.danger{ background: transparent; color: #8a1a1a; border: 1px solid #d8b4b4; }
  .row-actions button.danger:hover { background: #fbe9e9; }

  .empty { padding: 40px 20px; text-align: center; color: var(--muted); background: var(--cream-card); border:1px dashed var(--border); border-radius: 8px; }

  /* ---- Login page ---- */
  .login-wrap { max-width: 380px; margin: 80px auto; background: var(--cream-card); border:1px solid var(--border); border-radius: 10px; padding: 32px 28px; text-align: center; }
  .login-wrap h1 { font-family: 'Archivo Black', sans-serif; margin: 0 0 6px; }
  .login-wrap input { width:100%; box-sizing: border-box; padding: 12px; margin-top: 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 15px; }
  .login-wrap button { width:100%; background: var(--brown-deep); color: var(--gold); border:none; padding: 12px; border-radius: 6px; margin-top: 10px; font-weight: 700; cursor:pointer; font-size: 15px; }
</style>
</head>
<body>
<?php knk_render_admin_nav($me); ?>
<div class="wrap">

  <header class="bar">
    <div class="title">
      <span class="eyebrow">Staff only · Orders</span>
      <h1>Rooftop <em>orders</em></h1>
    </div>
    <div class="actions">
      <a class="btn-mini" href="orders.php" title="Refresh">↻ Refresh</a>
    </div>
  </header>

  <?php if ($flash): ?>
    <div class="flash"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <div class="totals-bar">
    <div class="stat">
      <div class="l">Open tab</div>
      <div class="v"><?= htmlspecialchars(knk_vnd($openSum)) ?></div>
    </div>
    <div class="stat">
      <div class="l">Open orders</div>
      <div class="v"><?= (int)$counts["open"] ?></div>
    </div>
    <div class="stat">
      <div class="l">Paid today</div>
      <div class="v">
        <?php
        $today0 = strtotime("today");
        $paidToday = array_filter($all, fn($o) => ($o["status"] ?? "") === "paid" && ($o["paid_at"] ?? 0) >= $today0);
        echo count($paidToday);
        ?>
      </div>
    </div>
  </div>

  <nav class="tabs">
    <a class="tab <?= $filter === "open" ? "active" : "" ?>" href="orders.php?filter=open">Open <span class="n"><?= (int)$counts["open"] ?></span></a>
    <a class="tab <?= $filter === "paid" ? "active" : "" ?>" href="orders.php?filter=paid">Paid <span class="n"><?= (int)$counts["paid"] ?></span></a>
    <a class="tab <?= $filter === "all"  ? "active" : "" ?>" href="orders.php?filter=all">All <span class="n"><?= (int)$counts["all"] ?></span></a>
  </nav>

  <?php if (!$view): ?>
    <div class="empty">
      <p>No orders <?= $filter === "open" ? "open" : ($filter === "paid" ? "paid" : "yet") ?>.</p>
      <?php if ($filter !== "all" && $counts["all"] > 0): ?>
        <p><a href="orders.php?filter=all">See all orders</a></p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <table class="orders">
      <thead>
        <tr>
          <th style="width:140px;">When</th>
          <th>Customer &amp; location</th>
          <th>Items</th>
          <th style="width:110px;text-align:right;">Total</th>
          <th style="width:120px;">Status</th>
          <th style="width:130px;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($view as $o): ?>
          <tr>
            <td class="time">
              <?= htmlspecialchars(date("D j M", (int)$o["created_at"])) ?><br>
              <?= htmlspecialchars(date("H:i", (int)$o["created_at"])) ?>
            </td>
            <td>
              <div class="email"><?= htmlspecialchars($o["email"]) ?></div>
              <div class="loc"><?= htmlspecialchars(knk_location_label($o["location"], $o["room_number"] ?? null)) ?></div>
            </td>
            <td>
              <ul class="items">
                <?php foreach ($o["items"] as $it): ?>
                  <li><?= htmlspecialchars($it["name"]) ?> × <?= (int)$it["qty"] ?></li>
                <?php endforeach; ?>
              </ul>
              <?php if (!empty($o["notes"])): ?>
                <div class="notes">“<?= htmlspecialchars($o["notes"]) ?>”</div>
              <?php endif; ?>
            </td>
            <td style="text-align:right;">
              <div><b><?= htmlspecialchars(knk_vnd((int)$o["total_vnd"])) ?></b></div>
              <div class="email">subtotal <?= htmlspecialchars(knk_vnd((int)$o["subtotal_vnd"])) ?><br>+ VAT <?= htmlspecialchars(knk_vnd((int)$o["vat_vnd"])) ?></div>
            </td>
            <td>
              <span class="status <?= htmlspecialchars($o["status"]) ?>"><?= htmlspecialchars($o["status"]) ?></span>
              <?php if (!empty($o["received_at"])): ?>
                <div class="email" style="margin-top:4px;">rec'd <?= htmlspecialchars(date("H:i", (int)$o["received_at"])) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div class="row-actions">
                <?php if ($o["status"] === "pending"): ?>
                  <form method="post" action="orders.php">
                    <input type="hidden" name="action" value="received">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($o["id"]) ?>">
                    <button type="submit">Mark received</button>
                  </form>
                <?php endif; ?>
                <?php if ($o["status"] === "pending" || $o["status"] === "received"): ?>
                  <form method="post" action="orders.php">
                    <input type="hidden" name="action" value="paid">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($o["id"]) ?>">
                    <button type="submit" class="paid">Mark paid</button>
                  </form>
                  <form method="post" action="orders.php" onsubmit="return confirm('Cancel this order?');">
                    <input type="hidden" name="action" value="cancelled">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($o["id"]) ?>">
                    <button type="submit" class="ghost">Cancel</button>
                  </form>
                <?php endif; ?>
                <?php if (($me["role"] ?? "") === "super_admin"): ?>
                  <form method="post" action="orders.php"
                        onsubmit="return confirm('Permanently delete order <?= htmlspecialchars($o["id"]) ?>?\n\nThis removes it from the orders dashboard for good. The audit log keeps a record of the deletion.');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($o["id"]) ?>">
                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                    <button type="submit" class="danger">Delete</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <p style="color:var(--muted);font-size:12px;margin-top:24px;">
    Orders live in <code>orders.json</code>. Menu comes from <code>drinks.php</code> — edit the drinks page to change the menu.
  </p>

</div>

</body></html>

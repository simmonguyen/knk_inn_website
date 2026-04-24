<?php
/*
 * KnK Inn — /order-admin.php
 *
 * Staff-only live orders dashboard.  Shares the same session/password as
 * bookings.php so Simmo logs in once.
 *
 * Bartender can:
 *   · Mark a pending order as "received" (also emails the customer)
 *   · Mark an order as "paid"        (settles the tab)
 *   · Mark an order as "cancelled"   (mistake / walkout)
 */

session_start();

require_once __DIR__ . "/includes/orders_store.php";
require_once __DIR__ . "/includes/order_email.php";

/* Password lives in config.php (gitignored). Shared with bookings.php. */
$_CFG = @include __DIR__ . "/config.php";
define("ADMIN_PASSWORD", is_array($_CFG) && !empty($_CFG["admin_password"]) ? $_CFG["admin_password"] : "");

function is_admin(): bool { return !empty($_SESSION["admin_ok"]); }

/* ---------- Logout ---------- */
if (($_POST["action"] ?? "") === "logout") {
    $_SESSION = [];
    session_destroy();
    header("Location: order-admin.php");
    exit;
}

/* ---------- Login ---------- */
$login_error = "";
if (($_POST["action"] ?? "") === "login") {
    $pw = $_POST["password"] ?? "";
    if (hash_equals(ADMIN_PASSWORD, $pw)) {
        session_regenerate_id(true);
        $_SESSION["admin_ok"] = true;
        header("Location: order-admin.php");
        exit;
    }
    $login_error = "Wrong password.";
}

if (!is_admin()) {
    echo render_login($login_error);
    exit;
}

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
    header("Location: order-admin.php?msg=" . urlencode($flash));
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

  .empty { padding: 40px 20px; text-align: center; color: var(--muted); background: var(--cream-card); border:1px dashed var(--border); border-radius: 8px; }

  /* ---- Login page ---- */
  .login-wrap { max-width: 380px; margin: 80px auto; background: var(--cream-card); border:1px solid var(--border); border-radius: 10px; padding: 32px 28px; text-align: center; }
  .login-wrap h1 { font-family: 'Archivo Black', sans-serif; margin: 0 0 6px; }
  .login-wrap input { width:100%; box-sizing: border-box; padding: 12px; margin-top: 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 15px; }
  .login-wrap button { width:100%; background: var(--brown-deep); color: var(--gold); border:none; padding: 12px; border-radius: 6px; margin-top: 10px; font-weight: 700; cursor:pointer; font-size: 15px; }
</style>
</head>
<body>

<div class="wrap">

  <header class="bar">
    <div class="title">
      <span class="eyebrow">Staff only · Orders</span>
      <h1>Rooftop <em>orders</em></h1>
    </div>
    <div class="actions">
      <a class="btn-mini" href="bookings.php">Bookings</a>
      <a class="btn-mini" href="order-admin.php" title="Refresh">↻ Refresh</a>
      <form method="post" style="margin:0;">
        <input type="hidden" name="action" value="logout">
        <button type="submit">Log out</button>
      </form>
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
    <a class="tab <?= $filter === "open" ? "active" : "" ?>" href="order-admin.php?filter=open">Open <span class="n"><?= (int)$counts["open"] ?></span></a>
    <a class="tab <?= $filter === "paid" ? "active" : "" ?>" href="order-admin.php?filter=paid">Paid <span class="n"><?= (int)$counts["paid"] ?></span></a>
    <a class="tab <?= $filter === "all"  ? "active" : "" ?>" href="order-admin.php?filter=all">All <span class="n"><?= (int)$counts["all"] ?></span></a>
  </nav>

  <?php if (!$view): ?>
    <div class="empty">
      <p>No orders <?= $filter === "open" ? "open" : ($filter === "paid" ? "paid" : "yet") ?>.</p>
      <?php if ($filter !== "all" && $counts["all"] > 0): ?>
        <p><a href="order-admin.php?filter=all">See all orders</a></p>
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
                  <form method="post" action="order-admin.php">
                    <input type="hidden" name="action" value="received">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($o["id"]) ?>">
                    <button type="submit">Mark received</button>
                  </form>
                <?php endif; ?>
                <?php if ($o["status"] === "pending" || $o["status"] === "received"): ?>
                  <form method="post" action="order-admin.php">
                    <input type="hidden" name="action" value="paid">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($o["id"]) ?>">
                    <button type="submit" class="paid">Mark paid</button>
                  </form>
                  <form method="post" action="order-admin.php" onsubmit="return confirm('Cancel this order?');">
                    <input type="hidden" name="action" value="cancelled">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($o["id"]) ?>">
                    <button type="submit" class="ghost">Cancel</button>
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
    Orders live in <code>orders.json</code>. Menu comes from <code>drinks.html</code> — edit the drinks page to change the menu.
  </p>

</div>

</body></html>
<?php

function render_login(string $error = ""): string {
    $err = $error ? "<p style='color:#9c2222;margin-top:8px;'>" . htmlspecialchars($error) . "</p>" : "";
    return <<<HTML
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Staff · KnK Inn</title>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
body{margin:0;padding:0;background:#f4ede0;color:#180c03;font-family:Inter,system-ui,sans-serif;}
.login-wrap{max-width:380px;margin:80px auto;background:#fdf8ef;border:1px solid #e7dcc2;border-radius:10px;padding:32px 28px;text-align:center;}
.login-wrap h1{font-family:'Archivo Black',sans-serif;margin:0 0 6px;}
.login-wrap input{width:100%;box-sizing:border-box;padding:12px;margin-top:12px;border:1px solid #e7dcc2;border-radius:6px;font-size:15px;}
.login-wrap button{width:100%;background:#180c03;color:#c9aa71;border:none;padding:12px;border-radius:6px;margin-top:10px;font-weight:700;cursor:pointer;font-size:15px;}
p.muted{color:#6e5d40;font-size:13px;margin:0;}
</style></head><body>
<div class="login-wrap">
  <h1>Staff only</h1>
  <p class="muted">Rooftop orders dashboard.</p>
  <form method="post" action="order-admin.php">
    <input type="hidden" name="action" value="login">
    <input type="password" name="password" placeholder="Admin password" required autofocus>
    <button type="submit">Enter</button>
  </form>
  {$err}
</div></body></html>
HTML;
}

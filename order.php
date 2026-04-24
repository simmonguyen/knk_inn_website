<?php
/*
 * KnK Inn — /order.php
 *
 * Rooftop ordering page.
 * Flow:
 *   1. Customer enters email → session remembers it (no verification).
 *   2. Menu is parsed from drinks.php; qty box next to each item.
 *   3. Customer picks delivery location + optional notes → submit.
 *   4. Server creates a pending order, emails knkinnsaigon@gmail.com
 *      with a "Mark received" link for the bartender.
 *   5. Customer sees a confirmation + their unpaid + past orders.
 *
 * Pricing: subtotal + 10% VAT shown as separate lines on the order.
 */

session_start();

require_once __DIR__ . "/includes/orders_store.php";
require_once __DIR__ . "/includes/order_email.php";
require_once __DIR__ . "/includes/guests_store.php";
require_once __DIR__ . "/includes/market_engine.php";

/* Phase 6 — Stay logged in:
 * Remember the guest's email across visits via a 90-day cookie so they
 * don't have to re-type it every time. Session carries it within a visit;
 * this cookie re-seeds the session on the next visit. */
define("KNK_GUEST_COOKIE",     "knk_guest_email");
define("KNK_GUEST_COOKIE_TTL", 90 * 24 * 60 * 60);  // 90 days

if (empty($_SESSION["order_email"]) && !empty($_COOKIE[KNK_GUEST_COOKIE])) {
    $_remembered = strtolower(trim((string)$_COOKIE[KNK_GUEST_COOKIE]));
    if (filter_var($_remembered, FILTER_VALIDATE_EMAIL)) {
        $_SESSION["order_email"] = $_remembered;
    }
}

/* Build the site URL from the current request so local tests link
   to localhost and production orders link to knkinn.com. */
$_scheme  = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
$_host    = $_SERVER["HTTP_HOST"] ?? "knkinn.com";
$SITE_URL = $_scheme . "://" . $_host;

/* ---- Helpers ---- */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function redirect($to) { header("Location: $to"); exit; }

$flash  = "";
$confirm_order = null;

/* ---- Logout / change email ---- */
if (($_GET["logout"] ?? "") !== "") {
    unset($_SESSION["order_email"]);
    if (isset($_COOKIE[KNK_GUEST_COOKIE])) {
        $secure = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";
        setcookie(KNK_GUEST_COOKIE, "", [
            "expires"  => time() - 3600,
            "path"     => "/",
            "secure"   => $secure,
            "httponly" => true,
            "samesite" => "Lax",
        ]);
        unset($_COOKIE[KNK_GUEST_COOKIE]);
    }
    redirect("order.php");
}

/* ---- Email login (POST) ---- */
if (($_POST["action"] ?? "") === "login") {
    $email = strtolower(trim($_POST["email"] ?? ""));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $flash = "That email doesn't look right. Try again.";
    } else {
        $_SESSION["order_email"] = $email;
        // Remember across visits for 90 days.
        $secure = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";
        setcookie(KNK_GUEST_COOKIE, $email, [
            "expires"  => time() + KNK_GUEST_COOKIE_TTL,
            "path"     => "/",
            "secure"   => $secure,
            "httponly" => true,
            "samesite" => "Lax",
        ]);
        redirect("order.php");
    }
}

/* ---- Place order (POST) ---- */
/*
 * Market-aware flow:
 *   • Every render stamps `stamp_ts` (unix) + `stamp_price[<code>]`
 *     hidden fields so we can detect stale prices on submit.
 *   • On POST we recompute the live quote for each cart item.
 *     If the market is on AND (stamp_ts is older than the
 *     price_lock_seconds window OR any snapshot price differs
 *     from the current quote OR a previously-eligible item is
 *     no longer on the board), we bounce to a "prices updated"
 *     re-confirm screen instead of creating the order.
 *   • Fair-play (max market items per order + per-guest cooldown)
 *     is enforced via knk_market_fairplay_check().
 *   • Ineligible drinks always trade at base price — the market
 *     engine's knk_market_quote() handles that distinction.
 */
$reconfirm_cart = null;   // set when we need to show "prices updated" screen
if (($_POST["action"] ?? "") === "place_order") {
    $email = strtolower(trim($_SESSION["order_email"] ?? ""));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirect("order.php");
    }

    $lookup    = knk_menu_lookup();
    $qtys      = $_POST["qty"] ?? [];
    $stampTs   = (int)($_POST["stamp_ts"] ?? 0);
    $stampPx   = is_array($_POST["stamp_price"] ?? null) ? $_POST["stamp_price"] : [];
    $marketOn  = knk_market_enabled();
    $lockSec   = $marketOn ? (int)knk_market_config()["price_lock_seconds"] : 0;

    $items    = [];
    $subtotal = 0;
    $stale    = false;
    $cartCodes = [];
    if (is_array($qtys)) {
        foreach ($qtys as $id => $qty) {
            $qty = (int)$qty;
            if ($qty < 1) continue;
            if ($qty > 20) $qty = 20;  // guard against typos
            if (!isset($lookup[$id])) continue;
            $it = $lookup[$id];
            $cartCodes[] = $id;

            // Live quote wins over the menu base price for eligible drinks.
            $unit = (int)$it["price_vnd"];
            if ($marketOn) {
                $q    = knk_market_quote($id);
                $unit = (int)$q["price_vnd"];
                $snap = isset($stampPx[$id]) ? (int)$stampPx[$id] : -1;
                // Stale if snapshot missing or different from current.
                if ($snap !== $unit) $stale = true;
            }

            $line = $unit * $qty;
            $subtotal += $line;
            $items[] = [
                "id"         => $id,
                "name"       => $it["name"],
                "price_vnd"  => $unit,
                "qty"        => $qty,
            ];
        }
    }

    if ($marketOn && $lockSec > 0 && $stampTs > 0 && (time() - $stampTs) > $lockSec) {
        $stale = true;
    }

    if (!$items) {
        $flash = "Pick at least one drink, mate.";
    } elseif ($marketOn && $stale) {
        // Don't place — show the re-confirm screen. The cart carries
        // its quantities through as hidden fields on the next form.
        $reconfirm_cart = ["items" => $items];
        $flash = "Prices moved on the market — check the new numbers and confirm.";
    } else {
        // Fair-play: max market items per order + per-guest cooldown.
        if ($marketOn) {
            $fp = knk_market_fairplay_check($cartCodes, $email);
            if (!$fp["ok"]) {
                $flash = $fp["reason"];
            }
        }

        if ($flash === "") {
            $vat    = (int)round($subtotal * KNK_VAT_RATE);
            $total  = $subtotal + $vat;
            $loc    = $_POST["location"] ?? "rooftop";
            if (!in_array($loc, ["rooftop", "floor-5", "floor-1", "room"], true)) $loc = "rooftop";
            $room   = null;
            if ($loc === "room") {
                $room = preg_replace('/[^0-9A-Za-z]/', '', substr($_POST["room_number"] ?? "", 0, 6));
                if ($room === "") {
                    $flash = "For Room delivery, type your room number.";
                }
            }
            $notes  = trim(substr($_POST["notes"] ?? "", 0, 500));

            if ($flash === "") {
                $order = orders_create([
                    "email"        => $email,
                    "location"     => $loc,
                    "room_number"  => $room,
                    "items"        => $items,
                    "subtotal_vnd" => $subtotal,
                    "vat_vnd"      => $vat,
                    "total_vnd"    => $total,
                    "notes"        => $notes,
                ]);

                /* Fire the bartender email (best-effort — don't block the customer) */
                @knk_email_bar_new_order($order, $SITE_URL);

                /* V2 Phase 3: upsert guest + refresh cached stats. Swallows
                 * its own errors so a guests-table issue never blocks the
                 * bartender flow. */
                $gid = knk_guest_upsert($email);
                if ($gid) knk_guest_refresh_stats($gid);

                $confirm_order = $order;
            }
        }
    }
}

/* =========================================================
   RENDER
   ========================================================= */

$email = $_SESSION["order_email"] ?? "";
$menu  = knk_menu();

/* Market quotes keyed by item_code. When the market is off this is
 * a map of identity quotes (price == base, eligible=false) so the
 * render loop can read it uniformly. */
$marketOn = knk_market_enabled();
$quotes   = [];
if ($marketOn) {
    foreach ($menu as $cat) foreach ($cat["items"] as $it) {
        $quotes[$it["id"]] = knk_market_quote($it["id"]);
    }
}
$renderTs = time();   // baked into hidden stamp_ts for the 15-sec price lock

$history = $email ? orders_for_email($email) : [];
$unpaid  = array_values(array_filter($history, fn($o) => ($o["status"] ?? "") !== "paid" && ($o["status"] ?? "") !== "cancelled"));
$past    = array_values(array_filter($history, fn($o) => ($o["status"] ?? "") === "paid" || ($o["status"] ?? "") === "cancelled"));

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Order — KnK Inn</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@400;500;600;700&family=Caveat:wght@700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/styles.css?v=12">
<style>
  :root {
    --brown-deep:#180c03; --brown-mid:#3d1f0d; --gold:#c9aa71; --gold-dark:#9c7f4a;
    --cream:#f4ede0; --cream-card:#fdf8ef; --border:#e7dcc2; --muted:#6e5d40;
  }
  body { background: var(--cream); color: var(--brown-deep); font-family: Inter, system-ui, sans-serif; }
  .wrap { max-width: 720px; margin: 0 auto; padding: 28px 16px 80px; }
  .hero { text-align:center; padding: 14px 0 22px; }
  .hero .eyebrow { font-size: 11px; letter-spacing: 0.22em; text-transform:uppercase; color: var(--gold-dark); }
  .hero h1 { font-family: 'Archivo Black', sans-serif; font-size: 34px; margin: 4px 0 4px; color: var(--brown-deep); }
  .hero p { color: var(--muted); margin: 0; }

  .card { background: var(--cream-card); border:1px solid var(--border); border-radius: 10px; padding: 18px 20px; margin: 14px 0; box-shadow: 0 1px 0 rgba(24,12,3,0.03); }
  .card h2 { margin:0 0 6px; font-size: 18px; color: var(--brown-deep); }
  .card h3 { margin:0 0 8px; font-size: 15px; color: var(--brown-deep); }
  .muted { color: var(--muted); font-size: 13px; }

  label.lbl { display:block; font-size:12px; letter-spacing:0.1em; text-transform:uppercase; color:var(--gold-dark); margin-bottom:4px; font-weight:700; }
  input[type=email], input[type=text], select, textarea {
    width: 100%; box-sizing: border-box; padding: 11px 13px; font-size: 15px;
    background: #fff; border:1px solid var(--border); border-radius: 6px; color: var(--brown-deep);
    font-family: inherit;
  }
  textarea { min-height: 70px; resize: vertical; }
  .btn { display:inline-block; background: var(--brown-deep); color: var(--gold); border:none; padding: 12px 20px; border-radius: 6px; font-weight:700; font-size:15px; cursor:pointer; text-decoration:none; letter-spacing:0.04em; }
  .btn:hover { background: var(--brown-mid); }
  .btn.ghost { background: transparent; color: var(--brown-mid); border: 1px solid var(--border); }
  .btn.block { display:block; width:100%; text-align:center; padding: 14px; font-size: 16px; }

  .flash { background: #fff3d0; border:1px solid #e0c896; color: #5e4717; padding: 10px 14px; border-radius: 6px; margin: 10px 0; }

  .loggedin { display:flex; justify-content:space-between; align-items:center; background:var(--brown-deep); color:var(--gold); padding: 10px 16px; border-radius: 8px; margin-bottom: 14px; font-size: 14px; }
  .loggedin a { color: var(--cream); text-decoration: underline; font-size: 12px; }

  .cat { margin: 14px 0; }
  .cat-title { font-family: 'Archivo Black', sans-serif; font-size: 16px; color: var(--brown-mid); padding: 6px 0; border-bottom: 2px solid var(--gold); margin: 12px 0 6px; letter-spacing: 0.02em; text-transform: uppercase; }
  .item { display:grid; grid-template-columns: 1fr auto auto; gap: 10px; align-items:center; padding: 6px 0; border-bottom: 1px dashed var(--border); }
  .item .nm { font-weight: 600; color: var(--brown-deep); }
  .item .pr { color: var(--muted); font-size: 13px; min-width: 80px; text-align: right; }
  .item input[type=number] { width: 56px; padding: 6px 8px; text-align: center; font-size: 14px; }
  .item .trend { font-size: 12px; margin-left: 4px; }
  .item .trend.up   { color: #b84c2b; }
  .item .trend.down { color: #1f5a1f; }
  .item .trend.flat { color: var(--muted); }
  .tag-pill { display:inline-block; padding: 1px 7px; border-radius: 9px; font-size: 10px; letter-spacing: 0.1em; text-transform: uppercase; font-weight: 700; margin-left: 6px; vertical-align: middle; }
  .tag-pill.crash { background: #b84c2b; color: #fff; animation: crashFlash 1.4s infinite; }
  .tag-pill.lock  { background: #c9aa71; color: #2a1a08; }
  @keyframes crashFlash {
    0%, 100% { opacity: 1; }
    50%      { opacity: 0.55; }
  }
  .market-ribbon { background: linear-gradient(90deg,#2a1a08,#3d1f0d); color: var(--gold); padding: 8px 12px; border-radius: 6px; font-size: 12px; letter-spacing: 0.12em; text-transform: uppercase; text-align: center; margin-bottom: 10px; }
  .market-ribbon a { color: var(--gold); text-decoration: underline; }
  .reconfirm-list { margin: 6px 0 12px; }
  .reconfirm-list .rc-row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px dashed var(--border); font-size: 14px; }
  .reconfirm-list .rc-row:last-child { border-bottom: none; }

  .loc-row { display:grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .loc-row .roomnum { display:none; }
  .loc-row.show-room .roomnum { display:block; }

  .totals { background: #fff; border:1px solid var(--border); border-radius: 8px; padding: 12px 16px; margin: 14px 0 10px; }
  .totals .row { display:flex; justify-content:space-between; padding: 4px 0; color: var(--muted); font-size: 14px; }
  .totals .row.total { border-top: 2px solid var(--brown-deep); margin-top: 6px; padding-top: 8px; color: var(--brown-deep); font-weight: 800; font-size: 17px; }

  .history-row { display:flex; justify-content:space-between; align-items:center; padding: 8px 0; border-bottom: 1px dashed var(--border); font-size: 14px; }
  .history-row:last-child { border-bottom: none; }
  .history-row .date { color: var(--muted); font-size: 12px; }
  .status { font-size: 11px; padding: 2px 8px; border-radius: 10px; letter-spacing: 0.08em; text-transform: uppercase; font-weight:700; }
  .status.pending  { background: #f5e5c5; color: #6c511a; }
  .status.received { background: #cfe8cf; color: #1f5a1f; }
  .status.paid     { background: #d7d7d7; color: #3a3a3a; }
  .status.cancelled{ background: #e9c6c6; color: #6c1a1a; }

  details > summary { cursor: pointer; padding: 6px 0; color: var(--brown-mid); font-weight: 600; }
  details[open] > summary { margin-bottom: 8px; }

  .ok-banner { background: #1f5a1f; color: #fff; padding: 16px 20px; border-radius: 10px; text-align:center; margin: 10px 0 16px; }
  .ok-banner h2 { color: #fff; margin: 0 0 4px; font-family: 'Archivo Black'; font-size: 22px; }
</style>
</head>
<body>

<nav id="nav">
  <div class="nav-inner">
    <a href="index.php" class="nav-logo">KnK Inn</a>
    <ul class="nav-links">
      <li><a href="index.php">Home</a></li>
      <li><a href="rooms.html">Rooms</a></li>
      <li><a href="drinks.php">Drinks</a></li>
      <li><a href="gallery.php">Gallery</a></li>
      <li><a href="order.php" class="active">Order</a></li>
    </ul>
  </div>
</nav>

<div class="wrap">

  <div class="hero">
    <div class="eyebrow">Rooftop &amp; in-room</div>
    <h1>Order a drink</h1>
    <p class="muted">Pick what you want — we'll bring it up.</p>
  </div>

  <?php if ($flash): ?>
    <div class="flash"><?= h($flash) ?></div>
  <?php endif; ?>

  <?php if ($confirm_order): ?>
    <!-- ─────── ORDER CONFIRMATION ─────── -->
    <div class="ok-banner">
      <h2>Order in.</h2>
      <p>We'll let you know when the bartender's on the way up.</p>
    </div>
    <div class="card">
      <h3>Your order</h3>
      <?php foreach ($confirm_order["items"] as $it): ?>
        <div class="item">
          <span class="nm"><?= h($it["name"]) ?> <span class="muted">× <?= (int)$it["qty"] ?></span></span>
          <span></span>
          <span class="pr"><?= h(knk_vnd($it["price_vnd"] * $it["qty"])) ?></span>
        </div>
      <?php endforeach; ?>
      <div class="totals">
        <div class="row"><span>Subtotal</span><span><?= h(knk_vnd($confirm_order["subtotal_vnd"])) ?></span></div>
        <div class="row"><span>VAT 10%</span><span><?= h(knk_vnd($confirm_order["vat_vnd"])) ?></span></div>
        <div class="row total"><span>Total</span><span><?= h(knk_vnd($confirm_order["total_vnd"])) ?></span></div>
      </div>
      <p class="muted">Delivering to: <b><?= h(knk_location_label($confirm_order["location"], $confirm_order["room_number"] ?? null)) ?></b></p>
      <?php if (!empty($confirm_order["notes"])): ?>
        <p class="muted">Notes: <?= h($confirm_order["notes"]) ?></p>
      <?php endif; ?>
    </div>
    <a href="order.php" class="btn block">Place another order</a>

  <?php elseif ($reconfirm_cart): ?>
    <!-- ─────── PRICES UPDATED — RE-CONFIRM ─────── -->
    <?php
      $rcSub = 0;
      foreach ($reconfirm_cart["items"] as $it) $rcSub += (int)$it["price_vnd"] * (int)$it["qty"];
      $rcVat = (int)round($rcSub * KNK_VAT_RATE);
      $rcTot = $rcSub + $rcVat;
    ?>
    <div class="card">
      <h2>Prices updated</h2>
      <p class="muted">The market moved while you were browsing. Check the new numbers and confirm — or tap back to the menu.</p>
      <form method="post" action="order.php">
        <input type="hidden" name="action" value="place_order">
        <input type="hidden" name="stamp_ts" value="<?= (int)$renderTs ?>">
        <input type="hidden" name="location" value="<?= h($_POST["location"] ?? "rooftop") ?>">
        <?php if (!empty($_POST["room_number"])): ?>
          <input type="hidden" name="room_number" value="<?= h($_POST["room_number"]) ?>">
        <?php endif; ?>
        <?php if (!empty($_POST["notes"])): ?>
          <input type="hidden" name="notes" value="<?= h($_POST["notes"]) ?>">
        <?php endif; ?>

        <div class="reconfirm-list">
          <?php foreach ($reconfirm_cart["items"] as $it): ?>
            <div class="rc-row">
              <span><b><?= h($it["name"]) ?></b> <span class="muted">× <?= (int)$it["qty"] ?></span></span>
              <span><?= h(knk_vnd((int)$it["price_vnd"] * (int)$it["qty"])) ?></span>
            </div>
            <input type="hidden" name="qty[<?= h($it["id"]) ?>]" value="<?= (int)$it["qty"] ?>">
            <input type="hidden" name="stamp_price[<?= h($it["id"]) ?>]" value="<?= (int)$it["price_vnd"] ?>">
          <?php endforeach; ?>
        </div>

        <div class="totals">
          <div class="row"><span>Subtotal</span><span><?= h(knk_vnd($rcSub)) ?></span></div>
          <div class="row"><span>VAT 10%</span><span><?= h(knk_vnd($rcVat)) ?></span></div>
          <div class="row total"><span>Total</span><span><?= h(knk_vnd($rcTot)) ?></span></div>
        </div>

        <button type="submit" class="btn block">Confirm at new prices</button>
      </form>
      <p style="text-align:center; margin-top: 10px;">
        <a href="order.php" class="muted">Back to the menu</a>
      </p>
    </div>

  <?php elseif (!$email): ?>
    <!-- ─────── EMAIL LOGIN ─────── -->
    <div class="card">
      <h2>Your email</h2>
      <p class="muted">So the bar knows who's ordering and you can see your tab.</p>
      <form method="post" action="order.php">
        <input type="hidden" name="action" value="login">
        <label class="lbl" for="email">Email</label>
        <input type="email" id="email" name="email" required autocomplete="email" placeholder="you@example.com" value="<?= h($_POST["email"] ?? "") ?>">
        <p style="margin:14px 0 0;">
          <button type="submit" class="btn block">Start order</button>
        </p>
      </form>
    </div>

  <?php else: ?>
    <!-- ─────── LOGGED-IN: MENU + HISTORY ─────── -->

    <div class="loggedin">
      <span>Ordering as <b><?= h($email) ?></b></span>
      <a href="order.php?logout=1">Not you?</a>
    </div>

    <?php if ($unpaid): ?>
      <div class="card">
        <h3>Your open tab (<?= count($unpaid) ?>)</h3>
        <p class="muted">Orders not yet paid. Settle up with the bar when you're done.</p>
        <?php $openTotal = 0; foreach ($unpaid as $o): $openTotal += (int)($o["total_vnd"] ?? 0); ?>
          <div class="history-row">
            <div>
              <div><b><?= count($o["items"]) ?> item<?= count($o["items"]) === 1 ? "" : "s" ?></b> · <?= h(knk_location_label($o["location"], $o["room_number"] ?? null)) ?></div>
              <div class="date"><?= h(date("D j M · H:i", $o["created_at"])) ?></div>
            </div>
            <div style="text-align:right;">
              <div><?= h(knk_vnd($o["total_vnd"])) ?></div>
              <span class="status <?= h($o["status"]) ?>"><?= h($o["status"]) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
        <div class="history-row" style="border-bottom:none;padding-top:12px;">
          <b>Open tab total</b>
          <b><?= h(knk_vnd($openTotal)) ?></b>
        </div>
      </div>
    <?php endif; ?>

    <!-- Order form -->
    <form method="post" action="order.php" id="orderForm">
      <input type="hidden" name="action" value="place_order">
      <input type="hidden" name="stamp_ts" value="<?= (int)$renderTs ?>">

      <?php if ($marketOn): ?>
        <div class="market-ribbon">
          The Beer Stock Market is live — prices move in real time · <a href="/market.php" target="_blank">Watch the board ↗</a>
        </div>
      <?php endif; ?>

      <div class="card">
        <h2>Menu</h2>
        <p class="muted">Prices in Vietnamese đồng (VND). 10% VAT added at checkout.</p>

        <?php foreach ($menu as $cat): ?>
          <div class="cat">
            <div class="cat-title"><?= h($cat["title"]) ?></div>
            <?php foreach ($cat["items"] as $it):
              $code = (string)$it["id"];
              // Live price for eligible market items; base otherwise.
              $liveQuote = $marketOn ? ($quotes[$code] ?? null) : null;
              $livePrice = $liveQuote ? (int)$liveQuote["price_vnd"] : (int)$it["price_vnd"];
              $isEligible = $liveQuote && !empty($liveQuote["eligible"]);
              $trend = $isEligible ? knk_market_trend($code) : "";
              $inCrash = $isEligible && !empty($liveQuote["in_crash"]);
              $isLocked = $isEligible && !empty($liveQuote["locked_until"]);
            ?>
              <div class="item">
                <span class="nm">
                  <?= h($it["name"]) ?>
                  <?php if ($inCrash): ?>
                    <span class="tag-pill crash">Crashing</span>
                  <?php elseif ($isLocked): ?>
                    <span class="tag-pill lock">Special</span>
                  <?php endif; ?>
                </span>
                <span class="pr">
                  <?= h(knk_vnd($livePrice)) ?>
                  <?php if ($isEligible && $trend === "up"): ?>
                    <span class="trend up" title="Trending up">&#9650;</span>
                  <?php elseif ($isEligible && $trend === "down"): ?>
                    <span class="trend down" title="Trending down">&#9660;</span>
                  <?php elseif ($isEligible): ?>
                    <span class="trend flat" title="Flat">&ndash;</span>
                  <?php endif; ?>
                </span>
                <input type="number" name="qty[<?= h($code) ?>]" min="0" max="20" step="1" value="0"
                       data-price="<?= (int)$livePrice ?>" class="qty-input" aria-label="Quantity of <?= h($it["name"]) ?>">
                <?php if ($marketOn): ?>
                  <input type="hidden" name="stamp_price[<?= h($code) ?>]" value="<?= (int)$livePrice ?>">
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>

        <div class="totals" aria-live="polite">
          <div class="row"><span>Subtotal</span><span id="subtotal">0đ</span></div>
          <div class="row"><span>VAT 10%</span><span id="vat">0đ</span></div>
          <div class="row total"><span>Total</span><span id="total">0đ</span></div>
        </div>
      </div>

      <div class="card">
        <h3>Where should we bring it?</h3>
        <div class="loc-row" id="locRow">
          <div>
            <label class="lbl" for="location">Deliver to</label>
            <select name="location" id="location">
              <option value="rooftop" selected>Rooftop (Level 6)</option>
              <option value="floor-5">Level 5 bar</option>
              <option value="floor-1">Ground-floor bar</option>
              <option value="room">My room</option>
            </select>
          </div>
          <div class="roomnum">
            <label class="lbl" for="room_number">Room number</label>
            <input type="text" id="room_number" name="room_number" maxlength="6" placeholder="e.g. 301">
          </div>
        </div>
        <div style="margin-top:12px;">
          <label class="lbl" for="notes">Notes for the bar (optional)</label>
          <textarea id="notes" name="notes" maxlength="500" placeholder="Ice with the gin, please. Or — table in the corner by the stairs."></textarea>
        </div>
      </div>

      <button type="submit" class="btn block" id="submitBtn" disabled>Place order</button>
      <p class="muted" style="text-align:center;margin-top:10px;font-size:12px;">
        Pay at the bar when you're done. Open tab rolls up here until it's settled.
      </p>
    </form>

    <?php if ($past): ?>
      <div class="card" style="margin-top: 18px;">
        <details>
          <summary>Past visits (<?= count($past) ?>)</summary>
          <?php foreach ($past as $o): ?>
            <div class="history-row">
              <div>
                <div><?= count($o["items"]) ?> item<?= count($o["items"]) === 1 ? "" : "s" ?> · <?= h(knk_location_label($o["location"], $o["room_number"] ?? null)) ?></div>
                <div class="date"><?= h(date("D j M Y", $o["created_at"])) ?></div>
              </div>
              <div style="text-align:right;">
                <div><?= h(knk_vnd($o["total_vnd"])) ?></div>
                <span class="status <?= h($o["status"]) ?>"><?= h($o["status"]) ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </details>
      </div>
    <?php endif; ?>

  <?php endif; ?>

</div>

<script>
  // Live total + "Place order" gate + room-number show/hide
  (function () {
    const fmt = (n) => new Intl.NumberFormat('en-US').format(n) + 'đ';
    const qtyInputs = document.querySelectorAll('.qty-input');
    const subEl  = document.getElementById('subtotal');
    const vatEl  = document.getElementById('vat');
    const totEl  = document.getElementById('total');
    const btn    = document.getElementById('submitBtn');
    const loc    = document.getElementById('location');
    const locRow = document.getElementById('locRow');

    function recalc() {
      let sub = 0;
      qtyInputs.forEach(el => {
        const q = Math.max(0, parseInt(el.value || '0', 10));
        const p = parseInt(el.dataset.price || '0', 10);
        sub += q * p;
      });
      const vat = Math.round(sub * 0.10);
      const tot = sub + vat;
      if (subEl) subEl.textContent = fmt(sub);
      if (vatEl) vatEl.textContent = fmt(vat);
      if (totEl) totEl.textContent = fmt(tot);
      if (btn)   btn.disabled = sub === 0;
    }
    qtyInputs.forEach(el => el.addEventListener('input', recalc));

    function locChange() {
      if (!loc || !locRow) return;
      if (loc.value === 'room') locRow.classList.add('show-room');
      else                      locRow.classList.remove('show-room');
    }
    if (loc) loc.addEventListener('change', locChange);
    locChange();
    recalc();
  })();
</script>

</body>
</html>

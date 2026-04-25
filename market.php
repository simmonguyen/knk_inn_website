<?php
/*
 * KnK Inn — /market.php  (Beer Stock Market "Big Board")
 *
 * Public kiosk page. Aimed at the ground-floor Sony TV running a
 * Chrome window in full-screen. No auth. Read-only. Polls
 * /api/market_state.php every few seconds (poll_seconds comes
 * from the API response, default 5s when the market is live).
 *
 * Theme is a cheeky trading-desk feel — black background, green
 * (up) / red (down) numbers, chunky Archivo Black headings. We
 * never show credit-card style prices; VND only.
 *
 * We render the first frame server-side so the board shows the
 * current state the moment the page loads (no 5-second flash of
 * blank screen while JS warms up). Subsequent frames come from
 * the JSON endpoint.
 *
 * If the market's kill-switch is off, the page renders a quiet
 * "market closed" card — no prices. This is deliberately friendly,
 * not broken, because Simmo might toggle the market off mid-night.
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/market_engine.php";
require_once __DIR__ . "/includes/orders_store.php"; // knk_vnd()

$enabled = knk_market_enabled();
$cfg     = knk_market_config();
$poll    = $enabled ? max(2, (int)$cfg["board_poll_seconds"]) : 60;
$band    = knk_market_band_active();

$initial = ["items" => [], "any_crash" => false, "crash_names" => []];
if ($enabled) {
    $board = knk_market_board_items();
    foreach ($board as $row) {
        $code  = (string)$row["item_code"];
        $q     = knk_market_quote($code);
        $base  = (int)$q["base_price_vnd"];
        $price = (int)$q["price_vnd"];
        $pct   = $base > 0 ? (int)round(($price - $base) * 100 / $base) : 0;
        $initial["items"][] = [
            "item_code"      => $code,
            "name"           => (string)$row["name"],
            "category"       => (string)($row["category"] ?? ""),
            "price_vnd"      => $price,
            "base_price_vnd" => $base,
            "pct_vs_base"    => $pct,
            "trend"          => knk_market_trend($code, 900),
            "in_crash"       => (bool)$q["in_crash"],
            "pin_slot"       => $row["pin_slot"] ?? null,
            "sparkline"      => knk_market_sparkline($code, 24),
        ];
        if ($q["in_crash"]) {
            $initial["any_crash"]     = true;
            $initial["crash_names"][] = (string)$row["name"];
        }
    }
}

$bandLabel = knk_market_band_label($band["band"]);
$bandMult  = (int)$band["mult_bp"];

// Helper for PHP-side initial render.
function knk_board_arrow(string $t): string {
    if ($t === "up")   return "&#x25B2;";
    if ($t === "down") return "&#x25BC;";
    return "&ndash;";
}
function knk_board_trend_class(string $t): string {
    if ($t === "up")   return "up";
    if ($t === "down") return "down";
    return "flat";
}
?><!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>KnK Inn — Beer Stock Market</title>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@400;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<style>
  :root {
    --bg:          #050708;
    --panel:       #0c1013;
    --panel-alt:   #0f1519;
    --line:        #1c2329;
    --text:        #eef4f1;
    --muted:       #7a8a8f;
    --gold:        #c9aa71;
    --up:          #2fdc7a;
    --up-dim:      rgba(47,220,122,0.12);
    --down:        #ff4d5b;
    --down-dim:    rgba(255,77,91,0.12);
    --flat:        #a0adb3;
    --crash:       #ff2330;
    --crash-bg:    rgba(255,35,48,0.18);
    --pin-beer:    #ffd24c;
    --pin-owner:   #ff9b3b;
  }
  * { box-sizing: border-box; }
  html, body {
    margin: 0; padding: 0;
    background: var(--bg);
    color: var(--text);
    font-family: Inter, system-ui, sans-serif;
    min-height: 100vh;
    overflow-x: hidden;
  }
  .board {
    padding: 22px 28px;
    max-width: 1800px;
    margin: 0 auto;
  }

  /* ---- header strip ---- */
  .ticker-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    border-bottom: 1px solid var(--line);
    padding-bottom: 14px;
    margin-bottom: 14px;
  }
  .brand {
    display: flex; align-items: baseline; gap: 14px;
  }
  .brand .logo {
    font-family: "Archivo Black", sans-serif;
    font-size: 44px;
    letter-spacing: 0.02em;
    color: var(--gold);
    line-height: 1;
  }
  .brand .sub {
    font-family: "Archivo Black", sans-serif;
    font-size: 22px;
    color: var(--text);
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }
  .brand .dot {
    width: 12px; height: 12px; border-radius: 50%;
    background: var(--up);
    box-shadow: 0 0 12px var(--up);
    display: inline-block;
    animation: pulse 1.8s ease-in-out infinite;
    margin-right: 6px;
  }
  .brand .dot.closed { background: var(--muted); box-shadow: none; animation: none; }
  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50%      { opacity: 0.35; }
  }
  .meta {
    display: flex; gap: 14px; align-items: center;
    font-family: "JetBrains Mono", monospace;
    font-size: 15px;
  }
  .chip {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 14px;
    border: 1px solid var(--line);
    border-radius: 999px;
    background: var(--panel);
    color: var(--muted);
    letter-spacing: 0.1em;
    text-transform: uppercase;
    font-size: 12px;
    font-weight: 700;
  }
  .chip b { color: var(--text); font-weight: 800; }
  .chip.band-happy   b { color: var(--up); }
  .chip.band-peak    b { color: var(--pin-owner); }
  .chip.band-default b { color: var(--flat); }
  .chip.clock b { color: var(--gold); }

  /* ---- crash banner ---- */
  .crash-banner {
    margin: 0 0 14px;
    padding: 14px 18px;
    background: var(--crash-bg);
    border: 1px solid var(--crash);
    border-radius: 8px;
    color: #ffe8ea;
    display: none;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    font-weight: 700;
    animation: crashPulse 1.2s ease-in-out infinite;
  }
  .crash-banner.on { display: flex; }
  .crash-banner .label {
    font-family: "Archivo Black", sans-serif;
    font-size: 22px;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--crash);
  }
  .crash-banner .names { color: #ffc4c8; font-size: 16px; }
  @keyframes crashPulse {
    0%, 100% { box-shadow: 0 0 0 rgba(255,35,48,0); }
    50%      { box-shadow: 0 0 24px rgba(255,35,48,0.4); }
  }

  /* ---- grid ---- */
  .grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
  }
  @media (max-width: 1280px) { .grid { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 820px)  { .grid { grid-template-columns: 1fr; } }

  .card {
    position: relative;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 16px 18px;
    display: grid;
    grid-template-columns: 1fr 140px;
    gap: 10px 18px;
    align-items: center;
    min-height: 116px;
    transition: background 0.35s ease;
  }
  .card.flash-up   { background: var(--up-dim); }
  .card.flash-down { background: var(--down-dim); }
  .card.in-crash {
    border-color: var(--crash);
    background: var(--crash-bg);
  }
  .card.pin-beer  { border-left: 4px solid var(--pin-beer); }
  .card.pin-owner { border-left: 4px solid var(--pin-owner); }

  .card .pin-badge {
    position: absolute;
    top: -8px; left: 14px;
    font-size: 10px;
    letter-spacing: 0.2em;
    font-weight: 800;
    text-transform: uppercase;
    padding: 2px 8px;
    border-radius: 4px;
    background: var(--panel-alt);
    color: var(--gold);
    border: 1px solid var(--line);
  }
  .card.pin-beer  .pin-badge { color: var(--pin-beer); border-color: var(--pin-beer); }
  .card.pin-owner .pin-badge { color: var(--pin-owner); border-color: var(--pin-owner); }
  .card .crash-tag {
    position: absolute;
    top: -8px; right: 14px;
    font-size: 10px;
    letter-spacing: 0.2em;
    font-weight: 800;
    text-transform: uppercase;
    padding: 2px 8px;
    border-radius: 4px;
    background: var(--crash);
    color: #fff;
    display: none;
  }
  .card.in-crash .crash-tag { display: inline-block; }

  .card .name {
    font-family: "Archivo Black", sans-serif;
    font-size: 22px;
    letter-spacing: 0.02em;
    line-height: 1.05;
    color: var(--text);
  }
  .card .category {
    color: var(--muted);
    font-size: 12px;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    margin-bottom: 4px;
  }
  .card .price-wrap { text-align: right; }
  .card .price {
    font-family: "JetBrains Mono", monospace;
    font-size: 30px;
    font-weight: 700;
    letter-spacing: 0.01em;
    color: var(--text);
    line-height: 1;
  }
  .card.trend-up   .price { color: var(--up); }
  .card.trend-down .price { color: var(--down); }
  .card .pct {
    margin-top: 6px;
    font-family: "JetBrains Mono", monospace;
    font-size: 15px;
    font-weight: 700;
    color: var(--muted);
  }
  .card.trend-up   .pct { color: var(--up); }
  .card.trend-down .pct { color: var(--down); }
  .card.trend-up   .pct::before   { content: "\25B2 "; }
  .card.trend-down .pct::before   { content: "\25BC "; }
  .card.trend-flat .pct::before   { content: "\2013 "; }

  .card .spark {
    grid-column: 1 / -1;
    height: 42px;
    width: 100%;
    margin-top: 4px;
  }
  .spark svg { width: 100%; height: 100%; display: block; }

  /* ---- footer ---- */
  .ticker-bottom {
    margin-top: 22px;
    border-top: 1px solid var(--line);
    padding-top: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: var(--muted);
    font-size: 13px;
    letter-spacing: 0.05em;
  }
  .ticker-bottom .left { display: flex; gap: 18px; }
  .ticker-bottom b { color: var(--text); font-weight: 700; }

  /* ---- empty / closed state ---- */
  .closed {
    text-align: center;
    padding: 90px 20px 60px;
    color: var(--muted);
  }
  .closed h2 {
    font-family: "Archivo Black", sans-serif;
    font-size: 40px;
    color: var(--gold);
    letter-spacing: 0.06em;
    margin: 0 0 12px;
  }
  .closed p { max-width: 600px; margin: 0 auto; font-size: 18px; line-height: 1.5; }
</style>
</head>
<body>

<div class="board">

  <div class="ticker-top">
    <div class="brand">
      <span class="logo">KnK</span>
      <span class="sub"><span class="dot<?= $enabled ? "" : " closed" ?>"></span>Beer Stock Market</span>
    </div>
    <div class="meta">
      <div class="chip band-<?= htmlspecialchars($band["band"]) ?>" id="band-chip">
        Band <b><?= htmlspecialchars($bandLabel) ?></b>
        <span style="color:var(--muted);">x<?= number_format($bandMult / 100, 2) ?></span>
      </div>
      <div class="chip clock" id="clock-chip">
        Saigon <b id="clock-time">--:--:--</b>
      </div>
    </div>
  </div>

  <div class="crash-banner<?= $initial["any_crash"] ? " on" : "" ?>" id="crash-banner">
    <span class="label">Flash Crash</span>
    <span class="names" id="crash-names"><?= htmlspecialchars(implode(", ", $initial["crash_names"])) ?></span>
  </div>

  <div id="board-body">
  <?php if (!$enabled): ?>
    <div class="closed">
      <h2>Market Closed</h2>
      <p>Drink prices are on the regular menu tonight. Ask the bartender, or pop down to <b>Level 5</b> or the <b>Rooftop</b> for a top-up. Cheers.</p>
    </div>
  <?php elseif (empty($initial["items"])): ?>
    <div class="closed">
      <h2>Warming Up</h2>
      <p>Not enough orders yet to fire up the board. Order a few drinks and the market wakes up.</p>
    </div>
  <?php else: ?>
    <div class="grid" id="grid">
      <?php foreach ($initial["items"] as $it): ?>
        <?php
          $code = (string)$it["item_code"];
          $tc   = knk_board_trend_class((string)$it["trend"]);
          $pin  = $it["pin_slot"];
          $classes = "card trend-" . $tc;
          if ($pin === "beer")  $classes .= " pin-beer";
          if ($pin === "owner") $classes .= " pin-owner";
          if (!empty($it["in_crash"])) $classes .= " in-crash";
          $pctLabel = ($it["pct_vs_base"] > 0 ? "+" : "") . $it["pct_vs_base"] . "% vs base";
        ?>
        <div class="<?= $classes ?>" data-code="<?= htmlspecialchars($code) ?>" data-price="<?= (int)$it["price_vnd"] ?>">
          <?php if ($pin === "beer"): ?><span class="pin-badge">House Beer</span><?php endif; ?>
          <?php if ($pin === "owner"): ?><span class="pin-badge">Owner's Pick</span><?php endif; ?>
          <span class="crash-tag">Crashing</span>
          <div>
            <div class="category"><?= htmlspecialchars((string)$it["category"]) ?></div>
            <div class="name"><?= htmlspecialchars((string)$it["name"]) ?></div>
          </div>
          <div class="price-wrap">
            <div class="price"><?= knk_vnd((int)$it["price_vnd"]) ?></div>
            <div class="pct"><?= htmlspecialchars($pctLabel) ?></div>
          </div>
          <div class="spark" data-spark='<?= htmlspecialchars(json_encode($it["sparkline"])) ?>'></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  </div>

  <div class="ticker-bottom">
    <div class="left">
      <span>Order at <b>/order.php</b> or the QR on your table</span>
      <span>Prices live &middot; VAT included</span>
    </div>
    <div class="right">
      Last update <b id="last-update">just now</b>
      &middot; Refresh every <b id="poll-sec"><?= (int)$poll ?></b>s
    </div>
  </div>

</div>

<script>
(function () {
  "use strict";

  var POLL_SEC = <?= (int)$poll ?>;
  var POLL_URL = "/api/market_state.php";

  // ---- VND formatter (match PHP knk_vnd) ----
  function vnd(n) {
    n = Math.max(0, Math.round(Number(n) || 0));
    return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",") + "\u0111";
  }

  // ---- clock ----
  function updateClock() {
    // Server runs +07:00 so use local fmt with wall clock from JS
    // (the TV browser should be set to Saigon time; even if not the
    //  clock is only for vibes, not accounting).
    var d = new Date();
    var hh = String(d.getHours()).padStart(2, "0");
    var mm = String(d.getMinutes()).padStart(2, "0");
    var ss = String(d.getSeconds()).padStart(2, "0");
    var el = document.getElementById("clock-time");
    if (el) el.textContent = hh + ":" + mm + ":" + ss;
  }
  updateClock();
  setInterval(updateClock, 1000);

  // ---- sparkline renderer ----
  // Draw a simple smoothed polyline into each .spark element.
  function renderSpark(el, points, trend) {
    if (!el) return;
    if (!points || points.length < 2) {
      el.innerHTML = "";
      return;
    }
    var color = trend === "up" ? "#2fdc7a"
              : trend === "down" ? "#ff4d5b"
              : "#7a8a8f";
    var w = el.clientWidth || 260;
    var h = el.clientHeight || 42;
    var min = Math.min.apply(null, points);
    var max = Math.max.apply(null, points);
    var range = Math.max(1, max - min);
    var stepX = w / Math.max(1, points.length - 1);
    var path = "";
    for (var i = 0; i < points.length; i++) {
      var x = i * stepX;
      var y = h - ((points[i] - min) / range) * (h - 6) - 3;
      path += (i === 0 ? "M" : "L") + x.toFixed(1) + " " + y.toFixed(1) + " ";
    }
    var fillPath = path + "L" + w + " " + h + " L0 " + h + " Z";
    el.innerHTML =
      '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none">' +
      '<path d="' + fillPath + '" fill="' + color + '" fill-opacity="0.12"/>' +
      '<path d="' + path + '" fill="none" stroke="' + color + '" stroke-width="2" stroke-linejoin="round"/>' +
      '</svg>';
  }

  // Draw the initial server-rendered sparklines.
  function drawAllSparks() {
    var cards = document.querySelectorAll(".card");
    for (var i = 0; i < cards.length; i++) {
      var card = cards[i];
      var sEl  = card.querySelector(".spark");
      if (!sEl) continue;
      var pts = [];
      try { pts = JSON.parse(sEl.getAttribute("data-spark") || "[]"); } catch (e) {}
      var trend = card.classList.contains("trend-up") ? "up"
                : card.classList.contains("trend-down") ? "down" : "flat";
      renderSpark(sEl, pts, trend);
    }
  }
  drawAllSparks();
  window.addEventListener("resize", drawAllSparks);

  // ---- diff + update a card ----
  function setTrendClass(card, trend) {
    card.classList.remove("trend-up", "trend-down", "trend-flat");
    card.classList.add("trend-" + (trend || "flat"));
  }

  function updateCard(card, item) {
    var oldPrice = parseInt(card.getAttribute("data-price") || "0", 10);
    var newPrice = item.price_vnd;

    card.querySelector(".price").textContent = vnd(newPrice);
    card.querySelector(".pct").textContent   =
      (item.pct_vs_base > 0 ? "+" : "") + item.pct_vs_base + "% vs base";

    setTrendClass(card, item.trend);

    card.classList.toggle("pin-beer",  item.pin_slot === "beer");
    card.classList.toggle("pin-owner", item.pin_slot === "owner");
    card.classList.toggle("in-crash",  !!item.in_crash);

    // Pin badge text
    var badge = card.querySelector(".pin-badge");
    if (item.pin_slot && !badge) {
      var b = document.createElement("span");
      b.className = "pin-badge";
      b.textContent = item.pin_slot === "beer" ? "House Beer" : "Owner's Pick";
      card.insertBefore(b, card.firstChild);
    } else if (!item.pin_slot && badge) {
      badge.parentNode.removeChild(badge);
    } else if (badge) {
      badge.textContent = item.pin_slot === "beer" ? "House Beer" : "Owner's Pick";
    }

    // Flash the card if price moved.
    if (newPrice !== oldPrice) {
      card.classList.remove("flash-up", "flash-down");
      void card.offsetWidth; // restart CSS transition
      card.classList.add(newPrice > oldPrice ? "flash-up" : "flash-down");
      setTimeout(function () {
        card.classList.remove("flash-up", "flash-down");
      }, 900);
      card.setAttribute("data-price", String(newPrice));
    }

    // Spark
    var sEl = card.querySelector(".spark");
    if (sEl) {
      sEl.setAttribute("data-spark", JSON.stringify(item.sparkline || []));
      renderSpark(sEl, item.sparkline || [], item.trend);
    }
  }

  function makeCard(item) {
    var card = document.createElement("div");
    card.className = "card trend-" + (item.trend || "flat")
      + (item.pin_slot === "beer"  ? " pin-beer"  : "")
      + (item.pin_slot === "owner" ? " pin-owner" : "")
      + (item.in_crash ? " in-crash" : "");
    card.setAttribute("data-code", item.item_code);
    card.setAttribute("data-price", String(item.price_vnd));

    var pinBadge = "";
    if (item.pin_slot === "beer")  pinBadge = '<span class="pin-badge">House Beer</span>';
    if (item.pin_slot === "owner") pinBadge = '<span class="pin-badge">Owner\'s Pick</span>';

    card.innerHTML =
      pinBadge +
      '<span class="crash-tag">Crashing</span>' +
      '<div>' +
        '<div class="category">' + escapeHtml(item.category || "") + '</div>' +
        '<div class="name">' + escapeHtml(item.name || "") + '</div>' +
      '</div>' +
      '<div class="price-wrap">' +
        '<div class="price">' + vnd(item.price_vnd) + '</div>' +
        '<div class="pct">' + ((item.pct_vs_base > 0 ? "+" : "") + item.pct_vs_base + "% vs base") + '</div>' +
      '</div>' +
      '<div class="spark" data-spark=\'' + JSON.stringify(item.sparkline || []) + '\'></div>';
    return card;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;" }[c];
    });
  }

  // ---- main poll ----
  function applyState(state) {
    // Poll cadence can change on the fly (kill switch flipped, etc.)
    if (state && state.poll_seconds) {
      POLL_SEC = Math.max(2, parseInt(state.poll_seconds, 10) || POLL_SEC);
      var ps = document.getElementById("poll-sec");
      if (ps) ps.textContent = String(POLL_SEC);
    }

    // Band chip
    if (state && state.band) {
      var chip = document.getElementById("band-chip");
      if (chip) {
        chip.classList.remove("band-happy", "band-peak", "band-default");
        chip.classList.add("band-" + state.band.name);
        chip.innerHTML = 'Band <b>' + escapeHtml(state.band.label) + '</b> '
          + '<span style="color:var(--muted);">x'
          + (state.band.multiplier_bp / 100).toFixed(2) + '</span>';
      }
    }

    // Crash banner
    var cb = document.getElementById("crash-banner");
    var cn = document.getElementById("crash-names");
    if (cb && cn) {
      if (state && state.any_crash && state.crash_names && state.crash_names.length) {
        cn.textContent = state.crash_names.join(", ");
        cb.classList.add("on");
      } else {
        cb.classList.remove("on");
      }
    }

    // Full body swap for closed / warming-up / live.
    var body = document.getElementById("board-body");
    if (!body) return;

    if (!state || !state.enabled) {
      body.innerHTML =
        '<div class="closed"><h2>Market Closed</h2>' +
        '<p>Drink prices are on the regular menu tonight. Ask the bartender, or pop down to ' +
        '<b>Level 5</b> or the <b>Rooftop</b> for a top-up. Cheers.</p></div>';
      var dot = document.querySelector(".brand .dot");
      if (dot) dot.classList.add("closed");
      return;
    }
    var dot2 = document.querySelector(".brand .dot");
    if (dot2) dot2.classList.remove("closed");

    if (!state.items || !state.items.length) {
      body.innerHTML =
        '<div class="closed"><h2>Warming Up</h2>' +
        '<p>Not enough orders yet to fire up the board. Order a few drinks and the market wakes up.</p></div>';
      return;
    }

    // Ensure grid container exists.
    var grid = body.querySelector("#grid");
    if (!grid) {
      body.innerHTML = '<div class="grid" id="grid"></div>';
      grid = body.querySelector("#grid");
    }

    // Reconcile: for each item in state.items, update or create. Then drop stragglers.
    var seen = {};
    for (var i = 0; i < state.items.length; i++) {
      var it = state.items[i];
      seen[it.item_code] = true;
      var existing = grid.querySelector('.card[data-code="' + cssEscape(it.item_code) + '"]');
      if (existing) {
        // Make sure DOM order matches API order.
        if (grid.children[i] !== existing) {
          grid.insertBefore(existing, grid.children[i] || null);
        }
        updateCard(existing, it);
      } else {
        var el = makeCard(it);
        grid.insertBefore(el, grid.children[i] || null);
        renderSpark(el.querySelector(".spark"), it.sparkline || [], it.trend);
      }
    }
    // Remove cards no longer in state.
    var cards = grid.querySelectorAll(".card");
    for (var j = cards.length - 1; j >= 0; j--) {
      var code = cards[j].getAttribute("data-code");
      if (!seen[code]) cards[j].parentNode.removeChild(cards[j]);
    }

    var lu = document.getElementById("last-update");
    if (lu) lu.textContent = new Date().toLocaleTimeString("en-GB", { hour12: false });
  }

  function cssEscape(s) {
    // Minimal escape for attribute selector. Our codes are slugs.
    return String(s).replace(/[^a-zA-Z0-9_-]/g, "\\$&");
  }

  var consecutiveErrors = 0;
  function poll() {
    fetch(POLL_URL + "?_=" + Date.now(), { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        consecutiveErrors = 0;
        applyState(j);
      })
      .catch(function () {
        consecutiveErrors++;
        // Leave last good state up; don't blank the board on a blip.
      })
      .then(function () {
        // Back off a bit if we keep failing, but never slower than 30s.
        var next = POLL_SEC * 1000;
        if (consecutiveErrors > 2) next = Math.min(30000, POLL_SEC * 1000 * consecutiveErrors);
        setTimeout(poll, next);
      });
  }
  // Let the first server-rendered frame breathe for one cycle before
  // the first poll.
  setTimeout(poll, POLL_SEC * 1000);
})();
</script>

</body>
</html>

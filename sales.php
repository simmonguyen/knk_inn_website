<?php
/* =========================================================
   KnK Inn — Sales dashboard (V2 Phase 5)
   https://knkinn.com/sales.php
   Role-gated (super_admin, owner only).

   Three tabs (pick via ?tab=...):
     daily    — Last 30 days, room + drinks revenue per day (stacked bars)
     mix      — Drinks vs rooms share, last 30 days and all time
     weekday  — Drink orders counted by day of week (all time)
   ========================================================= */

declare(strict_types=1);

require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/sales_store.php";

$me = knk_require_permission("sales");

/* ---------- Tab routing ---------- */
$tab = (string)($_GET["tab"] ?? "daily");
if (!in_array($tab, ["daily", "mix", "weekday"], true)) $tab = "daily";

/* ---------- Data ---------- */
$today_ymd = date("Y-m-d");

$daily30  = [];
$kpi_30d  = ["room" => 0, "drinks" => 0, "combined" => 0, "drink_orders" => 0, "room_nights" => 0];
$kpi_all  = ["room" => 0, "drinks" => 0, "combined" => 0, "drink_orders" => 0, "room_nights" => 0];
$by_wday  = [];

if ($tab === "daily") {
    $daily30 = knk_sales_daily_totals(30);
    $kpi_30d = knk_sales_period_totals(30);
} elseif ($tab === "mix") {
    $kpi_30d = knk_sales_period_totals(30);
    $kpi_all = knk_sales_period_totals(0);
} else { // weekday
    $by_wday = knk_sales_orders_by_weekday();
}

/* ---------- Helpers ---------- */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function vnd(int $n): string { return number_format($n, 0, ".", ",") . " ₫"; }

/** Compact VND — turns 1,230,000 ₫ into "1.23M ₫" for small KPI tiles. */
function vnd_short(int $n): string {
    if ($n >= 1_000_000) return number_format($n / 1_000_000, 2) . "M ₫";
    if ($n >= 1_000)     return number_format($n / 1_000,     1) . "k ₫";
    return $n . " ₫";
}

function pct(int $part, int $whole): string {
    if ($whole <= 0) return "0%";
    return round($part * 100 / $whole) . "%";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>KnK Inn — Sales</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Caveat:wght@700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/styles.css?v=12">
  <style>
    body { padding: 2rem 1rem 4rem; }
    .wrap { max-width: 1100px; margin: 0 auto; }

    header.bar {
      display: flex; align-items: center; justify-content: space-between;
      gap: 1rem; flex-wrap: wrap; margin-bottom: 1.4rem;
    }
    header.bar .title { flex: 1; min-width: 240px; }
    header.bar .actions a.btn-mini {
      color: var(--cream-dim); font-size: 0.72rem; letter-spacing: 0.18em;
      text-transform: uppercase; text-decoration: none; padding: 0.55rem 1rem;
      border: 1px solid rgba(201,170,113,0.3); border-radius: 3px;
    }
    header.bar .actions a.btn-mini:hover { border-color: var(--gold); color: var(--gold); }

    /* ---------- Tab row ---------- */
    .tab-row {
      display: flex; gap: 0.4rem;
      border-bottom: 1px solid rgba(201,170,113,0.25);
      margin: 0 0 1.5rem 0;
    }
    .tab-row a {
      color: var(--cream-dim); text-decoration: none;
      padding: 0.55rem 1.1rem;
      border-radius: 6px 6px 0 0; font-weight: 600; font-size: 0.92rem;
      border: 1px solid transparent; border-bottom: none;
      background: rgba(255,255,255,0.02);
    }
    .tab-row a:hover { color: var(--cream); background: rgba(201,170,113,0.08); }
    .tab-row a.is-active {
      color: var(--brown-deep, #2a1a08); background: var(--gold, #c9aa71);
      border-color: var(--gold, #c9aa71);
    }

    /* ---------- Panel / card patterns (match bookings.php) ---------- */
    section.panel {
      margin-bottom: 2rem;
      background: rgba(24,12,3,0.4);
      border: 1px solid rgba(201,170,113,0.18);
      border-radius: 6px;
      padding: 1.5rem 1.4rem 1.2rem;
    }
    section.panel > h2 {
      font-size: 0.78rem; letter-spacing: 0.22em; text-transform: uppercase;
      color: var(--gold); margin: 0 0 1rem 0; font-weight: 700;
    }
    section.panel > h2 .sub {
      color: var(--cream-faint); font-weight: 400; letter-spacing: 0.12em; margin-left: 0.6rem;
    }
    .note {
      color: var(--cream-faint); font-size: 0.78rem; margin: 0.7rem 0 0;
      line-height: 1.5;
    }

    /* ---------- KPI tiles ---------- */
    .kpis {
      display: grid; gap: 0.8rem;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      margin-bottom: 0.6rem;
    }
    .kpi {
      background: rgba(0,0,0,0.25); border: 1px solid rgba(201,170,113,0.18);
      border-radius: 5px; padding: 0.9rem 1rem;
    }
    .kpi .l { font-size: 0.7rem; letter-spacing: 0.14em; text-transform: uppercase; color: var(--cream-faint); }
    .kpi .n { font-family: "Archivo Black", sans-serif; color: var(--gold); font-size: 1.4rem; margin-top: 0.25rem; }
    .kpi .s { font-size: 0.72rem; color: var(--cream-faint); margin-top: 0.15rem; }

    /* ---------- Stacked bar chart (Tab 1) ---------- */
    .chart {
      margin-top: 1.4rem;
      background: rgba(0,0,0,0.2); border: 1px solid rgba(201,170,113,0.15);
      border-radius: 4px; padding: 1rem 1rem 0.5rem;
    }
    .chart-hd {
      display: flex; justify-content: space-between; align-items: baseline;
      gap: 1rem; flex-wrap: wrap; margin-bottom: 0.9rem;
    }
    .chart-hd .cap {
      font-size: 0.72rem; letter-spacing: 0.16em; text-transform: uppercase;
      color: var(--cream-faint);
    }
    .chart-hd .legend { display: flex; gap: 1rem; font-size: 0.78rem; color: var(--cream-dim); }
    .chart-hd .legend .sw { display: inline-block; width: 10px; height: 10px; border-radius: 2px; vertical-align: middle; margin-right: 5px; }
    .chart-hd .legend .sw.room   { background: var(--gold, #c9aa71); }
    .chart-hd .legend .sw.drinks { background: #8a6e3e; }

    .bars {
      display: grid; grid-template-columns: repeat(30, 1fr);
      gap: 3px; align-items: end;
      height: 220px; padding: 0 2px;
    }
    .bar {
      position: relative; display: flex; flex-direction: column; justify-content: flex-end;
      height: 100%; min-height: 2px;
    }
    .bar .seg { width: 100%; min-height: 1px; }
    .bar .seg.drinks { background: #8a6e3e; }
    .bar .seg.room   { background: var(--gold, #c9aa71); }
    .bar.today { outline: 1px solid rgba(255,255,255,0.35); outline-offset: 1px; }
    .bar.empty::after {
      content: ""; position: absolute; left: 0; right: 0; bottom: 0;
      height: 2px; background: rgba(255,255,255,0.08);
    }

    .xaxis {
      display: grid; grid-template-columns: repeat(30, 1fr);
      gap: 3px; padding: 0.45rem 2px 0.2rem; font-size: 0.62rem;
      color: var(--cream-faint); letter-spacing: 0.04em;
      text-align: center;
    }
    .xaxis .tick { overflow: hidden; white-space: nowrap; }
    .xaxis .tick.hide { visibility: hidden; }

    /* ---------- Mix cards (Tab 2) ---------- */
    .mix-grid {
      display: grid; gap: 1.2rem;
      grid-template-columns: 1fr;
    }
    @media (min-width: 780px) { .mix-grid { grid-template-columns: 1fr 1fr; } }
    .mix-card {
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(201,170,113,0.2);
      border-radius: 6px; padding: 1.3rem 1.3rem 1.1rem;
    }
    .mix-card h3 {
      margin: 0 0 0.4rem 0; font-size: 0.78rem;
      letter-spacing: 0.18em; text-transform: uppercase;
      color: var(--cream-faint); font-weight: 600;
    }
    .mix-card .total {
      font-family: "Archivo Black", sans-serif; color: var(--gold);
      font-size: 1.8rem; margin: 0.3rem 0 1rem;
    }
    .mix-split {
      display: flex; height: 14px; border-radius: 3px; overflow: hidden;
      background: rgba(0,0,0,0.3); margin-bottom: 0.6rem;
    }
    .mix-split .seg { height: 100%; }
    .mix-split .seg.room   { background: var(--gold, #c9aa71); }
    .mix-split .seg.drinks { background: #8a6e3e; }
    .mix-rows { display: grid; grid-template-columns: auto 1fr auto; gap: 0.3rem 0.8rem; font-size: 0.9rem; }
    .mix-rows .label { color: var(--cream-dim); }
    .mix-rows .label .sw { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin-right: 6px; vertical-align: middle; }
    .mix-rows .label .sw.room   { background: var(--gold, #c9aa71); }
    .mix-rows .label .sw.drinks { background: #8a6e3e; }
    .mix-rows .val  { color: var(--cream); text-align: right; font-variant-numeric: tabular-nums; }
    .mix-rows .share { color: var(--cream-faint); text-align: right; font-size: 0.82rem; }

    /* ---------- Weekday bars (Tab 3) ---------- */
    .wday-wrap {
      background: rgba(0,0,0,0.2); border: 1px solid rgba(201,170,113,0.15);
      border-radius: 4px; padding: 1.1rem 1rem 0.6rem;
    }
    .wday-bars {
      display: grid; grid-template-columns: repeat(7, 1fr);
      gap: 10px; align-items: end; height: 180px; padding: 0 2px;
    }
    .wday-bars .col {
      display: flex; flex-direction: column; justify-content: flex-end; height: 100%;
      position: relative;
    }
    .wday-bars .col .fill {
      background: var(--gold, #c9aa71); border-radius: 2px 2px 0 0; min-height: 2px;
      width: 100%;
    }
    .wday-bars .col.top .fill { background: var(--gold-light, #d8c08b); box-shadow: 0 0 0 1px rgba(255,255,255,0.2); }
    .wday-bars .col .num {
      position: absolute; top: -1.1rem; left: 0; right: 0; text-align: center;
      font-size: 0.72rem; color: var(--cream); font-weight: 600;
    }
    .wday-labels {
      display: grid; grid-template-columns: repeat(7, 1fr);
      gap: 10px; padding: 0.5rem 2px 0;
      font-size: 0.72rem; letter-spacing: 0.14em;
      text-transform: uppercase; color: var(--cream-faint); text-align: center;
    }
    .wday-summary {
      margin-top: 1rem; display: flex; gap: 1.5rem; flex-wrap: wrap;
      font-size: 0.88rem; color: var(--cream-dim);
    }
    .wday-summary strong { color: var(--gold); }

    .empty { color: var(--cream-faint); font-size: 0.9rem; padding: 0.8rem 0; }
  </style>
</head>
<body>
<?php knk_render_admin_nav($me); ?>
<div class="wrap">

  <header class="bar">
    <div class="title">
      <span class="eyebrow">Staff only</span>
      <h1 class="display-md">Sales <em>admin</em></h1>
    </div>
    <div class="actions">
      <a class="btn-mini" href="index.php" target="_blank">View site</a>
    </div>
  </header>

  <div class="tab-row">
    <a href="sales.php?tab=daily"    class="<?= $tab === "daily"    ? "is-active" : "" ?>">Last 30 days</a>
    <a href="sales.php?tab=mix"      class="<?= $tab === "mix"      ? "is-active" : "" ?>">Drinks vs Rooms</a>
    <a href="sales.php?tab=weekday"  class="<?= $tab === "weekday"  ? "is-active" : "" ?>">By weekday</a>
  </div>

  <?php if ($tab === "daily"): /* =============== TAB 1 =============== */ ?>

  <section class="panel">
    <h2>Last 30 days <span class="sub">up to today</span></h2>

    <div class="kpis">
      <div class="kpi">
        <div class="l">Combined</div>
        <div class="n"><?= h(vnd_short((int)$kpi_30d["combined"])) ?></div>
        <div class="s">Rooms + drinks</div>
      </div>
      <div class="kpi">
        <div class="l">Room revenue</div>
        <div class="n"><?= h(vnd_short((int)$kpi_30d["room"])) ?></div>
        <div class="s"><?= (int)$kpi_30d["room_nights"] ?> night<?= (int)$kpi_30d["room_nights"] === 1 ? "" : "s" ?> sold</div>
      </div>
      <div class="kpi">
        <div class="l">Drink revenue</div>
        <div class="n"><?= h(vnd_short((int)$kpi_30d["drinks"])) ?></div>
        <div class="s"><?= (int)$kpi_30d["drink_orders"] ?> order<?= (int)$kpi_30d["drink_orders"] === 1 ? "" : "s" ?></div>
      </div>
      <div class="kpi">
        <div class="l">Average per day</div>
        <div class="n"><?= h(vnd_short((int)round(((int)$kpi_30d["combined"]) / 30))) ?></div>
        <div class="s">over 30 days</div>
      </div>
    </div>

    <div class="chart">
      <div class="chart-hd">
        <span class="cap">Revenue per day (VND)</span>
        <span class="legend">
          <span><span class="sw room"></span>Room</span>
          <span><span class="sw drinks"></span>Drinks</span>
        </span>
      </div>

      <?php
      // Find max daily combined to scale bars.
      $max_day = 0;
      foreach ($daily30 as $v) {
          $sum = (int)$v["room"] + (int)$v["drinks"];
          if ($sum > $max_day) $max_day = $sum;
      }
      if ($max_day <= 0) $max_day = 1; // avoid div-by-0
      $dates = array_keys($daily30);
      ?>

      <div class="bars">
        <?php foreach ($daily30 as $ymd => $v):
          $room = (int)$v["room"]; $dr = (int)$v["drinks"]; $sum = $room + $dr;
          $room_pct = $sum > 0 ? ($room / $max_day) * 100 : 0;
          $dr_pct   = $sum > 0 ? ($dr   / $max_day) * 100 : 0;
          $cls = "bar" . ($sum === 0 ? " empty" : "") . ($ymd === $today_ymd ? " today" : "");
          $title = date("D j M", strtotime($ymd)) . " · " . vnd($sum);
          if ($sum > 0) $title .= " (room " . vnd($room) . ", drinks " . vnd($dr) . ")";
        ?>
          <div class="<?= $cls ?>" title="<?= h($title) ?>">
            <?php if ($dr > 0):   ?><div class="seg drinks" style="height: <?= $dr_pct ?>%;"></div><?php endif; ?>
            <?php if ($room > 0): ?><div class="seg room"   style="height: <?= $room_pct ?>%;"></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="xaxis">
        <?php foreach ($dates as $i => $ymd):
          // Label first, last, and every 5th bar (roughly weekly).
          $show = ($i === 0) || ($i === count($dates) - 1) || (($i % 5) === 0);
          $label = $show ? date("j M", strtotime($ymd)) : "";
        ?>
          <div class="tick<?= $show ? "" : " hide" ?>"><?= h($label) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
    <p class="note">
      Room revenue is spread across the nights a guest actually slept at the inn — not the booking day.
      Hover any bar to see the room + drinks split.
    </p>
  </section>

  <?php elseif ($tab === "mix"): /* =============== TAB 2 =============== */ ?>

  <section class="panel">
    <h2>Drinks vs rooms <span class="sub">share of revenue</span></h2>

    <div class="mix-grid">
      <?php
      $cards = [
          ["label" => "Last 30 days", "data" => $kpi_30d],
          ["label" => "All time",     "data" => $kpi_all],
      ];
      foreach ($cards as $c):
          $room   = (int)$c["data"]["room"];
          $drinks = (int)$c["data"]["drinks"];
          $total  = $room + $drinks;
          $room_w = $total > 0 ? round($room   * 100 / $total, 1) : 0;
          $dr_w   = $total > 0 ? round($drinks * 100 / $total, 1) : 0;
      ?>
      <div class="mix-card">
        <h3><?= h($c["label"]) ?></h3>
        <div class="total"><?= h(vnd_short($total)) ?></div>
        <div class="mix-split" title="<?= h(pct($room, $total)) ?> room · <?= h(pct($drinks, $total)) ?> drinks">
          <?php if ($room_w > 0): ?><div class="seg room"   style="width: <?= $room_w ?>%;"></div><?php endif; ?>
          <?php if ($dr_w   > 0): ?><div class="seg drinks" style="width: <?= $dr_w   ?>%;"></div><?php endif; ?>
        </div>
        <div class="mix-rows">
          <div class="label"><span class="sw room"></span>Room revenue</div>
          <div class="val"><?= h(vnd($room)) ?></div>
          <div class="share"><?= h(pct($room, $total)) ?></div>

          <div class="label"><span class="sw drinks"></span>Drinks revenue</div>
          <div class="val"><?= h(vnd($drinks)) ?></div>
          <div class="share"><?= h(pct($drinks, $total)) ?></div>

          <div class="label" style="color:var(--cream-faint);font-size:0.8rem;padding-top:0.3rem;">
            <?= (int)$c["data"]["room_nights"] ?> room-night<?= (int)$c["data"]["room_nights"] === 1 ? "" : "s" ?> ·
            <?= (int)$c["data"]["drink_orders"] ?> drink order<?= (int)$c["data"]["drink_orders"] === 1 ? "" : "s" ?>
          </div>
          <div></div><div></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="note">
      "All time" only counts nights a guest has already slept — future confirmed bookings aren't added until they happen.
    </p>
  </section>

  <?php else: /* =============== TAB 3: WEEKDAY =============== */ ?>

  <section class="panel">
    <h2>Drink orders by weekday <span class="sub">all time, cancelled orders excluded</span></h2>

    <?php
    $wday_labels = ["Mon","Tue","Wed","Thu","Fri","Sat","Sun"];
    $wday_full   = ["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"];
    $max_w = max($by_wday);
    if ($max_w <= 0) $max_w = 1;
    $total_w = array_sum($by_wday);

    // Busiest / quietest day labels
    $busy_i  = array_search(max($by_wday), $by_wday, true);
    $quiet_i = array_search(min($by_wday), $by_wday, true);
    ?>

    <?php if ($total_w === 0): ?>
      <div class="empty">No drink orders yet — the heatmap will fill in as orders come in.</div>
    <?php else: ?>
      <div class="wday-wrap">
        <div class="wday-bars">
          <?php foreach ($by_wday as $i => $count):
              $h_pct = ($count / $max_w) * 100;
              $is_top = ($count === $max_w);
              $cls = "col" . ($is_top ? " top" : "");
              $tooltip = $wday_full[$i] . ": " . $count . " order" . ($count === 1 ? "" : "s");
          ?>
            <div class="<?= $cls ?>" title="<?= h($tooltip) ?>">
              <span class="num"><?= $count ?></span>
              <div class="fill" style="height: <?= $h_pct ?>%;"></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="wday-labels">
          <?php foreach ($wday_labels as $l): ?>
            <div><?= h($l) ?></div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="wday-summary">
        <span>Busiest day: <strong><?= h($wday_full[$busy_i]) ?></strong> (<?= (int)$by_wday[$busy_i] ?> orders)</span>
        <span>Quietest day: <strong><?= h($wday_full[$quiet_i]) ?></strong> (<?= (int)$by_wday[$quiet_i] ?> orders)</span>
        <span>Total orders counted: <strong><?= (int)$total_w ?></strong></span>
      </div>
    <?php endif; ?>

    <p class="note">
      Counts every drink order by the day of the week it was placed. Useful for spotting which nights are busiest and where to put staff.
    </p>
  </section>

  <?php endif; /* end tab branches */ ?>

</div>
</body>
</html>

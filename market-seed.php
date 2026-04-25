<?php
/*
 * KnK Inn — /market-seed.php  (one-off Beer Stock Market seeder)
 *
 * Super-Admin only. Drops a small batch of fake drink orders into
 * orders.json so the Big Board has something to plot on opening
 * night before real orders kick in.
 *
 * The Stock Market needs at least eligibility_min_orders (default 5)
 * for a drink to appear on the Board. With zero opening-night orders
 * the chart is bare. This page creates ~6 orders per seeded drink,
 * spread across the past 2 hours, all marked status:paid so they
 * never sit in the bartender queue.
 *
 * Every seeded order has notes = "[seed]" so we can wipe them later
 * with the "Clear seeded orders" button.
 *
 * Drinks seeded — Ben's opening-night ten:
 *   1.  Tiger Draught         (beer.tigerDraught)
 *   2.  Tiger Crystal         (beer.tigerCrystal)
 *   3.  Long Island Iced Tea  (cocktail.longIsland)
 *   4.  Screwdriver           (cocktail.screwdriver)
 *   5.  Heineken              (beer.heineken)
 *   6.  Saigon Special        (beer.saigonSpecial)
 *   7.  Jim Beam              (bourbon.jimBeam)        — Ben said "Jim Beam White"
 *   8.  House Tequila         (tequila.house)
 *   9.  Gordon's              (gin.gordons)
 *   10. Captain Morgan        (rum.captainMorgan)
 *
 * Delete this file once Simmo's bar is generating real orders — it
 * stays gated behind super_admin and nothing links to it from the
 * regular nav, but tidy is tidy.
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/orders_store.php";
require_once __DIR__ . "/includes/market_engine.php";

/* Super-Admin only — never expose this to Owner / Reception / Bartender. */
$me = knk_require_role(["super_admin"]);
$me_id = (int)$me["id"];

/* ----- Drink list. Order matters — drives display order in the table. ----- */
$SEED_DRINKS = [
    ["code" => "beer.tigerDraught",   "label" => "Tiger Draught"],
    ["code" => "beer.tigerCrystal",   "label" => "Tiger Crystal"],
    ["code" => "cocktail.longIsland", "label" => "Long Island Iced Tea"],
    ["code" => "cocktail.screwdriver","label" => "Screwdriver"],
    ["code" => "beer.heineken",       "label" => "Heineken"],
    ["code" => "beer.saigonSpecial",  "label" => "Saigon Special"],
    ["code" => "bourbon.jimBeam",     "label" => "Jim Beam"],
    ["code" => "tequila.house",       "label" => "House Tequila"],
    ["code" => "gin.gordons",         "label" => "Gordon's"],
    ["code" => "rum.captainMorgan",   "label" => "Captain Morgan"],
];

/* Per-drink target — vary so the chart isn't a flat row of 6s. */
$SEED_QTY = [
    "beer.tigerDraught"   => 8,
    "beer.tigerCrystal"   => 7,
    "cocktail.longIsland" => 6,
    "cocktail.screwdriver"=> 5,
    "beer.heineken"       => 8,
    "beer.saigonSpecial"  => 7,
    "bourbon.jimBeam"     => 6,
    "tequila.house"       => 5,
    "gin.gordons"         => 6,
    "rum.captainMorgan"   => 6,
];

/* Locations are rotated for variety — sales reports won't lean toward
 * one zone of the building. */
$SEED_LOCATIONS = ["rooftop", "floor-5", "floor-1"];

$flash = "";
$error = "";
$action = (string)($_POST["action"] ?? "");

/* ============================================================
 * ACTION: seed
 * ========================================================== */
if ($action === "seed") {
    $menu = knk_menu_lookup();
    $now  = time();

    /* Spread orders across the past 2 hours, with a healthy chunk in
     * the last 45 minutes so the demand multiplier has fresh signal. */
    $window = 2 * 60 * 60;       // 2 hours
    $recent = 45 * 60;           // 45 minutes (matches demand_window_minutes default)

    $created = 0;
    $skipped = [];

    foreach ($SEED_DRINKS as $i => $d) {
        $code = $d["code"];
        if (!isset($menu[$code])) {
            $skipped[] = $d["label"] . " (item not in menu)";
            continue;
        }
        $price = (int)$menu[$code]["price_vnd"];
        $qty   = $SEED_QTY[$code] ?? 6;

        for ($n = 0; $n < $qty; $n++) {
            /* First 2 of each drink → in the last 45 minutes (so the
             * price multiplier moves). Remainder → spread back to 2h. */
            if ($n < 2) {
                $age = random_int(60, $recent);
            } else {
                $age = random_int($recent, $window);
            }
            $ts = $now - $age;

            $loc  = $SEED_LOCATIONS[($i + $n) % count($SEED_LOCATIONS)];
            $sub  = $price;
            $vat  = (int)round($sub * KNK_VAT_RATE);
            $tot  = $sub + $vat;

            orders_create([
                "email"        => "seed@knkinn.com",
                "location"     => $loc,
                "room_number"  => null,
                "items"        => [[
                    "id"        => $code,
                    "name"      => $menu[$code]["name"],
                    "price_vnd" => $price,
                    "qty"       => 1,
                ]],
                "subtotal_vnd" => $sub,
                "vat_vnd"      => $vat,
                "total_vnd"    => $tot,
                "notes"        => "[seed]",
                "status"       => "paid",
                "created_at"   => $ts,
                "received_at"  => $ts,
                "paid_at"      => $ts,
            ]);
            $created++;
        }
    }

    knk_audit("market.seed", "orders", null, [
        "by"      => $me_id,
        "created" => $created,
    ]);

    $flash = "Seeded {$created} sample order" . ($created === 1 ? "" : "s") . ".";
    if ($skipped) {
        $flash .= "  Skipped: " . implode(", ", $skipped) . ".";
    }
    header("Location: /market-seed.php?ok=" . rawurlencode($flash));
    exit;
}

/* ============================================================
 * ACTION: clear
 *   Wipes every order whose notes contain "[seed]". Safe to re-run.
 * ========================================================== */
if ($action === "clear") {
    [$fp, $data] = orders_open();
    $before = count($data["orders"]);
    $kept   = [];
    foreach ($data["orders"] as $o) {
        $note = (string)($o["notes"] ?? "");
        if (strpos($note, "[seed]") !== false) continue;   // drop
        $kept[] = $o;
    }
    $data["orders"] = $kept;
    orders_save($fp, $data);
    $removed = $before - count($kept);

    knk_audit("market.seed_clear", "orders", null, [
        "by"      => $me_id,
        "removed" => $removed,
    ]);

    $flash = "Removed {$removed} seeded order" . ($removed === 1 ? "" : "s") . ".";
    header("Location: /market-seed.php?ok=" . rawurlencode($flash));
    exit;
}

/* ----- Display: how many seeded orders + total per drink ----- */
$ok = (string)($_GET["ok"] ?? "");

$all   = orders_all();
$seeded_count = 0;
$counts_7d    = [];   // item_code => orders in last 7d (any source)
$seeded_per   = [];   // item_code => seeded orders only

$cutoff_7d = time() - (7 * 24 * 60 * 60);

foreach ($all as $o) {
    if (($o["status"] ?? "") === "cancelled") continue;
    $is_seed = strpos((string)($o["notes"] ?? ""), "[seed]") !== false;
    if ($is_seed) $seeded_count++;
    foreach (($o["items"] ?? []) as $it) {
        $code = (string)($it["id"] ?? "");
        if ($code === "") continue;
        $qty  = max(1, (int)($it["qty"] ?? 1));
        if (($o["created_at"] ?? 0) >= $cutoff_7d) {
            $counts_7d[$code] = ($counts_7d[$code] ?? 0) + $qty;
        }
        if ($is_seed) {
            $seeded_per[$code] = ($seeded_per[$code] ?? 0) + $qty;
        }
    }
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Market Seed · KnK Inn</title>
<style>
  body {
    margin: 0; padding: 0;
    font-family: "Inter", system-ui, sans-serif;
    background: #f5efe2; color: #2a1a0c;
  }
  .wrap { max-width: 800px; margin: 0 auto; padding: 1.25rem 1rem 3rem; }
  h1 { font-family: "Archivo Black", system-ui, sans-serif; font-size: 1.5rem; margin: 0.25rem 0 0.25rem; }
  .sub { color: #6b563b; margin-bottom: 1.5rem; }
  .crumbs a { color: #4a3a25; text-decoration: none; border-bottom: 1px dotted #8a7858; }
  .crumbs { font-size: 0.85rem; margin-bottom: 0.75rem; }
  .flash {
    background: #2e7d32; color: #fff;
    padding: 0.7rem 1rem; border-radius: 8px;
    margin-bottom: 1rem; font-weight: 600;
  }
  .card {
    background: #fff; border: 1px solid #e1d4ba;
    border-radius: 12px; padding: 1rem 1.25rem;
    margin-bottom: 1rem;
  }
  .card h2 { margin: 0 0 0.5rem; font-size: 1.1rem; }
  table { width: 100%; border-collapse: collapse; margin-top: 0.5rem; }
  th, td { padding: 0.4rem 0.5rem; text-align: left; font-size: 0.95rem; }
  th { color: #6b563b; font-weight: 600; border-bottom: 1px solid #e1d4ba; }
  td { border-bottom: 1px solid #f0e7d3; }
  td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
  td.warn { color: #b57506; font-weight: 600; }
  td.ok   { color: #2e7d32; font-weight: 600; }
  .actions { display: flex; gap: 0.75rem; margin-top: 1rem; flex-wrap: wrap; }
  button {
    font-family: inherit; font-size: 1rem; font-weight: 700;
    padding: 0.7rem 1.1rem; border-radius: 8px; border: 0;
    cursor: pointer;
    background: #2a1a0c; color: #f5e9d1;
  }
  button.danger { background: #8a1c1c; }
  button:hover { filter: brightness(1.1); }
  .muted { color: #8a7858; font-size: 0.85rem; }
  ul.tidy { margin: 0.5rem 0 0; padding-left: 1.2rem; }
  ul.tidy li { margin: 0.15rem 0; }
</style>
</head>
<body>
<div class="wrap">

  <div class="crumbs">
    <a href="/market-admin.php">← Market admin</a>
    &nbsp;·&nbsp;
    <a href="/market.php" target="_blank">Big Board ↗</a>
  </div>

  <h1>Market — sample order seeder</h1>
  <p class="sub">Drops fake drink orders into the system so the Big Board has something to plot before real orders come in. Super-Admin only.</p>

  <?php if ($ok !== ""): ?>
    <div class="flash"><?= h($ok) ?></div>
  <?php endif; ?>

  <div class="card">
    <h2>Drinks to seed</h2>
    <p class="muted">Each click of <em>Seed sample orders</em> adds the per-drink quantity below, all marked <strong>paid</strong> and timestamped across the past 2 hours.</p>
    <table>
      <thead>
        <tr>
          <th>Drink</th>
          <th class="num">Per click</th>
          <th class="num">Already seeded</th>
          <th class="num">Total in last 7d</th>
          <th>Above 5-order threshold?</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($SEED_DRINKS as $d):
          $code = $d["code"];
          $seeded = (int)($seeded_per[$code] ?? 0);
          $total  = (int)($counts_7d[$code]  ?? 0);
          $passes = $total >= 5;
        ?>
        <tr>
          <td><?= h($d["label"]) ?> <span class="muted">(<?= h($code) ?>)</span></td>
          <td class="num"><?= (int)($SEED_QTY[$code] ?? 6) ?></td>
          <td class="num"><?= $seeded ?></td>
          <td class="num"><?= $total ?></td>
          <td class="<?= $passes ? "ok" : "warn" ?>"><?= $passes ? "Yes — on Board" : "No — needs more" ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="actions">
      <form method="post" style="margin:0;">
        <input type="hidden" name="action" value="seed">
        <button type="submit">Seed sample orders</button>
      </form>
      <form method="post" style="margin:0;" onsubmit="return confirm('Remove every order tagged [seed]? This cannot be undone.');">
        <input type="hidden" name="action" value="clear">
        <button type="submit" class="danger">Clear seeded orders (<?= (int)$seeded_count ?>)</button>
      </form>
    </div>
  </div>

  <div class="card">
    <h2>How it works</h2>
    <ul class="tidy">
      <li>Every seeded order is stamped <code>notes = "[seed]"</code> — that's how the Clear button identifies them.</li>
      <li>Status is <strong>paid</strong>, so seeded orders don't appear in the bartender queue.</li>
      <li>Email is <code>seed@knkinn.com</code> — handy if you want to filter them out of guest reports.</li>
      <li>Two of each drink land within the last 45 minutes so the live price multiplier has fresh demand to chew on.</li>
      <li>Sales reports <em>will</em> include seeded revenue. Hit Clear before any real reporting period starts.</li>
    </ul>
  </div>

</div>
</body>
</html>

<?php
/*
 * KnK Inn — /market-admin.php
 *
 * Beer Stock Market control room. Super Admin and Owner only.
 *
 * Sections:
 *   1. Kill switch                — ON/OFF for the whole market
 *   2. Live state                 — current Big-Board items + force price + unwind
 *   3. Pin slots                  — Beer pin + Owner's pick
 *   4. Social Crash               — one-tap crash of top-trending drinks
 *   5. Configuration              — every knob, single form, one Save
 *   6. Reset to defaults          — confirms, rewrites config row
 *   7. Recent events              — audit trail
 *
 * All actions POST-redirect-GET so refreshes don't re-fire.
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/menu_store.php";
require_once __DIR__ . "/includes/market_engine.php";

$me = knk_require_permission("market");
$me_id = (int)$me["id"];

$flash = "";
$error = "";
$action = (string)($_POST["action"] ?? "");

try {
    if ($action === "toggle_enabled") {
        $target = !empty($_POST["enabled"]) ? 1 : 0;
        knk_market_config_update(["enabled" => $target], $me_id);
        knk_audit("market.toggle", "market_config", "1", ["enabled" => $target]);
        $flash = $target
            ? "Market is ON. Eligible drinks will start trading on the next tick."
            : "Market is OFF. Order page and Big Board are back on menu prices.";
    }
    elseif ($action === "save_config") {
        $updates = [];
        foreach (knk_market_config_fields() as $f) {
            if ($f === "enabled") continue;  // only the toggle writes this
            if (!array_key_exists($f, $_POST)) continue;
            $v = $_POST[$f];
            // Time fields stay as strings. Everything else is int-ish.
            if (in_array($f, ["happy_start", "happy_end", "peak_start", "peak_end"], true)) {
                $v = trim((string)$v);
                if ($v !== "" && !preg_match('/^\d{2}:\d{2}$/', $v)) {
                    throw new RuntimeException("Time fields must be HH:MM (got '{$v}').");
                }
                $updates[$f] = $v;
            } else {
                $updates[$f] = (int)$v;
            }
        }
        // Sanity checks — clamp obvious typos instead of letting them leak
        // through. The DB is unsigned so negatives are rejected anyway, but
        // friendlier to catch here.
        foreach (["cap_floor_bp","cap_ceiling_bp","demand_min_bp","demand_max_bp",
                  "happy_mult_bp","peak_mult_bp","default_mult_bp"] as $k) {
            if (isset($updates[$k]) && $updates[$k] < 1) $updates[$k] = 1;
            if (isset($updates[$k]) && $updates[$k] > 500) $updates[$k] = 500;
        }
        if (isset($updates["cap_floor_bp"], $updates["cap_ceiling_bp"])
            && $updates["cap_floor_bp"] >= $updates["cap_ceiling_bp"]) {
            throw new RuntimeException("Floor must be lower than ceiling.");
        }
        if (isset($updates["demand_min_bp"], $updates["demand_max_bp"])
            && $updates["demand_min_bp"] > $updates["demand_max_bp"]) {
            throw new RuntimeException("Demand min must be ≤ demand max.");
        }
        knk_market_config_update($updates, $me_id);
        knk_audit("market.config", "market_config", "1", ["fields" => array_keys($updates)]);
        $flash = "Settings saved.";
    }
    elseif ($action === "reset_defaults") {
        knk_market_reset_defaults($me_id);
        knk_audit("market.reset_defaults", "market_config", "1");
        $flash = "Back to recommended defaults. Market kill switch is OFF.";
    }
    elseif ($action === "pin_set") {
        $slot = (string)($_POST["slot"] ?? "");
        $code = trim((string)($_POST["item_code"] ?? ""));
        knk_market_pin_set($slot, $code === "" ? null : $code, $me_id);
        knk_audit("market.pin", "market_pinned", $slot, ["item_code" => $code]);
        $flash = $code === ""
            ? "Pin cleared on slot '{$slot}'."
            : "Pinned '{$code}' to slot '{$slot}'.";
    }
    elseif ($action === "force_price") {
        $code  = trim((string)($_POST["item_code"] ?? ""));
        $price = (int)($_POST["price_vnd"] ?? 0);
        $lock  = (int)($_POST["lock_minutes"] ?? 0);
        if ($code === "") throw new RuntimeException("Pick a drink.");
        if ($price <= 0) throw new RuntimeException("Price must be > 0.");
        if ($lock < 0 || $lock > 1440) throw new RuntimeException("Lock must be 0–1440 minutes.");
        knk_market_force_price($code, $price, $lock, $me_id);
        knk_audit("market.force_price", "market_events", $code, [
            "price_vnd" => $price, "lock_minutes" => $lock,
        ]);
        $flash = "Forced " . htmlspecialchars($code) . " to "
            . number_format($price, 0, ".", ",") . "đ"
            . ($lock > 0 ? " (locked for {$lock} min)" : "");
    }
    elseif ($action === "unwind") {
        $code = trim((string)($_POST["item_code"] ?? ""));
        if ($code === "") throw new RuntimeException("Pick a drink.");
        // Write a fresh 'band' event at base price with no lock/crash — next
        // tick takes over cleanly.
        $menuRow = knk_menu_find_by_code($code);
        if (!$menuRow) throw new RuntimeException("Unknown drink.");
        $base = (int)$menuRow["price_vnd"];
        knk_market_record_event($code, $base, $base, 100, "reset", $me_id);
        knk_audit("market.unwind", "market_events", $code);
        $flash = "Cleared override on " . htmlspecialchars($code) . " — back to base price.";
    }
    elseif ($action === "social_crash") {
        $drop = (int)($_POST["drop_bp"] ?? 20);
        $dur  = (int)($_POST["duration_minutes"] ?? 3);
        if ($drop < 5 || $drop > 60) throw new RuntimeException("Drop must be 5–60%.");
        if ($dur  < 1 || $dur  > 15) throw new RuntimeException("Duration must be 1–15 min.");
        $fired = knk_market_social_crash($drop, $dur, $me_id);
        knk_audit("market.social_crash", "market_events", null, [
            "items" => $fired, "drop_bp" => $drop, "duration_minutes" => $dur,
        ]);
        $flash = $fired
            ? "Social crash fired on " . count($fired) . " item"
                . (count($fired) === 1 ? "" : "s") . ": " . implode(", ", $fired)
            : "Nothing to crash — no eligible items right now.";
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($action !== "") {
    $qs = [];
    if ($flash) $qs["msg"] = $flash;
    if ($error) $qs["err"] = $error;
    header("Location: /market-admin.php" . ($qs ? "?" . http_build_query($qs) : ""));
    exit;
}

$flash = (string)($_GET["msg"] ?? "");
$error = (string)($_GET["err"] ?? "");

/* --------------------------------------------------------------------
 * Read current state for display
 * ------------------------------------------------------------------ */
$cfg       = knk_market_config();
$enabled   = !empty($cfg["enabled"]);
$pinned    = knk_market_pinned();
$board     = knk_market_board_items();
$band      = knk_market_band_active();
$allDrinks = knk_menu_list(false);  // include hidden so Simmo can pin them if he wants
$events    = knk_market_recent_events(40);

/* Batch quotes for the board rows so the Live State table shows
 * computed prices without N+1 DB hits. */
$quotes = [];
foreach ($board as $row) {
    $quotes[$row["item_code"]] = knk_market_quote($row["item_code"]);
}

/** Tiny helper — format a bp multiplier as "85%" or "1.10×". */
function mkt_bp_pct(int $bp): string { return $bp . "%"; }
function mkt_vnd(int $n): string { return number_format($n, 0, ".", ",") . "đ"; }
function mkt_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>KnK Inn — Market admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/styles.css?v=12">
  <style>
    body { background: #1b0f04; color: var(--cream, #f5e9d1); font-family: "Inter", system-ui, sans-serif; margin: 0; }
    main.wrap { max-width: 1100px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
    h1.display-md { margin: 1.6rem 0 0.3rem; }
    .lede { color: var(--cream-dim, #d8c9ab); margin-bottom: 1.6rem; }
    .flash { padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1.2rem; font-size: 0.95rem; }
    .flash.ok  { background: rgba(142,212,154,0.12); color: #8ed49a; border: 1px solid rgba(142,212,154,0.28); }
    .flash.err { background: rgba(255,154,138,0.1);  color: #ff9a8a; border: 1px solid rgba(255,154,138,0.3); }
    section.card {
      background: rgba(24,12,3,0.6); border: 1px solid rgba(201,170,113,0.22);
      border-radius: 6px; padding: 1.5rem; margin-bottom: 1.5rem;
    }
    section.card h2 { margin: 0 0 0.4rem; font-family: "Archivo Black", sans-serif; font-size: 1.25rem; letter-spacing: .02em; }
    section.card h3 { margin: 1.2rem 0 0.6rem; font-family: "Archivo Black", sans-serif; font-size: 0.98rem; letter-spacing: .04em; color: var(--gold, #c9aa71); text-transform: uppercase; }
    section.card p.explain { color: var(--cream-dim, #d8c9ab); font-size: 0.92rem; margin: 0.2rem 0 1rem; line-height: 1.55; }

    .status-pill {
      display: inline-flex; align-items: center; gap: 0.4rem;
      padding: 0.35rem 0.85rem; border-radius: 999px;
      font-size: 0.75rem; letter-spacing: 0.12em; text-transform: uppercase; font-weight: 600;
    }
    .status-pill.on  { background: rgba(142,212,154,0.12); color: #8ed49a; border: 1px solid rgba(142,212,154,0.3); }
    .status-pill.off { background: rgba(255,154,138,0.1);  color: #ff9a8a; border: 1px solid rgba(255,154,138,0.3); }
    .status-pill.warn{ background: rgba(255,200,120,0.1);  color: #ffc878; border: 1px solid rgba(255,200,120,0.3); }
    .status-pill .dot { width: 0.55rem; height: 0.55rem; border-radius: 50%; background: currentColor; }

    label { display: block; font-size: 0.72rem; letter-spacing: 0.14em; text-transform: uppercase; color: var(--cream-dim, #d8c9ab); margin: 0 0 0.3rem 0.15rem; }
    input[type="number"], input[type="text"], input[type="time"], select {
      padding: 0.55rem 0.7rem; background: rgba(255,255,255,0.04);
      border: 1px solid rgba(201,170,113,0.3); color: var(--cream, #f5e9d1);
      font-size: 0.92rem; font-family: inherit; border-radius: 4px; box-sizing: border-box;
    }
    input[type="number"] { width: 110px; }
    input[type="text"]   { width: 130px; }
    input:focus, select:focus { outline: none; border-color: var(--gold, #c9aa71); }
    select { max-width: 320px; }

    button, .btn {
      display: inline-block; padding: 0.55rem 1rem; background: var(--gold, #c9aa71);
      color: var(--brown-deep, #2a1a08); border: none; font-weight: 700;
      letter-spacing: 0.12em; text-transform: uppercase; font-size: 0.72rem;
      cursor: pointer; border-radius: 4px; font-family: inherit; text-decoration: none;
    }
    button:hover, .btn:hover { background: #d8c08b; }
    button.ghost { background: transparent; color: var(--cream-dim, #d8c9ab); border: 1px solid rgba(201,170,113,0.3); }
    button.ghost:hover { background: rgba(201,170,113,0.12); color: var(--cream, #f5e9d1); }
    button.danger { background: #b54141; color: #fff; }
    button.danger:hover { background: #d65454; }
    button.crash {
      background: linear-gradient(180deg, #e44a4a, #a42020); color: #fff;
      font-size: 0.85rem; padding: 0.75rem 1.6rem; letter-spacing: 0.16em;
      box-shadow: 0 0 0 0 rgba(228,74,74,0.6); animation: pulseCrash 2.2s infinite;
    }
    button.crash:hover { background: linear-gradient(180deg, #ff5a5a, #b42929); }
    @keyframes pulseCrash {
      0%   { box-shadow: 0 0 0 0 rgba(228,74,74,0.45); }
      70%  { box-shadow: 0 0 0 14px rgba(228,74,74,0); }
      100% { box-shadow: 0 0 0 0 rgba(228,74,74,0); }
    }

    table.mkt { width: 100%; border-collapse: collapse; margin-top: 0.6rem; font-size: 0.9rem; }
    table.mkt th { text-align: left; font-size: 0.72rem; letter-spacing: 0.14em; text-transform: uppercase; color: var(--cream-dim, #d8c9ab); padding: 0.55rem 0.6rem; border-bottom: 1px solid rgba(201,170,113,0.22); font-weight: 600; }
    table.mkt td { padding: 0.55rem 0.6rem; border-bottom: 1px dashed rgba(201,170,113,0.12); vertical-align: middle; }
    table.mkt tr:last-child td { border-bottom: none; }
    table.mkt .num { text-align: right; font-variant-numeric: tabular-nums; }
    table.mkt .pin-beer  { border-left: 3px solid #f5c46a; }
    table.mkt .pin-owner { border-left: 3px solid #c9aa71; }
    table.mkt .muted { color: var(--cream-dim, #d8c9ab); }

    .tag { display: inline-block; padding: 0.14rem 0.5rem; border-radius: 3px; font-size: 0.68rem; letter-spacing: 0.1em; text-transform: uppercase; font-weight: 700; }
    .tag.crash { background: rgba(228,74,74,0.18); color: #ff9a8a; border: 1px solid rgba(228,74,74,0.4); }
    .tag.lock  { background: rgba(201,170,113,0.15); color: var(--gold, #c9aa71); border: 1px solid rgba(201,170,113,0.35); }
    .tag.pin   { background: rgba(142,212,154,0.12); color: #8ed49a; border: 1px solid rgba(142,212,154,0.3); }

    .grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 0.9rem 1.2rem; }
    .grid-2 .field { display: flex; flex-direction: column; }
    .inline { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }

    .logrow { display: grid; grid-template-columns: 150px 120px 1fr 140px 140px; gap: 0.6rem; padding: 0.35rem 0; border-bottom: 1px dashed rgba(201,170,113,0.1); font-size: 0.85rem; }
    .logrow:last-child { border-bottom: none; }
    .logrow .ts { color: var(--cream-dim, #d8c9ab); font-variant-numeric: tabular-nums; }
    .logrow .src { font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase; color: var(--gold, #c9aa71); }

    .hint { font-size: 0.82rem; color: var(--cream-dim, #d8c9ab); margin-top: 0.5rem; }
    .band-chip { display: inline-block; padding: 0.2rem 0.6rem; border-radius: 3px; background: rgba(201,170,113,0.14); font-size: 0.78rem; letter-spacing: 0.06em; margin-left: 0.5rem; }
  </style>
</head>
<body>
  <?php knk_render_admin_nav($me); ?>
  <main class="wrap">
    <span class="eyebrow">Super Admin &amp; Owner</span>
    <h1 class="display-md">Beer Stock Market</h1>
    <p class="lede">Live-price drinks on the ground-floor TV — tune it here.</p>

    <?php if ($flash): ?><div class="flash ok"><?= mkt_h($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="flash err"><?= mkt_h($error) ?></div><?php endif; ?>

    <!-- 1. Kill switch ------------------------------------------------ -->
    <section class="card">
      <h2>Market is
        <?php if ($enabled): ?>
          <span class="status-pill on"><span class="dot"></span>On</span>
        <?php else: ?>
          <span class="status-pill off"><span class="dot"></span>Off</span>
        <?php endif; ?>
        <span class="band-chip">Right now: <?= mkt_h(knk_market_band_label($band["band"])) ?> (<?= (int)$band["mult_bp"] ?>%)</span>
      </h2>
      <p class="explain">
        When OFF, the public order page and the Big Board both show the
        plain menu price — guests see no change, the TV goes blank, and
        the cron tick is a no-op. Safe to toggle mid-shift.
      </p>
      <form method="post" class="inline">
        <input type="hidden" name="action" value="toggle_enabled">
        <?php if ($enabled): ?>
          <button type="submit" class="ghost">Turn market off</button>
        <?php else: ?>
          <input type="hidden" name="enabled" value="1">
          <button type="submit">Turn market on</button>
        <?php endif; ?>
        <a class="btn ghost" href="/market.php" target="_blank">Open Big Board ↗</a>
      </form>
    </section>

    <!-- 2. Live state ------------------------------------------------- -->
    <section class="card">
      <h2>Live state</h2>
      <p class="explain">
        Current price for every drink on the Big Board. Pinned drinks
        always make the board regardless of volume. Force a price
        manually with the form in each row, or clear an override to
        hand it back to the engine.
      </p>

      <?php if (!$board): ?>
        <p class="muted">Nothing on the board yet. Either no orders in the window, or the min-orders floor hasn't been cleared — tune it in Configuration below.</p>
      <?php else: ?>
      <table class="mkt">
        <thead>
          <tr>
            <th>Drink</th>
            <th class="num">Base</th>
            <th class="num">Live</th>
            <th class="num">×</th>
            <th class="num">Vol (7d)</th>
            <th>State</th>
            <th>Force / clear</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($board as $row):
            $code = $row["item_code"];
            $q    = $quotes[$code];
            $pinClass = $row["pin_slot"] === "beer" ? " pin-beer"
                      : ($row["pin_slot"] === "owner" ? " pin-owner" : "");
          ?>
            <tr class="<?= mkt_h(trim($pinClass)) ?>">
              <td>
                <strong><?= mkt_h($row["name"]) ?></strong>
                <?php if ($row["pin_slot"]): ?>
                  <span class="tag pin">Pin: <?= mkt_h($row["pin_slot"]) ?></span>
                <?php endif; ?>
                <div class="muted" style="font-size:0.78rem;"><?= mkt_h($row["category"]) ?> · <code><?= mkt_h($code) ?></code></div>
              </td>
              <td class="num"><?= mkt_vnd($row["base_price_vnd"]) ?></td>
              <td class="num"><strong><?= mkt_vnd($q["price_vnd"]) ?></strong></td>
              <td class="num muted"><?= mkt_bp_pct((int)$q["multiplier_bp"]) ?></td>
              <td class="num"><?= (int)$row["volume"] ?></td>
              <td>
                <?php if ($q["in_crash"]): ?>
                  <span class="tag crash">Crash · <?= max(0,(int)(($q["crash_until"] - time()) / 60)) ?>m left</span>
                <?php elseif ($q["locked_until"]): ?>
                  <span class="tag lock">Lock · <?= max(0,(int)(($q["locked_until"] - time()) / 60)) ?>m left</span>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <form method="post" class="inline" style="gap:0.35rem;">
                  <input type="hidden" name="action" value="force_price">
                  <input type="hidden" name="item_code" value="<?= mkt_h($code) ?>">
                  <input type="number" name="price_vnd" min="1000" step="1000" placeholder="VND" value="<?= (int)$q["price_vnd"] ?>" style="width:95px;">
                  <input type="number" name="lock_minutes" min="0" max="1440" step="1" value="10" title="Lock (minutes)" style="width:60px;">
                  <button type="submit" class="ghost" title="Force this price">Force</button>
                </form>
                <?php if ($q["in_crash"] || $q["locked_until"] || $q["source"] === "manual"): ?>
                <form method="post" style="margin-top:0.3rem;" onsubmit="return confirm('Clear the override on <?= mkt_h($row["name"]) ?>?');">
                  <input type="hidden" name="action" value="unwind">
                  <input type="hidden" name="item_code" value="<?= mkt_h($code) ?>">
                  <button type="submit" class="ghost">Clear override</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </section>

    <!-- 3. Pins ------------------------------------------------------- -->
    <section class="card">
      <h2>Pinned slots</h2>
      <p class="explain">
        Two slots that are always shown on the Big Board, regardless
        of order volume. Use them to guarantee a beer is visible even
        on a quiet night, and to surface Simmo's pick.
      </p>
      <div class="grid-2">
        <?php foreach (["beer" => "Beer pin", "owner" => "Owner's pick"] as $slot => $lbl):
          $cur = $pinned[$slot]["item_code"] ?? null; ?>
          <form method="post" class="field">
            <input type="hidden" name="action" value="pin_set">
            <input type="hidden" name="slot"   value="<?= mkt_h($slot) ?>">
            <label><?= mkt_h($lbl) ?></label>
            <select name="item_code">
              <option value="">— empty slot —</option>
              <?php foreach ($allDrinks as $d): ?>
                <option value="<?= mkt_h($d["item_code"]) ?>" <?= $cur === $d["item_code"] ? "selected" : "" ?>>
                  <?= mkt_h($d["category"]) ?> · <?= mkt_h($d["name"]) ?> (<?= mkt_vnd((int)$d["price_vnd"]) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <div style="margin-top:0.5rem;"><button type="submit" class="ghost">Save slot</button></div>
          </form>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- 4. Social crash ---------------------------------------------- -->
    <section class="card">
      <h2>Social crash</h2>
      <p class="explain">
        One-tap crash on the top <?= (int)$cfg["crash_items_max"] ?> trending drinks
        right now. Fire this when the bar's buzzing and you want
        people to look up at the TV. Guests in the middle of
        ordering see the new price on their next page load.
      </p>
      <form method="post" class="inline" onsubmit="return confirm('Fire a social crash now?');">
        <input type="hidden" name="action" value="social_crash">
        <div class="field">
          <label>Drop (%)</label>
          <input type="number" name="drop_bp" min="5" max="60" step="1" value="20">
        </div>
        <div class="field">
          <label>Duration (min)</label>
          <input type="number" name="duration_minutes" min="1" max="15" step="1" value="3">
        </div>
        <button type="submit" class="crash">Fire social crash</button>
      </form>
    </section>

    <!-- 5. Configuration --------------------------------------------- -->
    <section class="card">
      <h2>Configuration</h2>
      <p class="explain">
        Every knob the engine looks at. Changes take effect on the
        next tick (within a minute). Times are Saigon local; multipliers
        are in percent (100 = base price, 130 = 30% more, 85 = 15% off).
      </p>

      <form method="post">
        <input type="hidden" name="action" value="save_config">

        <h3>Time bands</h3>
        <div class="grid-2">
          <div class="field">
            <label>Happy hour start</label>
            <input type="text" name="happy_start" value="<?= mkt_h($cfg["happy_start"]) ?>" placeholder="16:00" pattern="\d{2}:\d{2}">
          </div>
          <div class="field">
            <label>Happy hour end</label>
            <input type="text" name="happy_end"   value="<?= mkt_h($cfg["happy_end"])   ?>" placeholder="19:00" pattern="\d{2}:\d{2}">
          </div>
          <div class="field">
            <label>Happy hour ×</label>
            <input type="number" name="happy_mult_bp" min="1" max="500" value="<?= (int)$cfg["happy_mult_bp"] ?>">
          </div>
          <div class="field">
            <label>Peak start</label>
            <input type="text" name="peak_start" value="<?= mkt_h($cfg["peak_start"]) ?>" placeholder="20:00" pattern="\d{2}:\d{2}">
          </div>
          <div class="field">
            <label>Peak end</label>
            <input type="text" name="peak_end"   value="<?= mkt_h($cfg["peak_end"])   ?>" placeholder="23:00" pattern="\d{2}:\d{2}">
          </div>
          <div class="field">
            <label>Peak ×</label>
            <input type="number" name="peak_mult_bp" min="1" max="500" value="<?= (int)$cfg["peak_mult_bp"] ?>">
          </div>
          <div class="field">
            <label>Off-peak ×</label>
            <input type="number" name="default_mult_bp" min="1" max="500" value="<?= (int)$cfg["default_mult_bp"] ?>">
          </div>
        </div>

        <h3>Demand engine</h3>
        <div class="grid-2">
          <div class="field">
            <label>Rolling window (min)</label>
            <input type="number" name="demand_window_minutes" min="5" max="240" value="<?= (int)$cfg["demand_window_minutes"] ?>">
          </div>
          <div class="field">
            <label>Baseline orders/hour</label>
            <input type="number" name="baseline_orders_per_hour" min="1" max="100" value="<?= (int)$cfg["baseline_orders_per_hour"] ?>">
          </div>
          <div class="field">
            <label>Demand min ×</label>
            <input type="number" name="demand_min_bp" min="1" max="500" value="<?= (int)$cfg["demand_min_bp"] ?>">
          </div>
          <div class="field">
            <label>Demand max ×</label>
            <input type="number" name="demand_max_bp" min="1" max="500" value="<?= (int)$cfg["demand_max_bp"] ?>">
          </div>
        </div>

        <h3>Crash cadence</h3>
        <div class="grid-2">
          <div class="field">
            <label>Min gap (min)</label>
            <input type="number" name="crash_cadence_min" min="1" max="480" value="<?= (int)$cfg["crash_cadence_min"] ?>">
          </div>
          <div class="field">
            <label>Max gap (min)</label>
            <input type="number" name="crash_cadence_max" min="1" max="480" value="<?= (int)$cfg["crash_cadence_max"] ?>">
          </div>
          <div class="field">
            <label>Per-item cooldown (min)</label>
            <input type="number" name="crash_item_cooldown_min" min="0" max="480" value="<?= (int)$cfg["crash_item_cooldown_min"] ?>">
          </div>
          <div class="field">
            <label>Items per crash (max)</label>
            <input type="number" name="crash_items_max" min="1" max="10" value="<?= (int)$cfg["crash_items_max"] ?>">
          </div>
        </div>

        <h3>Crash magnitude</h3>
        <div class="grid-2">
          <div class="field">
            <label>Drop min (%)</label>
            <input type="number" name="crash_drop_min_bp" min="1" max="90" value="<?= (int)$cfg["crash_drop_min_bp"] ?>">
          </div>
          <div class="field">
            <label>Drop max (%)</label>
            <input type="number" name="crash_drop_max_bp" min="1" max="90" value="<?= (int)$cfg["crash_drop_max_bp"] ?>">
          </div>
          <div class="field">
            <label>Duration min (min)</label>
            <input type="number" name="crash_duration_min_minutes" min="1" max="60" value="<?= (int)$cfg["crash_duration_min_minutes"] ?>">
          </div>
          <div class="field">
            <label>Duration max (min)</label>
            <input type="number" name="crash_duration_max_minutes" min="1" max="60" value="<?= (int)$cfg["crash_duration_max_minutes"] ?>">
          </div>
        </div>

        <h3>Hard caps</h3>
        <div class="grid-2">
          <div class="field">
            <label>Floor (% of base)</label>
            <input type="number" name="cap_floor_bp" min="1" max="200" value="<?= (int)$cfg["cap_floor_bp"] ?>">
          </div>
          <div class="field">
            <label>Ceiling (% of base)</label>
            <input type="number" name="cap_ceiling_bp" min="100" max="500" value="<?= (int)$cfg["cap_ceiling_bp"] ?>">
          </div>
        </div>
        <p class="hint">No price, however crashed or peaked, goes below floor or above ceiling.</p>

        <h3>Big Board eligibility</h3>
        <div class="grid-2">
          <div class="field">
            <label>Window (days)</label>
            <input type="number" name="eligibility_window_days" min="1" max="60" value="<?= (int)$cfg["eligibility_window_days"] ?>">
          </div>
          <div class="field">
            <label>Top N</label>
            <input type="number" name="eligibility_top_n" min="1" max="30" value="<?= (int)$cfg["eligibility_top_n"] ?>">
          </div>
          <div class="field">
            <label>Min orders to qualify</label>
            <input type="number" name="eligibility_min_orders" min="0" max="200" value="<?= (int)$cfg["eligibility_min_orders"] ?>">
          </div>
        </div>

        <h3>Fair-play &amp; timing</h3>
        <div class="grid-2">
          <div class="field">
            <label>Price-lock window (sec)</label>
            <input type="number" name="price_lock_seconds" min="1" max="120" value="<?= (int)$cfg["price_lock_seconds"] ?>">
          </div>
          <div class="field">
            <label>Max market items / order</label>
            <input type="number" name="fairplay_max_market_items" min="0" max="20" value="<?= (int)$cfg["fairplay_max_market_items"] ?>">
          </div>
          <div class="field">
            <label>Per-guest cooldown (sec)</label>
            <input type="number" name="fairplay_cooldown_seconds" min="0" max="3600" value="<?= (int)$cfg["fairplay_cooldown_seconds"] ?>">
          </div>
          <div class="field">
            <label>Big Board poll (sec)</label>
            <input type="number" name="board_poll_seconds" min="1" max="60" value="<?= (int)$cfg["board_poll_seconds"] ?>">
          </div>
        </div>

        <div style="margin-top:1.2rem;">
          <button type="submit">Save all settings</button>
        </div>
      </form>
    </section>

    <!-- 6. Reset ----------------------------------------------------- -->
    <section class="card">
      <h2>Reset to defaults</h2>
      <p class="explain">
        Rewrites every setting above back to the recommended values.
        The market kill switch also flips off — you turn it on again
        after confirming the defaults look right. Doesn't touch pins,
        price history, or the menu.
      </p>
      <form method="post" onsubmit="return confirm('Reset every market setting back to defaults? The kill switch will flip to OFF.');">
        <input type="hidden" name="action" value="reset_defaults">
        <button type="submit" class="danger">Reset to defaults</button>
      </form>
    </section>

    <!-- 7. Recent events -------------------------------------------- -->
    <section class="card">
      <h2>Recent events</h2>
      <p class="explain">Last 40 price changes. Useful for debugging a crash that did or didn't fire.</p>
      <?php if (!$events): ?>
        <p class="muted">No events yet. Start the market and give it a tick or two.</p>
      <?php else: ?>
        <div class="logrow" style="border-bottom:1px solid rgba(201,170,113,0.25); font-size:0.72rem; letter-spacing:0.14em; text-transform:uppercase; color:var(--cream-dim,#d8c9ab);">
          <div>When</div><div>Source</div><div>Drink</div><div class="num">Price</div><div class="num">Actor</div>
        </div>
        <?php foreach ($events as $e): ?>
          <div class="logrow">
            <div class="ts"><?= mkt_h(date("D H:i:s", strtotime((string)$e["created_at"]))) ?></div>
            <div class="src"><?= mkt_h(knk_market_source_label((string)$e["source"])) ?></div>
            <div>
              <strong><?= mkt_h($e["item_name"] ?? $e["item_code"]) ?></strong>
              <span class="muted">&nbsp;<?= mkt_bp_pct((int)$e["multiplier_bp"]) ?></span>
              <?php if (!empty($e["crash_until"]) && (int)$e["crash_until"] > time()): ?>
                <span class="tag crash">Crash</span>
              <?php elseif (!empty($e["locked_until"]) && (int)$e["locked_until"] > time()): ?>
                <span class="tag lock">Lock</span>
              <?php endif; ?>
            </div>
            <div class="num">
              <?= mkt_vnd((int)$e["new_price_vnd"]) ?>
              <span class="muted" style="font-size:0.78rem;">(base <?= mkt_vnd((int)$e["base_price_vnd"]) ?>)</span>
            </div>
            <div class="num muted"><?= mkt_h($e["actor_name"] ?? "—") ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

  </main>
</body>
</html>

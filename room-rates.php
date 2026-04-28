<?php
/*
 * KnK Inn — /room-rates.php
 *
 * Per-room rate management for staff. Gated under the "bookings"
 * permission since the same role that handles reservations should
 * be the one tuning prices.
 *
 * What you can do here:
 *   - Pick a room (one of 7 physical rooms)
 *   - Edit its default ("rack") rate
 *   - Paint a season + nightly rate across a date range
 *   - See the next ~90 days as a calendar grid, colour-coded by
 *     season, with the override rate or default fallback
 *   - Clear a single override (returns that night to default)
 *
 * Design notes:
 *   - Each form posts an action then redirects (PRG) so refresh
 *     doesn't double-submit.
 *   - Calendar grid is server-rendered HTML — no JS required to
 *     read it. Single-cell clear is a tiny inline form.
 *   - Date range paint uses knk_room_rate_set_range() inside a
 *     transaction.
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/room_rates_store.php";

$me    = knk_require_permission("bookings");
$me_id = (int)$me["id"];

/* --------------------------------------------------------------
 * POST handlers — PRG pattern. Set $_SESSION flash, redirect,
 * the GET render reads the flash and clears it.
 * -------------------------------------------------------------- */
if (session_status() === PHP_SESSION_NONE) session_start();
$flash_key = "knk_room_rates_flash";
$err_key   = "knk_room_rates_err";

function rr_redirect(string $url): void { header("Location: $url"); exit; }
function rr_flash(string $msg): void { $_SESSION["knk_room_rates_flash"] = $msg; }
function rr_error(string $msg): void { $_SESSION["knk_room_rates_err"]   = $msg; }

$action = (string)($_POST["action"] ?? "");
$rooms_all = knk_rooms_all(true);

/* Pick the room we're editing — query string sticks across PRG. */
$current_slug = (string)($_GET["room"] ?? $_POST["room_slug"] ?? "");
if ($current_slug === "" && !empty($rooms_all)) $current_slug = $rooms_all[0]["slug"];
$current_room = knk_room_get($current_slug);

if ($action !== "" && $current_room) {
    $back = "/room-rates.php?room=" . urlencode($current_slug);

    if ($action === "set_default") {
        $vnd = (int)preg_replace('/[^0-9]/', '', (string)($_POST["default_vnd"] ?? "0"));
        if ($vnd <= 0) {
            rr_error("Default rate has to be greater than 0.");
        } else {
            if (knk_room_default_rate_set($current_slug, $vnd)) {
                rr_flash("Default rate updated.");
                knk_audit("room_rate.default_set", "rooms", $current_slug, ["vnd" => $vnd]);
            } else {
                rr_error("Couldn't save the default rate.");
            }
        }
        rr_redirect($back);
    }

    if ($action === "paint_range") {
        $start  = (string)($_POST["start"] ?? "");
        $end    = (string)($_POST["end"]   ?? "");
        $vnd    = (int)preg_replace('/[^0-9]/', '', (string)($_POST["vnd"] ?? "0"));
        $season = trim((string)($_POST["season"] ?? "")) ?: null;
        $note   = trim((string)($_POST["note"]   ?? "")) ?: null;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            rr_error("Pick valid start and end dates.");
        } elseif ($vnd <= 0) {
            rr_error("Nightly rate has to be greater than 0.");
        } elseif (strtotime($end) < strtotime($start)) {
            rr_error("End date is before the start date.");
        } else {
            $touched = knk_room_rate_set_range($current_slug, $start, $end, $vnd, $season, $note);
            if ($touched > 0) {
                rr_flash("Painted $touched night(s) at " . number_format($vnd) . " VND.");
                knk_audit("room_rate.paint_range", "rooms", $current_slug, [
                    "start" => $start, "end" => $end, "vnd" => $vnd,
                    "season" => $season, "nights" => $touched,
                ]);
            } else {
                rr_error("Nothing was saved. Check the dates.");
            }
        }
        rr_redirect($back);
    }

    if ($action === "clear_one") {
        $d = (string)($_POST["date"] ?? "");
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) &&
            knk_room_rate_clear($current_slug, $d)) {
            rr_flash("Cleared override for $d (back to default).");
            knk_audit("room_rate.clear", "rooms", $current_slug, ["date" => $d]);
        } else {
            rr_error("Couldn't clear that night.");
        }
        rr_redirect($back);
    }
}

/* --------------------------------------------------------------
 * GET — render
 * -------------------------------------------------------------- */
$flash = $_SESSION[$flash_key] ?? "";  unset($_SESSION[$flash_key]);
$error = $_SESSION[$err_key]   ?? "";  unset($_SESSION[$err_key]);

/* Calendar window — default to today + 90 days. The query string
 * lets staff jump further out by passing ?from=YYYY-MM-DD&days=N. */
$from_q = (string)($_GET["from"]  ?? "");
$days_q = (int)   ($_GET["days"]  ?? 90);
if ($days_q < 14)  $days_q = 14;
if ($days_q > 365) $days_q = 365;
$from_ts = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_q)
    ? strtotime($from_q)
    : strtotime("today");
$from_ymd = date("Y-m-d", $from_ts);
$to_ymd   = date("Y-m-d", strtotime("+" . ($days_q - 1) . " days", $from_ts));

$seasons = knk_room_rate_seasons();
$season_by_slug = [];
foreach ($seasons as $s) $season_by_slug[$s["slug"]] = $s;

$overrides = $current_room
    ? knk_room_rates_calendar($from_ymd, $to_ymd, $current_slug)
    : [];

function rr_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, "UTF-8"); }
function rr_vnd(int $n): string  { return number_format($n) . " ₫"; }

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Room rates — KnK Inn admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/styles.css?v=8">
<style>
  .rr-page { max-width: 1100px; margin: 0 auto; padding: 1rem; }
  .rr-room-tabs { display: flex; flex-wrap: wrap; gap: 0.4rem; margin: 0.6rem 0 1rem; }
  .rr-room-tab {
    padding: 0.45rem 0.85rem; border-radius: 999px;
    background: rgba(245,233,209,0.06); color: var(--cream,#f5e9d1);
    text-decoration: none; font-size: 0.92rem; border: 1px solid transparent;
  }
  .rr-room-tab.is-on {
    background: rgba(201,170,113,0.22); color: var(--gold,#c9aa71);
    border-color: rgba(201,170,113,0.4);
  }
  .rr-grid {
    display: grid; grid-template-columns: repeat(7, 1fr);
    gap: 4px; margin-top: 0.5rem;
  }
  .rr-day {
    border-radius: 7px; padding: 0.45rem 0.4rem; min-height: 64px;
    background: rgba(245,233,209,0.04); border: 1px solid rgba(245,233,209,0.06);
    display: flex; flex-direction: column; justify-content: space-between;
    font-size: 0.78rem;
  }
  .rr-day.is-override {
    background: rgba(201,170,113,0.12);
    border-color: rgba(201,170,113,0.4);
  }
  .rr-day .d { color: rgba(245,233,209,0.55); font-weight: 600; }
  .rr-day .v { color: var(--cream,#f5e9d1); font-weight: 700; font-size: 0.85rem; }
  .rr-day .s { font-size: 0.7rem; color: rgba(245,233,209,0.5); margin-top: 2px; }
  .rr-day .x {
    background: transparent; border: 0; color: rgba(245,233,209,0.4);
    cursor: pointer; font-size: 0.85rem; align-self: flex-end;
  }
  .rr-day .x:hover { color: #ff8a8a; }
  .rr-row { display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: end; }
  .rr-row .field { display: flex; flex-direction: column; gap: 0.2rem; }
  .rr-row label { font-size: 0.78rem; color: rgba(245,233,209,0.6); text-transform: uppercase; letter-spacing: 0.04em; }
  .rr-row input, .rr-row select {
    background: rgba(245,233,209,0.06); border: 1px solid rgba(245,233,209,0.18);
    color: var(--cream,#f5e9d1); padding: 0.45rem 0.6rem; border-radius: 8px;
    font-size: 0.95rem; min-width: 0;
  }
  .rr-row button {
    background: rgba(201,170,113,0.22); color: var(--gold,#c9aa71);
    border: 1px solid rgba(201,170,113,0.4);
    padding: 0.55rem 1rem; border-radius: 8px; font-weight: 700; cursor: pointer;
  }
  .rr-month-h {
    grid-column: 1 / -1; padding: 0.6rem 0 0.2rem;
    color: var(--gold,#c9aa71); font-weight: 700; font-size: 0.95rem;
    border-bottom: 1px solid rgba(201,170,113,0.25);
  }
  .rr-dow {
    grid-column: 1 / -1; display: grid; grid-template-columns: repeat(7, 1fr);
    gap: 4px; font-size: 0.7rem; color: rgba(245,233,209,0.4); padding-bottom: 4px;
  }
  .rr-dow span { text-align: center; }
  .rr-flash { padding: 0.6rem 0.9rem; border-radius: 8px; margin-bottom: 0.8rem; }
  .rr-flash.ok  { background: rgba(122,165,106,0.2); color: #b6dba0; }
  .rr-flash.err { background: rgba(217,122,90,0.2);  color: #ffb59d; }
  .rr-card {
    background: rgba(245,233,209,0.04); border-radius: 12px;
    padding: 1rem 1.1rem; margin-bottom: 0.9rem; border: 1px solid rgba(245,233,209,0.08);
  }
  .rr-card h2 { font-size: 1rem; margin: 0 0 0.6rem; color: var(--gold,#c9aa71); }
  .rr-help { font-size: 0.82rem; color: rgba(245,233,209,0.55); margin-top: 0.4rem; }
</style>
</head>
<body>
<?php knk_render_admin_nav($me); ?>

<main class="rr-page">
  <h1 style="margin:0.2rem 0 0.4rem;">Room rates</h1>
  <p class="rr-help" style="margin:0 0 0.8rem;">
    Per-room nightly pricing for the next 90 days. The OTAs (Airbnb / Booking.com / Tripadvisor) read these rates when guests look at your listings.
  </p>

  <?php if ($flash !== ""): ?><div class="rr-flash ok"><?= rr_h($flash) ?></div><?php endif; ?>
  <?php if ($error !== ""): ?><div class="rr-flash err"><?= rr_h($error) ?></div><?php endif; ?>

  <!-- Room picker — sticky at top so staff can jump rooms without scrolling. -->
  <div class="rr-room-tabs">
    <?php foreach ($rooms_all as $r):
      $on = ($r["slug"] === $current_slug);
      $url = "/room-rates.php?room=" . urlencode($r["slug"]);
    ?>
      <a class="rr-room-tab<?= $on ? ' is-on' : '' ?>" href="<?= rr_h($url) ?>">
        <?= rr_h($r["display_name"]) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if (!$current_room): ?>
    <div class="rr-card">
      <p>No rooms set up yet. Run <code>/migrate.php</code> to seed the default room registry.</p>
    </div>
  <?php else: ?>

    <!-- Default rack rate. The fallback for any night without an override row. -->
    <div class="rr-card">
      <h2>Default rate (rack rate)</h2>
      <form method="post" class="rr-row">
        <input type="hidden" name="action" value="set_default">
        <input type="hidden" name="room_slug" value="<?= rr_h($current_slug) ?>">
        <div class="field" style="flex:1 1 220px;">
          <label for="rr-default-vnd">VND per night</label>
          <input id="rr-default-vnd" type="text" name="default_vnd" inputmode="numeric"
                 value="<?= (int)$current_room["default_vnd_per_night"] ?>"
                 placeholder="850000">
        </div>
        <button type="submit">Save default</button>
      </form>
      <p class="rr-help">
        This price applies to every night that doesn't have a specific override below.
        Useful for setting a baseline before you paint seasons over the top.
      </p>
    </div>

    <!-- Range painter. Pick a date range, season, rate; click Apply to bulk-set. -->
    <div class="rr-card">
      <h2>Paint a date range</h2>
      <form method="post" class="rr-row">
        <input type="hidden" name="action" value="paint_range">
        <input type="hidden" name="room_slug" value="<?= rr_h($current_slug) ?>">
        <div class="field"><label for="rr-start">Start</label>
          <input id="rr-start" type="date" name="start" required value="<?= rr_h($from_ymd) ?>"></div>
        <div class="field"><label for="rr-end">End (incl.)</label>
          <input id="rr-end"   type="date" name="end"   required></div>
        <div class="field"><label for="rr-season">Season</label>
          <select id="rr-season" name="season">
            <option value="">(none)</option>
            <?php foreach ($seasons as $s): ?>
              <option value="<?= rr_h($s["slug"]) ?>"><?= rr_h($s["display_name"]) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label for="rr-vnd">VND / night</label>
          <input id="rr-vnd" type="text" name="vnd" inputmode="numeric" required placeholder="950000"></div>
        <div class="field" style="flex:1 1 200px;"><label for="rr-note">Note (optional)</label>
          <input id="rr-note" type="text" name="note" maxlength="160" placeholder="Tet 2027 / NYE / etc."></div>
        <button type="submit">Apply to range</button>
      </form>
      <p class="rr-help">
        Paints every night between Start and End (inclusive) with the same rate.
        Existing overrides in that range are replaced.
      </p>
    </div>

    <!-- Season legend — compact swatch row so staff can read the calendar. -->
    <?php if (!empty($seasons)): ?>
      <div class="rr-card" style="padding-top:0.7rem; padding-bottom:0.7rem;">
        <div style="display:flex; flex-wrap:wrap; gap:0.8rem; align-items:center; font-size:0.85rem;">
          <strong style="color:var(--gold,#c9aa71);">Seasons:</strong>
          <?php foreach ($seasons as $s): ?>
            <span style="display:inline-flex; align-items:center; gap:0.3rem;">
              <span style="display:inline-block; width:14px; height:14px; border-radius:3px; background:<?= rr_h($s["color_hex"]) ?>;"></span>
              <?= rr_h($s["display_name"]) ?>
            </span>
          <?php endforeach; ?>
          <span style="opacity:0.55; margin-left:auto;">No swatch = default rack rate</span>
        </div>
      </div>
    <?php endif; ?>

    <!-- Calendar grid. Days grouped by month; padding cells for week-of-month
         alignment so Mondays line up. -->
    <div class="rr-card">
      <h2>Next <?= (int)$days_q ?> days</h2>

      <?php
        // Walk the date range building grouped-by-month buckets.
        $months = [];
        for ($t = $from_ts; $t <= strtotime($to_ymd); $t = strtotime("+1 day", $t)) {
            $key = date("Y-m", $t);
            $months[$key][] = $t;
        }
      ?>

      <?php foreach ($months as $monthkey => $days):
        // First day of this bucket determines week-start padding.
        $first = $days[0];
        $first_dow = (int)date("N", $first); // 1..7 Mon..Sun
        // We use a Monday-start grid.
        $pad = $first_dow - 1;
      ?>
        <div class="rr-grid">
          <div class="rr-month-h"><?= rr_h(date("F Y", $first)) ?></div>
          <div class="rr-dow">
            <span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span>
          </div>
          <?php for ($i = 0; $i < $pad; $i++): ?>
            <div></div>
          <?php endfor; ?>
          <?php foreach ($days as $t):
            $d = date("Y-m-d", $t);
            $is_override = isset($overrides[$d]);
            $vnd = $is_override
                ? (int)$overrides[$d]["vnd"]
                : (int)$current_room["default_vnd_per_night"];
            $season_slug = $is_override ? $overrides[$d]["season_slug"] : null;
            $color = ($season_slug !== null && isset($season_by_slug[$season_slug]))
                ? $season_by_slug[$season_slug]["color_hex"]
                : "transparent";
            $tip = "";
            if ($is_override && !empty($overrides[$d]["note"])) {
                $tip = (string)$overrides[$d]["note"];
            }
          ?>
            <div class="rr-day<?= $is_override ? " is-override" : "" ?>"
                 style="<?= $color !== 'transparent' ? "border-left:3px solid {$color};" : "" ?>"
                 title="<?= rr_h($tip) ?>">
              <div>
                <div class="d"><?= (int)date("j", $t) ?> · <?= rr_h(date("D", $t)) ?></div>
                <div class="v"><?= rr_vnd($vnd) ?></div>
                <?php if ($season_slug !== null && isset($season_by_slug[$season_slug])): ?>
                  <div class="s"><?= rr_h($season_by_slug[$season_slug]["display_name"]) ?></div>
                <?php endif; ?>
              </div>
              <?php if ($is_override): ?>
                <form method="post" style="margin:0;">
                  <input type="hidden" name="action"    value="clear_one">
                  <input type="hidden" name="room_slug" value="<?= rr_h($current_slug) ?>">
                  <input type="hidden" name="date"      value="<?= rr_h($d) ?>">
                  <button class="x" type="submit" title="Clear override (back to default)">×</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <p class="rr-help">
      Showing <?= rr_h($from_ymd) ?> → <?= rr_h($to_ymd) ?>.
      Need to look further out? Add <code>?from=YYYY-MM-DD&amp;days=180</code> to the URL.
    </p>
  <?php endif; ?>
</main>
</body>
</html>

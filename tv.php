<?php
/*
 * KnK Inn — /tv.php  (combined bar TV)
 *
 * The single-screen experience for the TVs around the bar:
 *
 *   ┌──────────┬──────────────────────────────┬──────────┐
 *   │ Jukebox  │   Beer Stock Market          │  Darts   │
 *   │  (left)  │         (centre, biggest)    │ (stacked │
 *   │          │                              │  right)  │
 *   └──────────┴──────────────────────────────┴──────────┘
 *
 * All three panels are ALWAYS visible — the layout never reflows.
 * If a feature is "off" (kill switch flipped, or just nothing
 * happening), the panel shows a friendly fallback card instead of
 * disappearing:
 *
 *   - Market    : if disabled / no eligible drinks → "Market closed"
 *                 / "Warming up" message in the same panel.
 *   - Jukebox   : if nothing playing AND queue empty → "ON THE RADIO"
 *                 (Triple J · Australia) advert card with a hint to
 *                 request a song. Same fallback when kill-switched.
 *   - Darts     : if no boards mid-game → "Waiting for players"
 *                 advert card with a hint to start a game. Same
 *                 fallback when kill-switched.
 *
 * The standalone TV pages stay around for setups that want a single
 * focused view per screen:
 *   /market.php             — Big Board only
 *   /jukebox-player.php     — Jukebox only (also has the audio fallback)
 *   /darts.php?board=N      — single dartboard scoreboard
 *
 * No auth — kiosk page on a Chrome window in full-screen, same as
 * the existing TV pages. Read-only.
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/market_engine.php";
require_once __DIR__ . "/includes/orders_store.php";   // knk_vnd()
require_once __DIR__ . "/includes/jukebox.php";
require_once __DIR__ . "/includes/darts.php";

/* =============================================================
 * Initial server-side state — so the first paint is correct
 * (no flash of empty panels while JS warms up).
 * =========================================================== */

/* ---- Market ---- */
$market_enabled = knk_market_enabled();
$market_cfg     = knk_market_config();
$market_poll    = $market_enabled ? max(2, (int)$market_cfg["board_poll_seconds"]) : 60;

$market_initial = ["items" => [], "any_crash" => false, "crash_names" => []];
if ($market_enabled) {
    foreach (knk_market_board_items() as $row) {
        $code  = (string)$row["item_code"];
        $q     = knk_market_quote($code);
        $base  = (int)$q["base_price_vnd"];
        $price = (int)$q["price_vnd"];
        $pct   = $base > 0 ? (int)round(($price - $base) * 100 / $base) : 0;
        $market_initial["items"][] = [
            "item_code"      => $code,
            "name"           => (string)$row["name"],
            "price_vnd"      => $price,
            "base_price_vnd" => $base,
            "pct_vs_base"    => $pct,
            "trend"          => knk_market_trend($code, 900),
            "in_crash"       => (bool)$q["in_crash"],
        ];
        if ($q["in_crash"]) {
            $market_initial["any_crash"]    = true;
            $market_initial["crash_names"][] = (string)$row["name"];
        }
    }
}
$market_band      = knk_market_band_active();
$market_band_lbl  = knk_market_band_label($market_band["band"]);

/* ---- Jukebox ---- */
$jbx_cfg       = knk_jukebox_config();
$jbx_enabled   = !empty($jbx_cfg["enabled"]);
$jbx_poll      = $jbx_enabled ? max(2, (int)$jbx_cfg["board_poll_seconds"]) : 60;
$jbx_now       = $jbx_enabled ? knk_jukebox_now_playing() : null;
$jbx_up_next   = $jbx_enabled ? knk_jukebox_up_next(5) : [];
/* Build the request URL for the radio fallback card so we can show
 * patrons where to send their song request when nothing is queued. */
$_scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
$_host   = (string)($_SERVER["HTTP_HOST"] ?? "knkinn.com");
$JUKEBOX_REQUEST_URL = $_scheme . "://" . $_host . "/jukebox.php";

/* ---- Darts ---- */
$darts_cfg     = knk_darts_config();
$darts_enabled = !empty($darts_cfg["enabled"]);
$darts_poll    = $darts_enabled ? max(2, (int)$darts_cfg["poll_seconds"]) : 60;
$darts_games   = [];
if ($darts_enabled) {
    $boards_by_id = [];
    foreach (knk_darts_boards() as $b) $boards_by_id[(int)$b["id"]] = $b;
    $st = knk_db()->query(
        "SELECT id, board_id, game_type, format, current_slot_no, state_json
           FROM darts_games
          WHERE status = 'playing'
          ORDER BY id DESC"
    );
    foreach ($st->fetchAll() as $g) {
        $bid = (int)$g["board_id"];
        if (!isset($boards_by_id[$bid])) continue;
        $players = knk_darts_load_players((int)$g["id"]);
        $sb      = $g["state_json"] ? json_decode((string)$g["state_json"], true) : null;
        $type    = (string)$g["game_type"];
        $format  = (string)$g["format"];
        $current = $g["current_slot_no"] !== null ? (int)$g["current_slot_no"] : null;
        $rows = [];
        foreach ($players as $p) {
            $slot = (int)$p["slot_no"];
            $rows[] = [
                "slot_no"   => $slot,
                "name"      => (string)$p["name"],
                "headline"  => knk_tv_darts_headline_inline($type, $format, $sb, $slot),
                "is_active" => ($current !== null && $slot === $current),
            ];
        }
        $darts_games[] = [
            "board_id"   => $bid,
            "board_name" => (string)$boards_by_id[$bid]["name"],
            "game_id"    => (int)$g["id"],
            "game_type"  => $type,
            "rows"       => $rows,
        ];
    }
}
/* The URL on the bar QR codes / "scan to play" cards for darts.
 * Falls back to /darts.php which lists the boards. */
$DARTS_LOBBY_URL = $_scheme . "://" . $_host . "/darts.php";

/* ---- Helpers ---- */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function knk_tv_arrow(string $t): string {
    if ($t === "up")   return "&#x25B2;";
    if ($t === "down") return "&#x25BC;";
    return "&ndash;";
}
function knk_tv_trend_class(string $t): string {
    if ($t === "up")   return "up";
    if ($t === "down") return "down";
    return "flat";
}
/* Display label for game type — terse so it fits on the TV. */
function knk_tv_game_label(string $type): string {
    if ($type === "501")          return "501";
    if ($type === "301")          return "301";
    if ($type === "cricket")      return "Cricket";
    if ($type === "aroundclock")  return "Around the Clock";
    if ($type === "killer")       return "Killer";
    if ($type === "halveit")      return "Halve It";
    return $type;
}
/* Inline copy of the API's headline picker so server-side first-paint
 * matches what the JS poll would render. Keep in sync with
 * /api/darts_live.php's knk_tv_darts_headline(). */
function knk_tv_darts_headline_inline(string $type, string $format, ?array $sb, int $slot): string {
    if (!is_array($sb)) return "—";
    if ($type === "501" || $type === "301") {
        if ($format === "doubles") {
            $tr = $sb["team_remaining"] ?? null;
            return is_array($tr) ? (string)min(array_map("intval", array_values($tr))) : "—";
        }
        $p = $sb["players"][$slot] ?? null;
        return is_array($p) ? (string)(int)($p["remaining"] ?? 0) : "—";
    }
    if ($type === "cricket") {
        if ($format === "doubles") {
            $ts = $sb["team_score"] ?? null;
            return is_array($ts) ? (string)max(array_map("intval", array_values($ts))) : "—";
        }
        $p = $sb["players"][$slot] ?? null;
        return is_array($p) ? (string)(int)($p["score"] ?? 0) : "—";
    }
    if ($type === "aroundclock") {
        $p = $sb["players"][$slot] ?? null;
        if (!is_array($p)) return "—";
        if (!empty($p["finished"])) return "DONE";
        $tgt = (int)($p["target"] ?? 1);
        return $tgt >= 21 ? "BULL" : (string)$tgt;
    }
    if ($type === "killer") {
        $p = $sb["players"][$slot] ?? null;
        if (!is_array($p)) return "—";
        if (!empty($p["eliminated"])) return "OUT";
        $lives = (int)($p["lives"] ?? 0);
        return !empty($p["killer"]) ? "K" . $lives : (string)$lives;
    }
    if ($type === "halveit") {
        $p = $sb["players"][$slot] ?? null;
        return is_array($p) ? (string)(int)($p["score"] ?? 0) : "—";
    }
    return "—";
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>KnK Inn — Bar TV</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  /* ============================================================
   * Bar TV layout. Designed for a 16:9 Chrome window in full-screen.
   * Avoid scrollbars at all costs — everything must fit.
   * ========================================================== */
  :root {
    --bg:        #0f0905;
    --bg2:       #1b0f04;
    --fg:        #f5e9d1;
    --gold:      #c9aa71;
    --muted:     #8a7858;
    --line:      rgba(201,170,113,0.22);
    --up:        #20c97a;
    --down:      #e4564a;
    --flat:      #8a7858;
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; height: 100vh; overflow: hidden; }
  body {
    background: var(--bg);
    color: var(--fg);
    font-family: "Inter", system-ui, sans-serif;
    display: grid;
    grid-template-rows: auto 1fr;
  }

  /* ---- Header strip ---- */
  .tv-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.6rem 1.2rem;
    background: var(--bg2);
    border-bottom: 1px solid var(--line);
  }
  .tv-bar .brand {
    font-family: "Archivo Black", sans-serif;
    font-size: 1.3rem; letter-spacing: 0.04em;
  }
  .tv-bar .brand em { color: var(--gold); font-style: normal; }
  .tv-bar .clock {
    font-variant-numeric: tabular-nums;
    color: var(--gold); font-weight: 700;
    font-size: 1.1rem; letter-spacing: 0.06em;
  }

  /* ---- Main grid (the panels) ----
   * Always 3-up: jukebox | market | darts. Panels never disappear;
   * they show a fallback card when their feature is idle or off. */
  .tv-main {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 2fr) minmax(0, 1fr);
    gap: 0;
    min-height: 0;
  }

  .panel {
    border-left: 1px solid var(--line);
    padding: 1rem 1.1rem;
    min-height: 0; min-width: 0;
    display: flex; flex-direction: column;
  }
  .panel:first-child { border-left: 0; }
  .panel h2 {
    font-family: "Archivo Black", sans-serif;
    font-size: 1.05rem; letter-spacing: 0.08em; text-transform: uppercase;
    color: var(--gold);
    margin: 0 0 0.6rem;
  }

  /* ============================================================
   * Market panel (centre)
   * ========================================================== */
  .panel-market { background: linear-gradient(180deg, #1b0f04 0%, #0f0905 100%); }
  .market-band {
    display: flex; align-items: baseline; gap: 0.6rem;
    margin: -0.2rem 0 0.6rem;
  }
  .market-band .label {
    font-family: "Archivo Black", sans-serif;
    font-size: 1.4rem; color: var(--gold);
  }
  .market-band .sub { color: var(--muted); font-size: 0.95rem; }
  .market-table {
    flex: 1 1 auto;
    overflow: hidden;     /* never scroll on the TV */
    display: flex; flex-direction: column;
    font-variant-numeric: tabular-nums;
  }
  .market-row {
    display: grid;
    grid-template-columns: 1fr 7rem 6rem 3rem;
    align-items: center;
    gap: 0.6rem;
    padding: 0.6rem 0.2rem;
    border-bottom: 1px solid var(--line);
  }
  .market-row.head {
    color: var(--muted); font-size: 0.78rem;
    text-transform: uppercase; letter-spacing: 0.1em;
    border-bottom: 2px solid var(--line);
    padding: 0.4rem 0.2rem;
  }
  .market-row .name { font-weight: 700; font-size: 1.5rem; }
  .market-row .name small { color: var(--muted); font-weight: 400; font-size: 0.7em; display: block; }
  .market-row .price { text-align: right; font-size: 1.6rem; font-weight: 700; }
  .market-row .pct { text-align: right; font-size: 1.2rem; font-weight: 700; }
  .market-row .arrow { text-align: center; font-size: 1.3rem; }
  .market-row.up    .price, .market-row.up    .pct, .market-row.up    .arrow { color: var(--up); }
  .market-row.down  .price, .market-row.down  .pct, .market-row.down  .arrow { color: var(--down); }
  .market-row.flat  .price, .market-row.flat  .pct, .market-row.flat  .arrow { color: var(--flat); }
  .market-row.crash {
    background: rgba(228,86,74,0.12);
    border-left: 4px solid var(--down);
    padding-left: 0.5rem;
  }
  .market-row.crash .name::after {
    content: " CRASH";
    color: var(--down);
    font-family: "Archivo Black", sans-serif;
    font-size: 0.7em;
    margin-left: 0.5rem;
    letter-spacing: 0.1em;
  }
  .market-empty {
    padding: 2rem; text-align: center;
    color: var(--muted); font-size: 1.2rem;
  }

  /* ============================================================
   * Jukebox panel (left)
   * ========================================================== */
  .panel-jukebox { background: #0f0905; }
  .jbx-now {
    display: flex; flex-direction: column;
    gap: 0.4rem;
    padding-bottom: 0.8rem;
    border-bottom: 1px solid var(--line);
    margin-bottom: 0.8rem;
  }
  .jbx-now .thumb {
    width: 100%; aspect-ratio: 16/9;
    background: #000;
    border-radius: 6px;
    object-fit: cover;
  }
  .jbx-now .meta-label {
    font-size: 0.72rem; letter-spacing: 0.12em; text-transform: uppercase;
    color: var(--gold); font-weight: 700;
  }
  .jbx-now .title {
    font-family: "Archivo Black", sans-serif;
    font-size: 1.2rem; line-height: 1.2;
  }
  .jbx-now .channel { color: var(--muted); font-size: 0.9rem; }
  .jbx-now .who { color: var(--gold); font-size: 0.95rem; font-weight: 600; }
  /* "ON THE RADIO" fallback — shown when nothing is playing or queued
   * (and also when the kill switch is off). Mirrors the bigger overlay
   * on /jukebox-player.php in spirit, but compact for the side panel. */
  .jbx-radio {
    display: flex; flex-direction: column; align-items: center;
    gap: 0.4rem;
    padding: 1.4rem 0.6rem;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: linear-gradient(180deg, #1b0f04 0%, #0f0905 100%);
    text-align: center;
  }
  .jbx-radio .pulse {
    font-size: 2.4rem;
    animation: jbx-pulse 2s ease-in-out infinite;
  }
  @keyframes jbx-pulse {
    0%, 100% { transform: scale(1);    opacity: 0.85; }
    50%      { transform: scale(1.12); opacity: 1; }
  }
  .jbx-radio h3 {
    font-family: "Archivo Black", sans-serif;
    font-size: 1.25rem; letter-spacing: 0.08em;
    margin: 0; color: var(--fg);
  }
  .jbx-radio h3 .accent { color: var(--gold); }
  .jbx-radio .station {
    color: var(--gold); font-size: 0.95rem; font-weight: 600;
    letter-spacing: 0.04em;
  }
  .jbx-radio .hint {
    color: var(--muted); font-size: 0.82rem;
    line-height: 1.4; margin-top: 0.2rem;
  }
  .jbx-radio .hint .url {
    color: var(--fg); font-weight: 600;
  }
  .jbx-up h3 {
    font-size: 0.78rem; letter-spacing: 0.12em; text-transform: uppercase;
    color: var(--gold); margin: 0 0 0.4rem;
  }
  .jbx-up ol { list-style: none; margin: 0; padding: 0; }
  .jbx-up li {
    padding: 0.45rem 0;
    border-bottom: 1px solid var(--line);
    display: grid; grid-template-columns: 1.4rem 1fr; gap: 0.4rem;
    font-size: 0.95rem;
  }
  .jbx-up li:last-child { border-bottom: 0; }
  .jbx-up li .num { color: var(--muted); font-weight: 700; }
  .jbx-up li .t { font-weight: 600; line-height: 1.2; }
  .jbx-up li .who { color: var(--muted); font-size: 0.78rem; display: block; margin-top: 2px; }

  /* ============================================================
   * Darts panel (right)
   * ========================================================== */
  .panel-darts { background: #0f0905; }
  .darts-stack {
    flex: 1 1 auto; min-height: 0;
    display: flex; flex-direction: column;
    gap: 0.7rem;
    overflow: hidden;
  }
  .darts-card {
    flex: 1 1 0;             /* split equally between live games */
    min-height: 0;
    display: flex; flex-direction: column;
    background: #1b0f04;
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0.7rem 0.85rem;
  }
  .darts-card .head {
    display: flex; justify-content: space-between; align-items: baseline;
    border-bottom: 1px solid var(--line);
    padding-bottom: 0.35rem; margin-bottom: 0.5rem;
  }
  .darts-card .head .board {
    font-family: "Archivo Black", sans-serif;
    font-size: 1rem; color: var(--gold); letter-spacing: 0.04em;
  }
  .darts-card .head .gtype {
    font-size: 0.78rem; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.08em;
  }
  .darts-rows {
    flex: 1 1 auto; min-height: 0;
    display: flex; flex-direction: column;
    justify-content: space-around;
    font-variant-numeric: tabular-nums;
  }
  .darts-row {
    display: grid; grid-template-columns: 1fr auto;
    align-items: center;
    padding: 0.18rem 0;
  }
  .darts-row .name {
    font-weight: 600; font-size: 1.05rem;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .darts-row .score {
    font-family: "Archivo Black", sans-serif;
    font-size: 1.5rem; color: var(--fg);
  }
  .darts-row.active .name { color: var(--gold); }
  .darts-row.active .score { color: var(--gold); }
  .darts-row.active .name::before {
    content: "▸ "; color: var(--gold);
  }
  /* "Waiting for players" advert — shown when no boards are mid-game,
   * or when the darts kill switch is off. Same visual weight as a
   * single live card so the panel doesn't look broken. */
  .darts-empty {
    flex: 1 1 auto; min-height: 0;
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; gap: 0.5rem;
    text-align: center;
    background: linear-gradient(180deg, #1b0f04 0%, #0f0905 100%);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 1.4rem 0.8rem;
  }
  .darts-empty .pulse {
    font-size: 2.6rem;
    animation: jbx-pulse 2.4s ease-in-out infinite;
  }
  .darts-empty h3 {
    font-family: "Archivo Black", sans-serif;
    font-size: 1.15rem; letter-spacing: 0.06em;
    color: var(--fg); margin: 0;
  }
  .darts-empty h3 .accent { color: var(--gold); }
  .darts-empty .sub {
    color: var(--gold); font-weight: 600; font-size: 0.92rem;
  }
  .darts-empty .hint {
    color: var(--muted); font-size: 0.82rem;
    line-height: 1.4; max-width: 16rem;
  }
  .darts-empty .hint .url {
    color: var(--fg); font-weight: 600;
  }

  /* ============================================================
   * Footer crash banner — only when something crashed
   * ========================================================== */
  .crash-banner {
    background: var(--down); color: #fff;
    padding: 0.5rem 1rem;
    font-family: "Archivo Black", sans-serif;
    text-align: center;
    letter-spacing: 0.06em;
  }
  .crash-banner.is-hidden { display: none; }
</style>
</head>
<body class="tv">

<header class="tv-bar">
  <div class="brand">KnK <em>Inn</em></div>
  <div class="clock" id="tv-clock">--:--</div>
</header>

<main class="tv-main">

  <!-- =========================================================
       Jukebox panel (left)
       ======================================================= -->
  <section class="panel panel-jukebox" id="panel-jukebox" aria-label="Jukebox">
    <h2>🎵 Jukebox</h2>

    <?php
    /* Decide first paint:
     *   - If a track is playing, show "now playing" + up-next list.
     *   - If queue has tracks but nothing's playing yet, show up-next.
     *   - Otherwise (idle OR kill switch off) show the radio card. */
    $jbx_show_radio = ($jbx_now === null && empty($jbx_up_next));
    ?>

    <div class="jbx-now" id="jbx-now"<?= $jbx_now ? "" : " hidden" ?>>
      <?php if ($jbx_now): ?>
        <img class="thumb" src="<?= h($jbx_now["thumbnail_url"] ?? "") ?>" alt="">
        <div class="meta-label">Now playing</div>
        <div class="title"><?= h($jbx_now["youtube_title"] ?? "") ?></div>
        <div class="channel"><?= h($jbx_now["youtube_channel"] ?? "") ?></div>
        <?php $rn = trim((string)($jbx_now["requester_name"] ?? "")); ?>
        <?php if ($rn !== ""): ?>
          <div class="who">Requested by <?= h($rn) ?></div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="jbx-radio" id="jbx-radio"<?= $jbx_show_radio ? "" : " hidden" ?>>
      <div class="pulse">📻</div>
      <h3>ON THE <span class="accent">RADIO</span></h3>
      <div class="station">Triple J · Australia</div>
      <div class="hint">Request a song at<br><span class="url"><?= h($_host) ?>/jukebox.php</span></div>
    </div>

    <div class="jbx-up" id="jbx-up"<?= empty($jbx_up_next) ? " hidden" : "" ?>>
      <h3>Up next</h3>
      <ol id="jbx-up-list">
        <?php foreach ($jbx_up_next as $i => $u): ?>
          <li>
            <span class="num"><?= ($i + 1) ?>.</span>
            <span>
              <span class="t"><?= h($u["youtube_title"] ?? "") ?></span>
              <?php $rn2 = trim((string)($u["requester_name"] ?? "")); ?>
              <?php if ($rn2 !== ""): ?>
                <span class="who"><?= h($rn2) ?></span>
              <?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </section>

  <!-- =========================================================
       Market panel (centre, always shown)
       ======================================================= -->
  <section class="panel panel-market" aria-label="Beer Stock Market">
    <h2>📈 Beer Stock Market</h2>

    <div class="market-band" id="mkt-band">
      <span class="label" id="mkt-band-label"><?= h($market_band_lbl) ?></span>
      <span class="sub">live drink prices</span>
    </div>

    <div class="market-table" id="mkt-table">
      <?php if (!$market_enabled): ?>
        <div class="market-empty">Market closed — back on menu prices.</div>
      <?php elseif (empty($market_initial["items"])): ?>
        <div class="market-empty">Warming up — drinks need a few orders to start trading.</div>
      <?php else: ?>
        <div class="market-row head">
          <span>Drink</span>
          <span style="text-align:right;">Price</span>
          <span style="text-align:right;">Move</span>
          <span></span>
        </div>
        <?php foreach ($market_initial["items"] as $it):
          $cls = knk_tv_trend_class((string)$it["trend"]);
          if ($it["in_crash"]) $cls .= " crash";
        ?>
          <div class="market-row <?= h($cls) ?>" data-code="<?= h($it["item_code"]) ?>">
            <span class="name"><?= h($it["name"]) ?></span>
            <span class="price"><?= number_format($it["price_vnd"], 0, ".", ",") ?>₫</span>
            <span class="pct"><?= ($it["pct_vs_base"] >= 0 ? "+" : "") . (int)$it["pct_vs_base"] ?>%</span>
            <span class="arrow"><?= knk_tv_arrow((string)$it["trend"]) ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

  <!-- =========================================================
       Darts panel (right)
       ======================================================= -->
  <section class="panel panel-darts" id="panel-darts" aria-label="Darts">
    <h2>🎯 Darts</h2>

    <div class="darts-stack" id="darts-stack">
      <?php if (empty($darts_games)): ?>
        <div class="darts-empty" id="darts-empty">
          <div class="pulse">🎯</div>
          <h3>WAITING FOR <span class="accent">PLAYERS</span></h3>
          <div class="sub">Boards are free — grab some darts.</div>
          <div class="hint">Start a game at<br><span class="url"><?= h($_host) ?>/darts.php</span></div>
        </div>
      <?php else: ?>
        <?php foreach ($darts_games as $g): ?>
          <div class="darts-card" data-game-id="<?= (int)$g["game_id"] ?>">
            <div class="head">
              <span class="board"><?= h($g["board_name"]) ?></span>
              <span class="gtype"><?= h(knk_tv_game_label((string)$g["game_type"])) ?></span>
            </div>
            <div class="darts-rows">
              <?php foreach ($g["rows"] as $r): ?>
                <div class="darts-row<?= !empty($r["is_active"]) ? " active" : "" ?>">
                  <span class="name"><?= h($r["name"]) ?></span>
                  <span class="score"><?= h($r["headline"]) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

</main>

<div class="crash-banner is-hidden" id="crash-banner"></div>

<script>
/* ============================================================
 * Bar TV polling — three panels, three independent intervals.
 *
 * All three panels are ALWAYS visible. When a panel has nothing
 * to show (empty queue, no live darts games, kill switch off),
 * each render() function paints a fallback card — the radio card
 * for jukebox, the "waiting for players" card for darts. The
 * layout never reflows.
 *
 * Initial state was server-rendered above, so this script's job
 * is to keep it fresh, not to bootstrap from blank.
 * ========================================================== */

(function () {
  "use strict";

  // ----- Clock in the header strip -----
  function tickClock() {
    var d = new Date();
    var hh = String(d.getHours()).padStart(2, "0");
    var mm = String(d.getMinutes()).padStart(2, "0");
    document.getElementById("tv-clock").textContent = hh + ":" + mm;
  }
  tickClock();
  setInterval(tickClock, 30 * 1000);

  // ----- Helpers -----
  // Hostname for fallback URL hints — matches what PHP rendered.
  var TV_HOST = location.host;

  function vnd(n) {
    n = parseInt(n, 10) || 0;
    return n.toLocaleString("en-US") + "\u20AB";
  }
  function arrow(t) { return t === "up" ? "\u25B2" : t === "down" ? "\u25BC" : "\u2013"; }
  function escapeHtml(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }

  // ============================================================
  // MARKET (centre — always visible, but state can be "closed" or "empty")
  // ============================================================
  var MARKET_POLL = <?= (int)$market_poll * 1000 ?>;

  function pollMarket() {
    fetch("/api/market_state.php", { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(renderMarket)
      .catch(function () { /* keep last frame on the screen */ });
  }
  function renderMarket(s) {
    if (!s) return;
    document.getElementById("mkt-band-label").textContent =
      (s.band && s.band.label) ? s.band.label : "—";

    var table = document.getElementById("mkt-table");
    if (!s.enabled) {
      table.innerHTML = '<div class="market-empty">Market closed — back on menu prices.</div>';
    } else if (!s.items || !s.items.length) {
      table.innerHTML = '<div class="market-empty">Warming up — drinks need a few orders to start trading.</div>';
    } else {
      var rows = ['<div class="market-row head">' +
        '<span>Drink</span>' +
        '<span style="text-align:right;">Price</span>' +
        '<span style="text-align:right;">Move</span>' +
        '<span></span></div>'];
      s.items.forEach(function (it) {
        var cls = it.trend === "up" ? "up" : it.trend === "down" ? "down" : "flat";
        if (it.in_crash) cls += " crash";
        var pct = (it.pct_vs_base >= 0 ? "+" : "") + (it.pct_vs_base | 0) + "%";
        rows.push(
          '<div class="market-row ' + cls + '" data-code="' + escapeHtml(it.item_code) + '">' +
            '<span class="name">' + escapeHtml(it.name) + '</span>' +
            '<span class="price">' + vnd(it.price_vnd) + '</span>' +
            '<span class="pct">' + pct + '</span>' +
            '<span class="arrow">' + arrow(it.trend) + '</span>' +
          '</div>'
        );
      });
      table.innerHTML = rows.join("");
    }

    var banner = document.getElementById("crash-banner");
    if (s.any_crash && s.crash_names && s.crash_names.length) {
      banner.textContent = "Crash! " + s.crash_names.join(", ") + " — grab them before they bounce back.";
      banner.classList.remove("is-hidden");
    } else {
      banner.classList.add("is-hidden");
    }

    if (typeof s.poll_seconds === "number" && s.poll_seconds * 1000 !== MARKET_POLL) {
      MARKET_POLL = Math.max(2000, s.poll_seconds * 1000);
    }
  }

  // ============================================================
  // JUKEBOX (left)
  // ============================================================
  var JBX_POLL = <?= (int)$jbx_poll * 1000 ?>;

  function pollJukebox() {
    fetch("/api/jukebox_state.php", { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(renderJukebox)
      .catch(function () { /* keep last frame */ });
  }
  function renderJukebox(s) {
    if (!s) return;

    var nowEl   = document.getElementById("jbx-now");
    var radioEl = document.getElementById("jbx-radio");
    var upEl    = document.getElementById("jbx-up");
    var ol      = document.getElementById("jbx-up-list");

    var hasNow  = !!s.now_playing;
    var upNext  = (s.up_next || []).slice(0, 5);
    var hasUp   = upNext.length > 0;
    /* Radio card shows when nothing is playing and queue is empty —
     * which is also the case when the kill switch is off (the API
     * returns an empty state in that scenario). */
    var showRadio = !hasNow && !hasUp;

    if (hasNow) {
      var n = s.now_playing;
      var who = n.name ? '<div class="who">Requested by ' + escapeHtml(n.name) + '</div>' : '';
      nowEl.innerHTML =
        '<img class="thumb" src="' + escapeHtml(n.thumb || "") + '" alt="">' +
        '<div class="meta-label">Now playing</div>' +
        '<div class="title">'   + escapeHtml(n.title   || "") + '</div>' +
        '<div class="channel">' + escapeHtml(n.channel || "") + '</div>' +
        who;
      nowEl.hidden = false;
    } else {
      nowEl.innerHTML = "";
      nowEl.hidden = true;
    }

    radioEl.hidden = !showRadio;

    if (hasUp) {
      var html = "";
      upNext.forEach(function (u, i) {
        var who = u.name ? '<span class="who">' + escapeHtml(u.name) + '</span>' : '';
        html +=
          '<li>' +
            '<span class="num">' + (i + 1) + '.</span>' +
            '<span>' +
              '<span class="t">' + escapeHtml(u.title || "") + '</span>' +
              who +
            '</span>' +
          '</li>';
      });
      ol.innerHTML = html;
      upEl.hidden = false;
    } else {
      ol.innerHTML = "";
      upEl.hidden = true;
    }

    if (typeof s.poll_seconds === "number" && s.poll_seconds * 1000 !== JBX_POLL) {
      JBX_POLL = Math.max(2000, s.poll_seconds * 1000);
    }
  }

  // ============================================================
  // DARTS (right) — stacked cards, one per playing game.
  // ============================================================
  var DARTS_POLL = <?= (int)$darts_poll * 1000 ?>;

  var GAME_LABELS = {
    "501": "501", "301": "301",
    "cricket": "Cricket", "aroundclock": "Around the Clock",
    "killer": "Killer", "halveit": "Halve It"
  };

  function pollDarts() {
    fetch("/api/darts_live.php", { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(renderDarts)
      .catch(function () { /* keep last frame */ });
  }
  function renderDarts(s) {
    if (!s) return;
    var games = (s && s.games) || [];
    var stack = document.getElementById("darts-stack");
    var html = "";

    if (games.length === 0) {
      /* No live games — paint the "Waiting for players" advert.
       * Same fallback whether boards are simply free or the kill
       * switch is off and the API returned an empty list. */
      html =
        '<div class="darts-empty" id="darts-empty">' +
          '<div class="pulse">🎯</div>' +
          '<h3>WAITING FOR <span class="accent">PLAYERS</span></h3>' +
          '<div class="sub">Boards are free — grab some darts.</div>' +
          '<div class="hint">Start a game at<br>' +
            '<span class="url">' + escapeHtml(TV_HOST) + '/darts.php</span>' +
          '</div>' +
        '</div>';
    } else {
      games.forEach(function (g) {
        var rows = "";
        (g.rows || []).forEach(function (r) {
          rows +=
            '<div class="darts-row' + (r.is_active ? " active" : "") + '">' +
              '<span class="name">'  + escapeHtml(r.name)     + '</span>' +
              '<span class="score">' + escapeHtml(r.headline) + '</span>' +
            '</div>';
        });
        var label = GAME_LABELS[g.game_type] || g.game_type;
        html +=
          '<div class="darts-card" data-game-id="' + (g.game_id | 0) + '">' +
            '<div class="head">' +
              '<span class="board">' + escapeHtml(g.board_name) + '</span>' +
              '<span class="gtype">' + escapeHtml(label)        + '</span>' +
            '</div>' +
            '<div class="darts-rows">' + rows + '</div>' +
          '</div>';
      });
    }
    stack.innerHTML = html;

    if (typeof s.poll_seconds === "number" && s.poll_seconds * 1000 !== DARTS_POLL) {
      DARTS_POLL = Math.max(2000, s.poll_seconds * 1000);
    }
  }

  // ============================================================
  // Drive each panel on its own (recursive setTimeout — keeps
  // intervals in sync with whatever poll_seconds the API returns).
  // ============================================================
  function loop(fn, getDelay) {
    fn();
    setTimeout(function () { loop(fn, getDelay); }, getDelay());
  }
  loop(pollMarket,  function () { return MARKET_POLL; });
  loop(pollJukebox, function () { return JBX_POLL;    });
  loop(pollDarts,   function () { return DARTS_POLL;  });

})();
</script>
</body>
</html>

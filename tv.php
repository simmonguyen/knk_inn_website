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
require_once __DIR__ . "/includes/settings_store.php";

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

/* Radio fallback (migration 009). Always upgrade http:// to https://
 * so the browser doesn't block mixed content on the live site. */
$radio_enabled = !empty($jbx_cfg["radio_enabled"]);
$radio_url     = (string)($jbx_cfg["radio_url"] ?? "");
if ($radio_url !== "" && stripos($radio_url, "http://") === 0) {
    $radio_url = "https://" . substr($radio_url, 7);
}

/* The "Request a song at..." pointer on the splash card. Points
 * at /bar.php (the unified bar shell) rather than /jukebox.php so
 * one URL covers music + drinks + darts. Display without the
 * scheme — bar punters don't need to see "https://". */
$_scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
$_host   = (string)($_SERVER["HTTP_HOST"] ?? "knkinn.com");
$JUKEBOX_REQUEST_URL = $_host . "/bar.php";

/* Audio-engine bootstrap payload — same shape as /jukebox-player.php
 * uses, so the JS audio loop is a near-copy of that page's. */
$jbx_initial = [
    "enabled"      => $jbx_enabled,
    "poll_seconds" => $jbx_poll,
    "radio"        => [
        "enabled" => $radio_enabled,
        "url"     => $radio_url,
    ],
    "now_playing"  => $jbx_now ? [
        "id"       => (int)$jbx_now["id"],
        "video_id" => (string)$jbx_now["youtube_video_id"],
        "title"    => (string)$jbx_now["youtube_title"],
        "channel"  => (string)$jbx_now["youtube_channel"],
        "duration" => (int)$jbx_now["duration_seconds"],
        "thumb"    => (string)$jbx_now["thumbnail_url"],
        "name"     => (string)$jbx_now["requester_name"],
        "table_no" => (string)$jbx_now["table_no"],
    ] : null,
    "up_next"      => array_map(function ($r) {
        return [
            "id"       => (int)$r["id"],
            "video_id" => (string)$r["youtube_video_id"],
            "title"    => (string)$r["youtube_title"],
            "channel"  => (string)$r["youtube_channel"],
            "duration" => (int)$r["duration_seconds"],
            "thumb"    => (string)$r["thumbnail_url"],
            "name"     => (string)$r["requester_name"],
            "table_no" => (string)$r["table_no"],
        ];
    }, $jbx_up_next),
];

/* ---- Darts ---- */
$darts_cfg     = knk_darts_config();
$darts_enabled = !empty($darts_cfg["enabled"]);
$darts_poll    = $darts_enabled ? max(2, (int)$darts_cfg["poll_seconds"]) : 60;
$darts_loud    = knk_setting_bool("darts_loud_mode", true);
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
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
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
  /* The native `hidden` attribute is `display: none`, but our flex/grid
   * panels use `display: flex` which has higher specificity and beats it.
   * Force the attribute back to actually hiding things — without this,
   * a "line above Radio box" leaks through when .jbx-now is meant to be
   * hidden (the panel's border-bottom remains rendered). */
  [hidden] { display: none !important; }
  html, body {
    margin: 0; padding: 0;
    width: 100vw; height: 100vh;
    max-width: 100vw;
    overflow: hidden;
  }
  body {
    background: var(--bg);
    color: var(--fg);
    font-family: "Inter", system-ui, sans-serif;
    display: grid;
    /* Single column, viewport-wide. Without explicit columns AND
     * min-width:0 on body, a grid item with wide intrinsic content
     * (e.g. a long ticker string or an oversized YouTube iframe)
     * can blow the body out past 100vw. We saw .tv-main computing
     * to 10456px on a 2576px screen — fixture strings in the
     * bottom ticker were the culprit. */
    grid-template-columns: minmax(0, 100vw);
    min-width: 0;
    /* Body grid: 4 explicit rows. We pin each child to its row by
     * `grid-row` below — DON'T rely on auto-placement. Browsers
     * disagree on whether <audio> without controls claims a grid
     * row (Chromium: display:none → no; Firefox: yes), and that
     * disagreement was sliding the 1fr off main and onto footer,
     * collapsing the 3-up panels so the jukebox column visually
     * stretched across the screen.
     *   row 1: crash strip (auto, collapses when no crash)
     *   row 2: header strip
     *   row 3: 3-up panels (1fr — takes all remaining vertical space)
     *   row 4: footer (lyrics / sports ticker) */
    grid-template-rows: auto auto 1fr auto;
  }
  /* Pin every body grid child to its row explicitly. Anything not
   * listed here (audio, splash, sync toast) gets position:fixed
   * or display:none so it never participates in grid track sizing. */
  body > .tv-crashbar { grid-row: 1; }
  body > .tv-bar      { grid-row: 2; }
  body > .tv-main     { grid-row: 3; }
  body > .tv-footer   { grid-row: 4; }
  /* Force the radio audio fallback out of grid flow regardless of
   * the browser's default audio:not([controls]) rule. */
  body > audio#tv-radio {
    position: absolute;
    width: 0; height: 0;
    visibility: hidden;
  }

  /* ---- Header strip ---- */
  .tv-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.4rem 1rem;
    background: var(--bg2);
    border-bottom: 1px solid var(--line);
  }
  .tv-bar .brand {
    font-family: "Archivo Black", sans-serif;
    font-size: 1.05rem; letter-spacing: 0.04em;
  }
  .tv-bar .brand em { color: var(--gold); font-style: normal; }
  .tv-bar .clock {
    font-variant-numeric: tabular-nums;
    color: var(--gold); font-weight: 700;
    font-size: 0.95rem; letter-spacing: 0.06em;
  }

  /* ---- Main grid (the panels) ----
   * Always 3-up: jukebox | market | darts. Panels never disappear;
   * they show a fallback card when their feature is idle or off. */
  .tv-main {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 2fr) minmax(0, 1fr);
    gap: 0;
    min-height: 0;
    min-width: 0;       /* let the grid item shrink even if a child
                           has a wider intrinsic min-content size */
    overflow: hidden;   /* belt-and-braces: clip anything that
                           still tries to push past the column */
  }

  .panel {
    border-left: 1px solid var(--line);
    padding: 0.7rem 0.85rem;
    min-height: 0; min-width: 0;
    display: flex; flex-direction: column;
  }
  .panel:first-child { border-left: 0; }
  .panel h2 {
    font-family: "Archivo Black", sans-serif;
    font-size: 0.85rem; letter-spacing: 0.08em; text-transform: uppercase;
    color: var(--gold);
    margin: 0 0 0.45rem;
  }

  /* ============================================================
   * Market panel (centre)
   * ========================================================== */
  .panel-market { background: linear-gradient(180deg, #1b0f04 0%, #0f0905 100%); }
  /* Header row: the section heading on the left, the band label
   * ("Off-peak / Peak / Custom — live drink prices") right-justified
   * on the same line. Replaces the old stacked layout where the
   * band sat below the heading. */
  .market-head {
    display: flex; align-items: baseline; justify-content: space-between;
    gap: 0.75rem;
    margin: 0 0 0.45rem;
  }
  .market-head h2 { margin: 0; }
  .market-band {
    display: flex; align-items: baseline; gap: 0.4rem;
    margin: 0;
  }
  .market-band .label {
    font-family: "Archivo Black", sans-serif;
    font-size: 0.78rem; color: var(--gold);
    letter-spacing: 0.04em;
  }
  .market-band .sub { color: var(--muted); font-size: 0.72rem; }
  .market-table {
    flex: 1 1 auto;
    overflow: hidden;     /* never scroll on the TV */
    display: flex; flex-direction: column;
    font-variant-numeric: tabular-nums;
  }
  /* Grid columns: name+spark (stacked) | price | move% | arrow.
   * Name sits on top, sparkline is stretched underneath it across
   * the whole first column so the chart isn't squashed into a
   * narrow side-cell. Mirrors /market.php's "stack" treatment. */
  .market-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 5.2rem 3.2rem 2rem;
    align-items: center;
    gap: 0.5rem;
    padding: 0.35rem 0.2rem;
    border-bottom: 1px solid var(--line);
  }
  .market-row.head {
    color: var(--muted); font-size: 0.65rem;
    text-transform: uppercase; letter-spacing: 0.1em;
    border-bottom: 2px solid var(--line);
    padding: 0.3rem 0.2rem;
  }
  /* First column: drink name on top, full-width sparkline below. */
  .market-row .name-cell {
    display: flex; flex-direction: column;
    min-width: 0;
    gap: 0.05rem;
  }
  .market-row .name { font-weight: 700; font-size: 1.1rem; line-height: 1.15; }
  .market-row .name small { color: var(--muted); font-weight: 400; font-size: 0.7em; display: block; }
  .market-row .price { text-align: right; font-size: 1.15rem; font-weight: 700; }
  .market-row .pct { text-align: right; font-size: 0.95rem; font-weight: 700; }
  .market-row .arrow { text-align: center; font-size: 1.05rem; }
  /* SVG sparkline — stretched across the full name column width. */
  .market-row .spark {
    height: 22px;
    width: 100%;
    display: block;
    overflow: hidden;
  }
  .market-row .spark svg { width: 100%; height: 100%; display: block; }
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
    padding: 1.4rem; text-align: center;
    color: var(--muted); font-size: 0.9rem;
  }

  /* ============================================================
   * Jukebox panel (left)
   * ========================================================== */
  .panel-jukebox { background: #0f0905; }
  .jbx-now {
    display: flex; flex-direction: column;
    gap: 0.15rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--line);
    margin-bottom: 0.5rem;
  }
  /* Visible YT player — replaces the static thumbnail. The browser
   * autoplay policy + iframe heuristics dislike off-screen 1×1 iframes,
   * so we put it where the user can see it. The audio is the main
   * point, but having a small video makes "stuck at startup" go away.
   *
   * Bled to true column width with negative margins matching the
   * panel's horizontal padding, so the video edges hit the column
   * edges instead of being inset by the panel's body padding. */
  .jbx-video {
    width: calc(100% + 1.7rem);
    margin-left: -0.85rem;
    margin-right: -0.85rem;
    margin-bottom: 0.45rem;
    aspect-ratio: 16/9;
    background: #000;
    border-radius: 0;
    overflow: hidden;
    position: relative;
  }
  /* Static thumb fallback — first paint, sits behind the YT iframe.
   * The iframe covers it once YT.Player has loaded the video, so
   * there's no layout shift when the player takes over. */
  .jbx-video .thumb-fallback {
    position: absolute; inset: 0;
    width: 100%; height: 100%;
    object-fit: cover;
    background: #000;
    z-index: 1;
  }
  /* The YT iframe sits ON TOP of the thumb. Before the API replaces
   * the div with an actual iframe, the div is empty/transparent so
   * the thumb shows through. */
  .jbx-video #tv-yt-player,
  .jbx-video #tv-yt-player iframe {
    position: absolute; inset: 0;
    width: 100%; height: 100%; border: 0; display: block;
    z-index: 2;
  }
  /* Slim now-playing strip: just artist/title + (optionally) the
   * requester. The "Now playing" label and YouTube channel were
   * dropped so the video can take the full column width and the
   * caption stays unobtrusive underneath it. */
  .jbx-now .title {
    font-size: 0.7rem;
    line-height: 1.25;
    color: var(--muted);
    font-weight: 500;
  }
  .jbx-now .who { color: var(--gold); font-size: 0.72rem; font-weight: 600; }
  /* "ON THE RADIO" fallback — shown when nothing is playing or queued
   * (and also when the kill switch is off). Mirrors the bigger overlay
   * on /jukebox-player.php in spirit, but compact for the side panel. */
  .jbx-radio {
    display: flex; flex-direction: column; align-items: center;
    gap: 0.3rem;
    padding: 1rem 0.5rem;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: linear-gradient(180deg, #1b0f04 0%, #0f0905 100%);
    text-align: center;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    transition: opacity 0.18s ease;
  }
  .jbx-radio:hover { border-color: rgba(201,170,113,0.55); }
  /* Muted state — user tapped the radio card to stop it. The pulse
     freezes, opacity drops, and a "tap to resume" hint appears. */
  .jbx-radio.is-muted { opacity: 0.55; }
  .jbx-radio.is-muted .pulse { animation: none; opacity: 0.5; }
  .jbx-radio .hint {
    display: none;
    font-size: 0.68rem; letter-spacing: 0.10em; text-transform: uppercase;
    color: var(--cream-faint, rgba(245,233,209,0.55));
    margin-top: 0.15rem;
  }
  .jbx-radio.is-muted .hint { display: block; }
  .jbx-radio .pulse {
    font-size: 1.9rem;
    animation: jbx-pulse 2s ease-in-out infinite;
  }
  @keyframes jbx-pulse {
    0%, 100% { transform: scale(1);    opacity: 0.85; }
    50%      { transform: scale(1.12); opacity: 1; }
  }
  .jbx-radio h3 {
    font-family: "Archivo Black", sans-serif;
    font-size: 1rem; letter-spacing: 0.08em;
    margin: 0; color: var(--fg);
  }
  .jbx-radio h3 .accent { color: var(--gold); }
  .jbx-radio .station {
    color: var(--gold); font-size: 0.82rem; font-weight: 600;
    letter-spacing: 0.04em;
  }
  .jbx-up h3 {
    font-size: 0.65rem; letter-spacing: 0.12em; text-transform: uppercase;
    color: var(--gold); margin: 0 0 0.3rem;
  }
  .jbx-up ol { list-style: none; margin: 0; padding: 0; }
  .jbx-up li {
    padding: 0.32rem 0;
    border-bottom: 1px solid var(--line);
    display: grid; grid-template-columns: 1.2rem 1fr; gap: 0.35rem;
    font-size: 0.78rem;
  }
  .jbx-up li:last-child { border-bottom: 0; }
  .jbx-up li .num { color: var(--muted); font-weight: 700; }
  .jbx-up li .t { font-weight: 600; line-height: 1.2; }
  .jbx-up li .who { color: var(--muted); font-size: 0.68rem; display: block; margin-top: 2px; }

  /* QR slide stage at the bottom of the jukebox column. Two
   * slides — bar.php (Music/Drinks/Darts) and share.php (Crash
   * the Market) — alternate every ~20s, driven by JS at the
   * bottom of the page. Only the slide carrying .is-active is
   * visible; CSS handles the cross-fade.
   *
   * Stacked vertically so the QR is a comfortable scan size
   * (a phone needs ~80% of its screen width to focus on a small QR
   * from a bar-distance away). */
  .jbx-logo {
    margin-top: auto;          /* pin to bottom of the jukebox column */
    padding-top: 0.7rem;
    position: relative;
    min-height: 240px;
  }
  .jbx-slide {
    position: absolute; left: 0; right: 0; top: 0.7rem;
    display: flex; flex-direction: column; align-items: center;
    gap: 0.5rem;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.55s ease;
  }
  .jbx-slide.is-active { opacity: 1; pointer-events: auto; }
  .jbx-slide .qr-frame {
    flex: 0 0 auto;
    width: 130px; height: 130px;
    background: #fff;
    border-radius: 8px;
    padding: 6px;
    display: flex; align-items: center; justify-content: center;
  }
  .jbx-slide .qr-frame img {
    width: 100%; height: 100%;
    display: block;
  }
  .jbx-slide .tagline {
    color: var(--fg);
    font-size: 0.78rem; line-height: 1.35;
    text-align: center;
    min-width: 0;
  }
  .jbx-slide .tagline .url {
    color: var(--gold); font-weight: 700;
    /* Don't break the URL mid-word — "knkinn.com/share.php" is
     * short enough to fit and the trailing "p" was wrapping
     * onto its own line because of word-break: break-all. */
    white-space: nowrap;
  }
  /* The "Crash the Market" slide gets a hot-red headline so it
   * pops harder when it cycles in. */
  .jbx-slide.is-share .tagline-hd {
    color: #ff4d5b;
    font-family: "Archivo Black", sans-serif;
    font-size: 0.86rem; letter-spacing: 0.06em;
    text-transform: uppercase;
    margin-bottom: 0.15rem;
  }

  /* ============================================================
   * Darts panel (right)
   * ========================================================== */
  .panel-darts { background: #0f0905; }
  .darts-stack {
    flex: 1 1 auto; min-height: 0;
    display: flex; flex-direction: column;
    gap: 0.55rem;
    overflow: hidden;
  }
  .darts-card {
    flex: 1 1 0;             /* split equally between live games */
    min-height: 0;
    display: flex; flex-direction: column;
    background: #1b0f04;
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0.5rem 0.7rem;
  }
  .darts-card .head {
    display: flex; justify-content: space-between; align-items: baseline;
    border-bottom: 1px solid var(--line);
    padding-bottom: 0.3rem; margin-bottom: 0.4rem;
  }
  .darts-card .head .board {
    font-family: "Archivo Black", sans-serif;
    font-size: 0.85rem; color: var(--gold); letter-spacing: 0.04em;
  }
  .darts-card .head .gtype {
    font-size: 0.65rem; color: var(--muted);
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
    padding: 0.15rem 0;
  }
  .darts-row .name {
    font-weight: 600; font-size: 0.85rem;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .darts-row .score {
    font-family: "Archivo Black", sans-serif;
    font-size: 1.2rem; color: var(--fg);
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
    justify-content: center; gap: 0.4rem;
    text-align: center;
    background: linear-gradient(180deg, #1b0f04 0%, #0f0905 100%);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 1rem 0.6rem;
  }
  .darts-empty .pulse {
    font-size: 2.1rem;
    animation: jbx-pulse 2.4s ease-in-out infinite;
  }
  .darts-empty h3 {
    font-family: "Archivo Black", sans-serif;
    font-size: 0.95rem; letter-spacing: 0.06em;
    color: var(--fg); margin: 0;
  }
  .darts-empty h3 .accent { color: var(--gold); }
  .darts-empty .sub {
    color: var(--gold); font-weight: 600; font-size: 0.78rem;
  }

  /* ============================================================
   * Tap-by-tap rendering — each player's row now shows three
   * dart slots for their CURRENT turn plus a small chip with
   * their last completed round's total. New darts get .is-new
   * for one frame to fire the slide-in animation.
   * ========================================================== */
  .darts-row {
    /* Override the previous flex justify-between so we can use
     * a 3-column grid: name | dart-strip | score. */
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) auto auto;
    gap: 0.5rem;
    align-items: center;
  }
  .darts-darts-strip {
    display: inline-flex; gap: 0.18rem;
    align-items: center;
  }
  .darts-dart-slot {
    min-width: 30px; padding: 0.18rem 0.32rem;
    border: 1px dashed rgba(201,170,113,0.3);
    border-radius: 4px;
    font-family: "Archivo Black", sans-serif;
    font-size: 0.7rem; letter-spacing: 0.02em;
    color: var(--cream-dim, rgba(245,233,209,0.55));
    text-align: center;
    line-height: 1;
    transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
  }
  .darts-dart-slot.has-throw {
    border-style: solid;
    border-color: var(--gold);
    color: var(--gold);
    background: rgba(201,170,113,0.08);
  }
  .darts-dart-slot.is-treble {
    background: rgba(201,170,113,0.22);
    color: #fff;
    border-color: #fff;
  }
  .darts-dart-slot.is-bull {
    background: #d94343;
    color: #fff;
    border-color: #fff;
  }
  .darts-dart-slot.is-double {
    background: rgba(63,184,128,0.22);
    border-color: #2fdc7a;
    color: #2fdc7a;
  }
  .darts-dart-slot.is-new {
    animation: dart-pop 0.45s ease-out both;
  }
  @keyframes dart-pop {
    0%   { transform: scale(0.6) translateX(-8px); opacity: 0; }
    60%  { transform: scale(1.18); opacity: 1; }
    100% { transform: scale(1)    translateX(0);  opacity: 1; }
  }

  /* Round-total chip — appears next to the player score whenever
   * we have a last_round on this slot. Gold if normal, special
   * colours for 180 / ton+ / stinker. */
  .darts-row .last-round {
    display: inline-flex; align-items: baseline; gap: 0.2rem;
    padding: 0.1rem 0.35rem;
    background: rgba(201,170,113,0.1);
    border: 1px solid rgba(201,170,113,0.35);
    border-radius: 999px;
    font-family: "Archivo Black", sans-serif;
    font-size: 0.62rem;
    color: var(--gold);
    letter-spacing: 0.04em;
    margin-left: 0.45rem;
    white-space: nowrap;
  }
  .darts-row .last-round .lr-tag {
    font-size: 0.52rem; opacity: 0.75; letter-spacing: 0.08em;
  }
  .darts-row .last-round.notable-180    { background: #d94343; border-color: #d94343; color: #fff; }
  .darts-row .last-round.notable-tonplus{ background: #c9aa71; border-color: #c9aa71; color: #1b0f04; }
  .darts-row .last-round.notable-finish { background: #2fdc7a; border-color: #2fdc7a; color: #0f0905; }
  .darts-row .last-round.notable-stinker{ background: #444;    border-color: #444;    color: #999; }
  .darts-row .last-round.is-fresh {
    animation: lr-pop 0.55s ease-out both;
  }
  @keyframes lr-pop {
    0%   { transform: scale(0.5); opacity: 0; }
    60%  { transform: scale(1.25); opacity: 1; }
    100% { transform: scale(1);    opacity: 1; }
  }

  /* ============================================================
   * Celebration overlay — full-panel banner on big shots
   * (180s, ton+ rounds, checkouts, stinkers). LOUD mode only;
   * QUIET mode keeps just the chip animations above.
   * ========================================================== */
  .darts-celebration {
    position: absolute;
    left: 0; right: 0; top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
    text-align: center;
    z-index: 20;
    opacity: 0;
  }
  .darts-celebration.is-firing {
    animation: celeb-show 3.2s ease-out both;
  }
  @keyframes celeb-show {
    0%   { opacity: 0; transform: translateY(-50%) scale(0.8); }
    18%  { opacity: 1; transform: translateY(-50%) scale(1.06); }
    35%  { opacity: 1; transform: translateY(-50%) scale(1); }
    85%  { opacity: 1; transform: translateY(-50%) scale(1); }
    100% { opacity: 0; transform: translateY(-50%) scale(1.04); }
  }
  .darts-celebration .celeb-headline {
    font-family: "Archivo Black", sans-serif;
    font-size: 2.4rem; line-height: 1; letter-spacing: 0.02em;
    color: var(--gold);
    text-shadow: 0 0 18px rgba(201,170,113,0.5);
    margin: 0;
  }
  .darts-celebration.kind-180   .celeb-headline { color: #fff; text-shadow: 0 0 24px #d94343; }
  .darts-celebration.kind-tonplus .celeb-headline { color: var(--gold); }
  .darts-celebration.kind-finish .celeb-headline { color: #2fdc7a; text-shadow: 0 0 22px #2fdc7a; }
  .darts-celebration.kind-stinker .celeb-headline { color: #ccc; text-shadow: none; }
  .darts-celebration .celeb-banter {
    font-family: "Inter", system-ui, sans-serif;
    font-size: 1.05rem; line-height: 1.25;
    color: var(--cream); font-weight: 600;
    margin-top: 0.6rem;
    padding: 0 0.8rem;
  }
  /* Container needs position:relative for the absolute celebration. */
  .panel-darts { position: relative; }
  /* On a 180 / finish / ton+ in LOUD mode also flash the panel bg. */
  .panel-darts.celeb-flash-180     { animation: celeb-flash-180   0.9s ease-out 1; }
  .panel-darts.celeb-flash-tonplus { animation: celeb-flash-gold  0.9s ease-out 1; }
  .panel-darts.celeb-flash-finish  { animation: celeb-flash-green 0.9s ease-out 1; }
  @keyframes celeb-flash-180 {
    0% { box-shadow: inset 0 0 0 0 rgba(217,67,67,0); }
    25% { box-shadow: inset 0 0 240px 0 rgba(217,67,67,0.32); }
    100% { box-shadow: inset 0 0 0 0 rgba(217,67,67,0); }
  }
  @keyframes celeb-flash-gold {
    0% { box-shadow: inset 0 0 0 0 rgba(201,170,113,0); }
    25% { box-shadow: inset 0 0 240px 0 rgba(201,170,113,0.32); }
    100% { box-shadow: inset 0 0 0 0 rgba(201,170,113,0); }
  }
  @keyframes celeb-flash-green {
    0% { box-shadow: inset 0 0 0 0 rgba(47,220,122,0); }
    25% { box-shadow: inset 0 0 240px 0 rgba(47,220,122,0.32); }
    100% { box-shadow: inset 0 0 0 0 rgba(47,220,122,0); }
  }

  /* ============================================================
   * Top crash strip — only visible when one or more drinks have
   * crashed. A slim red marquee that slides the crash announcement
   * right-to-left across the top of the screen. Collapses to 0
   * height when there's nothing to say so the panels below don't
   * jump when the crash clears.
   * ========================================================== */
  .tv-crashbar {
    overflow: hidden;
    white-space: nowrap;
    background: var(--down); color: #fff;
    font-family: "Archivo Black", sans-serif;
    font-size: 0.95rem; letter-spacing: 0.08em;
    display: flex; align-items: center;
    min-height: 38px;
    border-bottom: 1px solid #6e1d15;
  }
  .tv-crashbar.is-hidden { display: none; }
  .tv-crashbar-inner {
    display: inline-block;
    padding-left: 100%;
    animation: tv-ticker-scroll 22s linear infinite;
  }

  /* ============================================================
   * Footer bar — runs along the bottom of the screen.
   *
   * Reserved for live lyrics (centred fade) — and, when no synced
   * lyrics are available, a marquee of upcoming sports fixtures
   * pulled from the same JSON the homepage's #sports section uses.
   * Crash announcements are NOT shown here any more; they live in
   * the top crash strip so they don't fight with lyrics for the
   * same slot.
   * ========================================================== */
  .tv-footer {
    display: block;
    background: var(--bg2);
    border-top: 1px solid var(--line);
    /* Bumped to fit the bigger lyric font without resizing the
     * panels above when the ticker mode flips. */
    min-height: 64px;
  }

  /* Bottom ticker — lyric or sports. Lyric fades in at centre;
   * sports scrolls right-to-left as a marquee. Hidden state keeps
   * the row reserved so the panels above don't reflow. */
  .tv-ticker {
    position: relative;        /* anchor for the lyric nudge buttons */
    overflow: hidden;
    white-space: nowrap;
    color: var(--fg);
    font-family: "Archivo Black", sans-serif;
    font-size: 0.92rem; letter-spacing: 0.06em;
    display: flex; align-items: center;
    min-height: 64px;
  }
  /* Lyric nudge arrows — visible only when the ticker is in lyric
     mode. Tap once to shift lyric timing ±0.25s. The same offset is
     also bound to the [ ] keyboard keys for laptop control. */
  .tv-lyric-nudge {
    position: absolute; top: 50%;
    transform: translateY(-50%);
    width: 44px; height: 44px;
    border-radius: 50%;
    background: rgba(201,170,113,0.10);
    color: var(--gold, #c9aa71);
    border: 1px solid rgba(201,170,113,0.45);
    font-family: "Archivo Black", sans-serif;
    font-size: 1.6rem; line-height: 1;
    cursor: pointer;
    display: none;
    align-items: center; justify-content: center;
    z-index: 5;
    transition: background 0.15s ease, transform 0.05s ease;
    -webkit-tap-highlight-color: transparent;
    user-select: none;
  }
  .tv-lyric-nudge:hover  { background: rgba(201,170,113,0.22); }
  .tv-lyric-nudge:active { transform: translateY(-50%) scale(0.94); }
  .tv-lyric-nudge-left   { left:  1.2rem; }
  .tv-lyric-nudge-right  { right: 1.2rem; }
  .tv-ticker.is-lyric .tv-lyric-nudge { display: inline-flex; }
  .tv-ticker.is-hidden .tv-ticker-inner {
    visibility: hidden;
  }
  /* Sports mode: scrolls a "•"-separated list of upcoming fixtures
   * across the bottom whenever no synced lyric line is showing.
   * The .accent spans (sport tag + kickoff) are gilded so the
   * eye can pick events out of the stream. */
  .tv-ticker.is-sports {
    color: var(--fg);
    font-size: 1.1rem;
    letter-spacing: 0.05em;
  }
  .tv-ticker.is-sports .accent { color: var(--gold); font-weight: 700; }
  .tv-ticker.is-sports .sep    { color: var(--muted); margin: 0 0.6rem; }
  /* Marquee inner (used by is-sports). padding-left:100% pushes the
   * start of the message past the right edge so it slides in.
   * Duration tuned for readability — fixture strings are long and
   * staff need to be able to glance up and read kickoff times, so
   * we run slow. The crashbar reuses the same keyframe but overrides
   * its own (much shorter) duration above. */
  .tv-ticker-inner {
    display: inline-block;
    padding-left: 100%;
    animation: tv-ticker-scroll 320s linear infinite;
  }
  @keyframes tv-ticker-scroll {
    0%   { transform: translateX(0); }
    100% { transform: translateX(-100%); }
  }

  /* Lyric mode: centred, larger, italic, fades in (no scroll).
   * The fade triggers because refreshTickerBot() resets the inline
   * animation style on every text change, which retriggers the
   * keyframe. Each new line replaces the previous in place. */
  .tv-ticker.is-lyric {
    color: var(--gold);
    font-family: "Inter", system-ui, sans-serif;
    font-weight: 600;
    font-style: italic;
    font-size: 1.15rem;
    letter-spacing: 0.01em;
    justify-content: center;
    text-align: center;
  }
  .tv-ticker.is-lyric .tv-ticker-inner {
    display: block;
    padding-left: 0;
    white-space: normal;        /* allow long lines to wrap */
    max-width: 90%;
    animation: tv-lyric-fade 0.55s ease-out both;
  }
  @keyframes tv-lyric-fade {
    0%   { opacity: 0; transform: translateY(8px) scale(0.96); }
    60%  { opacity: 1; transform: translateY(0)   scale(1);    }
    100% { opacity: 1; transform: translateY(0)   scale(1);    }
  }

  /* ============================================================
   * Lyric-sync toast — small pill that fades in at the bottom-
   * centre when staff nudges the offset, then fades out on its
   * own. Sits ABOVE the bottom ticker so the lyric line stays
   * readable while the staffer fine-tunes. Driven by JS via
   * showSyncToast() — no other code touches it.
   * ========================================================== */
  .tv-sync-toast {
    position: fixed;
    left: 50%; bottom: 80px;
    transform: translateX(-50%);
    background: rgba(11, 5, 0, 0.9);
    color: var(--gold);
    border: 1px solid var(--gold);
    border-radius: 999px;
    padding: 0.5rem 1.1rem;
    font-family: "Archivo Black", sans-serif;
    font-size: 0.95rem; letter-spacing: 0.06em;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.18s ease-out;
    z-index: 90;
    white-space: nowrap;
  }
  .tv-sync-toast.is-visible { opacity: 1; }

  /* ============================================================
   * Splash gate — tap to unblock browser autoplay.
   * ========================================================== */
  .tv-splash {
    position: fixed; inset: 0; z-index: 100;
    background: radial-gradient(circle at center, #1b0f04 0%, #000 70%);
    display: flex; align-items: center; justify-content: center;
  }
  .tv-splash .inner { text-align: center; max-width: 640px; padding: 2rem; }
  .tv-splash h1 {
    font-family: "Archivo Black", sans-serif;
    font-size: 4rem; letter-spacing: 0.04em;
    margin: 0 0 0.4rem; color: var(--fg);
  }
  .tv-splash h1 .accent { color: var(--gold); }
  .tv-splash p { color: var(--muted); font-size: 1.1rem; margin: 0.6rem 0 1.6rem; }
  .tv-splash button.start {
    padding: 1.1rem 2.4rem; background: var(--gold);
    color: #2a1a08; border: 0;
    font-family: "Archivo Black", sans-serif;
    font-size: 1.05rem; letter-spacing: 0.16em; text-transform: uppercase;
    cursor: pointer; border-radius: 6px;
  }
  .tv-splash button.start:hover { background: #d8c08b; }
  .tv-splash .request-url { margin-top: 2rem; color: var(--muted); font-size: 0.95rem; }
  .tv-splash .request-url strong { color: var(--gold); font-weight: 700; }
  body.tv:not(.splash-on) .tv-splash { display: none; }
</style>
</head>
<body class="tv splash-on">

<!-- Splash gate — required so the browser autoplay policy lets us start
     audio. Tap once at the start of the night; after that the page plays
     each queued song automatically and falls back to Triple J radio
     when the queue's empty. Only shown when the jukebox is enabled. -->
<?php if ($jbx_enabled): ?>
<div class="tv-splash" id="tv-splash">
  <div class="inner">
    <h1>KnK <span class="accent">Bar TV</span></h1>
    <p>Tap to start the audio. After this, the jukebox plays automatically.</p>
    <button type="button" class="start" id="tv-start-btn">▶ Start</button>
    <div class="request-url">
      Request a song at <strong><?= h($JUKEBOX_REQUEST_URL) ?></strong>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Triple J radio fallback. Plays when nothing's queued (after the
     splash gate is dismissed). The YouTube player itself lives inside
     the jukebox panel — see <div class="jbx-video"> below — because
     browsers don't reliably autoplay video in tiny off-screen iframes. -->
<audio id="tv-radio" preload="none"></audio>

<!-- Lyric-sync toast — feedback chip when staff nudge the lyric
     offset with [ / ]. Hidden by default; JS toggles .is-visible. -->
<div class="tv-sync-toast" id="tv-sync-toast"></div>

<!-- Top crash strip — full-width red marquee shown only when one or
     more drinks have crashed (price collapsed). Hidden state collapses
     to 0 height so the panels below don't reflow when the crash clears.
     Lives at the TOP so it doesn't fight with the bottom lyric/sports
     ticker for the same row. -->
<div class="tv-crashbar is-hidden" id="tv-crashbar">
  <span class="tv-crashbar-inner" id="tv-crashbar-inner"></span>
</div>

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

    <!-- Persistent slot for the YouTube iframe. Hidden until the
         splash gate is dismissed (so the YT.Player is created in a
         normal-flow visible element — browsers don't reliably play
         video in 1×1 off-screen iframes). After dismiss the JS shows
         this and YT.Player swaps #tv-yt-player for the live iframe. -->
    <div class="jbx-video" id="jbx-video"<?= $jbx_now ? "" : " hidden" ?>>
      <?php if ($jbx_now && !empty($jbx_now["thumbnail_url"])): ?>
        <img class="thumb-fallback"
             src="<?= h($jbx_now["thumbnail_url"]) ?>"
             alt="">
      <?php endif; ?>
      <div id="tv-yt-player"></div>
    </div>

    <div class="jbx-now" id="jbx-now"<?= $jbx_now ? "" : " hidden" ?>>
      <?php if ($jbx_now): ?>
        <div class="title"><?= h($jbx_now["youtube_title"] ?? "") ?></div>
        <?php $rn = trim((string)($jbx_now["requester_name"] ?? "")); ?>
        <?php if ($rn !== ""): ?>
          <div class="who">Requested by <?= h($rn) ?></div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="jbx-radio" id="jbx-radio"<?= $jbx_now ? " hidden" : "" ?>
         role="button" tabindex="0" aria-label="Toggle radio playback"
         title="Tap to stop / resume the radio">
      <div class="pulse">📻</div>
      <h3>ON THE <span class="accent">RADIO</span></h3>
      <div class="station">Triple J · Australia</div>
      <div class="hint">Tap to resume</div>
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

    <!-- Two QR slides at the bottom of the jukebox column,
         pinned via margin-top:auto. JS toggles .is-active between
         them every ~20 s. The bar slide is the existing
         "scan to play" prompt; the share slide promotes the
         /share.php market-crash mechanic. -->
    <div class="jbx-logo" aria-hidden="true" id="qr-stage">
      <!-- Slide 1: bar.php (default visible) -->
      <div class="jbx-slide is-bar is-active" data-slide="bar">
        <div class="qr-frame">
          <img src="/assets/img/qr-bar.svg" alt="">
        </div>
        <div class="tagline">
          To queue Music, order a Drink or find a Darts Partner,
          scan the QR or goto
          <span class="url">knkinn.com/bar.php</span>
        </div>
      </div>
      <!-- Slide 2: share.php — crash the market -->
      <div class="jbx-slide is-share" data-slide="share">
        <div class="qr-frame">
          <img src="/assets/img/qr-share.svg" alt="">
        </div>
        <div class="tagline">
          <div class="tagline-hd">Crash the market →</div>
          Post about us on Facebook or write a Google review for
          cheaper drinks. Scan or goto
          <span class="url">knkinn.com/share.php</span>
        </div>
      </div>
    </div>
  </section>

  <!-- =========================================================
       Market panel (centre, always shown)
       ======================================================= -->
  <section class="panel panel-market" aria-label="Beer Stock Market">
    <!-- Heading + band sit on the same line: title left, "<Band> —
         live drink prices" right-justified on the same row. -->
    <div class="market-head">
      <h2>📈 Beer Stock Market</h2>
      <div class="market-band" id="mkt-band">
        <span class="label" id="mkt-band-label"><?= h($market_band_lbl) ?></span>
        <span class="sub">live drink prices</span>
      </div>
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
            <div class="name-cell">
              <span class="name"><?= h($it["name"]) ?></span>
              <!-- Sparkline stretched under the name — JS fills it on first poll. -->
              <span class="spark"></span>
            </div>
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

    <!-- Celebration overlay — only visible while .is-firing. JS sets
         the .kind-* class on each fire for the per-event colour. -->
    <div class="darts-celebration" id="darts-celebration" aria-hidden="true">
      <h3 class="celeb-headline" id="celeb-headline"></h3>
      <p class="celeb-banter"   id="celeb-banter"></p>
    </div>

    <div class="darts-stack" id="darts-stack">
      <?php if (empty($darts_games)): ?>
        <div class="darts-empty" id="darts-empty">
          <div class="pulse">🎯</div>
          <h3>WAITING FOR <span class="accent">PLAYERS</span></h3>
          <div class="sub">Boards are free — grab some darts.</div>
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

<!-- Footer bar — bottom ticker, lyric or sports. Always rendered so
     the panels above keep a stable height; the ticker just hides its
     content when there's nothing to say. JS sets the inner text +
     a mode class ("is-lyric" / "is-sports") on .tv-ticker. Lyrics
     win — sports fixtures fill the gap whenever no synced lyric is
     showing. Crash announcements live in the TOP strip, not here. -->
<footer class="tv-footer">
  <div class="tv-ticker is-hidden" id="tv-ticker">
    <button class="tv-lyric-nudge tv-lyric-nudge-left"
            id="tv-lyric-back" type="button"
            title="Lyrics earlier (−0.25s)" aria-label="Lyrics earlier">‹</button>
    <span class="tv-ticker-inner" id="tv-ticker-inner"></span>
    <button class="tv-lyric-nudge tv-lyric-nudge-right"
            id="tv-lyric-fwd" type="button"
            title="Lyrics later (+0.25s)" aria-label="Lyrics later">›</button>
  </div>
</footer>

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

  // ----- QR slide carousel (bar ↔ share) -----
  // Cycle the two QR slides at the bottom of the jukebox column.
  // 20 s on each, cross-fade is handled by the .jbx-slide.is-active
  // CSS rule. Pause stays on the bar slide if anything throws.
  (function () {
    var slides = document.querySelectorAll("#qr-stage .jbx-slide");
    if (slides.length < 2) return;
    var i = 0;
    setInterval(function () {
      try {
        slides[i].classList.remove("is-active");
        i = (i + 1) % slides.length;
        slides[i].classList.add("is-active");
      } catch (_) {}
    }, 20 * 1000);
  })();

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
  /* YouTube titles arrive HTML-encoded from oEmbed (so a song title
   * like "Rock & Roll" comes back as "Rock &amp; Roll"). If we then
   * escapeHtml() that, the ampersand becomes "&amp;amp;" and shows
   * up as a literal "&amp;" on screen. Round-trip through a textarea
   * to decode entities first, then re-escape for safe HTML insert. */
  var _decoder = document.createElement("textarea");
  function decodeHtml(s) {
    if (s == null) return "";
    _decoder.innerHTML = String(s);
    return _decoder.value;
  }
  function safeText(s) { return escapeHtml(decodeHtml(s)); }

  // ============================================================
  // TICKERS — top crash strip + bottom lyric/sports strip.
  //
  // Two independent slots:
  //
  //   TOP    .tv-crashbar  — only ever shows crash announcements.
  //                          Hidden (display:none) when no crash.
  //                          Driven by setTickerCrash(text).
  //
  //   BOTTOM .tv-ticker    — lyrics OR sports fixtures.
  //                          Priority: lyric > sports.
  //                          Driven by setTickerLyric(text) +
  //                          setTickerSports(htmlString) — sports
  //                          accepts pre-built HTML so kickoff/sport
  //                          tags can be highlighted in gold.
  //
  // Animation: each text change resets the inline animation style so
  // the new message enters from the right edge rather than picking
  // up the previous frame's offset. Lyric mode uses the fade-in
  // keyframe instead of the scroll keyframe (handled via CSS).
  // ============================================================
  var crashBarEl    = document.getElementById("tv-crashbar");
  var crashBarInner = document.getElementById("tv-crashbar-inner");
  var crashCurrent  = null;

  var tickerEl        = document.getElementById("tv-ticker");
  var tickerInner     = document.getElementById("tv-ticker-inner");
  var tickerLyricTxt  = null;
  var tickerSportsHtml = null;
  var tickerCurrent   = null;
  var tickerCurMode   = null;

  /* ----- TOP: crash strip ----- */
  function setTickerCrash(text) {
    var t = text || null;
    if (t === crashCurrent) return;
    crashCurrent = t;
    if (!t) {
      crashBarEl.classList.add("is-hidden");
      crashBarInner.textContent = "";
      return;
    }
    crashBarEl.classList.remove("is-hidden");
    crashBarInner.textContent = t;
    crashBarInner.style.animation = "none";
    void crashBarInner.offsetHeight;
    crashBarInner.style.animation = "";
  }

  /* ----- BOTTOM: lyric/sports strip ----- */
  function refreshTickerBot() {
    var nextHtml, nextTxt, nextMode;
    if (tickerLyricTxt) {
      nextTxt = tickerLyricTxt; nextHtml = null; nextMode = "lyric";
    } else if (tickerSportsHtml) {
      nextTxt = null; nextHtml = tickerSportsHtml; nextMode = "sports";
    } else {
      nextTxt = null; nextHtml = null; nextMode = null;
    }

    var key = nextMode + "\u0001" + (nextTxt || nextHtml || "");
    if (key === tickerCurrent && nextMode === tickerCurMode) return;
    tickerCurrent = key;
    tickerCurMode = nextMode;

    if (!nextMode) {
      tickerEl.classList.add("is-hidden");
      tickerEl.classList.remove("is-lyric", "is-sports");
      tickerInner.textContent = "";
      return;
    }
    tickerEl.classList.remove("is-hidden", "is-lyric", "is-sports");
    tickerEl.classList.add("is-" + nextMode);
    if (nextHtml !== null) {
      tickerInner.innerHTML = nextHtml;
    } else {
      tickerInner.textContent = nextTxt;
    }
    /* Restart the animation (scroll for sports, fade for lyric) so
     * the new message starts cleanly rather than mid-frame. */
    tickerInner.style.animation = "none";
    void tickerInner.offsetHeight;
    tickerInner.style.animation = "";
  }
  function setTickerLyric(text) {
    tickerLyricTxt = text || null;
    refreshTickerBot();
  }
  function setTickerSports(html) {
    tickerSportsHtml = html || null;
    refreshTickerBot();
  }

  // ============================================================
  // SPORTS FIXTURES — fallback content for the bottom ticker.
  //
  // Reads the same JSON that powers knkinn.com/#sports
  // (/assets/data/fixtures.json), filters to upcoming events within
  // the next 30 days, formats each as
  //   "<Sport>  <Sat 25 Apr 12:20 SGT>  Title · subtitle"
  // and concatenates them with " • " separators into a single long
  // marquee string. Sport tag + kickoff are wrapped in <span class
  // ="accent"> so they render in gold.
  //
  // Refreshed every 30 minutes so a long evening rotates fixtures
  // out as their kickoffs pass. The fetched HTML is cached on the
  // module and pushed into setTickerSports() each refresh — but the
  // ticker only displays it when no synced lyric is up.
  // ============================================================
  var SAIGON_TZ_TV    = "Asia/Ho_Chi_Minh";
  var FIXTURES_URL    = "/assets/data/fixtures.json";
  var FIXTURES_REFRESH_MS = 30 * 60 * 1000;
  var SPORT_ICONS_TV = {
    "Cricket": "\uD83C\uDFCF", "Formula 1": "\uD83C\uDFCE", "Boxing": "\uD83E\uDD4A",
    "AFL": "\uD83C\uDFC8",     "NRL": "\uD83C\uDFC9",       "Soccer": "\u26BD",
    "Rugby Union": "\uD83C\uDFC9", "Tennis": "\uD83C\uDFBE",
    "Olympics": "\uD83C\uDFC5", "World Cup": "\uD83C\uDFC6"
  };

  function fmtKickoffTV(iso) {
    if (!iso) return "";
    var d = new Date(iso);
    if (isNaN(d.getTime())) return "";
    var date = new Intl.DateTimeFormat("en-GB", {
      timeZone: SAIGON_TZ_TV, weekday: "short", day: "2-digit", month: "short"
    }).format(d);
    var time = new Intl.DateTimeFormat("en-GB", {
      timeZone: SAIGON_TZ_TV, hour: "2-digit", minute: "2-digit", hour12: false
    }).format(d);
    return time + " " + date + " SGT";
  }

  function buildSportsHtml(fixtures) {
    if (!fixtures || !fixtures.length) return null;
    var now = new Date();
    var horizon = new Date(now.getTime() + 30 * 24 * 60 * 60 * 1000);
    var upcoming = fixtures
      .filter(function (e) {
        if (!e.kickoff) return true;       /* tentative entries — keep */
        var k = new Date(e.kickoff);
        return k >= now && k <= horizon;
      })
      .sort(function (a, b) {
        if (!a.kickoff) return 1;
        if (!b.kickoff) return -1;
        return new Date(a.kickoff) - new Date(b.kickoff);
      })
      .slice(0, 14);                       /* match the homepage cap */
    if (!upcoming.length) return null;

    var pieces = upcoming.map(function (ev) {
      var icon = SPORT_ICONS_TV[ev.sport] || "\uD83C\uDFC6";
      var ko   = fmtKickoffTV(ev.kickoff);
      var sub  = ev.subtitle ? "  \u00B7  " + escapeHtml(ev.subtitle) : "";
      var koHtml = ko ? '<span class="accent">' + escapeHtml(ko) + '</span>  ' : "";
      return icon + '  <span class="accent">' + escapeHtml(ev.sport) + '</span>  ' +
             koHtml + escapeHtml(ev.title || "") + sub;
    });
    return pieces.join('<span class="sep">\u2022</span>');
  }

  function loadFixtures() {
    fetch(FIXTURES_URL, { cache: "no-cache" })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || !Array.isArray(j.fixtures)) return;
        var html = buildSportsHtml(j.fixtures);
        setTickerSports(html);
      })
      .catch(function (e) { /* offline — leave whatever's already up */ });
  }
  loadFixtures();
  setInterval(loadFixtures, FIXTURES_REFRESH_MS);

  // ============================================================
  // LYRIC-SYNC NUDGE — keyboard shortcuts so a staffer can line
  // up the lyrics with the audio when LRCLIB's timestamps don't
  // match the YouTube edit. Bindings:
  //
  //   ]            +0.25s  (lyrics later)
  //   [            -0.25s  (lyrics earlier)
  //   Shift+]      +1.0s
  //   Shift+[      -1.0s
  //   \            reset to 0
  //
  // The current offset is shown briefly in a toast and saved to
  // localStorage against the YouTube video id, so the next time
  // anyone queues that song the offset comes back automatically.
  // ============================================================
  var syncToastEl   = document.getElementById("tv-sync-toast");
  var syncToastTmr  = null;
  function showSyncToast(label) {
    if (!syncToastEl) return;
    syncToastEl.textContent = label;
    syncToastEl.classList.add("is-visible");
    if (syncToastTmr) clearTimeout(syncToastTmr);
    syncToastTmr = setTimeout(function () {
      syncToastEl.classList.remove("is-visible");
    }, 1400);
  }
  function fmtOffsetLabel(off) {
    if (off === 0) return "Lyric sync: 0.0s";
    var sign = off > 0 ? "+" : "\u2212";          /* unicode minus */
    var abs  = Math.abs(off).toFixed(2).replace(/0$/, "");
    if (abs.charAt(abs.length - 1) === ".") abs = abs.slice(0, -1);
    return "Lyric sync: " + sign + abs + "s";
  }
  function nudgeLyricOffset(deltaSec, reset) {
    if (!lyricsOffsetVid) {
      showSyncToast("No song playing");
      return;
    }
    var next = reset ? 0 : Math.round((lyricsOffsetSec + deltaSec) * 100) / 100;
    /* Clamp to ±30s — beyond that the LRC and the video probably
     * aren't even the same recording. */
    if (next >  30) next =  30;
    if (next < -30) next = -30;
    lyricsOffsetSec = next;
    saveLyricOffset(lyricsOffsetVid, next);
    /* Force the next tick to re-pick the line under the new
     * offset, even if the playhead hasn't moved. */
    lyricsLastIdx = -2;
    showSyncToast(fmtOffsetLabel(next));
  }
  document.addEventListener("keydown", function (e) {
    /* Don't hijack typing in form fields (the splash button is
     * the only interactive element on this page, but be safe). */
    var tag = (e.target && e.target.tagName) || "";
    if (tag === "INPUT" || tag === "TEXTAREA" || e.target.isContentEditable) return;
    if (e.metaKey || e.ctrlKey || e.altKey) return;
    var step = e.shiftKey ? 1.0 : 0.25;
    if (e.key === "]") { e.preventDefault(); nudgeLyricOffset( step, false); }
    else if (e.key === "[") { e.preventDefault(); nudgeLyricOffset(-step, false); }
    else if (e.key === "\\") { e.preventDefault(); nudgeLyricOffset(0, true); }
  });

  /* On-screen click arrows — same nudge as the [ / ] keys, for
   * touch/click access (Simmo doesn't always have a keyboard near
   * the TV). Buttons are only visible when the ticker is in lyric
   * mode, so clicking them outside that state is impossible. */
  (function () {
    var back = document.getElementById("tv-lyric-back");
    var fwd  = document.getElementById("tv-lyric-fwd");
    if (back) back.addEventListener("click", function (e) {
      e.preventDefault(); nudgeLyricOffset(-0.25, false);
    });
    if (fwd)  fwd.addEventListener("click", function (e) {
      e.preventDefault(); nudgeLyricOffset(+0.25, false);
    });
  })();

  // ============================================================
  // MARKET (centre — always visible, but state can be "closed" or "empty")
  // ============================================================
  var MARKET_POLL = <?= (int)$market_poll * 1000 ?>;

  /* Sparkline renderer — port of the same function in /market.php.
   * Renders a tiny SVG line chart (last ~24 ticks) coloured by trend
   * direction. Used to fill each row's .spark cell after each poll. */
  function renderSpark(el, points, trend) {
    if (!el) return;
    if (!points || points.length < 2) { el.innerHTML = ""; return; }
    var color = trend === "up" ? "#20c97a"
              : trend === "down" ? "#e4564a"
              : "#8a7858";
    var w = el.clientWidth || 120;
    var h = el.clientHeight || 36;
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
        '<path d="' + path + '" fill="none" stroke="' + color +
              '" stroke-width="2" stroke-linejoin="round"/>' +
      '</svg>';
  }

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
            '<div class="name-cell">' +
              '<span class="name">' + escapeHtml(it.name) + '</span>' +
              '<span class="spark"></span>' +
            '</div>' +
            '<span class="price">' + vnd(it.price_vnd) + '</span>' +
            '<span class="pct">' + pct + '</span>' +
            '<span class="arrow">' + arrow(it.trend) + '</span>' +
          '</div>'
        );
      });
      table.innerHTML = rows.join("");

      /* Fill in each sparkline. Done after the rows are in the DOM
       * so .clientWidth/.clientHeight return real numbers. */
      s.items.forEach(function (it) {
        var row = table.querySelector('.market-row[data-code="' + cssEscape(it.item_code) + '"]');
        if (!row) return;
        var cell = row.querySelector('.spark');
        renderSpark(cell, it.sparkline || [], it.trend);
      });
    }

    /* Crash → ticker. Crash takes priority over song info. */
    if (s.any_crash && s.crash_names && s.crash_names.length) {
      setTickerCrash("\u26A0 CRASH! " + s.crash_names.join(", ") +
                     " \u2014 grab them before they bounce back.");
    } else {
      setTickerCrash(null);
    }

    if (typeof s.poll_seconds === "number" && s.poll_seconds * 1000 !== MARKET_POLL) {
      MARKET_POLL = Math.max(2000, s.poll_seconds * 1000);
    }
  }

  /* CSS-escape an item_code for a querySelector. Drink codes are
   * tame in practice, but cheap insurance against quotes/spaces. */
  function cssEscape(s) {
    return String(s == null ? "" : s).replace(/[^a-zA-Z0-9_-]/g, function (c) {
      return "\\" + c;
    });
  }

  // ============================================================
  // LYRICS (LRCLIB) — synced lyric lines into the footer ticker.
  //
  // We pull synced lyrics from https://lrclib.net (free, no auth,
  // CORS-friendly) for the song that's currently in the YT player,
  // parse the LRC timestamps, and pipe each line into the ticker
  // as the playhead crosses its timecode.
  //
  // YouTube titles are noisy — "Artist - Track (Official Music Video)"
  // is the common shape — so cleanArtist/cleanTrack/splitTitle do
  // their best to extract a clean lookup key. When LRCLIB has nothing
  // we silently give up; the ticker just falls back to the song info.
  // ============================================================
  var lyricsLines        = null;     // [{time, text}, ...] or null
  var lyricsForId        = null;     // jukebox.id we last fetched for
  var lyricsLastIdx      = -1;
  var lyricsTickInterval = null;
  /* Tracks where the LRCLIB fetch is up to so playRow's adaptive
   * pre-roll knows when it's safe to start the video. The video
   * either waits for "ready" (lyrics found) or "none" (we know
   * there's no synced lyrics so no point holding back the audio). */
  var lyricsState        = "idle";   // "idle" | "fetching" | "ready" | "none"
  /* Per-YouTube-video lyric offset in seconds. The LRCLIB
   * timestamps are keyed to the studio recording, but YouTube
   * videos often add an intro (silence, label sting, live count-in)
   * that pushes the actual song start past 0:00. When that happens
   * the lyrics drift earlier than the singing — staff nudges with
   * "[" / "]" until it lines up, and we cache the offset against
   * the YouTube video id in localStorage so it sticks for future
   * plays. Positive offset = lyrics shown LATER than LRC says.
   * Keyed by row.video_id, NOT row.id, so the offset is shared
   * across guests who queue the same song.
   *
   * LYRIC_DEFAULT_OFFSET is applied to songs that don't have a
   * per-song offset stored yet — LRCLIB tends to run a touch behind
   * what we want on the rooftop TV (lyrics feel slightly slow), so
   * we nudge globally by -0.4s. Per-song +/- overrides still win. */
  var LYRIC_OFFSETS_KEY    = "tvLyricOffsets";
  var LYRIC_DEFAULT_OFFSET = -0.4;
  var lyricsOffsetSec      = LYRIC_DEFAULT_OFFSET;
  var lyricsOffsetVid      = null;   // video_id this offset applies to
  function loadLyricOffsets() {
    try {
      var raw = localStorage.getItem(LYRIC_OFFSETS_KEY);
      var obj = raw ? JSON.parse(raw) : {};
      return (obj && typeof obj === "object") ? obj : {};
    } catch (_) { return {}; }
  }
  function saveLyricOffset(vid, offsetSec) {
    if (!vid) return;
    try {
      var obj = loadLyricOffsets();
      if (offsetSec === 0) { delete obj[vid]; }
      else                 { obj[vid] = offsetSec; }
      localStorage.setItem(LYRIC_OFFSETS_KEY, JSON.stringify(obj));
    } catch (_) { /* private mode / quota — silent */ }
  }

  function cleanTrack(s) {
    s = String(s || "");
    /* Strip common parenthetical / bracket noise: "(Official Music
     * Video)", "[Lyric Video]", "(Audio)", "(HD)", "(4K)" etc. */
    s = s.replace(/\s*\([^)]*?(official|music|video|lyric|lyrics|audio|hd|hq|4k|remaster|live|m\/v)[^)]*?\)/gi, "");
    s = s.replace(/\s*\[[^\]]*?(official|music|video|lyric|lyrics|audio|hd|hq|4k|remaster|live|m\/v)[^\]]*?\]/gi, "");
    s = s.replace(/\s+/g, " ").trim();
    return s;
  }
  function cleanArtist(s) {
    s = String(s || "");
    s = s.replace(/\s*-\s*topic\s*$/i, "");
    s = s.replace(/\s*VEVO\s*$/i, "");
    s = s.replace(/\s+official\s*$/i, "");
    return s.trim();
  }
  function splitTitle(rawTitle, rawChannel) {
    var title   = cleanTrack(rawTitle);
    var channel = cleanArtist(rawChannel);
    /* Common YT title shapes: "Artist - Track", "Artist – Track",
     * "Artist — Track", "Artist | Track". Pick the first separator.
     *
     * (Using RegExp#exec rather than String#match so the lint
     * heuristic doesn't read this as a PHP 8 match expression.) */
    var re = /^(.+?)\s*[-\u2013\u2014|]\s*(.+)$/;
    var m  = re.exec(title);
    if (m) return { artist: m[1].trim(), track: m[2].trim() };
    /* Fallback: assume the channel is the artist and the whole
     * cleaned title is the track name. */
    return { artist: channel, track: title };
  }

  function parseLRC(lrc) {
    if (!lrc) return [];
    var out  = [];
    var rows = String(lrc).split(/\r?\n/);
    /* LRC line: "[mm:ss.xx]Lyric text". A single row can have
     * multiple timestamps prefixed (e.g. for repeated choruses);
     * we expand each. Empty / metadata rows are skipped. */
    var stampRe = /\[(\d+):(\d+(?:\.\d+)?)\]/g;
    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];
      if (!row) continue;
      var stamps = [];
      var m;
      stampRe.lastIndex = 0;
      while ((m = stampRe.exec(row)) !== null) {
        var min = parseInt(m[1], 10);
        var sec = parseFloat(m[2]);
        stamps.push(min * 60 + sec);
      }
      if (stamps.length === 0) continue;
      var text = row.replace(stampRe, "").trim();
      if (!text) continue;   // instrumental marker — skip
      for (var j = 0; j < stamps.length; j++) {
        out.push({ time: stamps[j], text: text });
      }
    }
    out.sort(function (a, b) { return a.time - b.time; });
    return out;
  }

  function fetchLyrics(songId, title, channel) {
    var sp  = splitTitle(title, channel);
    if (!sp.artist || !sp.track) {
      /* No usable artist/track — skip the call and tell the
       * pre-roll loop it's free to start the video right away. */
      lyricsState = "none";
      return;
    }
    lyricsState = "fetching";
    var url = "https://lrclib.net/api/get?artist_name=" +
              encodeURIComponent(sp.artist) +
              "&track_name=" + encodeURIComponent(sp.track);
    fetch(url, { cache: "force-cache" })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        /* /api/get is strict — exact artist+track match. If it
         * misses, fall back to /api/search which is fuzzy. */
        if (j && j.syncedLyrics) return j;
        return fetch("https://lrclib.net/api/search?q=" +
                     encodeURIComponent(sp.artist + " " + sp.track),
                     { cache: "force-cache" })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (arr) {
            if (!Array.isArray(arr)) return null;
            for (var k = 0; k < arr.length; k++) {
              if (arr[k] && arr[k].syncedLyrics) return arr[k];
            }
            return null;
          });
      })
      .then(function (rec) {
        /* If the song changed while we were fetching, this response
         * is for a stale id — just drop it. */
        if (lyricsForId !== songId) return;
        if (!rec || !rec.syncedLyrics) { lyricsState = "none"; return; }
        var parsed = parseLRC(rec.syncedLyrics);
        if (parsed.length === 0) { lyricsState = "none"; return; }
        lyricsLines   = parsed;
        lyricsLastIdx = -1;
        lyricsState   = "ready";
        startLyricLoop();
      })
      .catch(function () {
        /* CORS / network — treat as "no lyrics" so the pre-roll
         * doesn't sit on its hands waiting forever. */
        if (lyricsForId === songId) lyricsState = "none";
      });
  }

  function clearLyrics() {
    lyricsLines   = null;
    lyricsForId   = null;
    lyricsLastIdx = -1;
    lyricsState   = "idle";
    setTickerLyric(null);
    if (lyricsTickInterval) {
      clearInterval(lyricsTickInterval);
      lyricsTickInterval = null;
    }
  }
  function startLyricLoop() {
    if (lyricsTickInterval) clearInterval(lyricsTickInterval);
    lyricsTickInterval = setInterval(updateLyric, 350);
  }
  function updateLyric() {
    if (!lyricsLines || !lyricsLines.length) return;
    if (!ytPlayer || typeof ytPlayer.getCurrentTime !== "function") return;
    var t;
    try { t = ytPlayer.getCurrentTime(); } catch (_) { return; }
    if (typeof t !== "number" || isNaN(t)) return;
    /* Apply the per-song offset before lookup. Positive offset
     * means the YT video has an intro before the song actually
     * starts, so the effective song clock lags the playhead and
     * we should display lyric lines later than LRC says. */
    var songClock = t - lyricsOffsetSec;
    /* Find the latest lyric whose timestamp is <= songClock. */
    var idx = -1;
    for (var i = 0; i < lyricsLines.length; i++) {
      if (lyricsLines[i].time <= songClock) idx = i;
      else break;
    }
    if (idx === lyricsLastIdx) return;
    lyricsLastIdx = idx;
    if (idx < 0) {
      setTickerLyric(null);
    } else {
      /* No music-note prefix — the centred fade animation already
       * reads as a "lyric line" and the glyph just adds visual noise
       * Ben asked us to drop. */
      setTickerLyric(lyricsLines[idx].text);
    }
  }

  // ============================================================
  // JUKEBOX (left) — panel UI + audio engine.
  //
  // Two responsibilities glued together:
  //   1. Render the side panel (now playing + up-next + radio
  //      fallback card). Same as before.
  //   2. Drive the hidden YouTube iframe + radio <audio> element so
  //      the bar actually hears music. Mirrors the audio loop on
  //      /jukebox-player.php — kept in sync deliberately.
  //
  // Audio is gated behind the splash "Start" button: browsers won't
  // let us autoplay sound without a user gesture.
  // ============================================================
  var JBX_POLL = <?= (int)$jbx_poll * 1000 ?>;

  // ---- Audio engine state ----
  var JBX_INITIAL = <?= json_encode($jbx_initial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var RADIO       = JBX_INITIAL.radio || { enabled: false, url: "" };
  var ytPlayer    = null;
  var ytReady     = false;
  var currentRow  = JBX_INITIAL.now_playing; // row currently loaded (or null)
  var advancing   = false;
  var audioStarted = false;                  // splash dismissed?
  var radioPlaying = false;
  var radioEl      = document.getElementById("tv-radio");

  if (RADIO.enabled && RADIO.url) {
    radioEl.src = RADIO.url;
    radioEl.volume = 0.7;
  }
  // Surface stream errors so it's obvious in DevTools when a stream URL
  // is dead. No user-facing fuss — the radio overlay card stays up.
  radioEl.addEventListener("error", function () {
    var err = radioEl.error;
    console.warn("[radio] audio element error", err && err.code, RADIO.url);
  });
  radioEl.addEventListener("stalled", function () {
    console.warn("[radio] stream stalled");
  });
  // A real radio stream never ends. If the browser sees `ended` it
  // means the connection dropped — re-seat src with a cache buster
  // so we open a fresh connection instead of looping a buffered chunk.
  radioEl.addEventListener("ended", function () {
    if (!RADIO.enabled || !RADIO.url) return;
    if (currentRow) return; // a song just took over
    console.warn("[radio] stream ended — reconnecting");
    var sep = RADIO.url.indexOf("?") >= 0 ? "&" : "?";
    radioEl.src = RADIO.url + sep + "_=" + Date.now();
    var p = radioEl.play();
    if (p && p.catch) p.catch(function (e) {
      console.warn("[radio] reconnect play failed", e);
    });
  });

  // radioMuted = "user has tapped the radio card to stop it." When
  // true, startRadioIfIdle() is a no-op so the auto-resume flow
  // doesn't fight the user's intent. Cleared when a real song starts
  // playing (state has moved on) or when the user taps to resume.
  var radioMuted = false;

  function startRadioIfIdle() {
    if (!audioStarted) return;
    if (!RADIO.enabled || !RADIO.url) return;
    if (currentRow) return;             // YouTube has the floor
    if (radioMuted) return;             // user said stop
    // No-op if already streaming. Repeated play() on some streams
    // restarts the buffered chunk, which sounds like a short loop.
    if (radioPlaying && !radioEl.paused && !radioEl.ended && !radioEl.error) {
      return;
    }
    try {
      if (radioEl.error || radioEl.ended || !radioEl.src) {
        var sep = RADIO.url.indexOf("?") >= 0 ? "&" : "?";
        radioEl.src = RADIO.url + sep + "_=" + Date.now();
      }
      var p = radioEl.play();
      if (p && p.catch) p.catch(function (e) {
        console.warn("[radio] play blocked", e);
      });
    } catch (e) { console.warn(e); }
    radioPlaying = true;
  }
  function stopRadio() {
    if (!radioPlaying && radioEl.paused) return;
    try { radioEl.pause(); } catch (_) {}
    radioPlaying = false;
  }

  // ----- click-to-toggle on the radio card -----
  // Tapping the card while it's playing stops the stream and parks
  // it muted. Tapping again resumes. A real song queueing up resets
  // the muted flag so the post-song auto-resume still works.
  (function () {
    var card = document.getElementById("jbx-radio");
    if (!card) return;
    function toggle() {
      if (radioMuted) {
        radioMuted = false;
        card.classList.remove("is-muted");
        startRadioIfIdle();
      } else {
        radioMuted = true;
        card.classList.add("is-muted");
        stopRadio();
      }
    }
    card.addEventListener("click", toggle);
    card.addEventListener("keydown", function (e) {
      // Enter / Space activate the role=button element.
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        toggle();
      }
    });
  })();

  function loadYouTubeAPI() {
    if (window.YT && window.YT.Player) {
      onYouTubeReady();
      return;
    }
    var tag = document.createElement("script");
    tag.src = "https://www.youtube.com/iframe_api";
    document.head.appendChild(tag);
  }
  window.onYouTubeIframeAPIReady = onYouTubeReady;

  function onYouTubeReady() {
    if (ytReady) return;
    ytReady = true;
    var startVid = currentRow ? currentRow.video_id : null;
    ytPlayer = new YT.Player("tv-yt-player", {
      height: "100%",
      width: "100%",
      videoId: startVid || "",
      playerVars: {
        autoplay: 1,
        controls: 0,
        modestbranding: 1,
        rel: 0,
        fs: 0,
        playsinline: 1,
        iv_load_policy: 3
      },
      events: {
        onReady: function (e) {
          if (startVid) {
            try { e.target.playVideo(); } catch (_) {}
          } else {
            advanceNow(); // queue had something pending — pull it in
          }
        },
        onStateChange: function (e) {
          // YT.PlayerState.ENDED == 0
          if (e.data === 0) advanceNow();
        },
        onError: function (e) {
          console.warn("YT error", e && e.data);
          advanceNow();
        }
      }
    });
  }

  function advanceNow() {
    if (advancing) return;
    advancing = true;
    var cid = currentRow ? currentRow.id : null;
    fetch("/api/jukebox_advance.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: "current_id=" + encodeURIComponent(cid || "")
    })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      advancing = false;
      if (!j || j.ok !== true) return;
      playRow(j.next || null);
    })
    .catch(function (e) { advancing = false; console.warn(e); });
  }

  /* Pre-roll bounds. The video doesn't load until the LRCLIB fetch
   * has either succeeded or definitively failed — so songs with
   * lyrics get the buffer they need (lyrics ready before audio
   * starts) and songs without lyrics start as soon as we know
   * there's nothing coming. PREROLL_MIN guarantees a baseline
   * pause so the radio→video swap isn't jarring; PREROLL_MAX caps
   * the wait so a slow LRCLIB call can't strand the queue. */
  var PREROLL_MIN_MS = 1500;
  var PREROLL_MAX_MS = 5000;
  var prerollPoll    = null;     // setInterval handle, single-flighted

  function playRow(row) {
    currentRow = row;
    /* Always cancel any in-flight pre-roll — a newer song has taken
     * over the slot and the previous wait is moot. */
    if (prerollPoll) { clearInterval(prerollPoll); prerollPoll = null; }
    /* Keep the video/radio cards in sync with the playing state right
     * here, so we don't rely on the next poll cycle to flip them.
     * The radio card is mutually exclusive with the video — when
     * a song starts the radio hides, when the song ends it returns. */
    var videoEl   = document.getElementById("jbx-video");
    var radioCard = document.getElementById("jbx-radio");
    if (!row) {
      if (videoEl) videoEl.hidden = true;
      if (radioCard) radioCard.hidden = false;
      clearLyrics();
      if (ytPlayer && ytPlayer.stopVideo) try { ytPlayer.stopVideo(); } catch (_) {}
      startRadioIfIdle();
      return;
    }
    if (videoEl) videoEl.hidden = false;
    if (radioCard) radioCard.hidden = true;

    /* Pull the saved offset for this YouTube video id (if any) so
     * the lyric ticker is already lined up before the first line
     * fires. Default 0 for first-time songs. */
    lyricsOffsetVid = row.video_id || null;
    var offsets = loadLyricOffsets();
    lyricsOffsetSec = (lyricsOffsetVid && typeof offsets[lyricsOffsetVid] === "number")
      ? offsets[lyricsOffsetVid] : LYRIC_DEFAULT_OFFSET;

    /* Adaptive pre-roll. The radio (or previous song's post-roll)
     * keeps playing during the wait, so there's no dead air — we
     * just hold the new video until either:
     *   - lyricsState is "ready" (LRC parsed) and MIN has elapsed
     *   - lyricsState is "none"  (no LRC found) and MIN has elapsed
     *   - MAX has elapsed (LRCLIB stalled — give up and start)
     * Race-guard (currentRow !== row) bails the loop if a newer
     * song has overtaken this one. */
    var startedAt = Date.now();
    prerollPoll = setInterval(function () {
      if (currentRow !== row) {
        clearInterval(prerollPoll); prerollPoll = null; return;
      }
      var elapsed = Date.now() - startedAt;
      if (elapsed < PREROLL_MIN_MS) return;
      var lyricsKnown = (lyricsState === "ready" || lyricsState === "none");
      if (!lyricsKnown && elapsed < PREROLL_MAX_MS) return;
      clearInterval(prerollPoll); prerollPoll = null;
      stopRadio();
      // A real song's about to play — reset the user's mute flag so
      // when this song ends, the radio auto-resume isn't blocked by
      // a stale "tap to stop" from before.
      radioMuted = false;
      var rcard = document.getElementById("jbx-radio");
      if (rcard) rcard.classList.remove("is-muted");
      if (ytPlayer && ytPlayer.loadVideoById) {
        try { ytPlayer.loadVideoById(row.video_id); } catch (_) {}
      }
    }, 100);
  }

  // ---- Splash / start gesture (autoplay unblock) ----
  var startBtn = document.getElementById("tv-start-btn");
  if (startBtn) {
    startBtn.addEventListener("click", function () {
      audioStarted = true;
      document.body.classList.remove("splash-on");

      /* Show the video panel only if a song is already loaded — the
       * radio card and the video panel are mutually exclusive. If a
       * song arrives later, playRow() un-hides the video panel BEFORE
       * loadVideoById so the iframe isn't display:none at that moment. */
      var videoEl   = document.getElementById("jbx-video");
      var radioCard = document.getElementById("jbx-radio");
      if (videoEl)   videoEl.hidden   = !currentRow;
      if (radioCard) radioCard.hidden = !!currentRow;

      // Prime audio inside the user gesture so later .play() calls
      // aren't blocked. If nothing is queued, fire up the radio
      // overlay directly — don't wait for the first poll tick.
      if (RADIO.enabled && RADIO.url) {
        if (currentRow) {
          // A song will take over — just prime then pause.
          try {
            radioEl.play().then(function () {
              try { radioEl.pause(); } catch (_) {}
            }).catch(function (e) { console.warn("[radio] prime failed", e); });
          } catch (_) {}
        } else {
          startRadioIfIdle();
        }
      }
      loadYouTubeAPI();
    });
  } else {
    // Jukebox kill switch is off — the splash isn't rendered. We're
    // a passive scoreboard until staff flip it back on (and reload).
    audioStarted = false;
  }

  /* ---- Pause audio when the page is backgrounded ----
   *
   * On the smart TV, pressing Home doesn't kill the browser tab — it
   * keeps running in the background. Without this, the radio (and
   * any playing YouTube audio) keeps streaming over whatever app the
   * user switches to, and they have to power-cycle the TV to get
   * silence. This is the bug Ben flagged ("trips Simmo up"). */
  function pauseAllAudio() {
    try { radioEl.pause(); } catch (_) {}
    radioPlaying = false;
    if (ytPlayer && ytPlayer.pauseVideo) {
      try { ytPlayer.pauseVideo(); } catch (_) {}
    }
  }
  function resumeAllAudio() {
    if (!audioStarted) return;
    if (currentRow && ytPlayer && ytPlayer.playVideo) {
      try { ytPlayer.playVideo(); } catch (_) {}
    } else {
      startRadioIfIdle();
    }
  }
  document.addEventListener("visibilitychange", function () {
    if (document.hidden) pauseAllAudio();
    else                 resumeAllAudio();
  });
  // pagehide: backwards-belt for older browsers / true navigation away.
  window.addEventListener("pagehide", pauseAllAudio);

  function pollJukebox() {
    fetch("/api/jukebox_state.php", { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(renderJukebox)
      .catch(function () { /* keep last frame */ });
  }
  function renderJukebox(s) {
    if (!s) return;

    var videoEl   = document.getElementById("jbx-video");
    var nowEl     = document.getElementById("jbx-now");
    var radioCard = document.getElementById("jbx-radio");
    var upEl      = document.getElementById("jbx-up");
    var ol        = document.getElementById("jbx-up-list");

    var hasNow  = !!s.now_playing;
    var upNext  = (s.up_next || []).slice(0, 5);
    var hasUp   = upNext.length > 0;

    // ---- Audio engine sync ----
    // Mirrors the loop in /jukebox-player.php's pollState(): keep our
    // local YT player in step with whatever the server thinks.
    if (audioStarted) {
      var serverNow = s.now_playing;
      var localId   = currentRow ? currentRow.id : null;
      var serverId  = serverNow ? serverNow.id : null;
      if (localId !== serverId) {
        if (serverNow) {
          /* About to call loadVideoById — make the video panel
           * visible BEFORE the call so the iframe isn't display:none
           * when YT tries to autoplay (browsers block autoplay in
           * hidden iframes). */
          if (videoEl) videoEl.hidden = false;
          playRow(serverNow);
        } else if (!advancing && ytReady) {
          advanceNow();
        }
      } else if (!currentRow && hasUp && !advancing && ytReady) {
        // Nothing playing locally or on server, but a song just
        // landed in the queue — pick it up.
        advanceNow();
      } else if (!currentRow && !hasUp) {
        // Idle — make sure radio is on (no-op if already streaming).
        startRadioIfIdle();
      }
    }

    /* YouTube panel and Radio card are mutually exclusive:
     *   - song playing → video visible, radio hidden
     *   - no song      → video hidden,  radio visible
     * The audio engine keeps the YT iframe alive in the DOM either
     * way (it's just display:none when hidden), so flipping back is
     * cheap. */
    if (videoEl) {
      videoEl.hidden = !hasNow;
    }
    radioCard.hidden = hasNow;

    if (hasNow) {
      var n = s.now_playing;
      var who = n.name ? '<div class="who">Requested by ' + safeText(n.name) + '</div>' : '';
      nowEl.innerHTML =
        '<div class="title">' + safeText(n.title || "") + '</div>' +
        who;
      nowEl.hidden = false;
    } else {
      nowEl.innerHTML = "";
      nowEl.hidden = true;
    }

    if (hasUp) {
      var html = "";
      upNext.forEach(function (u, i) {
        var who = u.name ? '<span class="who">' + safeText(u.name) + '</span>' : '';
        html +=
          '<li>' +
            '<span class="num">' + (i + 1) + '.</span>' +
            '<span>' +
              '<span class="t">' + safeText(u.title || "") + '</span>' +
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

    /* Lyrics: fetch once per song id. The bottom ticker is reserved
     * for synced lyrics (with sports fixtures filling the gap when
     * no lyric line is up); the now-playing song info is shown
     * under the video instead, so we don't push it to the ticker
     * any more. */
    if (hasNow) {
      var n2 = s.now_playing;
      if (lyricsForId !== n2.id) {
        clearLyrics();
        lyricsForId = n2.id;
        fetchLyrics(n2.id, decodeHtml(n2.title || ""), decodeHtml(n2.channel || ""));
      }
    } else {
      if (lyricsForId !== null) clearLyrics();
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

  /* Loud-mode toggle from settings.php (super_admin can flip it
   * via /settings.php → Darts celebrations). When false, the
   * tap-by-tap rendering still works; we just skip the full-panel
   * banner + colour flash. */
  var DARTS_LOUD = <?= $darts_loud ? 'true' : 'false' ?>;

  /* Aussie-pub banter banks. ~40 lines total, randomly drawn so we
   * don't hear the same line back-to-back. Lines are kept short so
   * they fit a phone-width panel and read at a glance. */
  var BANTER = {
    one_eighty: [
      "YOU LITTLE RIPPER!",
      "GET AMONGST IT!",
      "TOP OF THE LEAGUE!",
      "CRACKING THROW!",
      "ABSOLUTE BELTER!",
      "KNK SALUTES YOU!",
      "BLOODY BEAUTY!",
      "AUSTRALIAN OF THE YEAR!"
    ],
    tonplus: [
      "Now we're cooking",
      "Right up the guts",
      "Tidy little throw",
      "That'll do nicely",
      "Crack on, mate",
      "Solid work",
      "Easy as Sunday morning"
    ],
    finish: [
      "GAME, SET, MATCH!",
      "DONE AND DUSTED!",
      "Showing 'em how it's done",
      "Walk-off win, beauty",
      "Closer than a Bondi tram",
      "Pour him a cold one"
    ],
    stinker: [
      "Crikey, that's a stinker",
      "Have a Bex, have a lie down",
      "Blame the lighting, mate",
      "Even the cat could've done better",
      "Ouch — let's not speak of this again",
      "That's a yikes from me"
    ],
    bullseye: [
      "RIGHT IN THE GUTS!",
      "Bullseye, beauty!",
      "Dead centre — class",
      "Lasered it"
    ],
    treble: [
      "Tidy treble",
      "Right where you want it",
      "Easy money",
      "Buttery"
    ]
  };
  function pickBanter(kind) {
    var arr = BANTER[kind] || [];
    if (arr.length === 0) return "";
    return arr[Math.floor(Math.random() * arr.length)];
  }

  /* ------------------------------------------------------------
   * Per-game render state we carry across polls. We use this to:
   *   - decide which dart slots are "new" (animate them in)
   *   - detect when a player just COMPLETED their round (fire chip)
   *   - decide whether to trigger a celebration banner
   * ------------------------------------------------------------ */
  var dartsState = {
    /* gameById[game_id] = {
     *   latest_throw_id: int,
     *   slot_last_round: { slot_no: { turn, last_throw_id, total } }
     * } */
    gameById: {},
    /* True the very first time we render — suppresses celebrations
     * for darts that were already on the board when we loaded. */
    bootstrapped: false
  };

  function classifyNotable(gameType, lastRound, headline) {
    if (!lastRound) return null;
    var total = lastRound.total | 0;
    var darts = lastRound.darts || [];
    var anyBull = false, anyTreble = false, anyDouble = false;
    for (var i = 0; i < darts.length; i++) {
      var lbl = (darts[i].label || "").toUpperCase();
      if (lbl.indexOf("BULL") !== -1) anyBull = true;
      else if (lbl.charAt(0) === "T") anyTreble = true;
      else if (lbl.charAt(0) === "D") anyDouble = true;
    }
    var isX01 = (gameType === "501" || gameType === "301");
    /* Finish detection — heuristic only. In x01 the headline goes
     * to "0" or "DONE" when a player checks out. */
    var hl = (headline || "").toString().toUpperCase();
    if (isX01 && (hl === "0" || hl === "DONE")) return "finish";
    if (isX01 && total === 180)                  return "180";
    if (isX01 && total >= 100 && total < 180)    return "tonplus";
    if (isX01 && total <= 15)                    return "stinker";
    if (anyBull)                                 return "bullseye";
    if (anyTreble)                               return "treble";
    return null;
  }

  /* Fire the celebration banner. Auto-clears after the CSS
   * animation duration. Only one celebration runs at a time —
   * a second trigger replaces the in-flight banner. */
  var celebTimer = null;
  function fireCelebration(kind, playerName) {
    var cel = document.getElementById("darts-celebration");
    var hd  = document.getElementById("celeb-headline");
    var bn  = document.getElementById("celeb-banter");
    var panel = document.getElementById("panel-darts");
    if (!cel || !hd || !bn) return;

    var headline = "";
    if (kind === "180")          headline = "180!";
    else if (kind === "tonplus") headline = "TON-PLUS!";
    else if (kind === "finish")  headline = "CHECKOUT!";
    else if (kind === "stinker") headline = "OOF…";
    else if (kind === "bullseye")headline = "BULLSEYE!";
    else if (kind === "treble")  headline = "TREBLE!";
    else                         return;

    /* QUIET mode — just show a small chip-style badge under the
     * headline; skip the panel flash + huge banner. */
    if (!DARTS_LOUD) {
      hd.textContent = headline;
      bn.textContent = playerName ? ("— " + playerName) : "";
      cel.classList.remove("kind-180","kind-tonplus","kind-finish","kind-stinker","kind-bullseye","kind-treble");
      cel.classList.add("kind-" + kind);
      cel.classList.remove("is-firing");
      void cel.offsetWidth; // restart animation
      cel.classList.add("is-firing");
      return;
    }

    hd.textContent = headline;
    var line = pickBanter(kind);
    bn.textContent = line + (playerName ? " — " + playerName : "");
    cel.classList.remove("kind-180","kind-tonplus","kind-finish","kind-stinker","kind-bullseye","kind-treble");
    cel.classList.add("kind-" + kind);
    cel.classList.remove("is-firing");
    void cel.offsetWidth;
    cel.classList.add("is-firing");

    /* Panel flash for the loudest kinds only. */
    if (panel) {
      panel.classList.remove(
        "celeb-flash-180","celeb-flash-tonplus","celeb-flash-finish"
      );
      void panel.offsetWidth;
      if (kind === "180")          panel.classList.add("celeb-flash-180");
      else if (kind === "tonplus") panel.classList.add("celeb-flash-tonplus");
      else if (kind === "finish")  panel.classList.add("celeb-flash-finish");
    }

    if (celebTimer) clearTimeout(celebTimer);
    celebTimer = setTimeout(function () {
      cel.classList.remove("is-firing");
    }, 3300);
  }

  function pollDarts() {
    fetch("/api/darts_live.php", { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(renderDarts)
      .catch(function () { /* keep last frame */ });
  }

  function dartSlotHtml(throwObj, isNew) {
    /* throwObj may be undefined for empty slots — render a dashed
     * placeholder so the strip width stays stable as darts land. */
    if (!throwObj) {
      return '<span class="darts-dart-slot" aria-hidden="true">·</span>';
    }
    var lbl = (throwObj.label || "").toUpperCase();
    var cls = "darts-dart-slot has-throw";
    if (lbl.indexOf("BULL") !== -1)       cls += " is-bull";
    else if (lbl.charAt(0) === "T")       cls += " is-treble";
    else if (lbl.charAt(0) === "D")       cls += " is-double";
    if (isNew) cls += " is-new";
    return '<span class="' + cls + '">' + escapeHtml(lbl) + '</span>';
  }

  function lastRoundChipHtml(lr, notable, isFresh) {
    if (!lr) return "";
    var cls = "last-round";
    if (notable === "180")       cls += " notable-180";
    else if (notable === "tonplus") cls += " notable-tonplus";
    else if (notable === "finish")  cls += " notable-finish";
    else if (notable === "stinker") cls += " notable-stinker";
    if (isFresh) cls += " is-fresh";
    return '<span class="' + cls + '">'
         +   '<span class="lr-tag">RD</span> ' + (lr.total | 0)
         + '</span>';
  }

  function renderDarts(s) {
    if (!s) return;
    var games = (s && s.games) || [];
    var stack = document.getElementById("darts-stack");
    var html = "";

    if (games.length === 0) {
      html =
        '<div class="darts-empty" id="darts-empty">' +
          '<div class="pulse">🎯</div>' +
          '<h3>WAITING FOR <span class="accent">PLAYERS</span></h3>' +
          '<div class="sub">Boards are free — grab some darts.</div>' +
        '</div>';
      // Clean up any per-game state for games that ended.
      dartsState.gameById = {};
      stack.innerHTML = html;
    } else {
      var seenGames = {};
      games.forEach(function (g) {
        var gid = g.game_id | 0;
        seenGames[gid] = true;
        var prev = dartsState.gameById[gid] || {
          latest_throw_id: 0, slot_last_round: {}
        };
        var nextLatestThrowId = g.latest_throw_id | 0;
        var rows = "";

        (g.rows || []).forEach(function (r) {
          var slot = r.slot_no | 0;
          var prevLR = prev.slot_last_round[slot] || null;
          var lr = r.last_round || null;
          var notable = classifyNotable(g.game_type, lr, r.headline);

          /* "Round just landed" detection: the last_round.last_throw_id
           * for this slot has increased since the previous poll. We
           * suppress this on the first poll (bootstrap) so we don't
           * fire celebrations for darts that were already on the board
           * when the TV booted. */
          var isFreshRound = false;
          if (lr) {
            if (!prevLR || (lr.last_throw_id | 0) > (prevLR.last_throw_id | 0)) {
              isFreshRound = true;
            }
          }

          if (isFreshRound && notable && dartsState.bootstrapped) {
            fireCelebration(notable, r.name);
          }

          /* Update slot state for next poll. */
          if (lr) {
            prev.slot_last_round[slot] = {
              last_throw_id: lr.last_throw_id | 0,
              total:         lr.total | 0,
              turn:          lr.turn | 0
            };
          }

          /* Build the dart strip for this player's CURRENT turn.
           * Always 3 slots wide. New darts (id > prev latest) get
           * .is-new for the slide-in animation. */
          var ct = r.current_throws || [];
          var darts = "";
          for (var i = 0; i < 3; i++) {
            var t = ct[i];
            var isNew = !!(t && (t.id | 0) > (prev.latest_throw_id | 0));
            darts += dartSlotHtml(t, isNew);
          }

          rows +=
            '<div class="darts-row' + (r.is_active ? " active" : "") + '">' +
              '<span class="name">'  + escapeHtml(r.name)     + '</span>' +
              '<span class="darts-darts-strip">' + darts + '</span>' +
              '<span class="score">' + escapeHtml(r.headline) +
                lastRoundChipHtml(lr, notable, isFreshRound && dartsState.bootstrapped) +
              '</span>' +
            '</div>';
        });

        prev.latest_throw_id = nextLatestThrowId;
        dartsState.gameById[gid] = prev;

        var label = GAME_LABELS[g.game_type] || g.game_type;
        html +=
          '<div class="darts-card" data-game-id="' + gid + '">' +
            '<div class="head">' +
              '<span class="board">' + escapeHtml(g.board_name) + '</span>' +
              '<span class="gtype">' + escapeHtml(label)        + '</span>' +
            '</div>' +
            '<div class="darts-rows">' + rows + '</div>' +
          '</div>';
      });
      stack.innerHTML = html;
      // Drop state for games that have left the playing list.
      Object.keys(dartsState.gameById).forEach(function (k) {
        if (!seenGames[k]) delete dartsState.gameById[k];
      });
    }

    /* Mark the engine bootstrapped after the first frame. From the
     * second frame on, fresh-round detection can fire celebrations. */
    dartsState.bootstrapped = true;

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

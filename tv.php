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
  /* The native `hidden` attribute is `display: none`, but our flex/grid
   * panels use `display: flex` which has higher specificity and beats it.
   * Force the attribute back to actually hiding things — without this,
   * a "line above Radio box" leaks through when .jbx-now is meant to be
   * hidden (the panel's border-bottom remains rendered). */
  [hidden] { display: none !important; }
  html, body { margin: 0; padding: 0; height: 100vh; overflow: hidden; }
  body {
    background: var(--bg);
    color: var(--fg);
    font-family: "Inter", system-ui, sans-serif;
    display: grid;
    /* [header strip] [3-up panels — flexible] [footer: logo + ticker] */
    grid-template-rows: auto 1fr auto;
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
  }
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

  /* KnK Bar QR + scan link (sits at the bottom of the jukebox
   * column). Wrapped in a white frame so the SVG renders as the
   * intended black-on-white QR-style mark — without the frame the
   * unfilled black-default shapes blend into the dark page.
   *
   * Stacked vertically so the QR can be a comfortable scan size
   * (a phone needs ~80% of its screen width to focus on a small QR
   * from a bar-distance away). */
  .jbx-logo {
    margin-top: auto;          /* pin to bottom of the jukebox column */
    padding-top: 0.7rem;
    border-top: 1px solid var(--line);
    display: flex; flex-direction: column; align-items: center;
    gap: 0.5rem;
  }
  .jbx-logo .qr-frame {
    flex: 0 0 auto;
    width: 130px; height: 130px;
    background: #fff;
    border-radius: 8px;
    padding: 6px;
    display: flex; align-items: center; justify-content: center;
  }
  .jbx-logo .qr-frame img {
    width: 100%; height: 100%;
    display: block;
  }
  .jbx-logo .tagline {
    color: var(--fg);
    font-size: 0.78rem; line-height: 1.35;
    text-align: center;
    min-width: 0;
  }
  .jbx-logo .tagline .url {
    color: var(--gold); font-weight: 700;
    word-break: break-all;
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
   * Footer bar — runs along the bottom of the screen.
   *
   * Holds the scrolling ticker (crash announcements, now-playing
   * song info, live lyrics). The KnK Bar logo used to live here
   * too — it's now in the jukebox column so patrons can scan it
   * from a closer viewpoint.
   * ========================================================== */
  .tv-footer {
    display: block;
    background: var(--bg2);
    border-top: 1px solid var(--line);
    /* Bumped to fit the bigger lyric font without resizing the
     * panels above when the ticker mode flips. */
    min-height: 64px;
  }

  /* Footer ticker. Three competing messages, in priority order:
   *   1. Crash announcement (drink price collapse).
   *   2. Live lyric line (synced to the YouTube playhead).
   *   3. Now-playing song info.
   *
   * Crash and song info SCROLL right-to-left (marquee).
   * Lyric lines FADE IN at the centre and stay until the next
   * line replaces them — easier to read along to than a moving
   * marquee. Hidden state collapses the column without removing it. */
  .tv-ticker {
    overflow: hidden;
    white-space: nowrap;
    color: var(--fg);
    font-family: "Archivo Black", sans-serif;
    font-size: 0.92rem; letter-spacing: 0.06em;
    display: flex; align-items: center;
    min-height: 64px;
  }
  .tv-ticker.is-hidden .tv-ticker-inner {
    visibility: hidden;
  }
  .tv-ticker.is-crash {
    background: var(--down); color: #fff;
  }
  .tv-ticker.is-song .accent { color: var(--gold); }
  /* Marquee inner (used by is-crash and is-song). padding-left:100%
   * pushes the start of the message past the right edge so it
   * slides in. */
  .tv-ticker-inner {
    display: inline-block;
    padding-left: 100%;
    animation: tv-ticker-scroll 36s linear infinite;
  }
  .tv-ticker.is-crash .tv-ticker-inner {
    animation-duration: 22s;
  }
  @keyframes tv-ticker-scroll {
    0%   { transform: translateX(0); }
    100% { transform: translateX(-100%); }
  }

  /* Lyric mode: centred, larger, italic, fades in (no scroll).
   * The fade triggers because refreshTicker() resets the inline
   * animation style on every text change, which retriggers the
   * keyframe. Each new line replaces the previous in place. */
  .tv-ticker.is-lyric {
    color: var(--gold);
    font-family: "Inter", system-ui, sans-serif;
    font-weight: 600;
    font-style: italic;
    font-size: 1.55rem;
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

    <div class="jbx-radio" id="jbx-radio"<?= $jbx_now ? " hidden" : "" ?>>
      <div class="pulse">📻</div>
      <h3>ON THE <span class="accent">RADIO</span></h3>
      <div class="station">Triple J · Australia</div>
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

    <!-- KnK Bar scan QR. Pinned to the bottom of this column via
         margin-top:auto so it always sits in the same place
         regardless of which other cards (now/radio/up-next) are
         visible above it. The .qr-frame's white background is what
         makes the SVG render correctly — the logo has unfilled
         shapes that go invisible against the dark page bg. -->
    <div class="jbx-logo" aria-hidden="true">
      <div class="qr-frame">
        <img src="/assets/img/knk-bar-logo.svg" alt="">
      </div>
      <div class="tagline">
        To queue Music, order a Drink or find a Darts Partner,
        scan the QR or goto
        <span class="url">knkinn.com/bar.php</span>
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

<!-- Footer bar — full-width scrolling ticker. Always rendered so
     the panels above keep a stable height; the ticker just hides
     its content when there's nothing to say. JS sets the inner
     text + a mode class ("is-crash" / "is-lyric" / "is-song") on
     .tv-ticker. Crash > lyric > song in priority order. -->
<footer class="tv-footer">
  <div class="tv-ticker is-hidden" id="tv-ticker">
    <span class="tv-ticker-inner" id="tv-ticker-inner"></span>
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
  // FOOTER TICKER — multipurpose scrolling marquee.
  //
  // Three competing message sources, in priority order:
  //   1. setTickerCrash(text) — drink crash announcement
  //   2. setTickerLyric(text) — current synced lyric line
  //   3. setTickerSong(text)  — now-playing info
  //
  // Whoever calls with a non-null string wins the slot until they
  // call again with null. Crash always wins.
  //
  // The animation is restarted on each text change so the new text
  // starts from the right edge (otherwise it'd join mid-scroll).
  // ============================================================
  var tickerEl       = document.getElementById("tv-ticker");
  var tickerInner    = document.getElementById("tv-ticker-inner");
  var tickerCrashTxt = null;
  var tickerLyricTxt = null;
  var tickerSongTxt  = null;
  var tickerCurrent  = null;   // what's actually on screen right now
  var tickerCurMode  = null;

  function refreshTicker() {
    var nextTxt, nextMode;
    if (tickerCrashTxt)      { nextTxt = tickerCrashTxt; nextMode = "crash"; }
    else if (tickerLyricTxt) { nextTxt = tickerLyricTxt; nextMode = "lyric"; }
    else if (tickerSongTxt)  { nextTxt = tickerSongTxt;  nextMode = "song";  }
    else                     { nextTxt = null;           nextMode = null;    }

    if (nextTxt === tickerCurrent && nextMode === tickerCurMode) return;
    tickerCurrent = nextTxt;
    tickerCurMode = nextMode;

    if (!nextTxt) {
      tickerEl.classList.add("is-hidden");
      tickerEl.classList.remove("is-crash", "is-lyric", "is-song");
      tickerInner.textContent = "";
      return;
    }
    tickerEl.classList.remove("is-hidden", "is-crash", "is-lyric", "is-song");
    tickerEl.classList.add("is-" + nextMode);
    tickerInner.textContent = nextTxt;
    /* Restart the scroll animation so the new message enters from
     * the right rather than picking up the previous frame's offset. */
    tickerInner.style.animation = "none";
    void tickerInner.offsetHeight;   // force reflow
    tickerInner.style.animation = "";
  }
  function setTickerCrash(text) {
    tickerCrashTxt = text || null;
    refreshTicker();
  }
  function setTickerLyric(text) {
    tickerLyricTxt = text || null;
    refreshTicker();
  }
  function setTickerSong(text) {
    tickerSongTxt = text || null;
    refreshTicker();
  }

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
    if (!sp.artist || !sp.track) return;
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
        if (!rec || !rec.syncedLyrics) return;
        var parsed = parseLRC(rec.syncedLyrics);
        if (parsed.length === 0) return;
        lyricsLines   = parsed;
        lyricsLastIdx = -1;
        startLyricLoop();
      })
      .catch(function () { /* CORS / network — silent fallback */ });
  }

  function clearLyrics() {
    lyricsLines   = null;
    lyricsForId   = null;
    lyricsLastIdx = -1;
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
    /* Find the latest lyric whose timestamp is <= playhead. */
    var idx = -1;
    for (var i = 0; i < lyricsLines.length; i++) {
      if (lyricsLines[i].time <= t) idx = i;
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

  function startRadioIfIdle() {
    if (!audioStarted) return;
    if (!RADIO.enabled || !RADIO.url) return;
    if (currentRow) return;             // YouTube has the floor
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

  /* Pre-roll delay before the YouTube video actually starts, so
   * synced lyrics from LRCLIB (which fetchLyrics() kicks off via
   * renderJukebox the moment the new song id is seen) have time to
   * come back. Without this, the first 1–2 lines fly past before
   * we know what to display. Bumped to 3.0s after Ben asked for a
   * bigger gap — LRCLIB cold-cache fetches were sometimes still
   * outstanding at 1.8s. */
  var PLAYROW_PREROLL_MS = 3000;

  function playRow(row) {
    currentRow = row;
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
    /* Defer the actual loadVideoById by the pre-roll window. During
     * this gap the previous source (radio or the previous video's
     * post-roll) keeps running so there's no dead-air pop. The
     * race-guard (currentRow !== row) bails if a newer song has
     * already taken the slot before the timeout fires. */
    setTimeout(function () {
      if (currentRow !== row) return;
      stopRadio();
      if (ytPlayer && ytPlayer.loadVideoById) {
        try { ytPlayer.loadVideoById(row.video_id); } catch (_) {}
      }
    }, PLAYROW_PREROLL_MS);
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

    /* Feed song info to the footer ticker. Lower priority than crash
     * announcements (and live lyrics) — setTickerSong() is a no-op
     * while either of those is up. */
    if (hasNow) {
      var n2 = s.now_playing;
      var t  = decodeHtml(n2.title || "");
      var rq = (n2.name || "").trim();
      var msg = "\u266B  Now playing:  " + t;
      if (rq) msg += "    \u2014  Requested by " + rq;
      setTickerSong(msg);

      /* Lyrics: fetch once per song id. If we already fetched for
       * this id, just keep the existing tick-loop running. */
      if (lyricsForId !== n2.id) {
        clearLyrics();
        lyricsForId = n2.id;
        fetchLyrics(n2.id, decodeHtml(n2.title || ""), decodeHtml(n2.channel || ""));
      }
    } else {
      setTickerSong(null);
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

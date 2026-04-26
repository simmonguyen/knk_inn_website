<?php
/*
 * KnK Inn — /darts.php  (player page)
 *
 * Single-page state machine for the darts room:
 *
 *   1. Board picker          — /darts.php
 *   2. Host setup             — /darts.php?board=N
 *   3. Join with name         — /darts.php?join=CODE   or  /darts.php?game=ID&join=1
 *   4. Lobby (host + joiners) — /darts.php?game=ID
 *   5. Playing scoreboard     — /darts.php?game=ID
 *   6. Finished               — /darts.php?game=ID
 *
 * Server renders the shell + initial state; the client polls
 * /api/darts_state.php every couple of seconds for live updates and
 * drives the per-dart numpad when it's the player's turn.
 *
 * No login. Phones identify themselves with a session_token stored
 * in a cookie keyed by game id (`darts_token_<game_id>`).
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/darts.php";
require_once __DIR__ . "/includes/hours.php";
require_once __DIR__ . "/includes/darts_lobby.php";

/* Closed-hours gate. Outside service hours (07:30–12:30 / 16:00–23:30
 * Saigon time) we don't let new darts games start. */
if (!knk_bar_is_open()) {
    knk_bar_render_closed_and_exit("Darts");
}

function dh($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

/* Self-URL helper (frame-aware).
 *
 * Standalone: returns "/darts.php" (or "/darts.php?game=42").
 * Framed inside /bar.php: returns "/bar.php?tab=darts" (or "/bar.php?tab=darts&game=42").
 *
 * The QR codes in the darts room point at /darts.php?board=N directly;
 * this helper only matters for in-page navigation links and redirects.
 */
function knk_darts_url(array $params = []): string {
    if (defined('KNK_BAR_FRAME')) {
        $params = array_merge(['tab' => 'darts'], $params);
        return '/bar.php?' . http_build_query($params);
    }
    return '/darts.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}

/* ----- Resolve which view we're rendering ----- */
$view = 'pick_board';
$board   = null;
$game    = null;
$initial = null;
$session_token = '';

knk_darts_cleanup_stale();
$cfg = knk_darts_config();
$enabled = !empty($cfg['enabled']);
$boards  = knk_darts_boards();

$qs_join = trim((string)($_GET['join'] ?? ''));
$qs_game = (int)($_GET['game'] ?? 0);
$qs_board = (int)($_GET['board'] ?? 0);

if ($qs_join !== '' && $qs_game === 0) {
    $g = knk_darts_game_by_join_code($qs_join);
    if ($g) {
        header("Location: " . knk_darts_url(['game' => (int)$g['id'], 'join' => 1]));
        exit;
    }
    $view = 'pick_board'; // bad code, fall back
}

if ($qs_game > 0) {
    $st = knk_db()->prepare("SELECT * FROM darts_games WHERE id = ?");
    $st->execute([$qs_game]);
    $game = $st->fetch();
    if (!$game) {
        header("Location: " . knk_darts_url());
        exit;
    }
    $cookie_name = "darts_token_{$qs_game}";
    $session_token = (string)($_COOKIE[$cookie_name] ?? '');
    $me = $session_token ? knk_darts_player_by_token($qs_game, $session_token) : null;

    if (!$me) {
        // Need to join first.
        $view = 'name_entry';
    } else {
        $view = 'in_game'; // lobby/playing/finished — the JS picks the right sub-view from status
    }
    $initial = knk_darts_view_state($qs_game, $session_token);
} elseif ($qs_board > 0) {
    foreach ($boards as $b) if ((int)$b['id'] === $qs_board) { $board = $b; break; }
    if (!$board) {
        header("Location: " . knk_darts_url());
        exit;
    }
    $active = knk_darts_active_game_on_board((int)$board['id']);
    if ($active) {
        if ($active['status'] === 'lobby') {
            // Drop them into the lobby view (as a joiner).
            header("Location: " . knk_darts_url(['game' => (int)$active['id']]));
            exit;
        }
        // A game is already playing on this board — let them watch.
        header("Location: " . knk_darts_url(['game' => (int)$active['id']]));
        exit;
    }
    $view = 'host_setup';
}

/* ----- Lobby state (Looking-for-Opponent) -----
 * Only meaningful inside the bar shell where bar.php has already
 * minted/restored a session anon-cookie. Standalone /darts.php
 * (table-side QR codes) skips the lobby — those flows are about
 * picking a specific board, not finding a stranger. */
$lobby_email      = strtolower(trim((string)($_SESSION['order_email'] ?? '')));
$show_lobby       = ($view === 'pick_board') && defined('KNK_BAR_FRAME') && $lobby_email !== '';
$lobby_am_looking = false;
$lobby_others     = [];
$lobby_pending    = [];
$recent_games     = [];
if ($show_lobby) {
    $lobby_am_looking = knk_darts_lobby_is_looking($lobby_email);
    $lobby_others     = knk_darts_lobby_active_lookers($lobby_email, 25);
    $lobby_pending    = knk_darts_lobby_pending_challenges($lobby_email, 5);
    $recent_games     = knk_darts_recent_games_compact(10);
}

?>
<?php if (!defined('KNK_BAR_FRAME')): ?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>KnK Inn — Darts</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<?php endif; ?>
  <style>
    :root {
      --gold: #c9aa71;
      --gold-soft: #d8c08b;
      --gold-deep: #a78348;
      --cream: #f5e9d1;
      --cream-dim: #d8c9ab;
      --brown-deep: #2a1a08;
      --brown-mid: #3a230d;
      --brown-bg: #1b0f04;
      --red: #b54141;
      --green: #5a8c4a;
    }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body {
      background: var(--brown-bg);
      color: var(--cream);
      font-family: "Inter", system-ui, sans-serif;
      min-height: 100vh;
      padding-bottom: 4rem;
      -webkit-tap-highlight-color: transparent;
    }
    main { max-width: 32rem; margin: 0 auto; padding: 1.2rem 1rem 0; }
    .brand {
      display: flex; align-items: center; justify-content: center; gap: 0.55rem;
      font-family: "Archivo Black", sans-serif; letter-spacing: .04em;
      font-size: 1.05rem; padding: 0.4rem 0 1rem;
    }
    .brand em { color: var(--gold); font-style: normal; }
    h1 {
      font-family: "Archivo Black", sans-serif;
      font-size: 1.7rem; letter-spacing: .04em;
      margin: 0.4rem 0 0.3rem; line-height: 1.1;
    }
    h1 .accent { color: var(--gold); }
    h2 {
      font-family: "Archivo Black", sans-serif;
      font-size: 1rem; letter-spacing: .05em;
      color: var(--gold); text-transform: uppercase;
      margin: 1.4rem 0 0.6rem;
    }
    .lede { color: var(--cream-dim); margin: 0 0 1rem; line-height: 1.55; font-size: 0.96rem; }
    .card {
      background: rgba(24,12,3,0.65);
      border: 1px solid rgba(201,170,113,0.22);
      border-radius: 8px;
      padding: 1.05rem 1rem;
      margin-bottom: 1rem;
    }
    .btn, button {
      font: inherit;
      background: var(--gold);
      color: var(--brown-deep);
      border: none;
      border-radius: 6px;
      padding: 0.7rem 1rem;
      font-weight: 700;
      cursor: pointer;
      width: 100%;
      transition: background 0.1s ease;
    }
    .btn:active, button:active { background: var(--gold-deep); }
    .btn.secondary {
      background: transparent;
      color: var(--cream);
      border: 1px solid rgba(201,170,113,0.4);
    }
    .btn.danger { background: var(--red); color: var(--cream); }
    .btn-row { display: flex; gap: 0.5rem; }
    .btn-row > .btn { flex: 1; }
    label {
      display: block;
      color: var(--cream-dim);
      font-size: 0.86rem;
      margin: 0.6rem 0 0.3rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    input[type=text], input[type=number], select {
      width: 100%;
      padding: 0.65rem 0.7rem;
      border-radius: 6px;
      border: 1px solid rgba(201,170,113,0.3);
      background: rgba(0,0,0,0.35);
      color: var(--cream);
      font: inherit;
      font-size: 1rem;
    }
    input:focus, select:focus { outline: 2px solid var(--gold); outline-offset: -1px; }
    .muted { color: var(--cream-dim); font-size: 0.88rem; }
    .err { color: #ff9b9b; font-size: 0.92rem; margin-top: 0.5rem; }
    .ok  { color: #b9d3a3; font-size: 0.92rem; margin-top: 0.5rem; }
    .pill {
      display: inline-block;
      padding: 0.2rem 0.55rem;
      border-radius: 999px;
      font-size: 0.78rem;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      background: rgba(201,170,113,0.16);
      color: var(--gold);
    }
    .pill.live { background: rgba(90,140,74,0.25); color: #b9d3a3; }
    .pill.busy { background: rgba(181,65,65,0.22); color: #ffb6b6; }

    /* Board picker */
    .board-grid { display: grid; grid-template-columns: 1fr; gap: 0.7rem; }
    .board-tile {
      display: flex; flex-direction: column; gap: 0.3rem;
      padding: 1rem;
      border: 1px solid rgba(201,170,113,0.3);
      border-radius: 8px;
      background: rgba(24,12,3,0.55);
      color: inherit;
      text-decoration: none;
    }
    .board-tile .name {
      font-family: "Archivo Black", sans-serif; font-size: 1.2rem;
      letter-spacing: 0.04em;
    }
    .board-tile.disabled { opacity: 0.55; }

    /* Setup */
    .game-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; }
    .game-grid label.tile {
      display: flex; flex-direction: column; gap: 0.2rem;
      padding: 0.85rem 0.7rem;
      border: 1px solid rgba(201,170,113,0.3);
      border-radius: 8px;
      background: rgba(24,12,3,0.55);
      cursor: pointer;
      margin: 0; font-size: 0.95rem; text-transform: none; letter-spacing: 0;
      color: var(--cream);
    }
    .game-grid label.tile input { display: none; }
    .game-grid label.tile.selected {
      border-color: var(--gold); background: rgba(201,170,113,0.12);
    }
    .game-grid .tile .ttl { font-family: "Archivo Black", sans-serif; font-size: 1rem; letter-spacing: 0.03em; }
    .game-grid .tile .sub { color: var(--cream-dim); font-size: 0.84rem; }

    .row { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .chip {
      flex: 1;
      padding: 0.7rem 0.5rem;
      text-align: center;
      border: 1px solid rgba(201,170,113,0.3);
      border-radius: 6px;
      background: rgba(24,12,3,0.55);
      cursor: pointer;
      font-weight: 600;
    }
    .chip.selected { border-color: var(--gold); background: rgba(201,170,113,0.12); color: var(--gold); }

    /* Lobby */
    .qr-card { text-align: center; }
    .qr-card .code {
      font-family: "Archivo Black", sans-serif;
      font-size: 2.2rem; letter-spacing: 0.18em;
      color: var(--gold);
      margin: 0.5rem 0 0.7rem;
    }
    .qr-card img { width: 220px; height: 220px; max-width: 90%; background: white; padding: 8px; border-radius: 6px; }
    .roster { display: flex; flex-direction: column; gap: 0.4rem; }
    .roster .slot {
      display: flex; align-items: center; gap: 0.7rem;
      padding: 0.6rem 0.75rem;
      border: 1px solid rgba(201,170,113,0.18);
      border-radius: 6px;
      background: rgba(24,12,3,0.45);
    }
    .roster .slot .num {
      width: 28px; height: 28px; border-radius: 50%;
      background: var(--gold); color: var(--brown-deep);
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 0.9rem;
    }
    .roster .slot.empty { opacity: 0.55; border-style: dashed; }
    .roster .slot .team { color: var(--cream-dim); font-size: 0.8rem; margin-left: auto; }

    /* Playing */
    .scoreboard { display: flex; flex-direction: column; gap: 0.45rem; margin-bottom: 1rem; }
    .scoreboard .row {
      display: flex; align-items: center; gap: 0.7rem;
      padding: 0.65rem 0.75rem;
      border-radius: 6px;
      background: rgba(24,12,3,0.5);
      border: 1px solid rgba(201,170,113,0.16);
    }
    .scoreboard .row.active {
      border-color: var(--gold);
      background: rgba(201,170,113,0.10);
      box-shadow: 0 0 0 1px var(--gold) inset;
    }
    .scoreboard .row.eliminated { opacity: 0.45; }
    .scoreboard .name { flex: 1; font-weight: 600; }
    .scoreboard .big {
      font-family: "Archivo Black", sans-serif;
      font-size: 1.4rem; letter-spacing: 0.02em;
      color: var(--gold);
    }
    .scoreboard .sub { color: var(--cream-dim); font-size: 0.82rem; }

    /* Cricket marks: the seven targets in a row of pips */
    .marks { display: flex; gap: 0.25rem; align-items: center; }
    .mark { display: flex; flex-direction: column; align-items: center; min-width: 1.6rem; }
    .mark .n { font-size: 0.66rem; color: var(--cream-dim); }
    .mark .pip { width: 14px; height: 14px; border-radius: 50%; border: 1px solid var(--gold-deep); }
    .mark .pip.h1::before { content: "/"; display: block; text-align: center; line-height: 12px; color: var(--gold); font-weight: 800; font-size: 14px; }
    .mark .pip.h2::before { content: "X"; display: block; text-align: center; line-height: 12px; color: var(--gold); font-weight: 800; font-size: 13px; }
    .mark .pip.h3 { background: var(--gold); border-color: var(--gold); }

    /* Numpad */
    .pad-card {
      position: sticky; bottom: 0;
      background: rgba(15,8,2,0.97);
      border-top: 2px solid var(--gold);
      padding: 0.8rem 0.9rem 1.1rem;
      margin: 0 -1rem;
    }
    .pad-card h3 {
      margin: 0 0 0.5rem;
      font-family: "Archivo Black", sans-serif; font-size: 0.95rem; letter-spacing: 0.04em;
      color: var(--gold);
    }
    .pad-card .turn-info { color: var(--cream-dim); font-size: 0.85rem; margin-bottom: 0.5rem; }
    .pad-row { display: flex; gap: 0.35rem; margin-bottom: 0.35rem; }
    .pad-row .mult-chip {
      flex: 1; padding: 0.55rem 0.4rem; text-align: center;
      border: 1px solid rgba(201,170,113,0.3);
      border-radius: 5px; background: rgba(24,12,3,0.55);
      font-weight: 700; cursor: pointer; font-size: 0.85rem;
    }
    .pad-row .mult-chip.selected { background: var(--gold); color: var(--brown-deep); border-color: var(--gold); }
    .pad-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 0.3rem;
    }
    .pad-grid .seg-btn {
      padding: 0.6rem 0;
      text-align: center;
      border: 1px solid rgba(201,170,113,0.3);
      border-radius: 5px;
      background: rgba(24,12,3,0.55);
      color: var(--cream);
      font-weight: 700;
      cursor: pointer;
      font-size: 0.95rem;
    }
    .pad-grid .seg-btn:active { background: var(--gold); color: var(--brown-deep); }
    .pad-grid .seg-btn.special { background: rgba(201,170,113,0.18); }
    .pad-actions { display: flex; gap: 0.4rem; margin-top: 0.55rem; }
    .pad-actions .btn { padding: 0.55rem; font-size: 0.85rem; }

    .turn-darts {
      display: flex; gap: 0.4rem; margin: 0.4rem 0 0.6rem;
    }
    .turn-darts .dart {
      flex: 1; padding: 0.5rem; text-align: center;
      border: 1px dashed rgba(201,170,113,0.35);
      border-radius: 5px;
      font-weight: 700;
      color: var(--cream-dim);
    }
    .turn-darts .dart.thrown { border-style: solid; color: var(--gold); border-color: var(--gold); }

    .winner-card {
      text-align: center; padding: 1.4rem 1rem;
      background: rgba(90,140,74,0.16);
      border: 1px solid rgba(143,200,107,0.4);
    }
    .winner-card .crown { font-size: 2.2rem; margin-bottom: 0.4rem; }
    .winner-card .who {
      font-family: "Archivo Black", sans-serif; font-size: 1.6rem;
      color: var(--cream); margin: 0.2rem 0;
    }

    .small-link { color: var(--gold); text-decoration: none; font-size: 0.9rem; }
    .small-link:hover { text-decoration: underline; }

    /* ============================================================
     * Looking-for-Opponent lobby (pick_board view, bar shell only)
     * ========================================================== */
    .lobby-incoming { animation: lobby-pulse 1.4s ease-in-out infinite; }
    @keyframes lobby-pulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(201,170,113,0.45); }
      50%      { box-shadow: 0 0 0 6px rgba(201,170,113,0); }
    }
    .lobby-incoming-row {
      display: flex; align-items: center; justify-content: space-between;
      gap: 0.6rem;
      padding: 0.6rem 0;
      border-bottom: 1px solid rgba(201,170,113,0.12);
    }
    .lobby-incoming-row:last-child { border-bottom: none; }
    .lobby-incoming-row .lobby-name {
      font-weight: 700; color: var(--cream);
    }
    .lobby-incoming-row .lobby-actions { display: flex; gap: 0.4rem; }
    .lobby-incoming-row .lobby-accept,
    .lobby-incoming-row .lobby-decline {
      padding: 0.42rem 0.95rem;
      border-radius: 6px;
      font-weight: 700;
      cursor: pointer;
      border: 1px solid transparent;
    }
    .lobby-incoming-row .lobby-accept {
      background: var(--gold); color: var(--brown-deep, #1b0f04);
      border-color: var(--gold);
    }
    .lobby-incoming-row .lobby-decline {
      background: transparent; color: var(--cream-dim);
      border-color: rgba(201,170,113,0.35);
    }

    .lobby-me-row {
      display: flex; align-items: center; justify-content: space-between;
      flex-wrap: wrap; gap: 0.6rem;
    }
    .lobby-me-status {
      font-weight: 600; color: var(--gold); font-size: 0.95rem;
    }
    .lobby-btn-primary {
      padding: 0.7rem 1.1rem;
      background: var(--gold); color: var(--brown-deep, #1b0f04);
      border: none; border-radius: 8px;
      font-family: "Archivo Black", sans-serif;
      font-size: 0.95rem; letter-spacing: 0.04em;
      cursor: pointer;
      width: 100%;
    }
    .lobby-btn-ghost {
      padding: 0.55rem 0.95rem;
      background: transparent; color: var(--cream-dim);
      border: 1px solid rgba(201,170,113,0.35);
      border-radius: 6px;
      font-weight: 600;
      cursor: pointer;
    }

    .lobby-list-wrap { margin-top: 1rem; }
    .lobby-list-h {
      font-size: 0.75rem; letter-spacing: 0.12em; text-transform: uppercase;
      color: var(--cream-dim); margin: 0 0 0.4rem;
    }
    .lobby-list { list-style: none; padding: 0; margin: 0; }
    .lobby-list-row {
      display: flex; align-items: center; justify-content: space-between;
      gap: 0.6rem;
      padding: 0.55rem 0;
      border-bottom: 1px solid rgba(201,170,113,0.1);
    }
    .lobby-list-row:last-child { border-bottom: none; }
    .lobby-list-row .lobby-name { font-weight: 600; color: var(--cream); }
    .lobby-challenge {
      padding: 0.4rem 0.85rem;
      background: rgba(201,170,113,0.12);
      color: var(--gold);
      border: 1px solid rgba(201,170,113,0.45);
      border-radius: 999px;
      font-weight: 700;
      cursor: pointer;
      font-size: 0.85rem;
    }
    .lobby-challenge:hover { background: rgba(201,170,113,0.25); }
    .lobby-challenge.is-sent {
      background: rgba(47,220,122,0.12);
      color: #2fdc7a;
      border-color: rgba(47,220,122,0.45);
      cursor: default;
    }

    /* Recent games list */
    .recent-games { list-style: none; padding: 0; margin: 0; }
    .rg-row {
      display: flex; gap: 0.7rem; align-items: baseline;
      padding: 0.5rem 0;
      border-bottom: 1px solid rgba(201,170,113,0.08);
      font-size: 0.92rem;
    }
    .rg-row:last-child { border-bottom: none; }
    .rg-when {
      flex: 0 0 auto;
      color: var(--cream-dim); font-size: 0.78rem;
      letter-spacing: 0.06em; min-width: 70px;
    }
    .rg-body { flex: 1 1 auto; min-width: 0; line-height: 1.35; }
    .rg-winner { color: var(--gold); font-weight: 700; }
    .rg-vs { color: var(--cream-dim); }
    .rg-loser { color: var(--cream); }
    .rg-meta { color: var(--cream-dim); font-size: 0.82rem; }
  </style>
<?php if (!defined('KNK_BAR_FRAME')): ?>
</head>
<body>
<?php endif; ?>
  <main>
<?php if (!defined('KNK_BAR_FRAME')): ?>
    <div class="brand">KnK <em>Inn</em> · Darts</div>
<?php endif; ?>

    <?php if (!$enabled): ?>
      <div class="card" style="text-align:center">
        <h1>Darts is <span class="accent">closed</span></h1>
        <p class="lede">Bar staff have switched the darts scoreboard off. Grab a beer and try again later.</p>
      </div>

    <?php elseif ($view === 'pick_board'): ?>
      <h1>Pick a <span class="accent">board</span></h1>
      <p class="lede">Tap the board you're playing on. The first phone on a free board becomes the host of the game.</p>
      <div class="board-grid">
        <?php foreach ($boards as $b): ?>
          <?php
            $bid = (int)$b['id'];
            $en  = !empty($b['enabled']);
            $active = $en ? knk_darts_active_game_on_board($bid) : null;
            $href = $en ? knk_darts_url(['board' => $bid]) : "#";
            $is_busy = $active !== null;
            $tile_class = "board-tile" . (!$en ? " disabled" : "");
          ?>
          <a class="<?= $tile_class ?>" href="<?= dh($href) ?>"<?= $is_busy ? ' style="border-color:rgba(201,170,113,0.55);"' : '' ?>>
            <div class="name"><?= dh($b['name']) ?></div>
            <?php if (!$en): ?>
              <div class="muted">Out of order</div>
            <?php elseif ($active): ?>
              <span class="pill <?= $active['status'] === 'playing' ? 'busy' : 'live' ?>">
                <?= $active['status'] === 'playing' ? 'Game in progress' : 'Lobby open' ?>
              </span>
              <div class="muted">Tap to <?= $active['status'] === 'playing' ? 'watch' : 'join' ?></div>
            <?php else: ?>
              <span class="pill">Free</span>
              <div class="muted">Tap to start a game</div>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>

      <?php if ($show_lobby): ?>
      <!-- ============================================================
           Looking-for-Opponent lobby. Only rendered inside the bar
           shell — needs $_SESSION["order_email"] to identify the guest.
           ============================================================ -->

      <?php if (!empty($lobby_pending)): ?>
        <!-- Incoming challenge cards — top of the section so a guest
             scrolling in sees them immediately. Each has Accept /
             Decline buttons that fire /api/darts_lobby.php. -->
        <div class="card lobby-incoming" style="margin-top:1.4rem; border-color:var(--gold);">
          <h2 style="margin-top:0">⚡ Someone wants to play you!</h2>
          <?php foreach ($lobby_pending as $pc): ?>
            <div class="lobby-incoming-row" data-challenge-id="<?= (int)$pc["id"] ?>">
              <div class="lobby-name"><?= dh($pc["challenger_display"]) ?></div>
              <div class="lobby-actions">
                <button type="button" class="lobby-accept"  data-id="<?= (int)$pc["id"] ?>">Accept</button>
                <button type="button" class="lobby-decline" data-id="<?= (int)$pc["id"] ?>">Decline</button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="card" style="margin-top:1.4rem">
        <h2 style="margin-top:0">Looking for an opponent?</h2>

        <div id="lobbyMeRow" class="lobby-me-row">
          <?php if ($lobby_am_looking): ?>
            <div class="lobby-me-status is-on">
              🟢 You're in the lobby — others can challenge you
            </div>
            <button type="button" id="lobbyClearBtn" class="lobby-btn-ghost">
              Stop looking
            </button>
          <?php else: ?>
            <p class="lede" style="margin:0 0 0.6rem">
              Tap below and your name shows up to other guests on the darts tab.
              First Challenge that lands wins your seat.
            </p>
            <button type="button" id="lobbySetBtn" class="lobby-btn-primary">
              I'm looking for an opponent
            </button>
          <?php endif; ?>
        </div>

        <?php if (!empty($lobby_others)): ?>
          <div class="lobby-list-wrap">
            <h3 class="lobby-list-h">Others looking now</h3>
            <ul class="lobby-list" id="lobbyList">
              <?php foreach ($lobby_others as $lo):
                $oe = (string)$lo["email"];
                $od = (string)($lo["display_name"] ?: $oe);
              ?>
                <li class="lobby-list-row" data-target-email="<?= dh($oe) ?>">
                  <span class="lobby-name"><?= dh($od) ?></span>
                  <button type="button" class="lobby-challenge"
                          data-target="<?= dh($oe) ?>">Challenge</button>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($show_lobby && !empty($recent_games)): ?>
      <!-- Recent games — last 10 across all boards. A bit of "what's
           been going on" social proof for the bar tab. -->
      <div class="card" style="margin-top:1.4rem">
        <h2 style="margin-top:0">Recent games</h2>
        <ul class="recent-games">
          <?php foreach ($recent_games as $rg):
            $when_ago = $rg["finished_ts"] > 0
              ? max(0, time() - $rg["finished_ts"])
              : 0;
            if ($when_ago < 60)            $ago_lbl = "just now";
            elseif ($when_ago < 3600)      $ago_lbl = (int)round($when_ago / 60) . " min ago";
            elseif ($when_ago < 86400)     $ago_lbl = (int)round($when_ago / 3600) . " hr ago";
            else                           $ago_lbl = (int)round($when_ago / 86400) . "d ago";

            $type_label = strtoupper($rg["game_type"]);
          ?>
            <li class="rg-row">
              <div class="rg-when"><?= dh($ago_lbl) ?></div>
              <div class="rg-body">
                <span class="rg-winner"><?= dh($rg["winner_name"] ?: "—") ?></span>
                <span class="rg-vs"> beat </span>
                <?php
                  $others = array_values(array_filter($rg["players"], function ($n) use ($rg) {
                    return $n !== $rg["winner_name"];
                  }));
                ?>
                <span class="rg-loser"><?= dh(implode(", ", $others) ?: "—") ?></span>
                <span class="rg-meta"> · <?= dh($type_label) ?>
                  <?php if (!empty($rg["board_name"])): ?>
                    · <?= dh($rg["board_name"]) ?>
                  <?php endif; ?>
                </span>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <div class="card" style="margin-top:1.4rem">
        <h2 style="margin-top:0">Got a join code?</h2>
        <p class="lede" style="margin-bottom:0.6rem">If you've been given a 6-letter code by the host, type it here.</p>
        <form method="get" action="<?= defined('KNK_BAR_FRAME') ? '/bar.php' : '/darts.php' ?>" style="display:flex; gap:0.4rem">
          <?php if (defined('KNK_BAR_FRAME')): ?>
          <input type="hidden" name="tab" value="darts">
          <?php endif; ?>
          <input type="text" name="join" placeholder="ABCDEF" maxlength="6" autocapitalize="characters" style="flex:1">
          <button type="submit" style="width:auto; padding:0.65rem 1rem">Join</button>
        </form>
      </div>

    <?php elseif ($view === 'host_setup'): ?>
      <h1>You're <span class="accent">hosting</span></h1>
      <p class="lede">You're playing on <strong><?= dh($board['name']) ?></strong>. Pick a game and your group, then we'll show a QR for the others to join.</p>

      <form id="setupForm">
        <input type="hidden" name="board_id" value="<?= (int)$board['id'] ?>">

        <h2>Game</h2>
        <div class="game-grid">
          <?php
            $games = [
              ['code' => '501',         'title' => '501',             'sub' => 'Subtract to zero, finish on a double.'],
              ['code' => '301',         'title' => '301',             'sub' => 'Quicker version of 501.'],
              ['code' => 'cricket',     'title' => 'Cricket',         'sub' => 'Close 15-20 + bull, score on the rest.'],
              ['code' => 'aroundclock', 'title' => 'Around the Clock','sub' => 'Hit 1-20 in order, finish on bull.'],
              ['code' => 'killer',      'title' => 'Killer',          'sub' => 'Hit your double, then take their lives.'],
              ['code' => 'halveit',     'title' => 'Halve-It',        'sub' => 'Six rounds. Miss them all and your score halves.'],
            ];
            foreach ($games as $g): ?>
            <label class="tile" data-game-tile="<?= dh($g['code']) ?>">
              <input type="radio" name="game_type" value="<?= dh($g['code']) ?>">
              <span class="ttl"><?= dh($g['title']) ?></span>
              <span class="sub"><?= dh($g['sub']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>

        <h2>Players</h2>
        <div class="row" id="playerCountRow">
          <?php for ($i = 1; $i <= 4; $i++): ?>
            <div class="chip" data-player-count="<?= $i ?>"><?= $i ?></div>
          <?php endfor; ?>
        </div>

        <h2>Format</h2>
        <div class="row" id="formatRow">
          <div class="chip selected" data-format="singles">Singles</div>
          <div class="chip" data-format="doubles">Doubles (4 only)</div>
        </div>

        <h2>Your name</h2>
        <input type="text" name="host_name" id="hostName" placeholder="e.g. Tom" maxlength="40" autocapitalize="words">

        <div id="setupError" class="err" style="display:none"></div>

        <button type="button" id="hostStartBtn" style="margin-top:1rem">Open the lobby</button>
        <a class="small-link" href="<?= dh(knk_darts_url()) ?>" style="display:block; text-align:center; margin-top:0.7rem">← Pick a different board</a>
      </form>

    <?php elseif ($view === 'name_entry'): ?>
      <h1>Join <span class="accent"><?= dh($game['game_type']) ?></span></h1>
      <p class="lede">Enter your name and you'll be added to the lobby. The host will hit start when the group's ready.</p>
      <form id="joinForm">
        <input type="hidden" name="game_id" value="<?= (int)$game['id'] ?>">
        <h2>Your name</h2>
        <input type="text" name="name" id="joinName" placeholder="e.g. Brian" maxlength="40" autocapitalize="words">
        <div id="joinError" class="err" style="display:none"></div>
        <button type="button" id="joinBtn" style="margin-top:1rem">Join the lobby</button>
        <a class="small-link" href="<?= dh(knk_darts_url()) ?>" style="display:block; text-align:center; margin-top:0.7rem">← Cancel</a>
      </form>

    <?php else: /* in_game */ ?>
      <div id="gameRoot"></div>
    <?php endif; ?>

  </main>

  <script>
    var INITIAL    = <?= json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var GAME_ID    = <?= (int)($game['id'] ?? 0) ?>;
    var TOKEN      = <?= json_encode($session_token, JSON_UNESCAPED_SLASHES) ?>;
    var POLL_MS    = 2000;
    var BOARD_ID   = <?= (int)($board['id'] ?? 0) ?>;
    var BOARD_NAME = <?= json_encode($board['name'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
    var VIEW       = <?= json_encode($view) ?>;

    /* Frame-aware URL builder.
     * Standalone:    dartsUrl({game: 7})  →  "/darts.php?game=7"
     * Inside /bar.php: dartsUrl({game: 7})  →  "/bar.php?tab=darts&game=7" */
    var DARTS_BASE_PATH = <?= json_encode(defined('KNK_BAR_FRAME') ? '/bar.php' : '/darts.php') ?>;
    var DARTS_BASE_PARAMS = <?= defined('KNK_BAR_FRAME') ? "{tab: 'darts'}" : "{}" ?>;
    function dartsUrl(extra) {
      var all = {}, k;
      for (k in DARTS_BASE_PARAMS) all[k] = DARTS_BASE_PARAMS[k];
      if (extra) for (k in extra) all[k] = extra[k];
      var qs = '';
      for (k in all) {
        qs += (qs ? '&' : '?') + encodeURIComponent(k) + '=' + encodeURIComponent(all[k]);
      }
      return DARTS_BASE_PATH + qs;
    }

    function $(s, root) { return (root || document).querySelector(s); }
    function $$(s, root) { return Array.prototype.slice.call((root || document).querySelectorAll(s)); }

    function setCookie(name, val) {
      document.cookie = name + "=" + encodeURIComponent(val) + "; path=/; max-age=86400; SameSite=Lax";
    }

    /* ============================================================
     * PICK BOARD — Looking-for-Opponent lobby wiring
     * ============================================================ */
    if (VIEW === 'pick_board') (function () {
      var SHOW_LOBBY = <?= $show_lobby ? 'true' : 'false' ?>;
      if (!SHOW_LOBBY) return;

      var setBtn   = $('#lobbySetBtn');
      var clearBtn = $('#lobbyClearBtn');

      function call(action, extra) {
        var fd = new FormData();
        fd.append('action', action);
        if (extra) for (var k in extra) fd.append(k, extra[k]);
        return fetch('/api/darts_lobby.php', {
          method: 'POST', body: fd, credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
      }

      function reload() {
        // Server-rendered the page state, simplest way to refresh
        // is to reload — keeps the UI in lock-step with the DB.
        window.location.reload();
      }

      if (setBtn) {
        setBtn.addEventListener('click', function () {
          setBtn.disabled = true;
          setBtn.textContent = "Joining the lobby...";
          call('set_looking').then(function (j) {
            if (!j || !j.ok) {
              setBtn.disabled = false;
              setBtn.textContent = "I'm looking for an opponent";
              alert((j && j.error) || "Couldn't join the lobby.");
              return;
            }
            reload();
          });
        });
      }

      if (clearBtn) {
        clearBtn.addEventListener('click', function () {
          clearBtn.disabled = true;
          call('clear').then(function () { reload(); });
        });
      }

      $$('.lobby-challenge').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var target = btn.getAttribute('data-target') || '';
          if (!target || btn.classList.contains('is-sent')) return;
          var oldText = btn.textContent;
          btn.disabled = true;
          btn.textContent = "Sending...";
          call('challenge', { target_email: target }).then(function (j) {
            if (j && j.ok) {
              btn.classList.add('is-sent');
              btn.textContent = "Sent ✓";
            } else {
              btn.disabled = false;
              btn.textContent = oldText;
              alert((j && j.error) || "Couldn't fire the challenge.");
            }
          });
        });
      });

      $$('.lobby-accept').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = btn.getAttribute('data-id');
          btn.disabled = true; btn.textContent = "Starting game...";
          call('respond', { challenge_id: id, accept: 1 }).then(function (j) {
            if (j && j.ok && j.game_id) {
              // Land both players on the new game's view. Frame-aware
              // so we stay inside the bar shell when wrapped.
              var prefix = <?= json_encode(defined('KNK_BAR_FRAME') ? '/bar.php?tab=darts&game=' : '/darts.php?game=') ?>;
              window.location.href = prefix + j.game_id;
            } else {
              btn.disabled = false; btn.textContent = "Accept";
              alert((j && j.error) || "Couldn't accept.");
            }
          });
        });
      });

      $$('.lobby-decline').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = btn.getAttribute('data-id');
          btn.disabled = true; btn.textContent = "Declining...";
          call('respond', { challenge_id: id, accept: 0 }).then(function () { reload(); });
        });
      });

      // Polling — refresh the lobby state every 8s so other guests
      // showing up in the list / new incoming challenges appear
      // without manual refresh. We just diff a few signal counts;
      // any meaningful change triggers a full reload.
      var lastState = {
        am_looking:  <?= $lobby_am_looking ? 'true' : 'false' ?>,
        lookers:     <?= count($lobby_others) ?>,
        challenges:  <?= count($lobby_pending) ?>
      };
      setInterval(function () {
        call('state').then(function (j) {
          if (!j || !j.ok || !j.state) return;
          var s = j.state;
          var sig = {
            am_looking: !!s.am_looking,
            lookers:    (s.lookers || []).length,
            challenges: (s.incoming_challenges || []).length
          };
          if (sig.am_looking !== lastState.am_looking
              || sig.lookers     !== lastState.lookers
              || sig.challenges  !== lastState.challenges) {
            reload();
          }
        });
      }, 8000);
    })();

    /* ============================================================
     * HOST SETUP
     * ============================================================ */
    if (VIEW === 'host_setup') (function () {
      var selected = { game_type: null, player_count: null, format: 'singles' };

      $$('[data-game-tile]').forEach(function (el) {
        el.addEventListener('click', function () {
          $$('[data-game-tile]').forEach(function (e) { e.classList.remove('selected'); });
          el.classList.add('selected');
          selected.game_type = el.getAttribute('data-game-tile');
          // Killer needs at least 2 players, default to 2.
          if (selected.game_type === 'killer' && !selected.player_count) {
            $$('#playerCountRow .chip').forEach(function (c) {
              if (c.getAttribute('data-player-count') === '2') c.click();
            });
          }
        });
      });

      $$('#playerCountRow .chip').forEach(function (el) {
        el.addEventListener('click', function () {
          $$('#playerCountRow .chip').forEach(function (e) { e.classList.remove('selected'); });
          el.classList.add('selected');
          selected.player_count = parseInt(el.getAttribute('data-player-count'), 10);
          // If doubles is selected and count != 4, drop back to singles.
          if (selected.format === 'doubles' && selected.player_count !== 4) {
            $$('#formatRow .chip').forEach(function (c) {
              c.classList.toggle('selected', c.getAttribute('data-format') === 'singles');
            });
            selected.format = 'singles';
          }
        });
      });

      $$('#formatRow .chip').forEach(function (el) {
        el.addEventListener('click', function () {
          var fmt = el.getAttribute('data-format');
          if (fmt === 'doubles' && selected.player_count !== 4) {
            // Auto-select 4 players.
            $$('#playerCountRow .chip').forEach(function (c) {
              c.classList.toggle('selected', c.getAttribute('data-player-count') === '4');
            });
            selected.player_count = 4;
          }
          $$('#formatRow .chip').forEach(function (e) { e.classList.remove('selected'); });
          el.classList.add('selected');
          selected.format = fmt;
        });
      });

      $('#hostStartBtn').addEventListener('click', function () {
        var err = $('#setupError');
        err.style.display = 'none';
        if (!selected.game_type)    { err.textContent = 'Pick a game.'; err.style.display = 'block'; return; }
        if (!selected.player_count) { err.textContent = 'How many of you are playing?'; err.style.display = 'block'; return; }
        var name = $('#hostName').value.trim();
        if (!name) { err.textContent = 'Tell us your name.'; err.style.display = 'block'; return; }

        var fd = new FormData();
        fd.append('board_id', String(BOARD_ID));
        fd.append('game_type', selected.game_type);
        fd.append('format', selected.format);
        fd.append('player_count', String(selected.player_count));
        fd.append('host_name', name);

        $('#hostStartBtn').disabled = true;
        $('#hostStartBtn').textContent = 'Opening lobby...';

        fetch('/api/darts_create.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (!j.ok) throw new Error(j.error || 'Could not create the game.');
            setCookie('darts_token_' + j.game_id, j.session_token);
            window.location.href = dartsUrl({game: j.game_id});
          })
          .catch(function (e) {
            err.textContent = e.message; err.style.display = 'block';
            $('#hostStartBtn').disabled = false;
            $('#hostStartBtn').textContent = 'Open the lobby';
          });
      });
    })();

    /* ============================================================
     * NAME ENTRY (joining a lobby)
     * ============================================================ */
    if (VIEW === 'name_entry') (function () {
      $('#joinBtn').addEventListener('click', function () {
        var name = $('#joinName').value.trim();
        var err = $('#joinError');
        err.style.display = 'none';
        if (!name) { err.textContent = 'Tell us your name.'; err.style.display = 'block'; return; }

        var fd = new FormData();
        fd.append('game_id', String(GAME_ID));
        fd.append('name', name);

        $('#joinBtn').disabled = true;
        $('#joinBtn').textContent = 'Joining...';

        fetch('/api/darts_join.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (!j.ok) throw new Error(j.error || 'Could not join.');
            setCookie('darts_token_' + j.game_id, j.session_token);
            window.location.reload();
          })
          .catch(function (e) {
            err.textContent = e.message; err.style.display = 'block';
            $('#joinBtn').disabled = false;
            $('#joinBtn').textContent = 'Join the lobby';
          });
      });
    })();

    /* ============================================================
     * IN-GAME (lobby / playing / finished)
     * ============================================================ */
    if (VIEW === 'in_game') (function () {
      var root = $('#gameRoot');
      var pollTimer = null;
      var lastState = INITIAL;
      var pad = { mult: 1 }; // current multiplier in the numpad

      function poll() {
        fetch('/api/darts_state.php?game=' + GAME_ID + '&token=' + encodeURIComponent(TOKEN), { cache: 'no-store' })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (!j.ok) return;
            lastState = j;
            render(j);
          })
          .catch(function () {});
      }

      function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
          return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
      }

      function gameLabel(t) {
        return { '501':'501','301':'301','cricket':'Cricket','aroundclock':'Around the Clock','killer':'Killer','halveit':'Halve-It' }[t] || t;
      }

      function render(s) {
        var st = s.game.status;
        if (st === 'lobby')    return renderLobby(s);
        if (st === 'playing')  return renderPlaying(s);
        if (st === 'finished' || st === 'abandoned') return renderFinished(s);
      }

      /* ----- LOBBY ----- */
      function renderLobby(s) {
        var g = s.game;
        var amHost = s.me.is_host;
        var slots = [];
        for (var i = 1; i <= g.player_count; i++) slots.push(i);
        var byslot = {};
        s.players.forEach(function (p) { byslot[p.slot_no] = p; });

        /* QR codes always point to the standalone /darts.php — guests
         * scan from another phone that hasn't been browsing /bar.php yet. */
        var qrUrl = location.origin + '/darts.php?join=' + encodeURIComponent(g.join_code);
        var qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&margin=0&data=' + encodeURIComponent(qrUrl);

        var html = '';
        html += '<h1>Lobby — <span class="accent">' + escapeHtml(gameLabel(g.game_type)) + '</span></h1>';
        html += '<p class="lede">' + escapeHtml(g.format === 'doubles' ? 'Doubles · 4 players · pairs are 1+3 vs 2+4.' : ('Singles · ' + g.player_count + ' player' + (g.player_count === 1 ? '' : 's'))) + '</p>';

        if (amHost) {
          html += '<div class="card qr-card">';
          html += '<h2 style="margin-top:0">Players join here</h2>';
          html += '<img src="' + qrSrc + '" alt="QR code">';
          html += '<div class="muted" style="margin-top:0.5rem">or type this code on the Darts page</div>';
          html += '<div class="code">' + escapeHtml(g.join_code) + '</div>';
          html += '</div>';
        } else {
          html += '<div class="card" style="text-align:center">';
          html += '<div class="muted">Code</div>';
          html += '<div style="font-family:Archivo Black, sans-serif; font-size:1.6rem; letter-spacing:0.18em; color:var(--gold); margin:0.3rem 0">' + escapeHtml(g.join_code) + '</div>';
          html += '<div class="muted">Waiting for the host to start.</div>';
          html += '</div>';
        }

        html += '<h2>Roster</h2><div class="roster">';
        slots.forEach(function (slot) {
          var p = byslot[slot];
          if (p) {
            var team = (g.format === 'doubles') ? ('Team ' + p.team_no) : '';
            html += '<div class="slot"><div class="num">' + slot + '</div><div>' + escapeHtml(p.name) + (p.is_host ? ' <span class="pill">Host</span>' : '') + '</div>';
            if (team) html += '<div class="team">' + escapeHtml(team) + '</div>';
            html += '</div>';
          } else {
            html += '<div class="slot empty"><div class="num">' + slot + '</div><div class="muted">Waiting…</div></div>';
          }
        });
        html += '</div>';

        if (amHost) {
          var ready = (s.players.length >= 1);
          html += '<button id="startBtn" style="margin-top:1.1rem"' + (ready ? '' : ' disabled') + '>';
          html += ready ? 'Start the game' : 'Need at least 1 player';
          html += '</button>';
          html += '<div id="lobbyErr" class="err" style="display:none"></div>';
        }

        html += '<a class="small-link" href="' + dartsUrl() + '" style="display:block; text-align:center; margin-top:0.9rem">← Leave the lobby</a>';

        root.innerHTML = html;

        if (amHost) {
          $('#startBtn').addEventListener('click', function () {
            var btn = $('#startBtn');
            btn.disabled = true;
            btn.textContent = 'Starting…';
            var fd = new FormData();
            fd.append('game_id', String(GAME_ID));
            fd.append('token', TOKEN);
            fetch('/api/darts_start.php', { method: 'POST', body: fd })
              .then(function (r) { return r.json(); })
              .then(function (j) {
                if (!j.ok) throw new Error(j.error || 'Could not start.');
                poll();
              })
              .catch(function (e) {
                btn.disabled = false; btn.textContent = 'Start the game';
                var err = $('#lobbyErr'); err.textContent = e.message; err.style.display = 'block';
              });
          });
        }
      }

      /* ----- PLAYING ----- */
      function renderPlaying(s) {
        var g  = s.game;
        var sb = s.scoreboard || {};
        var byslot = {};
        s.players.forEach(function (p) { byslot[p.slot_no] = p; });
        var myTurn = (s.me.slot_no && s.me.slot_no === g.current_slot_no);

        var html = '';
        html += '<h1>' + escapeHtml(gameLabel(g.game_type)) + ' <span class="muted" style="font-size:1rem; font-weight:400">· ' + escapeHtml(BOARD_NAME) + '</span></h1>';
        html += '<div class="scoreboard">';
        s.players.sort(function (a, b) { return a.slot_no - b.slot_no; }).forEach(function (p) {
          var active = (p.slot_no === g.current_slot_no);
          var rowCls = 'row' + (active ? ' active' : '');
          var nameTag = escapeHtml(p.name);
          if (p.is_host) nameTag += ' <span class="pill">Host</span>';
          if (g.format === 'doubles') nameTag += ' <span class="muted" style="font-size:0.78rem">T' + p.team_no + '</span>';

          html += '<div class="' + rowCls + '">';
          html += '<div style="display:flex; flex-direction:column; flex:1">';
          html += '<div class="name">' + nameTag + '</div>';
          html += renderScoreLine(g, sb, p);
          html += '</div>';
          html += '</div>';
        });
        html += '</div>';

        // Doubles team summary for 501/301
        if (g.format === 'doubles' && (g.game_type === '501' || g.game_type === '301') && sb.team_remaining) {
          html += '<div class="card" style="display:flex; gap:1rem; justify-content:space-around; text-align:center">';
          [1, 2].forEach(function (t) {
            html += '<div><div class="muted">Team ' + t + '</div><div class="big" style="font-size:1.8rem; font-family:Archivo Black,sans-serif; color:var(--gold)">' + (sb.team_remaining[t] || 0) + '</div></div>';
          });
          html += '</div>';
        }

        // Numpad — only the active player sees it.
        if (myTurn) {
          html += renderNumpad(s);
        } else {
          var who = byslot[g.current_slot_no] ? byslot[g.current_slot_no].name : 'Someone';
          html += '<div class="card" style="text-align:center"><div class="muted">' + escapeHtml(who) + ' is throwing…</div>';
          html += '<div class="muted" style="font-size:0.8rem; margin-top:0.4rem">Dart ' + g.current_dart_no + ' of 3</div></div>';
        }

        root.innerHTML = html;

        if (myTurn) wireNumpad(s);
      }

      function renderScoreLine(g, sb, p) {
        var t = g.game_type;
        if (t === '501' || t === '301') {
          if (g.format === 'doubles') {
            var rem = (sb.team_remaining && sb.team_remaining[p.team_no]) || (t === '501' ? 501 : 301);
            return '<div class="big">' + rem + '</div>';
          }
          var ps = (sb.players && sb.players[p.slot_no]) || { remaining: (t === '501' ? 501 : 301), last_turn_total: 0 };
          var sub = ps.last_turn_total ? ('Last turn: −' + ps.last_turn_total) : '';
          return '<div style="display:flex; align-items:baseline; gap:0.6rem"><div class="big">' + ps.remaining + '</div><div class="sub">' + sub + '</div></div>';
        }
        if (t === 'cricket') {
          var marks, score;
          if (g.format === 'doubles') {
            marks = (sb.team_marks && sb.team_marks[p.team_no]) || {};
            score = (sb.team_score && sb.team_score[p.team_no]) || 0;
          } else {
            var pp = (sb.players && sb.players[p.slot_no]) || { marks: {}, score: 0 };
            marks = pp.marks || {}; score = pp.score || 0;
          }
          var html = '<div class="marks">';
          [15,16,17,18,19,20,25].forEach(function (n) {
            var hits = marks[n] || 0;
            var cls = 'pip h' + Math.min(hits, 3);
            html += '<div class="mark"><div class="n">' + (n === 25 ? 'B' : n) + '</div><div class="' + cls + '"></div></div>';
          });
          html += '</div><div class="sub" style="margin-top:0.2rem">Score: <strong style="color:var(--gold)">' + score + '</strong></div>';
          return html;
        }
        if (t === 'aroundclock') {
          var ps = (sb.players && sb.players[p.slot_no]) || { needs: '1', progress: 0 };
          return '<div style="display:flex; align-items:baseline; gap:0.6rem"><div class="big">Needs ' + ps.needs + '</div><div class="sub">' + ps.progress + ' / 20</div></div>';
        }
        if (t === 'killer') {
          var ps = (sb.players && sb.players[p.slot_no]) || { lives: 3, killer: false, eliminated: false, number: '–' };
          var hearts = '';
          for (var i = 0; i < ps.lives; i++) hearts += '♥';
          var status = ps.eliminated ? 'OUT' : (ps.killer ? '🗡 KILLER' : 'needs D' + (ps.number || '?'));
          return '<div style="display:flex; align-items:baseline; gap:0.6rem"><div class="big">' + hearts + '</div><div class="sub">' + status + '</div></div>';
        }
        if (t === 'halveit') {
          var ps = (sb.players && sb.players[p.slot_no]) || { score: 0, round: 1 };
          var targets = sb.targets || [];
          var label = targets[ps.round - 1] ? targets[ps.round - 1].label : '–';
          return '<div style="display:flex; align-items:baseline; gap:0.6rem"><div class="big">' + ps.score + '</div><div class="sub">Round ' + ps.round + ' / 6 · ' + label + '</div></div>';
        }
        return '';
      }

      /* ----- NUMPAD ----- */
      function renderNumpad(s) {
        var html = '<div class="pad-card">';
        html += '<h3>Your turn — dart ' + s.game.current_dart_no + ' / 3</h3>';
        html += '<div class="turn-darts">';
        for (var i = 1; i <= 3; i++) {
          html += '<div class="dart' + (i < s.game.current_dart_no ? ' thrown' : '') + '">' + i + '</div>';
        }
        html += '</div>';

        html += '<div class="pad-row">';
        ['1','2','3'].forEach(function (m) {
          html += '<div class="mult-chip' + (parseInt(m, 10) === pad.mult ? ' selected' : '') + '" data-mult="' + m + '">';
          html += (m === '1' ? 'Single' : (m === '2' ? 'Double' : 'Treble'));
          html += '</div>';
        });
        html += '</div>';

        html += '<div class="pad-grid">';
        for (var n = 1; n <= 20; n++) {
          html += '<div class="seg-btn" data-num="' + n + '">' + n + '</div>';
        }
        html += '<div class="seg-btn special" data-seg="SBULL">25</div>';
        html += '<div class="seg-btn special" data-seg="DBULL">BULL</div>';
        html += '<div class="seg-btn special" data-seg="MISS" style="grid-column: span 3">Miss</div>';
        html += '</div>';

        html += '<div class="pad-actions">';
        html += '<button class="btn secondary" id="undoBtn">Undo last</button>';
        html += '</div>';
        html += '<div id="padErr" class="err" style="display:none"></div>';
        html += '</div>';
        return html;
      }

      function wireNumpad(s) {
        $$('.pad-row .mult-chip').forEach(function (el) {
          el.addEventListener('click', function () {
            pad.mult = parseInt(el.getAttribute('data-mult'), 10);
            $$('.pad-row .mult-chip').forEach(function (e) { e.classList.remove('selected'); });
            el.classList.add('selected');
          });
        });
        $$('.pad-grid .seg-btn').forEach(function (el) {
          el.addEventListener('click', function () {
            var seg = el.getAttribute('data-seg');
            if (!seg) {
              var n = parseInt(el.getAttribute('data-num'), 10);
              var prefix = pad.mult === 1 ? 'S' : pad.mult === 2 ? 'D' : 'T';
              seg = prefix + n;
            }
            sendThrow(seg);
          });
        });
        $('#undoBtn').addEventListener('click', function () {
          var fd = new FormData();
          fd.append('game_id', String(GAME_ID));
          fd.append('token', TOKEN);
          fetch('/api/darts_undo.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
              if (!j.ok) throw new Error(j.error || 'Undo failed.');
              poll();
            })
            .catch(function (e) {
              var err = $('#padErr'); err.textContent = e.message; err.style.display = 'block';
            });
        });
      }

      function sendThrow(seg) {
        var fd = new FormData();
        fd.append('game_id', String(GAME_ID));
        fd.append('token', TOKEN);
        fd.append('segment', seg);
        // Reset multiplier back to single after each throw to match
        // how scorers actually work — most darts are singles.
        pad.mult = 1;
        fetch('/api/darts_throw.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (!j.ok) throw new Error(j.error || 'Could not record.');
            poll();
          })
          .catch(function (e) {
            var err = $('#padErr');
            if (err) { err.textContent = e.message; err.style.display = 'block'; }
          });
      }

      /* ----- FINISHED ----- */
      function renderFinished(s) {
        var g = s.game;
        var winnerName = '—';
        if (g.winner_slot_no) {
          var w = s.players.filter(function (p) { return p.slot_no === g.winner_slot_no; })[0];
          if (w) winnerName = w.name;
        } else if (g.winner_team_no) {
          var members = s.players.filter(function (p) { return p.team_no === g.winner_team_no; });
          winnerName = members.map(function (p) { return p.name; }).join(' & ');
        }

        var html = '';
        html += '<div class="card winner-card">';
        html += '<div class="crown">🏆</div>';
        html += '<div class="muted">Winner</div>';
        html += '<div class="who">' + escapeHtml(winnerName) + '</div>';
        html += '<div class="muted">' + escapeHtml(gameLabel(g.game_type)) + (g.format === 'doubles' ? ' · doubles' : '') + '</div>';
        html += '</div>';

        html += '<h2>Final scoreboard</h2>';
        html += '<div class="scoreboard">';
        s.players.sort(function (a, b) { return a.slot_no - b.slot_no; }).forEach(function (p) {
          html += '<div class="row">';
          html += '<div style="display:flex; flex-direction:column; flex:1">';
          html += '<div class="name">' + escapeHtml(p.name) + '</div>';
          html += renderScoreLine(g, s.scoreboard || {}, p);
          html += '</div></div>';
        });
        html += '</div>';

        html += '<a href="' + dartsUrl() + '" class="btn" style="display:block; text-align:center; text-decoration:none; margin-top:1rem">Play another game</a>';

        root.innerHTML = html;
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
      }

      /* ----- BOOT ----- */
      render(INITIAL);
      pollTimer = setInterval(poll, POLL_MS);
    })();
  </script>
<?php if (!defined('KNK_BAR_FRAME')): ?>
</body>
</html>
<?php endif; ?>

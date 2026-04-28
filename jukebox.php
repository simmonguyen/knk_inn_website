<?php
/*
 * KnK Inn — /jukebox.php  (guest request page)
 *
 * Phone-friendly. Guest scans the QR code on their table, types
 * Artist + Song Title, hits Queue. We search YouTube server-side
 * and add the top match to the queue. Shows current "Up Next" so
 * the guest sees their request show up after submit.
 *
 * No login. Anti-spam guards live in includes/jukebox.php
 * (per-IP cooldown, queue length cap, duration cap, blocklist).
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . "/includes/jukebox.php";
require_once __DIR__ . "/includes/hours.php";

/* Closed-hours gate. Outside service hours (07:30–12:30 / 16:00–23:30
 * Saigon time) we don't accept new song requests. */
if (!knk_bar_is_open()) {
    knk_bar_render_closed_and_exit("Jukebox");
}

function jbh($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
/* For YouTube-sourced text (titles, channel names) — decode once first
 * to flatten any HTML entities the API embedded (`&quot;`, `&amp;`,
 * `&#39;`), then re-encode for safe HTML insertion. Idempotent: titles
 * stored decoded by the new ingestion path also pass through cleanly. */
function jbh_yt($s): string {
    $decoded = html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, "UTF-8");
    return htmlspecialchars($decoded, ENT_QUOTES, "UTF-8");
}

/* Self-URL (frame-aware). When included from /bar.php, KNK_BAR_FRAME is
 * defined and self-references should keep the user inside the bar shell. */
$KNK_SELF_URL = defined('KNK_BAR_FRAME') ? '/bar.php?tab=music' : '/jukebox.php';

/* ----------- POST: submit a request ----------- */
$post_action = (string)($_POST["action"] ?? "");

if ($post_action === "submit") {
    try {
        $ip = knk_jukebox_client_ip();
        $result = knk_jukebox_request_submit([
            "artist"   => (string)($_POST["artist"]   ?? ""),
            "title"    => (string)($_POST["title"]    ?? ""),
            "name"     => (string)($_POST["name"]     ?? ""),
            "table_no" => (string)($_POST["table_no"] ?? ""),
            /* Pass through the bar-shell guest identity (anon or claimed)
             * so the song shows up on the guest's profile history. Standalone
             * /jukebox.php with no session leaves this empty. */
            "email"    => (string)($_SESSION["order_email"] ?? ""),
        ], $ip);
        $_SESSION["jukebox_result"] = ["ok" => true, "data" => $result];
    } catch (Throwable $e) {
        $_SESSION["jukebox_result"] = [
            "ok"    => false,
            "error" => $e->getMessage(),
            // Echo back the typed values so the form is pre-filled.
            "echo"  => [
                "artist"   => (string)($_POST["artist"]   ?? ""),
                "title"    => (string)($_POST["title"]    ?? ""),
                "name"     => (string)($_POST["name"]     ?? ""),
                "table_no" => (string)($_POST["table_no"] ?? ""),
            ],
        ];
    }
    header("Location: " . $KNK_SELF_URL);
    exit;
}

if ($post_action === "cancel") {
    /* Guest-side cancel of a song they queued. Only succeeds when:
     *   - the song row's requester_email matches this session
     *   - the song is still in pending/queued (not playing/played)
     * Anything else (someone else's song, already-playing, or
     * already-finished) silently no-ops; we redirect either way so
     * a refresh doesn't replay the cancel. */
    $cancel_id = (int)($_POST["song_id"] ?? 0);
    $cancel_email = (string)($_SESSION["order_email"] ?? "");
    if ($cancel_id > 0 && $cancel_email !== "") {
        $cancelled = knk_jukebox_cancel_by_guest($cancel_id, $cancel_email);
        $_SESSION["jukebox_flash"] = $cancelled
            ? "Song cancelled."
            : "Couldn't cancel that — it may already be playing.";
    }
    header("Location: " . $KNK_SELF_URL);
    exit;
}

/* ----------- GET: render ----------- */
$result = $_SESSION["jukebox_result"] ?? null;
unset($_SESSION["jukebox_result"]);

$cfg     = knk_jukebox_config();
$enabled = !empty($cfg["enabled"]);
$require_table = !empty($cfg["require_table_no"]);
$max_dur_min   = max(1, (int)ceil(((int)$cfg["max_duration_seconds"]) / 60));

$now_playing = knk_jukebox_now_playing();
$up_next     = knk_jukebox_up_next(8);

/* "Your songs" panel (bar shell only — needs the guest's session
 * identity to filter). Latest 8 of theirs, active rows surfaced
 * first by knk_jukebox_songs_for_email's status-priority sort. */
$my_email     = (string)($_SESSION["order_email"] ?? "");
$my_songs     = (defined('KNK_BAR_FRAME') && $my_email !== "")
    ? knk_jukebox_songs_for_email($my_email, 8)
    : [];

/* "Recently played at the bar" panel — last 25 actually-played
 * songs, all guests, all time. status='played' only (we hide the
 * skipped / rejected / cancelled-by-guest ones from the bar's
 * social view). Read here unconditionally — it's a single
 * cheap indexed query and the list fits in any context. */
$bar_recent = knk_jukebox_recent_played(25);

/* Per-guest playlist — used by the "My playlist" card in the bar
 * shell. Empty array when there's no order_email (standalone
 * /jukebox.php access). knk_playlist_list() is owner-scoped so a
 * stray email doesn't leak. */
require_once __DIR__ . "/includes/jukebox_playlists.php";
$bar_playlist_email = strtolower(trim((string)($_SESSION["order_email"] ?? "")));
$bar_playlist = ($bar_playlist_email !== "")
    ? knk_playlist_list($bar_playlist_email)
    : [];

/* One-shot flash banner from a recent cancel POST. */
$jb_flash = (string)($_SESSION["jukebox_flash"] ?? "");
unset($_SESSION["jukebox_flash"]);

$echo = ($result && empty($result["ok"]) && isset($result["echo"])) ? $result["echo"] : [];
?>
<?php if (!defined('KNK_BAR_FRAME')): ?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>KnK Inn — Jukebox</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<?php endif; ?>
  <style>
    :root {
      --gold: #c9aa71;
      --gold-soft: #d8c08b;
      --cream: #f5e9d1;
      --cream-dim: #d8c9ab;
      --brown-deep: #2a1a08;
      --brown-mid: #3a230d;
      --brown-bg: #1b0f04;
    }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body {
      background: var(--brown-bg);
      color: var(--cream);
      font-family: "Inter", system-ui, sans-serif;
      min-height: 100vh;
      padding-bottom: 4rem;
    }
    main { max-width: 32rem; margin: 0 auto; padding: 1.4rem 1.1rem 0; }
    .brand {
      display: flex; align-items: center; justify-content: center; gap: 0.55rem;
      font-family: "Archivo Black", sans-serif; letter-spacing: .04em;
      font-size: 1.05rem; padding: 0.4rem 0 1.1rem;
    }
    .brand em { color: var(--gold); font-style: normal; }
    h1 {
      font-family: "Archivo Black", sans-serif;
      font-size: 1.9rem; letter-spacing: .04em;
      margin: 0.4rem 0 0.3rem; line-height: 1.05;
    }
    h1 .accent { color: var(--gold); }
    .lede { color: var(--cream-dim); margin: 0 0 1.4rem; line-height: 1.55; font-size: 0.96rem; }

    .card {
      background: rgba(24,12,3,0.65);
      border: 1px solid rgba(201,170,113,0.22);
      border-radius: 8px;
      padding: 1.1rem 1.05rem;
      margin-bottom: 1.1rem;
    }

    .closed-card {
      text-align: center;
      padding: 2rem 1rem;
    }
    .closed-card h2 {
      font-family: "Archivo Black", sans-serif;
      color: var(--gold); margin: 0 0 0.4rem; font-size: 1.4rem;
    }
    .closed-card p { color: var(--cream-dim); margin: 0; }

    label {
      display: block;
      font-size: 0.72rem; letter-spacing: 0.14em; text-transform: uppercase;
      color: var(--cream-dim);
      margin: 0 0 0.35rem 0.15rem;
    }
    input[type="text"] {
      width: 100%;
      padding: 0.85rem 0.85rem;
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(201,170,113,0.3);
      color: var(--cream);
      font-size: 1rem;
      font-family: inherit;
      border-radius: 6px;
    }
    input[type="text"]:focus {
      outline: none;
      border-color: var(--gold);
      background: rgba(255,255,255,0.06);
    }
    .field { margin-bottom: 0.95rem; }
    .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.7rem; }

    button.submit {
      display: block;
      width: 100%;
      padding: 0.95rem 1rem;
      background: var(--gold);
      color: var(--brown-deep);
      border: none;
      font-weight: 800;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      font-size: 0.85rem;
      cursor: pointer;
      border-radius: 6px;
      font-family: inherit;
      margin-top: 0.4rem;
    }
    button.submit:hover { background: var(--gold-soft); }

    .flash {
      padding: 0.85rem 1rem;
      border-radius: 6px;
      margin-bottom: 1rem;
      font-size: 0.95rem;
      line-height: 1.5;
    }
    .flash.ok {
      background: rgba(142,212,154,0.12);
      color: #b3e6bd;
      border: 1px solid rgba(142,212,154,0.28);
    }
    .flash.err {
      background: rgba(255,154,138,0.1);
      color: #ffb3a6;
      border: 1px solid rgba(255,154,138,0.3);
    }

    .match {
      display: flex; gap: 0.85rem; align-items: center;
      padding: 0.7rem; margin-top: 0.7rem;
      background: rgba(255,255,255,0.04);
      border-radius: 6px;
      border: 1px solid rgba(201,170,113,0.18);
    }
    .match img {
      width: 96px; height: 72px; object-fit: cover; border-radius: 4px;
      flex-shrink: 0;
    }
    .match .meta { min-width: 0; }
    .match .meta .t {
      font-weight: 600; color: var(--cream); font-size: 0.92rem;
      overflow: hidden; text-overflow: ellipsis;
      display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
      line-height: 1.3;
    }
    .match .meta .c { color: var(--cream-dim); font-size: 0.82rem; margin-top: 0.15rem; }

    h2.section {
      font-family: "Archivo Black", sans-serif;
      font-size: 0.95rem; letter-spacing: .12em; text-transform: uppercase;
      color: var(--gold); margin: 0 0 0.8rem;
    }

    .now-playing {
      display: flex; gap: 0.85rem; align-items: center;
    }
    .now-playing img {
      width: 80px; height: 60px; object-fit: cover; border-radius: 4px;
    }
    .np-meta .t { font-weight: 600; color: var(--cream); font-size: 0.95rem; line-height: 1.3; }
    .np-meta .c { color: var(--cream-dim); font-size: 0.82rem; margin-top: 0.15rem; }

    ol.queue {
      list-style: none; padding: 0; margin: 0;
      counter-reset: q;
    }
    ol.queue li {
      counter-increment: q;
      display: flex; gap: 0.75rem; align-items: center;
      padding: 0.55rem 0;
      border-top: 1px solid rgba(201,170,113,0.1);
    }
    ol.queue li:first-child { border-top: none; }
    ol.queue li::before {
      content: counter(q);
      width: 1.5rem; height: 1.5rem; flex-shrink: 0;
      display: inline-flex; align-items: center; justify-content: center;
      background: rgba(201,170,113,0.18); color: var(--gold);
      border-radius: 50%; font-size: 0.78rem; font-weight: 700;
    }
    ol.queue li img { width: 56px; height: 42px; object-fit: cover; border-radius: 3px; flex-shrink: 0; }
    ol.queue li .meta { min-width: 0; flex: 1; }
    ol.queue li .meta .t {
      color: var(--cream); font-size: 0.88rem; line-height: 1.3;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    ol.queue li .meta .who {
      color: var(--cream-dim); font-size: 0.74rem; margin-top: 0.1rem;
    }

    .empty { color: var(--cream-dim); font-style: italic; font-size: 0.9rem; }

    /* "Your songs" panel — appears only inside the bar shell.
       Active rows (pending / queued / playing) sit above historical
       (played / skipped / rejected) and get a Cancel button if the
       song hasn't started yet. */
    .my-songs { list-style: none; padding: 0; margin: 0; }
    .my-song-row {
      display: flex; align-items: center; gap: 0.7rem;
      padding: 0.55rem 0;
      border-bottom: 1px solid rgba(201,170,113,0.1);
    }
    .my-song-row:last-child { border-bottom: none; }
    .my-song-row.is-historic { opacity: 0.55; }
    .my-song-row img {
      width: 46px; height: 34px; object-fit: cover;
      border-radius: 3px; flex-shrink: 0;
    }
    .my-song-row .meta { flex: 1; min-width: 0; }
    .my-song-row .meta .t {
      font-weight: 600; font-size: 0.92rem; line-height: 1.3;
      overflow: hidden; text-overflow: ellipsis;
      display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical;
    }
    .my-song-row .meta .who { margin-top: 0.15rem; }
    .my-song-row .status {
      display: inline-block; font-size: 0.7rem; letter-spacing: 0.06em;
      text-transform: uppercase; font-weight: 700;
      padding: 0.1rem 0.42rem; border-radius: 999px;
      background: rgba(201,170,113,0.15); color: var(--gold);
    }
    .my-song-row .status-playing  { background: #2fdc7a; color: #0f0905; }
    .my-song-row .status-queued   { background: rgba(201,170,113,0.22); color: var(--gold); }
    .my-song-row .status-pending  { background: rgba(255,255,255,0.10); color: var(--cream-dim); }
    .my-song-row .status-played   { background: rgba(0,0,0,0.25); color: var(--cream-dim); }
    .my-song-row .status-skipped  { background: rgba(0,0,0,0.25); color: var(--cream-dim); }
    .my-song-row .status-rejected { background: rgba(217,67,67,0.18); color: #ffb1b1; }
    .my-song-row .cancel-form { margin: 0; }
    .my-song-row .cancel-btn {
      padding: 0.35rem 0.75rem;
      background: transparent; color: var(--cream-dim);
      border: 1px solid rgba(201,170,113,0.35);
      border-radius: 6px; font-weight: 600; font-size: 0.78rem;
      cursor: pointer;
    }
    .my-song-row .cancel-btn:hover {
      background: rgba(217,67,67,0.18); color: #ffb1b1;
      border-color: rgba(217,67,67,0.5);
    }

    .flash {
      background: rgba(47,220,122,0.12);
      border: 1px solid rgba(47,220,122,0.45);
      color: #2fdc7a;
      padding: 0.6rem 0.9rem; border-radius: 8px;
      margin: 0 0 1rem;
      font-size: 0.92rem;
    }

    /* "Recently played at KnK" panel — public history wall. Same
       overall shape as the up-next list but with a relative-time
       suffix on each row and no enumeration. */
    .bar-recent { list-style: none; padding: 0; margin: 0; }
    .bar-recent li {
      display: flex; gap: 0.7rem; align-items: center;
      padding: 0.55rem 0;
      border-bottom: 1px solid rgba(201,170,113,0.08);
    }
    .bar-recent li:last-child { border-bottom: none; }
    .bar-recent li img {
      width: 46px; height: 34px; object-fit: cover;
      border-radius: 3px; flex-shrink: 0;
    }
    .bar-recent .meta { flex: 1; min-width: 0; }
    .bar-recent .meta .t {
      font-weight: 600; font-size: 0.92rem; line-height: 1.3;
      overflow: hidden; text-overflow: ellipsis;
      display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical;
    }
    .bar-recent .meta .who {
      font-size: 0.78rem; color: var(--cream-dim);
      margin-top: 0.15rem;
    }
    .bar-recent .meta .when {
      color: var(--gold); letter-spacing: 0.04em;
    }

    .footer-note {
      text-align: center; color: var(--cream-dim);
      font-size: 0.78rem; margin: 1.6rem 0 0; line-height: 1.5;
    }
    .footer-note a { color: var(--gold); }
  </style>
<?php if (!defined('KNK_BAR_FRAME')): ?>
</head>
<body>
<?php endif; ?>
  <main>
<?php if (!defined('KNK_BAR_FRAME')): ?>
    <div class="brand"><strong>KnK</strong> <em>Inn</em></div>
<?php endif; ?>

<?php if (!defined('KNK_BAR_FRAME')): ?>
    <h1>Pick a <span class="accent">song.</span></h1>
    <p class="lede">
      Type an artist and a song title. We'll find it on YouTube and play it on the bar TV.
    </p>
<?php else: ?>
    <!-- Bar-shell tab strip — three lightweight in-page tabs that
         swap which group of cards is visible. The cards themselves
         still render server-side; this is just CSS+JS chrome that
         hides the inactive ones. Default tab is Search since that's
         what 90% of guests came in to do. -->
    <div class="knk-music-tabs" id="knkMusicTabs"
         style="display:flex; gap:0.4rem; margin:0.2rem 0 0.8rem; padding:0.3rem; background:rgba(245,233,209,0.05); border-radius:12px;">
      <button type="button" class="knk-music-tab is-on" data-music-target="search"
              style="flex:1 1 0; min-width:0; padding:0.55rem 0.4rem; background:transparent; border:0; border-radius:9px; color:var(--cream,#f5e9d1); font-size:0.92rem; font-weight:600; cursor:pointer;">Search</button>
      <button type="button" class="knk-music-tab" data-music-target="queue"
              style="flex:1 1 0; min-width:0; padding:0.55rem 0.4rem; background:transparent; border:0; border-radius:9px; color:var(--cream,#f5e9d1); font-size:0.92rem; font-weight:600; cursor:pointer;">Queue</button>
      <button type="button" class="knk-music-tab" data-music-target="playlist"
              style="flex:1 1 0; min-width:0; padding:0.55rem 0.4rem; background:transparent; border:0; border-radius:9px; color:var(--cream,#f5e9d1); font-size:0.92rem; font-weight:600; cursor:pointer;">My playlist</button>
    </div>
    <style>
      .knk-music-tab.is-on {
        background: rgba(201,170,113,0.22) !important;
        color: var(--gold,#c9aa71) !important;
        box-shadow: inset 0 0 0 1px rgba(201,170,113,0.4);
      }
    </style>
<?php endif; ?>

    <?php if (!$enabled): ?>
      <div class="card closed-card">
        <h2>Jukebox is closed</h2>
        <p>Ask the bar to flip it on.</p>
      </div>
    <?php else: ?>

      <?php if ($result && !empty($result["ok"])):
        $d = $result["data"]; ?>
        <div class="flash ok">
          <?php if ($d["status"] === "queued"): ?>
            <strong>Queued.</strong> You're #<?= (int)$d["position"] ?> in the queue.
          <?php else: ?>
            <strong>Sent for approval.</strong> Staff will get the OK before it plays.
          <?php endif; ?>
          <div class="match">
            <?php if ($d["thumb"]): ?>
              <img src="<?= jbh($d["thumb"]) ?>" alt="">
            <?php endif; ?>
            <div class="meta">
              <div class="t"><?= jbh_yt($d["youtube_title"]) ?></div>
              <div class="c">
                <?= jbh_yt($d["channel"]) ?> · <?= jbh(knk_jukebox_fmt_duration((int)$d["duration"])) ?>
              </div>
            </div>
          </div>
        </div>
      <?php elseif ($result && empty($result["ok"])): ?>
        <div class="flash err">
          <?= jbh($result["error"] ?? "Couldn't queue that.") ?>
        </div>
      <?php endif; ?>

      <form class="card" method="post" action="<?= jbh($KNK_SELF_URL) ?>" autocomplete="off" data-music-tab="search">
        <input type="hidden" name="action" value="submit">

        <div class="field">
          <label for="f-artist">Artist</label>
          <input type="text" id="f-artist" name="artist"
                 placeholder="e.g. Tame Impala"
                 value="<?= jbh($echo["artist"] ?? "") ?>"
                 maxlength="200" required>
        </div>

        <div class="field">
          <label for="f-title">Song title</label>
          <input type="text" id="f-title" name="title"
                 placeholder="e.g. The Less I Know The Better"
                 value="<?= jbh($echo["title"] ?? "") ?>"
                 maxlength="200" required>
        </div>

        <?php if (!defined('KNK_BAR_FRAME')): ?>
        <div class="row2">
          <div class="field">
            <label for="f-name">Your name <span style="text-transform:none;letter-spacing:0;color:var(--cream-dim)">(optional)</span></label>
            <input type="text" id="f-name" name="name"
                   placeholder="e.g. Tom"
                   value="<?= jbh($echo["name"] ?? "") ?>"
                   maxlength="80">
          </div>
          <div class="field">
            <label for="f-table">Table <?php if ($require_table): ?><span style="color:#ffb3a6">*</span><?php else: ?><span style="text-transform:none;letter-spacing:0;color:var(--cream-dim)">(optional)</span><?php endif; ?></label>
            <input type="text" id="f-table" name="table_no"
                   placeholder="e.g. 7"
                   value="<?= jbh($echo["table_no"] ?? "") ?>"
                   maxlength="20" <?= $require_table ? "required" : "" ?>>
          </div>
        </div>
        <?php endif; /* KNK_BAR_FRAME — bar shell hides name+table; the
                        guest's identity comes from the anon cookie. */ ?>

        <button type="submit" class="submit">Queue song</button>

        <p class="footer-note">
          Songs longer than <?= (int)$max_dur_min ?> min, karaoke versions, and a few staff-banned tracks won't go through.
        </p>
      </form>

      <?php if ($jb_flash !== ""): ?>
        <div class="flash"><?= jbh($jb_flash) ?></div>
      <?php endif; ?>

      <?php if (defined('KNK_BAR_FRAME') && !empty($my_songs)): ?>
      <!-- "Your songs" — guest's own queued/playing/recent requests
           with Cancel buttons next to anything still pending or
           queued. Only shown inside the bar shell (where we have
           an authenticated session via the anon cookie). -->
      <div class="card" data-music-tab="queue">
        <h2 class="section">Your songs</h2>
        <ul class="my-songs">
          <?php foreach ($my_songs as $ms):
            $st = (string)$ms["status"];
            $is_active     = ($st === 'pending' || $st === 'queued' || $st === 'playing');
            $is_cancelable = ($st === 'pending' || $st === 'queued');
            $status_lbl =
              $st === 'pending'  ? 'Awaiting staff' :
              ($st === 'queued'  ? 'Queued' :
              ($st === 'playing' ? 'Playing now' :
              ($st === 'played'  ? 'Played' :
              ($st === 'skipped' ? 'Skipped' :
              ($st === 'rejected'? 'Rejected' : ucfirst($st))))));
          ?>
            <li class="my-song-row<?= $is_active ? ' is-active' : ' is-historic' ?>">
              <?php if (!empty($ms["thumbnail_url"])): ?>
                <img src="<?= jbh($ms["thumbnail_url"]) ?>" alt="">
              <?php endif; ?>
              <div class="meta">
                <div class="t"><?= jbh_yt($ms["youtube_title"]) ?></div>
                <div class="who">
                  <span class="status status-<?= jbh($st) ?>"><?= jbh($status_lbl) ?></span>
                </div>
              </div>
              <?php if ($is_cancelable): ?>
                <form method="post" action="<?= jbh($KNK_SELF_URL) ?>"
                      class="cancel-form"
                      onsubmit="return confirm('Cancel this song?');">
                  <input type="hidden" name="action"  value="cancel">
                  <input type="hidden" name="song_id" value="<?= (int)$ms["id"] ?>">
                  <button type="submit" class="cancel-btn">Cancel</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php if ($now_playing): ?>
        <div class="card" data-music-tab="queue">
          <h2 class="section">Now playing</h2>
          <div class="now-playing">
            <?php if (!empty($now_playing["thumbnail_url"])): ?>
              <img src="<?= jbh($now_playing["thumbnail_url"]) ?>" alt="">
            <?php endif; ?>
            <div class="np-meta">
              <div class="t"><?= jbh_yt($now_playing["youtube_title"]) ?></div>
              <div class="c"><?= jbh_yt($now_playing["youtube_channel"]) ?></div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="card" data-music-tab="queue">
        <h2 class="section">Up next (<?= count($up_next) ?>)</h2>
        <?php if (empty($up_next)): ?>
          <div class="empty">Nothing queued. Be the first.</div>
        <?php else: ?>
          <ol class="queue">
            <?php foreach ($up_next as $row): ?>
              <li>
                <?php if (!empty($row["thumbnail_url"])): ?>
                  <img src="<?= jbh($row["thumbnail_url"]) ?>" alt="">
                <?php endif; ?>
                <div class="meta">
                  <div class="t"><?= jbh_yt($row["youtube_title"]) ?></div>
                  <?php
                    $who = trim((string)$row["requester_name"]);
                    $tbl = trim((string)$row["table_no"]);
                    if ($who !== "" || $tbl !== ""):
                  ?>
                    <div class="who">
                      <?= $who !== "" ? jbh($who) : "" ?><?= ($who !== "" && $tbl !== "") ? " · " : "" ?><?= $tbl !== "" ? "T" . jbh($tbl) : "" ?>
                    </div>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </div>

      <?php if ($bar_playlist_email !== ''): ?>
      <!-- My playlist — only shown inside the bar shell (where the
           guest has an order_email session). Tracks they've saved
           with the ⊕ button on Recently-played rows. Each row has
           a small × to remove. Future: drag-reorder + Play All. -->
      <div class="card knk-playlist-card" id="knkPlaylistCard" data-music-tab="playlist">
        <h2 class="section" style="display:flex; align-items:center; justify-content:space-between; gap:0.6rem;">
          <span>My playlist <span class="muted" style="font-size:0.85rem; font-weight:400;" id="knkPlaylistCount">(<?= count($bar_playlist) ?>)</span></span>
          <button type="button" id="knkPlaylistPlayAll"
                  title="Add every track to the queue (shuffled)"
                  aria-label="Play all"
                  <?= empty($bar_playlist) ? 'disabled' : '' ?>
                  style="flex:0 0 auto; background:rgba(201,170,113,0.18); border:1px solid rgba(201,170,113,0.5); color:var(--gold,#c9aa71); font-size:0.85rem; font-weight:700; padding:0.35rem 0.85rem; border-radius:999px; cursor:pointer; <?= empty($bar_playlist) ? 'opacity:0.4; cursor:not-allowed;' : '' ?>">▶ Play all</button>
        </h2>
        <?php if (empty($bar_playlist)): ?>
          <div class="muted" id="knkPlaylistEmpty" style="font-size:0.92rem; padding:0.4rem 0;">
            Tap the <strong>⊕</strong> next to any track in <em>Recently played</em> to save it here.
          </div>
        <?php endif; ?>
        <div class="muted" id="knkPlaylistMsg" style="display:none; font-size:0.88rem; padding:0.4rem 0;"></div>
        <ul class="bar-recent" id="knkPlaylistList">
          <?php foreach ($bar_playlist as $pt): ?>
            <li data-row-id="<?= (int)$pt["id"] ?>">
              <?php if (!empty($pt["thumbnail"])): ?>
                <img src="<?= jbh($pt["thumbnail"]) ?>" alt="">
              <?php endif; ?>
              <div class="meta">
                <div class="t"><?= jbh_yt($pt["title"]) ?></div>
                <div class="who">
                  <?= jbh($pt["channel"]) ?>
                </div>
              </div>
              <div class="knk-pl-actions" style="flex:0 0 auto; display:flex; flex-direction:column; align-items:center; gap:0.15rem; margin-left:0.3rem;">
                <button type="button" class="knk-pl-up" data-row-id="<?= (int)$pt["id"] ?>"
                        title="Move up" aria-label="Move up"
                        style="background:transparent; border:1px solid rgba(245,233,209,0.18); color:rgba(245,233,209,0.7); font-size:0.7rem; line-height:1; padding:0.15rem 0.45rem; border-radius:6px; cursor:pointer;">▲</button>
                <button type="button" class="knk-pl-down" data-row-id="<?= (int)$pt["id"] ?>"
                        title="Move down" aria-label="Move down"
                        style="background:transparent; border:1px solid rgba(245,233,209,0.18); color:rgba(245,233,209,0.7); font-size:0.7rem; line-height:1; padding:0.15rem 0.45rem; border-radius:6px; cursor:pointer;">▼</button>
              </div>
              <button type="button" class="knk-pl-play" data-row-id="<?= (int)$pt["id"] ?>"
                      title="Add to the jukebox queue" aria-label="Play this track"
                      style="flex:0 0 auto; background:rgba(201,170,113,0.15); border:1px solid rgba(201,170,113,0.4); color:var(--gold,#c9aa71); font-size:0.95rem; font-weight:700; padding:0.3rem 0.6rem; border-radius:999px; cursor:pointer; margin-left:0.3rem;">▶</button>
              <button type="button" class="knk-pl-remove" data-row-id="<?= (int)$pt["id"] ?>"
                      title="Remove from playlist" aria-label="Remove from playlist"
                      style="flex:0 0 auto; background:transparent; border:0; color:rgba(245,233,209,0.55); font-size:1.2rem; padding:0.3rem 0.5rem; cursor:pointer;">×</button>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php if (!empty($bar_recent)): ?>
      <!-- Public history wall — every guest's plays, all time. The
           same panel renders standalone (table-side QR codes get
           it too) so guests can scroll the bar's "what's been on"
           regardless of where they came in. -->
      <div class="card" data-music-tab="playlist">
        <h2 class="section">Recently played at KnK</h2>
        <ul class="bar-recent">
          <?php foreach ($bar_recent as $row):
            $when_ts = !empty($row["played_at"])
                ? strtotime((string)$row["played_at"]) : 0;
            $ago = $when_ts > 0 ? max(0, time() - $when_ts) : 0;
            if      ($ago < 60)    $ago_lbl = "just now";
            elseif  ($ago < 3600)  $ago_lbl = (int)round($ago / 60)   . "m ago";
            elseif  ($ago < 86400) $ago_lbl = (int)round($ago / 3600) . "h ago";
            else                   $ago_lbl = (int)round($ago / 86400). "d ago";
          ?>
            <li>
              <?php if (!empty($row["thumbnail_url"])): ?>
                <img src="<?= jbh($row["thumbnail_url"]) ?>" alt="">
              <?php endif; ?>
              <div class="meta">
                <div class="t"><?= jbh_yt($row["youtube_title"]) ?></div>
                <div class="who">
                  <?php
                    /* Prefer the COALESCE'd who_name from the JOIN —
                     * falls back to display_name when no requester_name
                     * was typed (bar-shell flow skips that field). */
                    $rn = trim((string)($row["who_name"] ?? $row["requester_name"]));
                  ?>
                  <?= $rn !== "" ? jbh($rn) : "Guest" ?>
                  <span class="when"> · <?= jbh($ago_lbl) ?></span>
                </div>
              </div>
              <?php if ($bar_playlist_email !== ''): ?>
                <!-- Add-to-playlist button. Only shown inside the bar
                     shell since standalone /jukebox.php has no
                     order_email to attach the playlist to. -->
                <button type="button" class="knk-pl-add"
                        data-video-id="<?= jbh($row["youtube_video_id"]) ?>"
                        data-title="<?= jbh($row["youtube_title"]) ?>"
                        data-channel="<?= jbh($row["youtube_channel"]) ?>"
                        data-thumb="<?= jbh($row["thumbnail_url"]) ?>"
                        data-source="recent"
                        title="Save to your playlist" aria-label="Save to your playlist"
                        style="flex:0 0 auto; background:rgba(201,170,113,0.15); border:1px solid rgba(201,170,113,0.4); color:var(--gold,#c9aa71); font-size:1.05rem; font-weight:700; padding:0.35rem 0.7rem; border-radius:999px; cursor:pointer; margin-left:0.4rem;">⊕</button>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php if ($bar_playlist_email !== ''): ?>
      <script>
      (function () {
        var card = document.getElementById('knkPlaylistCard');
        var list = document.getElementById('knkPlaylistList');
        var emptyEl = document.getElementById('knkPlaylistEmpty');
        var countEl = document.getElementById('knkPlaylistCount');
        if (!card || !list) return;

        function bumpCount(delta) {
          var n = parseInt((countEl && countEl.textContent) || '0', 10) || 0;
          n = Math.max(0, n + delta);
          if (countEl) countEl.textContent = String(n);
          if (emptyEl) emptyEl.style.display = (n === 0) ? '' : 'none';
        }

        // Add buttons next to each Recently-played row.
        document.querySelectorAll('.knk-pl-add').forEach(function (btn) {
          btn.addEventListener('click', function () {
            if (btn.disabled) return;
            btn.disabled = true;
            var origText = btn.textContent;
            btn.textContent = '…';
            var fd = new FormData();
            fd.append('video_id', btn.getAttribute('data-video-id') || '');
            fd.append('title',    btn.getAttribute('data-title')    || '');
            fd.append('channel',  btn.getAttribute('data-channel')  || '');
            fd.append('thumbnail',btn.getAttribute('data-thumb')    || '');
            fd.append('source',   btn.getAttribute('data-source')   || 'manual');
            fetch('/api/playlist_add.php', { method: 'POST', body: fd, credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (j) {
                if (!j || !j.ok) throw new Error((j && j.error) || 'Couldn’t save.');
                btn.textContent = '✓';
                btn.style.color = '#2fdc7a';
                btn.style.borderColor = 'rgba(47,220,122,0.5)';
                if (countEl) countEl.textContent = String(j.count | 0);
                if (emptyEl) emptyEl.style.display = (j.count > 0) ? 'none' : '';
                /* Don’t re-enable — Add is one-shot per row. The
                 * server’s UNIQUE makes a second tap a no-op anyway. */
              })
              .catch(function (e) {
                btn.disabled = false;
                btn.textContent = origText;
                window.alert(e.message || 'Couldn’t save.');
              });
          });
        });

        // Remove buttons inside My playlist.
        function wireRemove(btn) {
          btn.addEventListener('click', function () {
            var li = btn.closest('li');
            var rid = parseInt(btn.getAttribute('data-row-id') || '0', 10);
            if (!rid || !li) return;
            btn.disabled = true;
            var fd = new FormData();
            fd.append('row_id', String(rid));
            fetch('/api/playlist_remove.php', { method: 'POST', body: fd, credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (j) {
                if (!j || !j.ok) throw new Error((j && j.error) || 'Couldn’t remove.');
                li.remove();
                if (countEl) countEl.textContent = String(j.count | 0);
                if (emptyEl) emptyEl.style.display = (j.count > 0) ? 'none' : '';
              })
              .catch(function (e) {
                btn.disabled = false;
                window.alert(e.message || 'Couldn’t remove.');
              });
          });
        }
        document.querySelectorAll('.knk-pl-remove').forEach(wireRemove);

        // Toast helper for Play / Play-all feedback. Reuses
        // #knkPlaylistMsg slot above the list so we don't pile up
        // alerts.
        var msgEl = document.getElementById('knkPlaylistMsg');
        var msgT  = null;
        function flash(text, isErr) {
          if (!msgEl) { window.alert(text); return; }
          if (msgT) { clearTimeout(msgT); msgT = null; }
          msgEl.textContent = text;
          msgEl.style.color = isErr ? '#ff8a8a' : 'var(--gold,#c9aa71)';
          msgEl.style.display = '';
          msgT = setTimeout(function () {
            msgEl.style.display = 'none';
          }, 5000);
        }

        // Play (single track) — small ▶ next to each row.
        function wirePlay(btn) {
          btn.addEventListener('click', function () {
            if (btn.disabled) return;
            var rid = parseInt(btn.getAttribute('data-row-id') || '0', 10);
            if (!rid) return;
            btn.disabled = true;
            var orig = btn.textContent;
            btn.textContent = '…';
            var fd = new FormData();
            fd.append('row_id', String(rid));
            fetch('/api/playlist_play.php', { method: 'POST', body: fd, credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (j) {
                if (!j || !j.ok) throw new Error((j && j.error) || 'Couldn’t add to queue.');
                btn.textContent = '✓';
                btn.style.color = '#2fdc7a';
                btn.style.borderColor = 'rgba(47,220,122,0.5)';
                flash('Queued — ' + (j.queue_count | 0) + ' track(s) ahead in the queue.');
                /* Re-arm after a bit so the guest can re-queue the
                 * same track later in the night if they want. */
                setTimeout(function () {
                  btn.disabled = false;
                  btn.textContent = orig;
                  btn.style.color = '';
                  btn.style.borderColor = '';
                }, 4000);
              })
              .catch(function (e) {
                btn.disabled = false;
                btn.textContent = orig;
                flash(e.message || 'Couldn’t add to queue.', true);
              });
          });
        }
        document.querySelectorAll('.knk-pl-play').forEach(wirePlay);

        // Up / Down reorder. Debounce the POST so a quick flurry of
        // taps only fires once — bar wifi can be slow and chasing
        // every nudge with its own request feels janky.
        var reorderT = null;
        function postCurrentOrder() {
          var ids = [];
          list.querySelectorAll('li[data-row-id]').forEach(function (el) {
            var v = parseInt(el.getAttribute('data-row-id') || '0', 10);
            if (v) ids.push(v);
          });
          if (ids.length === 0) return;
          var fd = new FormData();
          fd.append('row_ids', JSON.stringify(ids));
          fetch('/api/playlist_reorder.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
              if (!j || !j.ok) flash((j && j.error) || 'Couldn’t save the new order.', true);
            })
            .catch(function () {
              flash('Couldn’t save the new order.', true);
            });
        }
        function scheduleReorder() {
          if (reorderT) clearTimeout(reorderT);
          reorderT = setTimeout(postCurrentOrder, 600);
        }
        function moveLi(li, dir) {
          if (!li) return;
          if (dir < 0) {
            var prev = li.previousElementSibling;
            if (prev) list.insertBefore(li, prev);
          } else {
            var next = li.nextElementSibling;
            if (next) list.insertBefore(next, li);
          }
        }
        list.addEventListener('click', function (ev) {
          var t = ev.target;
          if (!t) return;
          if (t.classList && t.classList.contains('knk-pl-up')) {
            moveLi(t.closest('li'), -1); scheduleReorder();
          } else if (t.classList && t.classList.contains('knk-pl-down')) {
            moveLi(t.closest('li'), +1); scheduleReorder();
          }
        });

        // Play all — the big button in the playlist header.
        var pall = document.getElementById('knkPlaylistPlayAll');
        if (pall) {
          pall.addEventListener('click', function () {
            if (pall.disabled) return;
            var n = parseInt((countEl && countEl.textContent || '0').replace(/[^0-9]/g, ''), 10) || 0;
            if (n === 0) { flash('Your playlist is empty.', true); return; }
            if (n > 5) {
              if (!window.confirm('Queue all ' + n + ' tracks (shuffled)?')) return;
            }
            pall.disabled = true;
            var orig = pall.innerHTML;
            pall.innerHTML = 'Queuing…';
            var fd = new FormData();
            fd.append('mode', 'all');
            fd.append('shuffle', '1');
            fetch('/api/playlist_play.php', { method: 'POST', body: fd, credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (j) {
                if (!j) throw new Error('Couldn’t reach the jukebox.');
                if (!j.ok && (j.added | 0) === 0) {
                  throw new Error(j.error || 'Nothing was added.');
                }
                var added   = j.added   | 0;
                var skipped = j.skipped | 0;
                var msg = 'Queued ' + added + ' track(s)';
                if (skipped > 0) msg += ' (' + skipped + ' skipped)';
                msg += ' — ' + (j.queue_count | 0) + ' in queue.';
                flash(msg);
              })
              .catch(function (e) {
                flash(e.message || 'Couldn’t queue your playlist.', true);
              })
              .finally(function () {
                pall.innerHTML = orig;
                pall.disabled = false;
              });
          });
        }
      })();
      </script>
      <?php endif; ?>

    <?php endif; ?>

<?php if (defined('KNK_BAR_FRAME')): ?>
    <script>
    /* Music tab strip — Search / Queue / My playlist.
     *
     * Picks the active target from #hash (e.g. ?tab=music#playlist)
     * so that "Save to playlist" deep-links can drop guests onto
     * the right card. Falls back to Search.
     *
     * Handles missing tab content gracefully: if a guest has no
     * playlist yet, the Playlist tab still works — it just shows
     * the "Recently played at KnK" wall (which is also tagged
     * data-music-tab="playlist"). */
    (function () {
      var strip = document.getElementById('knkMusicTabs');
      if (!strip) return;
      var tabs = Array.prototype.slice.call(strip.querySelectorAll('.knk-music-tab'));
      var cards = Array.prototype.slice.call(document.querySelectorAll('[data-music-tab]'));

      function show(target) {
        cards.forEach(function (c) {
          c.style.display = (c.getAttribute('data-music-tab') === target) ? '' : 'none';
        });
        tabs.forEach(function (t) {
          var on = (t.getAttribute('data-music-target') === target);
          if (on) t.classList.add('is-on'); else t.classList.remove('is-on');
        });
        try { history.replaceState(null, '', '#' + target); } catch (e) {}
      }

      tabs.forEach(function (t) {
        t.addEventListener('click', function () {
          show(t.getAttribute('data-music-target') || 'search');
        });
      });

      var initial = (location.hash || '').replace('#', '');
      if (initial !== 'queue' && initial !== 'playlist') initial = 'search';
      show(initial);
    })();
    </script>
<?php endif; ?>

<?php if (!defined('KNK_BAR_FRAME')): ?>
    <p class="footer-note">
      KnK Inn · <a href="/">Back to site</a>
    </p>
<?php endif; ?>
  </main>
<?php if (!defined('KNK_BAR_FRAME')): ?>
</body>
</html>
<?php endif; ?>

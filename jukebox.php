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

/* ----------- GET: render ----------- */
$result = $_SESSION["jukebox_result"] ?? null;
unset($_SESSION["jukebox_result"]);

$cfg     = knk_jukebox_config();
$enabled = !empty($cfg["enabled"]);
$require_table = !empty($cfg["require_table_no"]);
$max_dur_min   = max(1, (int)ceil(((int)$cfg["max_duration_seconds"]) / 60));

$now_playing = knk_jukebox_now_playing();
$up_next     = knk_jukebox_up_next(8);

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

    <h1>Pick a <span class="accent">song.</span></h1>
    <p class="lede">
      Type an artist and a song title. We'll find it on YouTube and play it on the bar TV.
    </p>

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

      <form class="card" method="post" action="<?= jbh($KNK_SELF_URL) ?>" autocomplete="off">
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

        <button type="submit" class="submit">Queue song</button>

        <p class="footer-note">
          Songs longer than <?= (int)$max_dur_min ?> min, karaoke versions, and a few staff-banned tracks won't go through.
        </p>
      </form>

      <?php if ($now_playing): ?>
        <div class="card">
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

      <div class="card">
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

<?php
/*
 * KnK Inn — /jukebox-player.php  (bar TV)
 *
 * Open this once on the bar laptop, full-screen Chrome on the TV.
 * Tap "Start jukebox" once at the start of the night (browser autoplay
 * unblock). After that the page plays each queued song in turn.
 *
 * Loop:
 *   1. JS calls /api/jukebox_state.php to read state + queue.
 *   2. If there's a 'playing' row, load that videoId. Otherwise call
 *      /api/jukebox_advance.php to promote the next 'queued' row.
 *   3. When the IFrame fires onStateChange=ENDED (or onError), JS
 *      calls /api/jukebox_advance.php with the current id to mark
 *      played + fetch the next one.
 *   4. Poll /api/jukebox_state.php every few seconds to refresh the
 *      "Up Next" list and catch external skips.
 *
 * No login. The page is on the bar laptop. If someone external hits
 * /api/jukebox_advance.php they can skip a song; the state always
 * checks status, so it stays consistent.
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/jukebox.php";

$cfg     = knk_jukebox_config();
$enabled = !empty($cfg["enabled"]);
$poll    = max(2, (int)$cfg["board_poll_seconds"]);

// Initial state for first paint (avoids empty flash).
$now     = knk_jukebox_now_playing();
$upnext  = knk_jukebox_up_next(8);

$initial = [
    "enabled"     => $enabled,
    "poll_seconds"=> $poll,
    "now_playing" => $now ? [
        "id"         => (int)$now["id"],
        "video_id"   => (string)$now["youtube_video_id"],
        "title"      => (string)$now["youtube_title"],
        "channel"    => (string)$now["youtube_channel"],
        "duration"   => (int)$now["duration_seconds"],
        "thumb"      => (string)$now["thumbnail_url"],
        "name"       => (string)$now["requester_name"],
        "table_no"   => (string)$now["table_no"],
    ] : null,
    "up_next" => array_map(function($r){
        return [
            "id"         => (int)$r["id"],
            "video_id"   => (string)$r["youtube_video_id"],
            "title"      => (string)$r["youtube_title"],
            "channel"    => (string)$r["youtube_channel"],
            "duration"   => (int)$r["duration_seconds"],
            "thumb"      => (string)$r["thumbnail_url"],
            "name"       => (string)$r["requester_name"],
            "table_no"   => (string)$r["table_no"],
        ];
    }, $upnext),
];

function jph($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

// Build a request URL the bar can show as a QR (or manually share).
$_scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
$_host   = $_SERVER["HTTP_HOST"] ?? "knkinn.com";
$REQUEST_URL = $_scheme . "://" . $_host . "/jukebox.php";

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>KnK Inn — Jukebox player</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --gold: #c9aa71;
      --cream: #f5e9d1;
      --cream-dim: #d8c9ab;
      --brown-deep: #2a1a08;
    }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; height: 100%; }
    body {
      background: #000;
      color: var(--cream);
      font-family: "Inter", system-ui, sans-serif;
      overflow: hidden;
    }

    .stage {
      display: grid;
      grid-template-columns: 1fr 360px;
      height: 100vh;
    }
    .video {
      background: #000;
      position: relative;
    }
    #player, #player iframe {
      width: 100%;
      height: 100%;
      border: 0;
    }
    .video .badge {
      position: absolute; left: 1rem; top: 1rem;
      font-family: "Archivo Black", sans-serif; letter-spacing: .04em;
      font-size: 1rem; color: var(--gold);
      background: rgba(0,0,0,0.5); padding: 0.4rem 0.8rem; border-radius: 999px;
      text-transform: uppercase;
    }
    .video .credit {
      position: absolute; left: 1rem; bottom: 1rem; right: 1rem;
      font-size: 1rem; color: var(--cream-dim);
      background: rgba(0,0,0,0.55); padding: 0.6rem 0.9rem; border-radius: 6px;
      max-width: 60%;
    }
    .video .credit .who { color: var(--gold); font-weight: 600; }

    .sidebar {
      background: #0e0703;
      border-left: 2px solid rgba(201,170,113,0.18);
      padding: 1.4rem 1.2rem;
      display: flex; flex-direction: column;
      overflow: hidden;
    }
    .sidebar h2 {
      font-family: "Archivo Black", sans-serif;
      font-size: 0.95rem; letter-spacing: .14em; text-transform: uppercase;
      color: var(--gold); margin: 0 0 0.9rem;
    }
    .sidebar .now {
      padding: 0.85rem; background: rgba(201,170,113,0.07);
      border: 1px solid rgba(201,170,113,0.2); border-radius: 6px;
      margin-bottom: 1.2rem;
    }
    .sidebar .now .t {
      font-family: "Archivo Black", sans-serif; font-size: 1rem;
      color: var(--cream); line-height: 1.3;
      max-height: 2.6em; overflow: hidden;
    }
    .sidebar .now .c { color: var(--cream-dim); font-size: 0.86rem; margin-top: 0.3rem; }

    .sidebar ol { list-style: none; padding: 0; margin: 0; counter-reset: q; overflow-y: auto; flex: 1; }
    .sidebar ol li {
      counter-increment: q;
      display: flex; gap: 0.65rem; align-items: center;
      padding: 0.55rem 0; border-top: 1px solid rgba(201,170,113,0.08);
    }
    .sidebar ol li::before {
      content: counter(q);
      width: 1.4rem; height: 1.4rem; flex-shrink: 0;
      display: inline-flex; align-items: center; justify-content: center;
      background: rgba(201,170,113,0.15); color: var(--gold);
      border-radius: 50%; font-size: 0.74rem; font-weight: 700;
    }
    .sidebar ol li img { width: 56px; height: 42px; object-fit: cover; border-radius: 3px; flex-shrink: 0; }
    .sidebar ol li .meta { min-width: 0; flex: 1; }
    .sidebar ol li .t {
      color: var(--cream); font-size: 0.84rem; line-height: 1.3;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .sidebar ol li .who { color: var(--cream-dim); font-size: 0.74rem; margin-top: 0.1rem; }

    .empty { color: var(--cream-dim); font-style: italic; font-size: 0.9rem; padding: 0.5rem 0; }

    .footer-bar {
      margin-top: 0.9rem; padding-top: 0.9rem;
      border-top: 1px solid rgba(201,170,113,0.18);
      font-size: 0.76rem; color: var(--cream-dim);
      display: flex; justify-content: space-between; align-items: center;
      letter-spacing: 0.05em;
    }
    .footer-bar .url { color: var(--gold); font-weight: 600; letter-spacing: 0.04em; }

    /* Start screen + closed state */
    .splash {
      position: fixed; inset: 0;
      background: radial-gradient(circle at center, #1b0f04 0%, #000 70%);
      display: flex; align-items: center; justify-content: center;
      z-index: 50;
    }
    .splash .inner {
      text-align: center; max-width: 640px; padding: 2rem;
    }
    .splash h1 {
      font-family: "Archivo Black", sans-serif;
      font-size: 4rem; letter-spacing: .04em;
      margin: 0 0 0.4rem; color: var(--cream);
    }
    .splash h1 .accent { color: var(--gold); }
    .splash p { color: var(--cream-dim); font-size: 1.1rem; margin: 0.6rem 0 1.6rem; }
    .splash button.start {
      padding: 1.1rem 2.4rem; background: var(--gold);
      color: var(--brown-deep); border: none;
      font-family: "Archivo Black", sans-serif;
      font-size: 1.05rem; letter-spacing: 0.16em; text-transform: uppercase;
      cursor: pointer; border-radius: 6px;
    }
    .splash button.start:hover { background: #d8c08b; }
    .splash .request-url {
      margin-top: 2rem; color: var(--cream-dim); font-size: 0.95rem;
    }
    .splash .request-url strong { color: var(--gold); font-weight: 700; }

    .closed {
      text-align: center; max-width: 720px; padding: 2rem;
    }
    .closed h1 {
      font-family: "Archivo Black", sans-serif;
      font-size: 3.2rem; color: var(--gold); margin: 0 0 0.4rem;
    }
    .closed p { color: var(--cream-dim); font-size: 1.05rem; margin: 0.4rem 0; }

    /* Hide layout while splash is up */
    body.splash-on .stage { visibility: hidden; }
  </style>
</head>
<body class="splash-on">

  <!-- Splash / start -->
  <div class="splash" id="splash">
    <div class="inner">
      <h1>KnK <span class="accent">Jukebox</span></h1>
      <?php if (!$enabled): ?>
        <p>The jukebox is currently <strong>closed</strong>. Ask staff to flip it on.</p>
      <?php else: ?>
        <p>Tap to start. After this, songs play automatically.</p>
        <button type="button" class="start" id="startBtn">▶ Start jukebox</button>
        <div class="request-url">
          Request a song at <strong><?= jph($REQUEST_URL) ?></strong>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="stage" id="stage">
    <div class="video">
      <div class="badge">KnK Jukebox</div>
      <div id="player"></div>
      <div class="credit" id="credit" style="display:none">
        <div class="t" id="creditTitle"></div>
        <div id="creditWho"></div>
      </div>
    </div>

    <aside class="sidebar">
      <h2>Now playing</h2>
      <div class="now" id="nowCard">
        <div class="t" id="nowTitle">—</div>
        <div class="c" id="nowChannel"></div>
      </div>

      <h2>Up next <span id="queueCount" style="color:var(--cream-dim);font-weight:400">(0)</span></h2>
      <ol id="upNext"></ol>
      <div class="empty" id="emptyMsg" style="display:none">Nothing queued. Be the first.</div>

      <div class="footer-bar">
        <span>Request: <span class="url"><?= jph($REQUEST_URL) ?></span></span>
        <span id="statusDot">●</span>
      </div>
    </aside>
  </div>

  <script>
    var INITIAL = <?= json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var POLL_MS = (INITIAL.poll_seconds || 5) * 1000;

    var ytPlayer = null;
    var currentRow = INITIAL.now_playing; // row currently loaded in the player (or null)
    var pollTimer = null;
    var advancing = false;

    // ---- Initial sidebar render ----
    renderSidebar(INITIAL.now_playing, INITIAL.up_next);

    // ---- Splash + start gesture (browser autoplay unblock) ----
    var startBtn = document.getElementById("startBtn");
    if (startBtn) {
      startBtn.addEventListener("click", function () {
        document.getElementById("splash").style.display = "none";
        document.body.classList.remove("splash-on");
        loadYouTubeAPI();
      });
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
      var startVid = currentRow ? currentRow.video_id : null;
      ytPlayer = new YT.Player("player", {
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
              showCredit(currentRow);
            } else {
              advanceNow(); // queue had something pending — pull it in
            }
            startPolling();
          },
          onStateChange: function (e) {
            // YT.PlayerState.ENDED == 0
            if (e.data === 0) {
              advanceNow();
            }
          },
          onError: function (e) {
            console.warn("YT error", e && e.data);
            // Mark current as played and move on. (Embed-blocked, removed, etc.)
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
        // Refresh the sidebar from the response if provided.
        if (j.state) renderSidebar(j.state.now_playing, j.state.up_next);
      })
      .catch(function (e) { advancing = false; console.warn(e); });
    }

    function playRow(row) {
      currentRow = row;
      if (!row) {
        // Queue empty. Hide the credit and let polling refresh us.
        document.getElementById("credit").style.display = "none";
        if (ytPlayer && ytPlayer.stopVideo) try { ytPlayer.stopVideo(); } catch (_) {}
        document.getElementById("nowTitle").textContent = "Waiting for the next song";
        document.getElementById("nowChannel").textContent = "";
        return;
      }
      if (ytPlayer && ytPlayer.loadVideoById) {
        try { ytPlayer.loadVideoById(row.video_id); } catch (_) {}
      }
      showCredit(row);
    }

    function showCredit(row) {
      var c = document.getElementById("credit");
      if (!row) { c.style.display = "none"; return; }
      document.getElementById("creditTitle").textContent = row.title || "";
      var who = (row.name || "").trim();
      var tbl = (row.table_no || "").trim();
      var line = "";
      if (who && tbl) line = "Requested by " + who + " · table " + tbl;
      else if (who)   line = "Requested by " + who;
      else if (tbl)   line = "Requested from table " + tbl;
      var el = document.getElementById("creditWho");
      el.innerHTML = "";
      if (line) {
        var span = document.createElement("span");
        span.className = "who";
        span.textContent = line;
        el.appendChild(span);
      }
      document.getElementById("nowTitle").textContent = row.title || "";
      document.getElementById("nowChannel").textContent = row.channel || "";
      c.style.display = "block";
    }

    function renderSidebar(now, upnext) {
      // Now card
      if (now) {
        document.getElementById("nowTitle").textContent = now.title || "";
        document.getElementById("nowChannel").textContent = now.channel || "";
      } else if (!currentRow) {
        document.getElementById("nowTitle").textContent = "Waiting for the next song";
        document.getElementById("nowChannel").textContent = "";
      }

      // Up Next list
      upnext = upnext || [];
      var ol = document.getElementById("upNext");
      ol.innerHTML = "";
      upnext.forEach(function (row) {
        var li = document.createElement("li");
        if (row.thumb) {
          var img = document.createElement("img");
          img.src = row.thumb;
          li.appendChild(img);
        }
        var meta = document.createElement("div");
        meta.className = "meta";
        var t = document.createElement("div");
        t.className = "t";
        t.textContent = row.title || "";
        meta.appendChild(t);
        var who = (row.name || "").trim();
        var tbl = (row.table_no || "").trim();
        if (who || tbl) {
          var w = document.createElement("div");
          w.className = "who";
          w.textContent = (who || "") + (who && tbl ? " · " : "") + (tbl ? "T" + tbl : "");
          meta.appendChild(w);
        }
        li.appendChild(meta);
        ol.appendChild(li);
      });
      document.getElementById("queueCount").textContent = "(" + upnext.length + ")";
      document.getElementById("emptyMsg").style.display = upnext.length ? "none" : "block";
    }

    function startPolling() {
      if (pollTimer) clearInterval(pollTimer);
      pollTimer = setInterval(pollState, POLL_MS);
    }

    function pollState() {
      fetch("/api/jukebox_state.php", { cache: "no-store" })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j) return;
          // External skip detection — if the server thinks something
          // else is playing (or nothing) we re-sync.
          var serverNow = j.now_playing;
          var localId   = currentRow ? currentRow.id : null;
          var serverId  = serverNow ? serverNow.id : null;
          var queueHas  = j.up_next && j.up_next.length > 0;

          if (localId !== serverId) {
            if (serverNow) {
              playRow(serverNow);
            } else if (!advancing) {
              advanceNow();
            }
          } else if (!currentRow && queueHas && !advancing) {
            // Nothing playing locally or on server, but a new song
            // landed in the queue — pick it up.
            advanceNow();
          }
          renderSidebar(serverNow || currentRow, j.up_next || []);
        })
        .catch(function (e) { /* keep going */ });
    }
  </script>
</body>
</html>

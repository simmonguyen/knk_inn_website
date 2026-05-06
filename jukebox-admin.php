<?php
/*
 * KnK Inn — /jukebox-admin.php
 *
 * Jukebox control room. Super Admin / Owner / Bartender (so any
 * staffer can skip a track from their phone).
 *
 * Sections:
 *   1. Kill switch + auto-approve toggle
 *   2. Now playing  (+ Skip)
 *   3. Up next      (+ Skip per row)
 *   4. Pending approvals (only when auto_approve = 0)
 *   5. Settings (caps + cooldown + polling)
 *   6. Radio fallback (MP3 stream when queue is empty)
 *   6b. Spotify ambient layer (opt-in tier 2 — F5 only)
 *   7. Blocklist (videos + keywords)
 *   8. Recent activity (last 30 played/skipped/rejected)
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/jukebox.php";
require_once __DIR__ . "/includes/spotify.php";

$me    = knk_require_permission("jukebox");
$me_id = (int)$me["id"];

$flash = "";
$error = "";
$action = (string)($_POST["action"] ?? "");

// Pick up flash messages from the OAuth callback redirect.
if (!empty($_GET["spotify_ok"])) {
    $flash = (string)$_GET["spotify_ok"];
}
if (!empty($_GET["spotify_error"])) {
    $error = (string)$_GET["spotify_error"];
}

try {
    if ($action === "toggle_enabled") {
        $target = !empty($_POST["enabled"]) ? 1 : 0;
        knk_jukebox_config_update(["enabled" => $target], $me_id);
        knk_audit("jukebox.toggle", "jukebox_config", "1", ["enabled" => $target]);
        $flash = $target
            ? "Jukebox is ON. Guests can queue songs at /jukebox.php."
            : "Jukebox is OFF. Guest page shows a closed message.";
    }
    elseif ($action === "toggle_auto_approve") {
        $target = !empty($_POST["auto_approve"]) ? 1 : 0;
        knk_jukebox_config_update(["auto_approve" => $target], $me_id);
        knk_audit("jukebox.auto_approve", "jukebox_config", "1", ["auto_approve" => $target]);
        $flash = $target
            ? "Auto-approve ON — requests queue instantly."
            : "Auto-approve OFF — staff approve each request before it queues.";
    }
    elseif ($action === "save_config") {
        $updates = [];
        $int_fields = [
            "max_duration_seconds", "per_ip_cooldown_seconds",
            "max_queue_length",     "board_poll_seconds",
        ];
        foreach ($int_fields as $f) {
            if (array_key_exists($f, $_POST)) {
                $updates[$f] = max(0, (int)$_POST[$f]);
            }
        }
        if (array_key_exists("require_table_no", $_POST)) {
            $updates["require_table_no"] = !empty($_POST["require_table_no"]) ? 1 : 0;
        }
        knk_jukebox_config_update($updates, $me_id);
        knk_audit("jukebox.config", "jukebox_config", "1", ["fields" => array_keys($updates)]);
        $flash = "Settings saved.";
    }
    elseif ($action === "save_radio") {
        // Radio fallback — plays an MP3 stream when the queue is empty
        // and nothing is currently playing, so the bar isn't silent.
        $updates = [];
        $updates["radio_enabled"] = !empty($_POST["radio_enabled"]) ? 1 : 0;
        $url = trim((string)($_POST["radio_url"] ?? ""));
        if ($url !== "") {
            // Only http/https schemes; everything else is rejected.
            if (!preg_match('~^https?://~i', $url)) {
                throw new RuntimeException("Stream URL must start with http:// or https://");
            }
            // VARCHAR(400) cap.
            if (strlen($url) > 400) {
                throw new RuntimeException("Stream URL is too long (max 400 chars).");
            }
            $updates["radio_url"] = $url;
        }
        knk_jukebox_config_update($updates, $me_id);
        knk_audit("jukebox.radio", "jukebox_config", "1", ["fields" => array_keys($updates)]);
        $flash = "Radio fallback saved.";
    }
    elseif ($action === "save_spotify") {
        // Spotify ambient layer — credentials + playlist + volume.
        // Refresh token / device_id are NOT writable here; they
        // get filled in by the OAuth callback and Detect device.
        $updates = [];
        $cid    = trim((string)($_POST["spotify_client_id"] ?? ""));
        $secret = trim((string)($_POST["spotify_client_secret"] ?? ""));
        $list   = trim((string)($_POST["spotify_default_playlist_uri"] ?? ""));
        $volRaw = (string)($_POST["spotify_volume_pct"] ?? "");

        if ($cid !== "")    $updates["spotify_client_id"]     = $cid;
        if ($secret !== "") $updates["spotify_client_secret"] = $secret;

        if ($list !== "") {
            // Accept either spotify:playlist:XXX URI or open.spotify.com/playlist/XXX URL.
            if (preg_match('~^spotify:playlist:[A-Za-z0-9]+$~', $list)) {
                $updates["spotify_default_playlist_uri"] = $list;
            } elseif (preg_match('~open\.spotify\.com/playlist/([A-Za-z0-9]+)~', $list, $m)) {
                $updates["spotify_default_playlist_uri"] = "spotify:playlist:" . $m[1];
            } else {
                throw new RuntimeException("Playlist must be a spotify:playlist:... URI or an open.spotify.com/playlist/... URL.");
            }
        } else {
            // Empty input clears the playlist (= disables Spotify even on F5).
            $updates["spotify_default_playlist_uri"] = "";
        }

        if ($volRaw !== "") {
            $vol = (int)$volRaw;
            if ($vol < 0 || $vol > 100) {
                throw new RuntimeException("Volume must be 0-100.");
            }
            $updates["spotify_volume_pct"] = $vol;
        }

        knk_spotify_config_update($updates);
        knk_audit("jukebox.spotify_save", "jukebox_config", "1", ["fields" => array_keys($updates)]);
        $flash = "Spotify settings saved.";
    }
    elseif ($action === "spotify_detect_device") {
        // Hit /me/player/devices and grab whichever device looks most
        // like the venue machine. If the Spotify app on F5 isn't open
        // right now, this returns nothing and we tell the admin to
        // open it and retry.
        if (!knk_spotify_is_connected()) {
            throw new RuntimeException("Connect Spotify first.");
        }
        $devs = knk_spotify_devices();
        if (empty($devs)) {
            throw new RuntimeException("No Spotify devices found. Open the Spotify app on the F5 Windows machine and try again.");
        }
        $pick = null;
        foreach ($devs as $d) {
            if (!empty($d["is_active"])) { $pick = $d; break; }
        }
        if ($pick === null) $pick = $devs[0];
        knk_spotify_config_update([
            "spotify_device_id"   => isset($pick["id"]) ? (string)$pick["id"] : "",
            "spotify_device_name" => isset($pick["name"]) ? (string)$pick["name"] : "",
        ]);
        knk_audit("jukebox.spotify_detect", "jukebox_config", "1", ["device" => isset($pick["name"]) ? $pick["name"] : ""]);
        $flash = "Spotify device set to: " . (isset($pick["name"]) ? $pick["name"] : "(unnamed)");
    }
    elseif ($action === "spotify_clear") {
        // Disconnect: wipe refresh token + device. Client_id/secret
        // stay (admin keeps the dev app credentials).
        knk_spotify_config_update([
            "spotify_refresh_token" => "",
            "spotify_device_id"     => "",
            "spotify_device_name"   => "",
            "spotify_last_ok_at"    => null,
        ]);
        // Drop the local token cache too.
        @unlink(knk_spotify_token_cache_path());
        knk_audit("jukebox.spotify_clear", "jukebox_config", "1", []);
        $flash = "Spotify disconnected. Credentials kept; click Connect to re-link.";
    }
    elseif ($action === "skip") {
        $id = (int)($_POST["id"] ?? 0);
        if ($id <= 0) throw new RuntimeException("Bad id.");
        knk_jukebox_skip($id, $me_id);
        knk_audit("jukebox.skip", "jukebox_queue", (string)$id);
        $flash = "Skipped.";
    }
    elseif ($action === "approve") {
        $id = (int)($_POST["id"] ?? 0);
        if ($id <= 0) throw new RuntimeException("Bad id.");
        knk_jukebox_approve($id, $me_id);
        knk_audit("jukebox.approve", "jukebox_queue", (string)$id);
        $flash = "Approved — added to the queue.";
    }
    elseif ($action === "reject") {
        $id = (int)($_POST["id"] ?? 0);
        $reason = trim((string)($_POST["reason"] ?? ""));
        if ($id <= 0) throw new RuntimeException("Bad id.");
        knk_jukebox_reject($id, $reason !== "" ? $reason : "Rejected by staff", $me_id);
        knk_audit("jukebox.reject", "jukebox_queue", (string)$id, ["reason" => $reason]);
        $flash = "Rejected.";
    }
    elseif ($action === "blocklist_add") {
        $kind   = (string)($_POST["kind"]   ?? "");
        $value  = trim((string)($_POST["value"]  ?? ""));
        $reason = trim((string)($_POST["reason"] ?? ""));
        if (!in_array($kind, ["video","keyword"], true)) throw new RuntimeException("Pick a kind.");
        if ($value === "") throw new RuntimeException("Enter a value.");
        knk_jukebox_blocklist_add($kind, $value, $reason, $me_id);
        knk_audit("jukebox.blocklist_add", "jukebox_blocklist", null, [
            "kind" => $kind, "value" => $value,
        ]);
        $flash = "Added to the blocklist.";
    }
    elseif ($action === "blocklist_remove") {
        $id = (int)($_POST["id"] ?? 0);
        if ($id <= 0) throw new RuntimeException("Bad id.");
        knk_jukebox_blocklist_remove($id);
        knk_audit("jukebox.blocklist_remove", "jukebox_blocklist", (string)$id);
        $flash = "Removed.";
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($action !== "") {
    $qs = [];
    if ($flash) $qs["msg"] = $flash;
    if ($error) $qs["err"] = $error;
    header("Location: /jukebox-admin.php" . ($qs ? "?" . http_build_query($qs) : ""));
    exit;
}

$flash = (string)($_GET["msg"] ?? "");
$error = (string)($_GET["err"] ?? "");

$cfg     = knk_jukebox_config();
$enabled = !empty($cfg["enabled"]);
$auto    = !empty($cfg["auto_approve"]);
$now     = knk_jukebox_now_playing();
$upnext  = knk_jukebox_up_next(50);
$pending = $auto ? [] : knk_jukebox_pending(50);
$recent  = knk_jukebox_recent(30);
$blocks  = knk_jukebox_blocklist_list();
$api_key = knk_jukebox_api_key();

function jah($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
/* For YouTube-sourced text (titles, channel names) — decode any
 * pre-encoded HTML entities (`&quot;`, `&amp;`, `&#39;`) the API
 * returned, then re-encode for safe HTML insert. Idempotent on
 * already-decoded titles stored by the new ingestion path. */
function jah_yt($s): string {
    $decoded = html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, "UTF-8");
    return htmlspecialchars($decoded, ENT_QUOTES, "UTF-8");
}
function ja_when($s): string {
    if (!$s) return "—";
    $t = strtotime((string)$s);
    if (!$t) return jah($s);
    $age = time() - $t;
    if ($age < 60)    return $age . "s ago";
    if ($age < 3600)  return (int)floor($age/60)  . "m ago";
    if ($age < 86400) return (int)floor($age/3600) . "h ago";
    return date("M j, H:i", $t);
}
?><!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>KnK Inn — Jukebox admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/styles.css?v=12">
  <style>
    body { background: #1b0f04; color: var(--cream, #f5e9d1); font-family: "Inter", system-ui, sans-serif; margin: 0; }
    main.wrap { max-width: 1100px; margin: 0 auto; padding: 1.6rem 1.2rem 4rem; }
    h1.display-md { margin: 1.2rem 0 0.3rem; font-family: "Archivo Black", sans-serif; font-size: 1.7rem; letter-spacing: .04em; }
    .lede { color: var(--cream-dim, #d8c9ab); margin-bottom: 1.6rem; }
    .flash { padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1.2rem; font-size: 0.95rem; }
    .flash.ok  { background: rgba(142,212,154,0.12); color: #8ed49a; border: 1px solid rgba(142,212,154,0.28); }
    .flash.err { background: rgba(255,154,138,0.1);  color: #ff9a8a; border: 1px solid rgba(255,154,138,0.3); }
    section.card {
      background: rgba(24,12,3,0.6); border: 1px solid rgba(201,170,113,0.22);
      border-radius: 6px; padding: 1.4rem 1.4rem 1.2rem; margin-bottom: 1.2rem;
    }
    section.card h2 { margin: 0 0 0.4rem; font-family: "Archivo Black", sans-serif; font-size: 1.2rem; letter-spacing: .02em; }
    section.card h3 { margin: 1.2rem 0 0.6rem; font-family: "Archivo Black", sans-serif; font-size: 0.92rem; letter-spacing: .04em; color: var(--gold, #c9aa71); text-transform: uppercase; }
    section.card p.explain { color: var(--cream-dim, #d8c9ab); font-size: 0.9rem; margin: 0.2rem 0 1rem; line-height: 1.55; }

    .status-pill {
      display: inline-flex; align-items: center; gap: 0.4rem;
      padding: 0.32rem 0.8rem; border-radius: 999px;
      font-size: 0.72rem; letter-spacing: 0.12em; text-transform: uppercase; font-weight: 600;
    }
    .status-pill.on  { background: rgba(142,212,154,0.12); color: #8ed49a; border: 1px solid rgba(142,212,154,0.3); }
    .status-pill.off { background: rgba(255,154,138,0.1);  color: #ff9a8a; border: 1px solid rgba(255,154,138,0.3); }
    .status-pill.warn{ background: rgba(255,200,120,0.1);  color: #ffc878; border: 1px solid rgba(255,200,120,0.3); }
    .status-pill .dot { width: 0.55rem; height: 0.55rem; border-radius: 50%; background: currentColor; }

    label { display: block; font-size: 0.7rem; letter-spacing: 0.14em; text-transform: uppercase; color: var(--cream-dim, #d8c9ab); margin: 0 0 0.3rem 0.15rem; }
    input[type="number"], input[type="text"], select {
      padding: 0.55rem 0.7rem; background: rgba(255,255,255,0.04);
      border: 1px solid rgba(201,170,113,0.3); color: var(--cream, #f5e9d1);
      font-size: 0.92rem; font-family: inherit; border-radius: 4px;
    }
    input[type="number"] { width: 110px; }
    input:focus, select:focus { outline: none; border-color: var(--gold, #c9aa71); }

    button, .btn {
      display: inline-block; padding: 0.55rem 1rem; background: var(--gold, #c9aa71);
      color: var(--brown-deep, #2a1a08); border: none; font-weight: 700;
      letter-spacing: 0.12em; text-transform: uppercase; font-size: 0.7rem;
      cursor: pointer; border-radius: 4px; font-family: inherit; text-decoration: none;
    }
    button:hover, .btn:hover { background: #d8c08b; }
    button.ghost { background: transparent; color: var(--cream-dim, #d8c9ab); border: 1px solid rgba(201,170,113,0.3); }
    button.ghost:hover { background: rgba(201,170,113,0.12); color: var(--cream, #f5e9d1); }
    button.danger { background: #b54141; color: #fff; }
    button.danger:hover { background: #d65454; }

    .grid {
      display: grid; gap: 0.75rem;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      margin-bottom: 0.8rem;
    }

    .row {
      display: flex; gap: 0.75rem; align-items: center; padding: 0.55rem 0;
      border-top: 1px solid rgba(201,170,113,0.1);
    }
    .row:first-child { border-top: none; }
    .row img { width: 64px; height: 48px; object-fit: cover; border-radius: 4px; flex-shrink: 0; }
    .row .meta { min-width: 0; flex: 1; }
    .row .meta .t { color: var(--cream, #f5e9d1); font-size: 0.92rem; line-height: 1.3; }
    .row .meta .c { color: var(--cream-dim, #d8c9ab); font-size: 0.78rem; margin-top: 0.2rem; }
    .row .meta .who { color: var(--gold, #c9aa71); font-size: 0.78rem; margin-top: 0.15rem; }
    .row .actions { display: flex; gap: 0.4rem; }

    .empty { color: var(--cream-dim, #d8c9ab); font-style: italic; padding: 0.5rem 0; }

    table.events {
      width: 100%; border-collapse: collapse; font-size: 0.85rem;
    }
    table.events th, table.events td {
      padding: 0.45rem 0.6rem; text-align: left;
      border-bottom: 1px solid rgba(201,170,113,0.1);
    }
    table.events th { color: var(--cream-dim, #d8c9ab); font-weight: 600; font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase; }
    table.events td.status { font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase; }
    table.events td.status.played   { color: #8ed49a; }
    table.events td.status.skipped  { color: #ffc878; }
    table.events td.status.rejected { color: #ff9a8a; }

    .toggle-form {
      display: flex; align-items: center; gap: 0.9rem; flex-wrap: wrap;
    }
    .toggle-form input[type="checkbox"] {
      width: 1.1rem; height: 1.1rem; accent-color: var(--gold, #c9aa71);
    }
    .toggle-form label.cb {
      letter-spacing: 0; text-transform: none; font-size: 0.92rem;
      color: var(--cream, #f5e9d1); margin: 0; display: inline-flex; gap: 0.45rem; align-items: center;
    }
  </style>
</head>
<body>
  <?php knk_render_admin_nav($me); ?>
  <main class="wrap">
    <h1>Jukebox admin</h1>
    <p class="lede">
      Bar guests request songs at <code style="color:var(--gold,#c9aa71)">/jukebox.php</code>.
      Open <code style="color:var(--gold,#c9aa71)">/jukebox-player.php</code> on the bar laptop, full-screen on the TV.
    </p>

    <?php if ($flash): ?><div class="flash ok"><?= jah($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="flash err"><?= jah($error) ?></div><?php endif; ?>

    <?php if ($api_key === ""): ?>
      <div class="flash err">
        <strong>YouTube API key not set.</strong>
        Get one at <a href="https://console.cloud.google.com/" style="color:var(--gold,#c9aa71)">Google Cloud Console</a>
        (create a project → enable "YouTube Data API v3" → make an API key) and paste it into <code>config.php</code>'s <code>youtube_api_key</code> field.
      </div>
    <?php endif; ?>

    <!-- KILL SWITCH -->
    <section class="card">
      <h2>Kill switch</h2>
      <p class="explain">
        Off = guest page shows "Closed". Player TV shows the splash. On = requests flow.
      </p>
      <form method="post" class="toggle-form">
        <input type="hidden" name="action" value="toggle_enabled">
        <span class="status-pill <?= $enabled ? "on" : "off" ?>"><span class="dot"></span><?= $enabled ? "ON" : "OFF" ?></span>
        <?php if ($enabled): ?>
          <button type="submit" name="enabled" value="0" class="danger">Turn OFF</button>
        <?php else: ?>
          <button type="submit" name="enabled" value="1">Turn ON</button>
        <?php endif; ?>
      </form>

      <h3>Auto-approve</h3>
      <p class="explain">
        ON = guest requests queue instantly. OFF = each request lands here as "Pending" and a staffer must approve.
      </p>
      <form method="post" class="toggle-form">
        <input type="hidden" name="action" value="toggle_auto_approve">
        <span class="status-pill <?= $auto ? "on" : "warn" ?>"><span class="dot"></span><?= $auto ? "AUTO" : "MANUAL" ?></span>
        <?php if ($auto): ?>
          <button type="submit" name="auto_approve" value="0" class="ghost">Switch to manual</button>
        <?php else: ?>
          <button type="submit" name="auto_approve" value="1">Switch to auto</button>
        <?php endif; ?>
      </form>
    </section>

    <!-- NOW PLAYING -->
    <section class="card">
      <h2>Now playing</h2>
      <?php if (!$now): ?>
        <div class="empty">Nothing on the TV right now.</div>
      <?php else: ?>
        <div class="row">
          <?php if (!empty($now["thumbnail_url"])): ?>
            <img src="<?= jah($now["thumbnail_url"]) ?>" alt="">
          <?php endif; ?>
          <div class="meta">
            <div class="t"><?= jah_yt($now["youtube_title"]) ?></div>
            <div class="c">
              <?= jah_yt($now["youtube_channel"]) ?> · <?= jah(knk_jukebox_fmt_duration((int)$now["duration_seconds"])) ?>
              · requested <?= jah(ja_when($now["submitted_at"])) ?>
            </div>
            <?php if (trim((string)$now["requester_name"]) !== "" || trim((string)$now["table_no"]) !== ""): ?>
              <div class="who">
                <?= jah(trim((string)$now["requester_name"])) ?><?php if ($now["requester_name"] && $now["table_no"]): ?> · <?php endif; ?><?= $now["table_no"] !== "" ? "T" . jah($now["table_no"]) : "" ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="actions">
            <form method="post" onsubmit="return confirm('Skip this song?')">
              <input type="hidden" name="action" value="skip">
              <input type="hidden" name="id" value="<?= (int)$now["id"] ?>">
              <button type="submit" class="danger">Skip</button>
            </form>
          </div>
        </div>
      <?php endif; ?>
    </section>

    <!-- UP NEXT -->
    <section class="card">
      <h2>Up next (<?= count($upnext) ?>)</h2>
      <?php if (empty($upnext)): ?>
        <div class="empty">Queue is empty.</div>
      <?php else: ?>
        <?php foreach ($upnext as $row): ?>
          <div class="row">
            <?php if (!empty($row["thumbnail_url"])): ?>
              <img src="<?= jah($row["thumbnail_url"]) ?>" alt="">
            <?php endif; ?>
            <div class="meta">
              <div class="t"><?= jah_yt($row["youtube_title"]) ?></div>
              <div class="c">
                <?= jah_yt($row["youtube_channel"]) ?> · <?= jah(knk_jukebox_fmt_duration((int)$row["duration_seconds"])) ?>
                · <?= jah(ja_when($row["submitted_at"])) ?>
              </div>
              <?php if (trim((string)$row["requester_name"]) !== "" || trim((string)$row["table_no"]) !== ""): ?>
                <div class="who">
                  <?= jah(trim((string)$row["requester_name"])) ?><?php if ($row["requester_name"] && $row["table_no"]): ?> · <?php endif; ?><?= $row["table_no"] !== "" ? "T" . jah($row["table_no"]) : "" ?>
                </div>
              <?php endif; ?>
            </div>
            <div class="actions">
              <form method="post">
                <input type="hidden" name="action" value="skip">
                <input type="hidden" name="id" value="<?= (int)$row["id"] ?>">
                <button type="submit" class="ghost">Remove</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <!-- PENDING (manual mode only) -->
    <?php if (!$auto): ?>
    <section class="card">
      <h2>Pending approvals (<?= count($pending) ?>)</h2>
      <p class="explain">Each guest request waits here until you approve it.</p>
      <?php if (empty($pending)): ?>
        <div class="empty">Nothing pending.</div>
      <?php else: ?>
        <?php foreach ($pending as $row): ?>
          <div class="row">
            <?php if (!empty($row["thumbnail_url"])): ?>
              <img src="<?= jah($row["thumbnail_url"]) ?>" alt="">
            <?php endif; ?>
            <div class="meta">
              <div class="t"><?= jah_yt($row["youtube_title"]) ?></div>
              <div class="c">
                <?= jah_yt($row["youtube_channel"]) ?> · <?= jah(knk_jukebox_fmt_duration((int)$row["duration_seconds"])) ?>
                · requested <?= jah(ja_when($row["submitted_at"])) ?>
              </div>
              <div class="who">
                Searched: "<?= jah($row["artist_text"]) ?> — <?= jah($row["title_text"]) ?>"<?php if (trim((string)$row["requester_name"]) !== "" || trim((string)$row["table_no"]) !== ""): ?> · <?= jah(trim((string)$row["requester_name"])) ?><?php if ($row["requester_name"] && $row["table_no"]): ?> · <?php endif; ?><?= $row["table_no"] !== "" ? "T" . jah($row["table_no"]) : "" ?><?php endif; ?>
              </div>
            </div>
            <div class="actions">
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="id" value="<?= (int)$row["id"] ?>">
                <button type="submit">Approve</button>
              </form>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="id" value="<?= (int)$row["id"] ?>">
                <button type="submit" class="ghost">Reject</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- SETTINGS -->
    <section class="card">
      <h2>Settings</h2>
      <form method="post">
        <input type="hidden" name="action" value="save_config">
        <div class="grid">
          <div>
            <label>Max song length (sec)</label>
            <input type="number" name="max_duration_seconds" value="<?= (int)$cfg["max_duration_seconds"] ?>" min="30" max="3600">
            <p class="explain" style="margin-top:0.4rem">Default 420 (7 min). Anything longer is rejected.</p>
          </div>
          <div>
            <label>Per-IP cooldown (sec)</label>
            <input type="number" name="per_ip_cooldown_seconds" value="<?= (int)$cfg["per_ip_cooldown_seconds"] ?>" min="0" max="3600">
            <p class="explain" style="margin-top:0.4rem">One song per phone every N seconds. 0 = no limit.</p>
          </div>
          <div>
            <label>Max queue length</label>
            <input type="number" name="max_queue_length" value="<?= (int)$cfg["max_queue_length"] ?>" min="1" max="500">
          </div>
          <div>
            <label>Player poll (sec)</label>
            <input type="number" name="board_poll_seconds" value="<?= (int)$cfg["board_poll_seconds"] ?>" min="2" max="60">
          </div>
          <div>
            <label>Require table no?</label>
            <label class="cb" style="margin-top:0.5rem">
              <input type="checkbox" name="require_table_no" value="1" <?= !empty($cfg["require_table_no"]) ? "checked" : "" ?>>
              Force guests to enter their table
            </label>
          </div>
        </div>
        <button type="submit">Save settings</button>
      </form>
    </section>

    <!-- RADIO FALLBACK -->
    <section class="card">
      <h2>Radio fallback</h2>
      <p class="explain">
        When the queue is empty <em>and</em> nothing is playing, the player TV streams this MP3 station so the bar isn't silent. The radio stops the moment a request starts playing. Default is Triple J (Australia).
      </p>
      <form method="post">
        <input type="hidden" name="action" value="save_radio">
        <div class="grid">
          <div>
            <label>Radio fallback</label>
            <label class="cb" style="margin-top:0.5rem">
              <input type="checkbox" name="radio_enabled" value="1" <?= !empty($cfg["radio_enabled"]) ? "checked" : "" ?>>
              Play a radio stream when nothing is queued
            </label>
          </div>
          <div style="grid-column: 1 / -1">
            <label>Stream URL (MP3)</label>
            <input type="url" name="radio_url" value="<?= htmlspecialchars((string)($cfg["radio_url"] ?? ""), ENT_QUOTES, "UTF-8") ?>" placeholder="https://live-radio01.mediahubaustralia.com/6TJW/mp3/" style="font-family:monospace;font-size:0.85rem">
            <p class="explain" style="margin-top:0.4rem">
              Must start with <code>http://</code> or <code>https://</code>. Tip: pick a station with an HTTPS URL — knkinn.com is HTTPS, so plain <code>http://</code> streams may be blocked by the browser as mixed content.
              <?php if (!empty($cfg["radio_url"])): ?>
                <a href="<?= htmlspecialchars((string)$cfg["radio_url"], ENT_QUOTES, "UTF-8") ?>" target="_blank" rel="noopener" style="color:var(--gold);margin-left:0.3rem">Test stream &rarr;</a>
              <?php endif; ?>
            </p>
          </div>
        </div>
        <button type="submit">Save radio fallback</button>
      </form>
    </section>

    <!-- SPOTIFY AMBIENT LAYER -->
    <section class="card" id="spotify">
      <h2>Spotify ambient layer <small style="font-weight:400;color:#888;font-size:0.8rem">(F5 only — opt-in)</small></h2>
      <p class="explain">
        Optional tier-2 between guest YouTube requests and the radio. When enabled and reachable, the F5 TV plays a Spotify playlist while the YouTube queue is empty; falls through to radio if Spotify is unavailable. Only the F5 TV launches with <code>tv.php?spotify=1</code>; ground floor stays on the radio path.
      </p>

      <?php
        $spotify_connected = knk_spotify_is_connected();
        $spotify_ready     = knk_spotify_is_ready();
        $spotify_status_label = $spotify_ready
            ? '<span style="color:#3aa55c">&#10003; Ready (connected, device + playlist set)</span>'
            : ($spotify_connected
                ? '<span style="color:#e0a800">&#9888; Connected, but device or playlist missing</span>'
                : '<span style="color:#aaa">&middot; Not connected</span>');
      ?>
      <p style="margin:0 0 1rem 0">Status: <?= $spotify_status_label ?></p>

      <!-- Step 1: app credentials + playlist -->
      <form method="post" style="margin-bottom:1.2rem">
        <input type="hidden" name="action" value="save_spotify">
        <div class="grid">
          <div>
            <label>Client ID</label>
            <input type="text" name="spotify_client_id" value="<?= htmlspecialchars((string)($cfg["spotify_client_id"] ?? ""), ENT_QUOTES, "UTF-8") ?>" placeholder="32-char hex from developer.spotify.com" style="font-family:monospace;font-size:0.85rem">
          </div>
          <div>
            <label>Client secret</label>
            <input type="password" name="spotify_client_secret" value="<?= htmlspecialchars((string)($cfg["spotify_client_secret"] ?? ""), ENT_QUOTES, "UTF-8") ?>" placeholder="32-char hex; kept server-side" style="font-family:monospace;font-size:0.85rem" autocomplete="new-password">
          </div>
          <div style="grid-column: 1 / -1">
            <label>Default playlist (Spotify URI or open.spotify.com URL)</label>
            <input type="text" name="spotify_default_playlist_uri" value="<?= htmlspecialchars((string)($cfg["spotify_default_playlist_uri"] ?? ""), ENT_QUOTES, "UTF-8") ?>" placeholder="spotify:playlist:37i9dQZF1DXcBWIGoYBM5M" style="font-family:monospace;font-size:0.85rem">
            <p class="explain" style="margin-top:0.4rem">Leave blank to disable Spotify (F5 will fall back to radio even with <code>?spotify=1</code>). The playlist loops continuously in ambient mode.</p>
          </div>
          <div>
            <label>Ambient volume (0&ndash;100)</label>
            <input type="number" name="spotify_volume_pct" min="0" max="100" value="<?= (int)($cfg["spotify_volume_pct"] ?? 50) ?>" style="width:6rem">
          </div>
        </div>
        <button type="submit">Save Spotify settings</button>
      </form>

      <!-- Step 2: connect / disconnect -->
      <div style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:center;margin-bottom:0.8rem">
        <?php if (!$spotify_connected): ?>
          <a class="btn-link" href="/api/spotify_oauth_start.php" style="background:var(--gold);color:#111;padding:0.55rem 1rem;border-radius:6px;text-decoration:none;font-weight:600">Connect Spotify &rarr;</a>
          <span class="explain" style="margin:0">Opens accounts.spotify.com to authorise the venue's account.</span>
        <?php else: ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="spotify_detect_device">
            <button type="submit">Detect device</button>
          </form>
          <a class="btn-link" href="/api/spotify_oauth_start.php" style="text-decoration:underline;color:var(--gold)">Re-authorise</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Disconnect Spotify? Re-linking only takes 30 seconds.')">
            <input type="hidden" name="action" value="spotify_clear">
            <button type="submit" style="background:#aa3a3a">Disconnect</button>
          </form>
        <?php endif; ?>
      </div>

      <?php if ($spotify_connected): ?>
        <p class="explain" style="margin-top:0.2rem">
          Device: <code><?= htmlspecialchars((string)($cfg["spotify_device_name"] ?? "(none)"), ENT_QUOTES, "UTF-8") ?></code>
          <?php if (!empty($cfg["spotify_device_id"])): ?>
            <span style="color:#666"> &middot; id <?= htmlspecialchars(substr((string)$cfg["spotify_device_id"], 0, 12), ENT_QUOTES, "UTF-8") ?>&hellip;</span>
          <?php endif; ?>
          <?php if (!empty($cfg["spotify_last_ok_at"])): ?>
            <span style="color:#666"> &middot; last ok <?= htmlspecialchars((string)$cfg["spotify_last_ok_at"], ENT_QUOTES, "UTF-8") ?></span>
          <?php endif; ?>
        </p>
        <p class="explain" style="margin:0.4rem 0 0">
          If Detect device returns "no Spotify devices found", make sure the Spotify desktop app is open on F5 and signed into the venue account, then click again.
        </p>
      <?php else: ?>
        <p class="explain" style="margin-top:0.2rem">
          One-time setup: at <a href="https://developer.spotify.com/dashboard" target="_blank" rel="noopener" style="color:var(--gold)">developer.spotify.com/dashboard</a> create an app, paste its Client ID + Client Secret above, set the redirect URI to <code><?= htmlspecialchars(knk_spotify_redirect_uri(), ENT_QUOTES, "UTF-8") ?></code>, save, then click Connect.
        </p>
      <?php endif; ?>
    </section>

    <!-- BLOCKLIST -->
    <section class="card">
      <h2>Blocklist (<?= count($blocks) ?>)</h2>
      <p class="explain">
        Block by exact YouTube videoId (the bit after <code>v=</code> in a YouTube URL) or by a case-insensitive keyword that matches the resolved title or what the guest typed.
      </p>
      <form method="post" style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1rem">
        <input type="hidden" name="action" value="blocklist_add">
        <div>
          <label>Kind</label>
          <select name="kind">
            <option value="video">videoId</option>
            <option value="keyword">keyword</option>
          </select>
        </div>
        <div style="flex:1;min-width:160px">
          <label>Value</label>
          <input type="text" name="value" placeholder="dQw4w9WgXcQ  or  wagon wheel" style="width:100%">
        </div>
        <div style="flex:1;min-width:160px">
          <label>Reason (optional)</label>
          <input type="text" name="reason" placeholder="e.g. requested for the 50th time" style="width:100%">
        </div>
        <button type="submit">Add</button>
      </form>
      <?php if (empty($blocks)): ?>
        <div class="empty">Nothing blocked.</div>
      <?php else: ?>
        <table class="events">
          <thead>
            <tr><th>Kind</th><th>Value</th><th>Reason</th><th>Added</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($blocks as $b): ?>
            <tr>
              <td><?= jah($b["kind"]) ?></td>
              <td><code><?= jah($b["value"]) ?></code></td>
              <td><?= jah($b["reason"]) ?></td>
              <td><?= jah(ja_when($b["blocked_at"])) ?></td>
              <td>
                <form method="post" onsubmit="return confirm('Remove this entry?')">
                  <input type="hidden" name="action" value="blocklist_remove">
                  <input type="hidden" name="id" value="<?= (int)$b["id"] ?>">
                  <button type="submit" class="ghost">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <!-- RECENT -->
    <section class="card">
      <h2>Recent activity</h2>
      <?php if (empty($recent)): ?>
        <div class="empty">No songs played yet.</div>
      <?php else: ?>
        <table class="events">
          <thead>
            <tr><th>When</th><th>Status</th><th>Title</th><th>Searched</th><th>Who</th><th>Lyrics?</th></tr>
          </thead>
          <tbody>
          <?php foreach ($recent as $r):
            $status = (string)$r["status"];
            $when = $r["played_at"] ?: $r["submitted_at"];
            /* who_name = COALESCE(requester_name, guests.display_name).
             * Falls back from the form's typed name to the guest's
             * profile name. Anonymous guests with neither still show
             * a "—". See knk_jukebox_recent() for the JOIN. */
            $who = trim((string)($r["who_name"] ?? $r["requester_name"]));
            $tbl = trim((string)$r["table_no"]);
            $whoStr = $who !== "" ? $who : "—";
            if ($tbl !== "") $whoStr .= " · T" . $tbl;
            /* Lyrics status — populated by tv.php's LRCLIB callback.
             * 'unknown' = never tried yet (TV hasn't played this row). */
            $ly = (string)($r["lyrics_status"] ?? "unknown");
            $lyLabel = "—";
            $lyColor = "color:var(--cream-dim,#d8c9ab)";
            if      ($ly === "synced")  { $lyLabel = "✓ synced";  $lyColor = "color:#2fdc7a;font-weight:600"; }
            elseif  ($ly === "plain")   { $lyLabel = "plain";     $lyColor = "color:var(--gold,#c9aa71)"; }
            elseif  ($ly === "missing") { $lyLabel = "✗ none";    $lyColor = "color:#d94343"; }
          ?>
            <tr>
              <td><?= jah(ja_when($when)) ?></td>
              <td class="status <?= jah($status) ?>"><?= jah($status) ?></td>
              <td><?= jah_yt($r["youtube_title"]) ?></td>
              <td style="color:var(--cream-dim,#d8c9ab)"><?= jah($r["artist_text"]) ?> — <?= jah($r["title_text"]) ?></td>
              <td><?= jah($whoStr) ?></td>
              <td style="<?= $lyColor ?>;font-size:0.85rem"><?= jah($lyLabel) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

  </main>
</body>
</html>

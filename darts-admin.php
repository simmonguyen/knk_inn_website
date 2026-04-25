<?php
/*
 * KnK Inn — /darts-admin.php
 *
 * Darts control room. Super Admin / Owner / Bartender (so any
 * staffer can take a board offline or force-end a stuck game).
 *
 * Sections:
 *   1. Kill switch
 *   2. Settings (poll seconds, auto-abandon after N minutes)
 *   3. Boards (rename / enable / disable)
 *   4. Active games (lobby + playing) with force-end
 *   5. Recent games (last 30, with winner + duration + players)
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/darts.php";

$me    = knk_require_permission("darts");
$me_id = (int)$me["id"];

$flash = "";
$error = "";
$action = (string)($_POST["action"] ?? "");

try {
    if ($action === "toggle_enabled") {
        $target = !empty($_POST["enabled"]) ? 1 : 0;
        $st = knk_db()->prepare(
            "UPDATE darts_config SET enabled = ?, updated_by = ? WHERE id = 1"
        );
        $st->execute([$target, $me_id]);
        knk_audit("darts.toggle", "darts_config", "1", ["enabled" => $target]);
        $flash = $target
            ? "Darts is ON. Punters can pick a board at /darts.php."
            : "Darts is OFF. The picker shows a closed message.";
    }
    elseif ($action === "save_settings") {
        $poll  = max(2, min(60, (int)($_POST["poll_seconds"]        ?? 2)));
        $stale = max(5, min(720, (int)($_POST["stale_after_minutes"] ?? 60)));
        $st = knk_db()->prepare(
            "UPDATE darts_config
                SET poll_seconds = ?, stale_after_minutes = ?, updated_by = ?
              WHERE id = 1"
        );
        $st->execute([$poll, $stale, $me_id]);
        knk_audit("darts.settings", "darts_config", "1", [
            "poll_seconds" => $poll, "stale_after_minutes" => $stale,
        ]);
        $flash = "Settings saved.";
    }
    elseif ($action === "save_board") {
        $bid     = (int)($_POST["board_id"] ?? 0);
        $name    = trim((string)($_POST["name"] ?? ""));
        $enabled = !empty($_POST["enabled"]) ? 1 : 0;
        if ($bid <= 0)        throw new RuntimeException("Bad board id.");
        if ($name === "")     throw new RuntimeException("Enter a board name.");
        if (strlen($name) > 40) throw new RuntimeException("Board name max 40 chars.");
        $st = knk_db()->prepare(
            "UPDATE darts_boards SET name = ?, enabled = ? WHERE id = ?"
        );
        $st->execute([$name, $enabled, $bid]);
        knk_audit("darts.board_save", "darts_boards", (string)$bid, [
            "name" => $name, "enabled" => $enabled,
        ]);
        $flash = "Board saved.";
    }
    elseif ($action === "force_finish") {
        $gid = (int)($_POST["game_id"] ?? 0);
        if ($gid <= 0) throw new RuntimeException("Bad game id.");
        knk_darts_force_finish($gid);
        knk_audit("darts.force_finish", "darts_games", (string)$gid);
        $flash = "Game force-ended.";
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($action !== "") {
    $qs = [];
    if ($flash) $qs["msg"] = $flash;
    if ($error) $qs["err"] = $error;
    header("Location: /darts-admin.php" . ($qs ? "?" . http_build_query($qs) : ""));
    exit;
}

$flash = (string)($_GET["msg"] ?? "");
$error = (string)($_GET["err"] ?? "");

// Cleanup before reading so the active-games table doesn't show stale ones.
knk_darts_cleanup_stale();

$cfg     = knk_darts_config();
$enabled = !empty($cfg["enabled"]);
$boards  = knk_darts_boards();

// Active games (lobby + playing) — newest first.
$active_st = knk_db()->query(
    "SELECT g.*, b.name AS board_name,
            (SELECT COUNT(*) FROM darts_players p WHERE p.game_id = g.id) AS players_joined
       FROM darts_games g
       JOIN darts_boards b ON b.id = g.board_id
      WHERE g.status IN ('lobby','playing')
      ORDER BY g.id DESC"
);
$active = $active_st->fetchAll();

// Recent games — last 30 finished or abandoned.
$recent_st = knk_db()->query(
    "SELECT g.*, b.name AS board_name,
            (SELECT COUNT(*) FROM darts_players p WHERE p.game_id = g.id) AS players_joined,
            (SELECT GROUP_CONCAT(p.name ORDER BY p.slot_no SEPARATOR ', ')
               FROM darts_players p WHERE p.game_id = g.id) AS roster,
            (SELECT p.name FROM darts_players p
              WHERE p.game_id = g.id AND p.slot_no = g.winner_slot_no LIMIT 1) AS winner_name
       FROM darts_games g
       JOIN darts_boards b ON b.id = g.board_id
      WHERE g.status IN ('finished','abandoned')
      ORDER BY g.id DESC
      LIMIT 30"
);
$recent = $recent_st->fetchAll();

function dah($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

function da_when($s): string {
    if (!$s) return "—";
    $t = strtotime((string)$s);
    if (!$t) return dah($s);
    $age = time() - $t;
    if ($age < 60)    return $age . "s ago";
    if ($age < 3600)  return (int)floor($age/60)  . "m ago";
    if ($age < 86400) return (int)floor($age/3600) . "h ago";
    return date("M j, H:i", $t);
}

function da_duration(?string $start, ?string $end): string {
    if (!$start || !$end) return "—";
    $s = strtotime($start); $e = strtotime($end);
    if (!$s || !$e || $e < $s) return "—";
    $secs = $e - $s;
    if ($secs < 60)    return $secs . "s";
    if ($secs < 3600)  return (int)floor($secs/60) . "m " . ($secs % 60) . "s";
    $h = (int)floor($secs/3600);
    $m = (int)floor(($secs % 3600) / 60);
    return $h . "h " . $m . "m";
}

function da_game_label(string $type): string {
    $labels = [
        "501"         => "501",
        "301"         => "301",
        "cricket"     => "Cricket",
        "aroundclock" => "Around the Clock",
        "killer"      => "Killer",
        "halveit"     => "Halve-It",
    ];
    return $labels[$type] ?? $type;
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>KnK Inn — Darts admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/styles.css?v=12">
  <style>
    body { background: #1b0f04; color: var(--cream, #f5e9d1); font-family: "Inter", system-ui, sans-serif; margin: 0; }
    main.wrap { max-width: 1100px; margin: 0 auto; padding: 1.6rem 1.2rem 4rem; }
    h1 { margin: 1.2rem 0 0.3rem; font-family: "Archivo Black", sans-serif; font-size: 1.7rem; letter-spacing: .04em; }
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
    input[type="number"], input[type="text"] {
      padding: 0.55rem 0.7rem; background: rgba(255,255,255,0.04);
      border: 1px solid rgba(201,170,113,0.3); color: var(--cream, #f5e9d1);
      font-size: 0.92rem; font-family: inherit; border-radius: 4px;
    }
    input[type="number"] { width: 110px; }
    input:focus { outline: none; border-color: var(--gold, #c9aa71); }

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

    .empty { color: var(--cream-dim, #d8c9ab); font-style: italic; padding: 0.5rem 0; }

    table.events {
      width: 100%; border-collapse: collapse; font-size: 0.85rem;
    }
    table.events th, table.events td {
      padding: 0.55rem 0.6rem; text-align: left; vertical-align: top;
      border-bottom: 1px solid rgba(201,170,113,0.1);
    }
    table.events th { color: var(--cream-dim, #d8c9ab); font-weight: 600; font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase; }
    table.events td.code { font-family: monospace; color: var(--gold, #c9aa71); letter-spacing: 0.08em; }
    table.events td.status { font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase; }
    table.events td.status.lobby     { color: #ffc878; }
    table.events td.status.playing   { color: #8ed49a; }
    table.events td.status.finished  { color: #8ed49a; }
    table.events td.status.abandoned { color: #ff9a8a; }
    table.events td.winner { color: var(--gold, #c9aa71); font-weight: 600; }
    table.events td.muted  { color: var(--cream-dim, #d8c9ab); }

    .board-row {
      display: flex; gap: 0.75rem; align-items: flex-end;
      padding: 0.7rem 0; border-top: 1px solid rgba(201,170,113,0.1);
      flex-wrap: wrap;
    }
    .board-row:first-of-type { border-top: none; }
    .board-row .name-field { flex: 1; min-width: 180px; }
    .board-row .name-field input { width: 100%; }

    .panel-link { color: var(--gold, #c9aa71); text-decoration: none; font-weight: 600; }
    .panel-link:hover { text-decoration: underline; }
  </style>
</head>
<body>
  <?php knk_render_admin_nav($me); ?>
  <main class="wrap">
    <h1>Darts admin</h1>
    <p class="lede">
      Punters pick a board at <code style="color:var(--gold,#c9aa71)">/darts.php</code>.
      The first phone on a board is the host — they pick the game and others scan a QR or type the 6-letter code to join.
    </p>

    <?php if ($flash): ?><div class="flash ok"><?= dah($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="flash err"><?= dah($error) ?></div><?php endif; ?>

    <!-- KILL SWITCH -->
    <section class="card">
      <h2>Kill switch</h2>
      <p class="explain">
        Off = punters trying to start a game see a closed message. Any games already in progress keep working until they finish or get force-ended below.
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
    </section>

    <!-- SETTINGS -->
    <section class="card">
      <h2>Settings</h2>
      <form method="post">
        <input type="hidden" name="action" value="save_settings">
        <div class="grid">
          <div>
            <label>Phone poll (sec)</label>
            <input type="number" name="poll_seconds" value="<?= (int)$cfg["poll_seconds"] ?>" min="2" max="60">
            <p class="explain" style="margin-top:0.4rem">How often each phone refreshes the scoreboard. 2 is snappy; raise it if you ever see the database struggling.</p>
          </div>
          <div>
            <label>Auto-abandon after (min)</label>
            <input type="number" name="stale_after_minutes" value="<?= (int)$cfg["stale_after_minutes"] ?>" min="5" max="720">
            <p class="explain" style="margin-top:0.4rem">If nobody throws a dart for this many minutes, the game is auto-abandoned and the board is freed up.</p>
          </div>
        </div>
        <button type="submit">Save settings</button>
      </form>
    </section>

    <!-- BOARDS -->
    <section class="card">
      <h2>Boards (<?= count($boards) ?>)</h2>
      <p class="explain">
        Disable a board to take it offline (broken flights, being moved, used for storage). The picker hides disabled boards from punters.
      </p>
      <?php if (empty($boards)): ?>
        <div class="empty">No boards configured.</div>
      <?php else: ?>
        <?php foreach ($boards as $b): ?>
          <form method="post" class="board-row">
            <input type="hidden" name="action" value="save_board">
            <input type="hidden" name="board_id" value="<?= (int)$b["id"] ?>">
            <div class="name-field">
              <label>Board name</label>
              <input type="text" name="name" value="<?= dah($b["name"]) ?>" maxlength="40">
            </div>
            <div>
              <label>Enabled</label>
              <label class="cb" style="margin-top:0.5rem">
                <input type="checkbox" name="enabled" value="1" <?= !empty($b["enabled"]) ? "checked" : "" ?>>
                Show to punters
              </label>
            </div>
            <div>
              <button type="submit">Save</button>
            </div>
          </form>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <!-- ACTIVE GAMES -->
    <section class="card">
      <h2>Active games (<?= count($active) ?>)</h2>
      <p class="explain">
        Games still in the lobby or being played. Force-end one if a group walked off without finishing — it frees the board and writes the game off as abandoned.
      </p>
      <?php if (empty($active)): ?>
        <div class="empty">No games in progress.</div>
      <?php else: ?>
        <table class="events">
          <thead>
            <tr>
              <th>Code</th><th>Board</th><th>Game</th><th>Status</th>
              <th>Players</th><th>Started</th><th>Last throw</th><th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($active as $g):
            $status = (string)$g["status"];
          ?>
            <tr>
              <td class="code"><?= dah($g["join_code"]) ?></td>
              <td><?= dah($g["board_name"]) ?></td>
              <td>
                <?= dah(da_game_label((string)$g["game_type"])) ?>
                <span class="muted">· <?= dah($g["format"]) ?> · <?= (int)$g["player_count"] ?>p</span>
              </td>
              <td class="status <?= dah($status) ?>"><?= dah($status) ?></td>
              <td><?= (int)$g["players_joined"] ?> / <?= (int)$g["player_count"] ?></td>
              <td><?= dah(da_when($g["started_at"] ?: $g["created_at"])) ?></td>
              <td><?= dah(da_when($g["last_throw_at"])) ?></td>
              <td>
                <form method="post" onsubmit="return confirm('Force-end this game? Any score is discarded.')">
                  <input type="hidden" name="action" value="force_finish">
                  <input type="hidden" name="game_id" value="<?= (int)$g["id"] ?>">
                  <button type="submit" class="danger">Force end</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <!-- RECENT GAMES -->
    <section class="card">
      <h2>Recent games</h2>
      <p class="explain">Last 30 games that finished or were abandoned.</p>
      <?php if (empty($recent)): ?>
        <div class="empty">No games played yet.</div>
      <?php else: ?>
        <table class="events">
          <thead>
            <tr>
              <th>When</th><th>Status</th><th>Board</th><th>Game</th>
              <th>Players</th><th>Winner</th><th>Duration</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($recent as $g):
            $status = (string)$g["status"];
            $when = $g["finished_at"] ?: ($g["last_throw_at"] ?: $g["started_at"]);
            $winner = trim((string)($g["winner_name"] ?? ""));
            if ($status === "finished" && $winner === "" && !empty($g["winner_team_no"])) {
                $winner = "Team " . (int)$g["winner_team_no"];
            }
          ?>
            <tr>
              <td><?= dah(da_when($when)) ?></td>
              <td class="status <?= dah($status) ?>"><?= dah($status) ?></td>
              <td><?= dah($g["board_name"]) ?></td>
              <td>
                <?= dah(da_game_label((string)$g["game_type"])) ?>
                <span class="muted">· <?= dah($g["format"]) ?></span>
              </td>
              <td class="muted"><?= dah((string)($g["roster"] ?? "")) ?></td>
              <td class="winner"><?= $winner !== "" ? "🏆 " . dah($winner) : "—" ?></td>
              <td><?= dah(da_duration($g["started_at"], $g["finished_at"])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

  </main>
</body>
</html>

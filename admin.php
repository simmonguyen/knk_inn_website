<?php
/* =========================================================
   KnK Inn — Bookings admin for Simmo
   https://knkinn.com/admin.php
   Password-protected. See all bookings on one screen, confirm / decline
   pending holds, manually block dates (maintenance), browse recent history.
   ========================================================= */

session_start();

require_once __DIR__ . "/includes/bookings_store.php";

/* ---- Config ---- */
const ADMIN_PASSWORD = "Knk@070475";   // same password Simmo uses elsewhere
const ROOMS = [
    "standard-nowindow" => "Standard (no window)",
    "standard-balcony"  => "Standard with balcony",
    "vip"               => "VIP",
];
const CALENDAR_MONTHS = 3;   // how many months forward to render on the calendar grid

function is_logged_in(): bool {
    return !empty($_SESSION["admin_ok"]);
}

/* ---------- Logout ---------- */
if (($_POST["action"] ?? "") === "logout") {
    $_SESSION = [];
    session_destroy();
    header("Location: admin.php");
    exit;
}

/* ---------- Login ---------- */
$login_error = "";
if (($_POST["action"] ?? "") === "login") {
    $pw = $_POST["password"] ?? "";
    if (hash_equals(ADMIN_PASSWORD, $pw)) {
        session_regenerate_id(true);
        $_SESSION["admin_ok"] = true;
        header("Location: admin.php");
        exit;
    } else {
        $login_error = "Wrong password, mate. Try again.";
    }
}

/* Gate everything below */
if (!is_logged_in()) {
    render_login($login_error);
    exit;
}

/* ---------- Action: confirm / decline a hold by id ---------- */
$flash = "";
if (in_array(($_POST["action"] ?? ""), ["confirm", "decline"], true)) {
    $id = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST["id"] ?? "");
    if ($id !== "") {
        try {
            $res = bookings_set_status_by_id($id, $_POST["action"]);
            if ($res) {
                $flash = ($_POST["action"] === "confirm")
                    ? "Booking confirmed — dates are now blocked."
                    : "Booking declined — dates are open again.";
            } else {
                $flash = "Couldn't find that hold.";
            }
        } catch (Throwable $e) {
            $flash = "Error: " . $e->getMessage();
        }
    }
    header("Location: admin.php?msg=" . urlencode($flash));
    exit;
}

/* ---------- Action: manual block ---------- */
if (($_POST["action"] ?? "") === "block") {
    $room     = $_POST["room"]     ?? "";
    $checkin  = $_POST["checkin"]  ?? "";
    $checkout = $_POST["checkout"] ?? "";
    $reason   = trim($_POST["reason"] ?? "Blocked");
    if (!isset(ROOMS[$room])) {
        $flash = "Pick a room.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkin) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkout)) {
        $flash = "Pick valid dates.";
    } elseif (strtotime($checkout) <= strtotime($checkin)) {
        $flash = "End date must be after start date.";
    } else {
        try {
            bookings_manual_block($room, $checkin, $checkout, $reason !== "" ? $reason : "Blocked");
            $flash = "Blocked {$checkin} → {$checkout} on " . ROOMS[$room];
        } catch (Throwable $e) {
            $flash = "Could not block: " . $e->getMessage();
        }
    }
    header("Location: admin.php?msg=" . urlencode($flash));
    exit;
}

/* ---------- Action: delete a hold (used for unblocking manual blocks) ---------- */
if (($_POST["action"] ?? "") === "delete") {
    $id = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST["id"] ?? "");
    if ($id !== "") {
        try {
            $ok = bookings_delete_by_id($id);
            $flash = $ok ? "Entry removed." : "Already gone.";
        } catch (Throwable $e) {
            $flash = "Error: " . $e->getMessage();
        }
    }
    header("Location: admin.php?msg=" . urlencode($flash));
    exit;
}

$flash_msg = $_GET["msg"] ?? "";

/* ---------- Load data for the dashboard ---------- */
$all = bookings_list_all(true);  // auto-expires stale pending

$now = time();
$today_ymd = date("Y-m-d", $now);

$pending   = [];
$upcoming  = [];   // confirmed with checkout >= today
$past      = [];   // status confirmed|declined|expired with checkout < today
foreach ($all as $h) {
    $status = $h["status"] ?? "pending";
    $co = strtotime($h["checkout"] ?? "");
    if ($status === "pending") {
        $pending[] = $h;
    } elseif ($status === "confirmed" && $co && $co >= $now) {
        $upcoming[] = $h;
    } else {
        // confirmed past, declined, expired → history
        $past[] = $h;
    }
}
// sort upcoming by checkin ascending (nearest first)
usort($upcoming, function ($a, $b) { return strtotime($a["checkin"]) <=> strtotime($b["checkin"]); });
// limit history to most recent 50 to keep the page light
$past = array_slice($past, 0, 50);

/* ---------- Build calendar lookup: [room][Y-m-d] = status|reason ---------- */
$calendar = [];
foreach (array_keys(ROOMS) as $r) $calendar[$r] = [];
foreach ($all as $h) {
    $s = $h["status"] ?? "pending";
    if ($s === "declined" || $s === "expired") continue;
    if ($s === "pending" && ($now - ($h["created_at"] ?? 0)) > KNK_HOLD_TTL) continue;
    $room = $h["room"] ?? "";
    if (!isset($calendar[$room])) continue;
    $start = strtotime($h["checkin"]);
    $end   = strtotime($h["checkout"]);
    if (!$start || !$end) continue;
    for ($t = $start; $t < $end; $t += 86400) {
        $d = date("Y-m-d", $t);
        // confirmed wins over pending if both cover same day
        $prev = $calendar[$room][$d]["status"] ?? null;
        if ($prev === "confirmed") continue;
        $calendar[$room][$d] = [
            "status" => $s,
            "name"   => $h["guest"]["name"] ?? "",
            "id"     => $h["id"] ?? "",
        ];
    }
}

/* ---------- Helpers for rendering ---------- */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function fmt_date(string $ymd): string {
    $t = strtotime($ymd);
    return $t ? date("D j M Y", $t) : $ymd;
}
function fmt_datetime(int $ts): string {
    return $ts ? date("D j M · H:i", $ts) : "—";
}
function render_month_calendar(string $room, int $year, int $month, array $bookings): string {
    $first = mktime(0, 0, 0, $month, 1, $year);
    $daysInMonth = (int)date("t", $first);
    // Monday-first: PHP "w" returns 0=Sun..6=Sat, so shift
    $leading = ((int)date("w", $first) + 6) % 7;
    $today = date("Y-m-d");
    $html = '<div class="month">';
    $html .= '<div class="m-head">' . date("F Y", $first) . '</div>';
    $html .= '<div class="m-grid"><div class="m-dow">Mo</div><div class="m-dow">Tu</div><div class="m-dow">We</div><div class="m-dow">Th</div><div class="m-dow">Fr</div><div class="m-dow">Sa</div><div class="m-dow">Su</div>';
    for ($i = 0; $i < $leading; $i++) $html .= '<div class="m-cell empty"></div>';
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $ymd = sprintf("%04d-%02d-%02d", $year, $month, $d);
        $info = $bookings[$ymd] ?? null;
        $cls = "m-cell";
        $title = "";
        if ($info) {
            $cls .= " " . ($info["status"] === "confirmed" ? "confirmed" : "pending");
            $title = ($info["status"] === "confirmed" ? "Confirmed" : "Pending") . " — " . $info["name"];
        }
        if ($ymd === $today) $cls .= " today";
        if ($ymd < $today)   $cls .= " past";
        $html .= '<div class="' . $cls . '" title="' . h($title) . '"><span class="d">' . $d . '</span></div>';
    }
    $html .= '</div></div>';
    return $html;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>KnK Inn — Bookings admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Caveat:wght@700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/styles.css?v=12">
  <style>
    body { padding: 2rem 1rem 4rem; }
    .wrap { max-width: 1100px; margin: 0 auto; }
    header.bar {
      display: flex; align-items: center; justify-content: space-between;
      gap: 1rem; flex-wrap: wrap; margin-bottom: 2rem;
    }
    header.bar .title { flex: 1; min-width: 240px; }
    header.bar .actions { display: flex; gap: 0.6rem; flex-wrap: wrap; }
    header.bar .actions button,
    header.bar .actions a.btn-mini {
      color: var(--cream-dim); font-size: 0.72rem; letter-spacing: 0.18em;
      text-transform: uppercase; text-decoration: none; padding: 0.55rem 1rem;
      border: 1px solid rgba(201,170,113,0.3); border-radius: 3px; background: transparent;
      cursor: pointer; font-family: inherit;
    }
    header.bar .actions button:hover,
    header.bar .actions a.btn-mini:hover { border-color: var(--gold); color: var(--gold); }

    .flash {
      background: rgba(201,170,113,0.12); border: 1px solid rgba(201,170,113,0.35);
      color: var(--gold); padding: 0.8rem 1.1rem; border-radius: 4px; margin-bottom: 1.6rem;
      font-size: 0.9rem;
    }

    section.panel {
      margin-bottom: 2.6rem;
      background: rgba(24,12,3,0.4);
      border: 1px solid rgba(201,170,113,0.18);
      border-radius: 6px;
      padding: 1.6rem 1.4rem 1.3rem;
    }
    section.panel > h2 {
      font-size: 0.78rem; letter-spacing: 0.22em; text-transform: uppercase;
      color: var(--gold); margin: 0 0 1.2rem 0; font-weight: 700;
    }
    section.panel > h2 .sub {
      color: var(--cream-faint); font-weight: 400; letter-spacing: 0.12em; margin-left: 0.6rem;
    }

    .empty { color: var(--cream-faint); font-size: 0.9rem; padding: 0.8rem 0; }

    /* ---------- Pending / upcoming cards ---------- */
    .cards { display: grid; grid-template-columns: 1fr; gap: 0.9rem; }
    @media (min-width: 780px) { .cards { grid-template-columns: 1fr 1fr; } }
    .card {
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(201,170,113,0.18);
      border-radius: 5px; padding: 1rem 1.1rem;
      display: flex; flex-direction: column; gap: 0.5rem;
    }
    .card.pending { border-color: rgba(201,170,113,0.45); background: rgba(201,170,113,0.05); }
    .card .row1 {
      display: flex; justify-content: space-between; align-items: baseline; gap: 0.8rem; flex-wrap: wrap;
    }
    .card .name { font-weight: 700; color: var(--cream); font-size: 1.05rem; }
    .card .room { font-size: 0.72rem; letter-spacing: 0.16em; text-transform: uppercase; color: var(--gold); }
    .card .dates { color: var(--cream-dim); font-size: 0.92rem; }
    .card .dates strong { color: var(--cream); }
    .card .contact { font-size: 0.85rem; color: var(--cream-dim); }
    .card .contact a { color: var(--gold); text-decoration: none; }
    .card .contact a:hover { text-decoration: underline; }
    .card .msg {
      font-style: italic; color: var(--cream-dim); font-size: 0.88rem;
      background: rgba(0,0,0,0.2); padding: 0.5rem 0.75rem; border-left: 2px solid var(--gold);
      white-space: pre-wrap;
    }
    .card .meta { font-size: 0.72rem; color: var(--cream-faint); letter-spacing: 0.08em; }
    .card .btn-row { display: flex; gap: 0.6rem; margin-top: 0.3rem; flex-wrap: wrap; }
    .card button.ok,
    .card button.no,
    .card button.rm {
      padding: 0.55rem 1.1rem; border-radius: 3px; border: 1px solid;
      font-size: 0.74rem; letter-spacing: 0.16em; text-transform: uppercase;
      cursor: pointer; font-family: inherit; font-weight: 700;
    }
    .card button.ok { background: var(--gold); color: var(--brown-deep); border-color: var(--gold); }
    .card button.ok:hover { background: var(--gold-light, #d8c08b); }
    .card button.no { background: transparent; color: var(--cream-dim); border-color: rgba(201,170,113,0.3); }
    .card button.no:hover { border-color: #ff9a8a; color: #ff9a8a; }
    .card button.rm { background: transparent; color: var(--cream-faint); border-color: rgba(255,255,255,0.15); font-size: 0.68rem; }
    .card button.rm:hover { border-color: #ff9a8a; color: #ff9a8a; }

    /* ---------- Status pill ---------- */
    .pill {
      display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 0.68rem;
      letter-spacing: 0.14em; text-transform: uppercase; font-weight: 700;
    }
    .pill.confirmed { background: rgba(127,208,138,0.15); color: #a8e0b1; border: 1px solid rgba(127,208,138,0.4); }
    .pill.pending   { background: rgba(201,170,113,0.15); color: var(--gold); border: 1px solid rgba(201,170,113,0.4); }
    .pill.declined  { background: rgba(255,154,138,0.12); color: #ff9a8a; border: 1px solid rgba(255,154,138,0.35); }
    .pill.expired   { background: rgba(255,255,255,0.05); color: var(--cream-faint); border: 1px solid rgba(255,255,255,0.15); }

    /* ---------- Calendar ---------- */
    .room-block { margin-bottom: 2rem; }
    .room-block:last-child { margin-bottom: 0; }
    .room-block h3 {
      font-family: 'Archivo Black', sans-serif; font-size: 1.1rem; color: var(--cream);
      margin: 0 0 0.9rem 0; letter-spacing: 0.01em;
    }
    .months {
      display: grid; gap: 1rem;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    }
    .month {
      background: rgba(0,0,0,0.2); border: 1px solid rgba(201,170,113,0.15);
      border-radius: 4px; padding: 0.7rem 0.75rem;
    }
    .m-head {
      font-size: 0.78rem; letter-spacing: 0.16em; text-transform: uppercase;
      color: var(--gold); text-align: center; margin-bottom: 0.6rem; font-weight: 700;
    }
    .m-grid {
      display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px;
    }
    .m-dow {
      font-size: 0.58rem; letter-spacing: 0.1em; text-transform: uppercase;
      text-align: center; color: var(--cream-faint); padding: 2px 0 4px;
    }
    .m-cell {
      aspect-ratio: 1 / 1; position: relative; border-radius: 3px;
      background: rgba(255,255,255,0.02); display: flex; align-items: center; justify-content: center;
      font-size: 0.74rem; color: var(--cream-dim); border: 1px solid transparent;
    }
    .m-cell.empty { background: transparent; }
    .m-cell.today { border-color: var(--gold); color: var(--gold); font-weight: 700; }
    .m-cell.past { opacity: 0.4; }
    .m-cell.pending   { background: rgba(201,170,113,0.35); color: var(--brown-deep); font-weight: 700; }
    .m-cell.confirmed { background: var(--gold); color: var(--brown-deep); font-weight: 700; }
    .m-cell.past.confirmed,
    .m-cell.past.pending { opacity: 0.55; }
    .m-legend { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 0.8rem; font-size: 0.72rem; color: var(--cream-faint); }
    .m-legend .sw { display: inline-block; width: 10px; height: 10px; border-radius: 2px; vertical-align: middle; margin-right: 4px; }
    .sw.confirmed { background: var(--gold); }
    .sw.pending   { background: rgba(201,170,113,0.35); }
    .sw.today     { background: transparent; border: 1px solid var(--gold); }

    /* ---------- Manual block form ---------- */
    .block-form {
      display: grid; gap: 0.7rem;
      grid-template-columns: 1fr;
    }
    @media (min-width: 780px) {
      .block-form { grid-template-columns: 1.2fr 1fr 1fr 1.5fr auto; align-items: end; }
    }
    .block-form label { display: flex; flex-direction: column; gap: 0.3rem; font-size: 0.72rem; letter-spacing: 0.14em; text-transform: uppercase; color: var(--cream-faint); }
    .block-form input,
    .block-form select {
      padding: 0.6rem 0.7rem; background: rgba(255,255,255,0.04);
      border: 1px solid rgba(201,170,113,0.3); color: var(--cream);
      font-family: inherit; font-size: 0.9rem; border-radius: 3px;
    }
    .block-form input:focus,
    .block-form select:focus { outline: none; border-color: var(--gold); }
    .block-form button.block-btn {
      padding: 0.7rem 1.3rem; background: var(--gold); color: var(--brown-deep);
      border: none; font-weight: 700; letter-spacing: 0.16em; text-transform: uppercase;
      font-size: 0.74rem; cursor: pointer; border-radius: 3px; font-family: inherit;
    }
    .block-form button.block-btn:hover { background: var(--gold-light, #d8c08b); }

    /* ---------- History table ---------- */
    .history {
      width: 100%; border-collapse: collapse; font-size: 0.88rem; color: var(--cream-dim);
    }
    .history th, .history td {
      padding: 0.55rem 0.7rem; text-align: left; border-bottom: 1px solid rgba(201,170,113,0.1);
    }
    .history th {
      font-size: 0.68rem; letter-spacing: 0.16em; text-transform: uppercase;
      color: var(--cream-faint); font-weight: 600;
    }
    .history td.guest { color: var(--cream); font-weight: 600; }
    .history td a { color: var(--gold); text-decoration: none; }
    .history td a:hover { text-decoration: underline; }
    .history-wrap { overflow-x: auto; }

    .footnote { color: var(--cream-faint); font-size: 0.78rem; margin-top: 1rem; line-height: 1.5; }
    .footnote code { background: rgba(0,0,0,0.3); padding: 1px 5px; border-radius: 2px; font-size: 0.82em; }
  </style>
</head>
<body>
<div class="wrap">

  <header class="bar">
    <div class="title">
      <span class="eyebrow">Staff only</span>
      <h1 class="display-md">Bookings <em>admin</em></h1>
    </div>
    <div class="actions">
      <a class="btn-mini" href="photos.php">Photos</a>
      <a class="btn-mini" href="index.html" target="_blank">View site</a>
      <form method="post" style="margin:0;">
        <input type="hidden" name="action" value="logout">
        <button type="submit">Log out</button>
      </form>
    </div>
  </header>

  <?php if ($flash_msg): ?>
    <div class="flash"><?= h($flash_msg) ?></div>
  <?php endif; ?>

  <!-- ============================================================ -->
  <!-- PENDING -->
  <!-- ============================================================ -->
  <section class="panel">
    <h2>Pending holds <span class="sub"><?= count($pending) ?> waiting</span></h2>

    <?php if (empty($pending)): ?>
      <div class="empty">Nothing to action right now.</div>
    <?php else: ?>
      <div class="cards">
        <?php foreach ($pending as $h):
          $guest = $h["guest"] ?? [];
          $created = (int)($h["created_at"] ?? 0);
          $expiresIn = KNK_HOLD_TTL - ($now - $created);
          $hours = max(0, (int)floor($expiresIn / 3600));
          $mins  = max(0, (int)floor(($expiresIn % 3600) / 60));
        ?>
          <div class="card pending">
            <div class="row1">
              <span class="name"><?= h($guest["name"] ?? "(no name)") ?></span>
              <span class="room"><?= h(ROOMS[$h["room"]] ?? $h["room"]) ?></span>
            </div>
            <div class="dates">
              <strong><?= h(fmt_date($h["checkin"])) ?></strong> → <strong><?= h(fmt_date($h["checkout"])) ?></strong>
              · <?= (int)$h["nights"] ?> night<?= (int)$h["nights"] === 1 ? "" : "s" ?>
              <?php if (!empty($h["price_vnd_per_night"])): ?>
                · <?= number_format($h["price_vnd_per_night"] * (int)$h["nights"], 0, ".", ",") ?> VND total
              <?php endif; ?>
            </div>
            <?php if (!empty($guest["email"]) || !empty($guest["phone"])): ?>
              <div class="contact">
                <?php if (!empty($guest["email"])): ?><a href="mailto:<?= h($guest["email"]) ?>"><?= h($guest["email"]) ?></a><?php endif; ?>
                <?php if (!empty($guest["phone"])): ?><?= !empty($guest["email"]) ? " · " : "" ?><a href="tel:<?= h($guest["phone"]) ?>"><?= h($guest["phone"]) ?></a><?php endif; ?>
                <?php if (!empty($guest["guests"])): ?> · <?= h($guest["guests"]) ?> guests<?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if (!empty($guest["message"])): ?>
              <div class="msg"><?= h($guest["message"]) ?></div>
            <?php endif; ?>
            <div class="meta">
              Received <?= h(fmt_datetime($created)) ?>
              · expires in <?= $hours ?>h <?= $mins ?>m
              · <code style="opacity:0.7;"><?= h($h["id"]) ?></code>
            </div>
            <div class="btn-row">
              <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="confirm">
                <input type="hidden" name="id" value="<?= h($h["id"]) ?>">
                <button type="submit" class="ok">Confirm</button>
              </form>
              <form method="post" style="margin:0;" onsubmit="return confirm('Decline this booking?');">
                <input type="hidden" name="action" value="decline">
                <input type="hidden" name="id" value="<?= h($h["id"]) ?>">
                <button type="submit" class="no">Decline</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- ============================================================ -->
  <!-- UPCOMING CONFIRMED -->
  <!-- ============================================================ -->
  <section class="panel">
    <h2>Upcoming confirmed <span class="sub"><?= count($upcoming) ?> total</span></h2>

    <?php if (empty($upcoming)): ?>
      <div class="empty">No upcoming bookings.</div>
    <?php else: ?>
      <div class="cards">
        <?php foreach ($upcoming as $h):
          $guest = $h["guest"] ?? [];
          $isBlock = (($guest["name"] ?? "") === "Blocked") || stripos($guest["message"] ?? "", "Manually blocked") !== false;
        ?>
          <div class="card">
            <div class="row1">
              <span class="name">
                <?= h($guest["name"] ?? "(no name)") ?>
                <?php if ($isBlock): ?> <span class="pill expired" style="margin-left:6px;">Manual block</span><?php endif; ?>
              </span>
              <span class="room"><?= h(ROOMS[$h["room"]] ?? $h["room"]) ?></span>
            </div>
            <div class="dates">
              <strong><?= h(fmt_date($h["checkin"])) ?></strong> → <strong><?= h(fmt_date($h["checkout"])) ?></strong>
              · <?= (int)$h["nights"] ?> night<?= (int)$h["nights"] === 1 ? "" : "s" ?>
            </div>
            <?php if (!$isBlock && (!empty($guest["email"]) || !empty($guest["phone"]))): ?>
              <div class="contact">
                <?php if (!empty($guest["email"])): ?><a href="mailto:<?= h($guest["email"]) ?>"><?= h($guest["email"]) ?></a><?php endif; ?>
                <?php if (!empty($guest["phone"])): ?><?= !empty($guest["email"]) ? " · " : "" ?><a href="tel:<?= h($guest["phone"]) ?>"><?= h($guest["phone"]) ?></a><?php endif; ?>
                <?php if (!empty($guest["guests"])): ?> · <?= h($guest["guests"]) ?> guests<?php endif; ?>
              </div>
            <?php endif; ?>
            <div class="btn-row">
              <form method="post" style="margin:0;" onsubmit="return confirm('Remove this booking? Dates will re-open.');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= h($h["id"]) ?>">
                <button type="submit" class="rm">Remove / Unblock</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- ============================================================ -->
  <!-- CALENDAR -->
  <!-- ============================================================ -->
  <section class="panel">
    <h2>Room availability <span class="sub">next <?= CALENDAR_MONTHS ?> months</span></h2>

    <?php foreach (ROOMS as $roomId => $roomLabel):
      $bookings = $calendar[$roomId] ?? [];
    ?>
      <div class="room-block">
        <h3><?= h($roomLabel) ?></h3>
        <div class="months">
          <?php
          $y = (int)date("Y");
          $m = (int)date("n");
          for ($i = 0; $i < CALENDAR_MONTHS; $i++) {
              echo render_month_calendar($roomId, $y, $m, $bookings);
              $m++;
              if ($m > 12) { $m = 1; $y++; }
          }
          ?>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="m-legend">
      <span><span class="sw confirmed"></span>Confirmed</span>
      <span><span class="sw pending"></span>Pending hold</span>
      <span><span class="sw today"></span>Today</span>
    </div>
  </section>

  <!-- ============================================================ -->
  <!-- MANUAL BLOCK -->
  <!-- ============================================================ -->
  <section class="panel">
    <h2>Block dates manually <span class="sub">e.g. maintenance, private use</span></h2>

    <form method="post" class="block-form">
      <input type="hidden" name="action" value="block">
      <label>
        Room
        <select name="room" required>
          <?php foreach (ROOMS as $rid => $rlabel): ?>
            <option value="<?= h($rid) ?>"><?= h($rlabel) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        From
        <input type="date" name="checkin" required min="<?= h($today_ymd) ?>">
      </label>
      <label>
        To (exclusive)
        <input type="date" name="checkout" required min="<?= h($today_ymd) ?>">
      </label>
      <label>
        Reason
        <input type="text" name="reason" placeholder="Maintenance" maxlength="80">
      </label>
      <button type="submit" class="block-btn">Block</button>
    </form>
    <p class="footnote">"To" is the morning the room opens back up (checkout-style). Blocks show up straight on the public booking page.</p>
  </section>

  <!-- ============================================================ -->
  <!-- HISTORY -->
  <!-- ============================================================ -->
  <section class="panel">
    <h2>Recent history <span class="sub">last <?= count($past) ?> entries</span></h2>

    <?php if (empty($past)): ?>
      <div class="empty">Nothing in the history yet.</div>
    <?php else: ?>
      <div class="history-wrap">
      <table class="history">
        <thead>
          <tr>
            <th>Status</th>
            <th>Guest</th>
            <th>Room</th>
            <th>Dates</th>
            <th>Received</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($past as $h):
          $s = $h["status"] ?? "pending";
          $guest = $h["guest"] ?? [];
        ?>
          <tr>
            <td><span class="pill <?= h($s) ?>"><?= h($s) ?></span></td>
            <td class="guest">
              <?= h($guest["name"] ?? "(no name)") ?>
              <?php if (!empty($guest["email"])): ?><br><a href="mailto:<?= h($guest["email"]) ?>" style="font-size:0.78rem;"><?= h($guest["email"]) ?></a><?php endif; ?>
            </td>
            <td><?= h(ROOMS[$h["room"]] ?? $h["room"]) ?></td>
            <td><?= h(fmt_date($h["checkin"])) ?> → <?= h(fmt_date($h["checkout"])) ?></td>
            <td style="font-size:0.8rem;"><?= h(fmt_datetime((int)($h["created_at"] ?? 0))) ?></td>
            <td>
              <form method="post" style="margin:0;" onsubmit="return confirm('Delete this entry permanently?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= h($h["id"]) ?>">
                <button type="submit" class="rm" style="font-size:0.66rem;padding:0.35rem 0.7rem;">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </section>

  <?php
  $ics_key = "";
  $CFG_path = __DIR__ . "/config.php";
  if (file_exists($CFG_path)) {
      $_CFG = @include $CFG_path;
      if (is_array($_CFG) && !empty($_CFG["ics_key"])) $ics_key = $_CFG["ics_key"];
  }
  if ($ics_key):
    $icsUrl = "https://knkinn.com/bookings.ics.php?key=" . urlencode($ics_key);
  ?>
  <section class="panel">
    <h2>Google Calendar feed <span class="sub">subscribe to stay in sync</span></h2>
    <p style="color:var(--cream-dim);font-size:0.9rem;margin:0 0 0.7rem 0;">
      Copy this URL into Google Calendar → Other calendars → <em>From URL</em>. Events appear automatically as confirmed and pending bookings change.
    </p>
    <p style="word-break:break-all;"><code style="background:rgba(0,0,0,0.3);padding:4px 8px;border-radius:3px;font-size:0.82rem;color:var(--gold);"><?= h($icsUrl) ?></code></p>
  </section>
  <?php endif; ?>

</div>
</body>
</html>
<?php

/* ============================================================
   Helpers (rendered below to keep top tidy)
   ============================================================ */

function render_login(string $error = ""): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>KnK Inn — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Caveat:wght@700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/styles.css?v=12">
  <style>
    body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 2rem; }
    .lock-card {
      background: rgba(24,12,3,0.6); border: 1px solid rgba(201,170,113,0.22);
      padding: 2.4rem 2rem; border-radius: 6px; width: 100%; max-width: 380px;
      text-align: center; backdrop-filter: blur(8px);
    }
    .lock-card h1 { margin-bottom: 0.6rem; }
    .lock-card p { color: var(--cream-dim); font-size: 0.9rem; margin-bottom: 1.6rem; }
    .lock-card input[type=password] {
      width: 100%; padding: 0.85rem 1rem; margin-bottom: 1rem;
      background: rgba(255,255,255,0.04); border: 1px solid rgba(201,170,113,0.3);
      color: var(--cream); font-size: 1rem; font-family: inherit; border-radius: 4px;
    }
    .lock-card input[type=password]:focus { outline: none; border-color: var(--gold); }
    .lock-card button {
      width: 100%; padding: 0.85rem; background: var(--gold); color: var(--brown-deep);
      border: none; font-weight: 700; letter-spacing: 0.15em; text-transform: uppercase;
      font-size: 0.8rem; cursor: pointer; border-radius: 4px; font-family: inherit;
    }
    .lock-card button:hover { background: var(--gold-light, #d8c08b); }
    .err { color: #ff9a8a; font-size: 0.85rem; margin-bottom: 1rem; }
  </style>
</head>
<body>
  <form class="lock-card" method="post" autocomplete="off">
    <span class="eyebrow">Staff only</span>
    <h1 class="display-md">KnK <em>Admin</em></h1>
    <p>Enter password to manage bookings.</p>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <input type="hidden" name="action" value="login">
    <input type="password" name="password" placeholder="Password" autofocus required>
    <button type="submit">Unlock</button>
  </form>
</body>
</html>
<?php }

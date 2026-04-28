<?php
/* =========================================================
   KnK Inn — Bookings admin
   https://knkinn.com/bookings.php
   Permission-gated by "bookings" (see migration 015). See all
   bookings on one screen, confirm / decline pending holds, manually
   block dates (maintenance), browse recent history. The Guests tab
   is gated separately by "guests".
   ========================================================= */

require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/bookings_store.php";
require_once __DIR__ . "/includes/guests_store.php";

/* Bookings + Guests are now permission-gated rather than role-gated
 * (see migration 015 / knk_permissions()). Anyone with the "bookings"
 * permission can hit this page. The Guests tab is gated separately —
 * a user without "guests" gets bounced back to the Bookings tab. */
$me = knk_require_permission("bookings");
$can_see_guests = knk_user_can($me, "guests");

const ROOMS = [
    "standard-nowindow" => "Standard (no window)",
    "standard-balcony"  => "Standard with balcony",
    "vip"               => "VIP w/ tub",
];
const CALENDAR_MONTHS = 3;   // how many months forward to render on the calendar grid

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
    header("Location: bookings.php?msg=" . urlencode($flash));
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
    header("Location: bookings.php?msg=" . urlencode($flash));
    exit;
}

/* ---------- Action: external booking (mirror an OTA reservation) ----
 * Used when an Airbnb / Booking.com / Tripadvisor guest has booked
 * via that platform. We mirror it into our store as a confirmed
 * hold so the date is blocked everywhere (calendar, ICS feed,
 * availability export). The OTA's confirmation number goes in
 * external_ref so Simmo can cross-check.
 * ------------------------------------------------------------------ */
if (($_POST["action"] ?? "") === "external_booking") {
    $room     = $_POST["room"]     ?? "";
    $checkin  = $_POST["checkin"]  ?? "";
    $checkout = $_POST["checkout"] ?? "";
    $name     = trim((string)($_POST["name"]  ?? ""));
    $email    = trim((string)($_POST["email"] ?? ""));
    $phone    = trim((string)($_POST["phone"] ?? ""));
    $source   = trim((string)($_POST["source"] ?? ""));
    $extref   = trim((string)($_POST["external_ref"] ?? ""));
    $price    = (int)preg_replace('/[^0-9]/', '', (string)($_POST["price"] ?? "0"));
    $note     = trim((string)($_POST["note"] ?? ""));

    if (!isset(ROOMS[$room])) {
        $flash = "Pick a room.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkin) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkout)) {
        $flash = "Pick valid dates.";
    } elseif (strtotime($checkout) <= strtotime($checkin)) {
        $flash = "Check-out must be after check-in.";
    } elseif ($name === "") {
        $flash = "Type a guest name.";
    } else {
        try {
            $hold = bookings_create_hold([
                "room"                => $room,
                "checkin"             => $checkin,
                "checkout"            => $checkout,
                "price_vnd_per_night" => $price,
                "source"              => $source !== "" ? $source : "external",
                "external_ref"        => $extref,
                "guest"               => [
                    "name"    => $name,
                    "email"   => $email,
                    "phone"   => $phone,
                    "message" => $note,
                ],
            ]);
            /* Mirror straight to confirmed — we don't want to put
             * a 24-hour pending TTL on something that's already
             * been paid for on Airbnb. */
            $confirmed = bookings_set_status_by_id($hold["id"], "confirm");
            $flash = $confirmed
                ? "Recorded {$name} ({$source}) — {$checkin} → {$checkout}."
                : "Saved, but couldn't auto-confirm. Check the list below.";
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), "already held") !== false) {
                $flash = "Those dates are already booked. Check the calendar.";
            } else {
                $flash = "Could not save: " . $e->getMessage();
            }
        } catch (Throwable $e) {
            $flash = "Could not save: " . $e->getMessage();
        }
    }
    header("Location: bookings.php?msg=" . urlencode($flash));
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
    header("Location: bookings.php?msg=" . urlencode($flash));
    exit;
}

/* (Guest notes feature removed — Ben asked to drop the field.
 *  The DB column is left in place for now; nothing reads or
 *  writes it anymore.) */

$flash_msg = $_GET["msg"] ?? "";

/* ---------- Tab routing (V2 Phase 3 — Guests) ---------- */
$tab = $_GET["tab"] ?? "bookings";
if ($tab !== "bookings" && $tab !== "guests") $tab = "bookings";

// Reception can't see Guests — bounce back to Bookings.
if ($tab === "guests" && !$can_see_guests) {
    header("Location: bookings.php");
    exit;
}

// Guest-tab data (list OR single-profile depending on ?id).
$guests_rows      = [];
$guests_q         = "";
$guest_detail     = null;   // guest row from DB
$guest_bookings   = [];
$guest_orders     = [];
if ($tab === "guests") {
    $guest_id_arg = (int)($_GET["id"] ?? 0);
    if ($guest_id_arg > 0) {
        $guest_detail = knk_guest_get($guest_id_arg);
        if ($guest_detail) {
            // Refresh cached counters so the profile always reflects
            // the current JSON stores — cheap, and keeps the list in
            // sync with whatever the admin sees on the profile.
            knk_guest_refresh_stats($guest_id_arg);
            $guest_detail   = knk_guest_get($guest_id_arg);
            $guest_bookings = knk_guest_bookings_for_email((string)$guest_detail["email"]);
            $guest_orders   = knk_guest_orders_for_email((string)$guest_detail["email"]);
        }
    } else {
        $guests_q    = trim((string)($_GET["q"] ?? ""));
        $guests_rows = knk_guests_list($guests_q, 200);
    }
}

/* ---------- Load data for the dashboard ---------- */
$now = time();
$today_ymd = date("Y-m-d", $now);
$totalRooms = knk_total_rooms();   // 7 physical rooms

$pending   = [];
$upcoming  = [];
$past      = [];
$occupancy = []; // ymd => count
$dayGuests = []; // ymd => [ "Name (room-label)", ... ]  for tooltip

if ($tab === "bookings") {
    $all = bookings_list_all(true);  // auto-expires stale pending
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

    /* ---------- Today / tomorrow snapshot for the morning widget.
     * Only confirmed bookings count — pending hasn't been actioned
     * so it's not really "expected today". Manual blocks are
     * filtered out since they aren't real guests arriving.
     * ------------------------------------------------------------ */
    $tomorrow_ymd = date("Y-m-d", strtotime("+1 day", $now));
    $today_arrivals     = [];
    $today_departures   = [];
    $tomorrow_arrivals  = [];
    foreach ($all as $h) {
        if (($h["status"] ?? "") !== "confirmed") continue;
        $name = strtolower(trim((string)($h["guest"]["name"] ?? "")));
        if ($name === "blocked") continue;
        if (($h["checkin"]  ?? "") === $today_ymd)    $today_arrivals[]    = $h;
        if (($h["checkout"] ?? "") === $today_ymd)    $today_departures[]  = $h;
        if (($h["checkin"]  ?? "") === $tomorrow_ymd) $tomorrow_arrivals[] = $h;
    }
    /* In-house right now — checkin <= today < checkout. */
    $in_house = [];
    foreach ($all as $h) {
        if (($h["status"] ?? "") !== "confirmed") continue;
        $name = strtolower(trim((string)($h["guest"]["name"] ?? "")));
        if ($name === "blocked") continue;
        $hs = strtotime($h["checkin"]  ?? "");
        $he = strtotime($h["checkout"] ?? "");
        if (!$hs || !$he) continue;
        if ($hs <= $now && $now < $he) $in_house[] = $h;
    }

    /* ---------- Build unified occupancy map: [Y-m-d] = int count across all rooms ---------- */
    foreach ($all as $h) {
        $s = $h["status"] ?? "pending";
        if ($s === "declined" || $s === "expired") continue;
        if ($s === "pending" && ($now - ($h["created_at"] ?? 0)) > KNK_HOLD_TTL) continue;
        $start = strtotime($h["checkin"] ?? "");
        $end   = strtotime($h["checkout"] ?? "");
        if (!$start || !$end) continue;
        $label = ($h["guest"]["name"] ?? "?") . " (" . (ROOMS[$h["room"] ?? ""] ?? ($h["room"] ?? "")) . ")";
        for ($t = $start; $t < $end; $t += 86400) {
            $d = date("Y-m-d", $t);
            $occupancy[$d] = ($occupancy[$d] ?? 0) + 1;
            $dayGuests[$d][] = $label;
        }
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
/**
 * Unified calendar — one grid per month. Each day cell shows its fill ratio
 * (booked / total) as a bottom-up gold fill, with the count in the corner.
 */
function render_month_calendar(int $year, int $month, array $occupancy, array $dayGuests, int $totalRooms): string {
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
        $count = (int)($occupancy[$ymd] ?? 0);
        $pct   = $totalRooms > 0 ? min(100, (int)round($count * 100 / $totalRooms)) : 0;
        $cls   = "m-cell";
        $style = "";
        $title = "";
        if ($count > 0) {
            $cls  .= $count >= $totalRooms ? " full" : " part";
            $style = ' style="--fill:' . $pct . '%"';
            $guests = $dayGuests[$ymd] ?? [];
            $title  = "{$count} of {$totalRooms} booked";
            if ($guests) $title .= " — " . implode(", ", array_unique($guests));
        }
        if ($ymd === $today) $cls .= " today";
        if ($ymd < $today)   $cls .= " past";
        $badge = $count > 0 ? '<span class="c">' . $count . '</span>' : '';
        $html .= '<div class="' . $cls . '"' . $style . ' title="' . h($title) . '"><span class="d">' . $d . '</span>' . $badge . '</div>';
    }
    $html .= '</div></div>';
    return $html;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
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
    /* Today snapshot — morning glance widget at the top of the bookings tab. */
    .today-snap .snap-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 0.8rem;
    }
    .today-snap .snap-tile {
      background: rgba(245,233,209,0.04);
      border: 1px solid rgba(201,170,113,0.18);
      border-radius: 8px;
      padding: 0.9rem 1rem;
      min-height: 110px;
    }
    .today-snap .snap-l {
      font-size: 0.7rem; letter-spacing: 0.16em; text-transform: uppercase;
      color: rgba(245,233,209,0.55); font-weight: 600;
    }
    .today-snap .snap-v {
      font-family: "Archivo Black", sans-serif;
      font-size: 2.1rem; color: var(--gold, #c9aa71);
      line-height: 1.1; margin: 0.1rem 0 0.5rem;
    }
    .today-snap .snap-cap { font-size: 1rem; color: rgba(245,233,209,0.45); font-weight: 400; }
    .today-snap .snap-list {
      list-style: none; padding: 0; margin: 0;
      display: flex; flex-direction: column; gap: 0.25rem;
      font-size: 0.85rem;
    }
    .today-snap .snap-list li {
      display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: baseline;
    }
    .today-snap .snap-room {
      font-size: 0.72rem; color: rgba(245,233,209,0.55);
    }
    .today-snap .snap-src {
      display: inline-block; padding: 1px 6px; border-radius: 999px;
      font-size: 0.66rem; background: rgba(201,170,113,0.18);
      color: var(--gold, #c9aa71); font-weight: 600;
    }
    .today-snap .snap-empty {
      margin: 0; font-size: 0.82rem; color: rgba(245,233,209,0.4);
      font-style: italic;
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
    .card a.bill,
    .history a.bill {
      display: inline-block;
      padding: 0.55rem 1.1rem; border-radius: 3px;
      border: 1px solid rgba(201,170,113,0.45);
      font-size: 0.74rem; letter-spacing: 0.16em; text-transform: uppercase;
      font-family: inherit; font-weight: 700;
      text-decoration: none; color: var(--gold); background: transparent;
    }
    .card a.bill:hover,
    .history a.bill:hover { background: var(--gold); color: var(--brown-deep); }
    .history a.bill { font-size: 0.66rem; padding: 0.35rem 0.7rem; letter-spacing: 0.12em; }

    /* ---------- Status pill ---------- */
    .pill {
      display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 0.68rem;
      letter-spacing: 0.14em; text-transform: uppercase; font-weight: 700;
    }
    .pill.confirmed { background: rgba(127,208,138,0.15); color: #a8e0b1; border: 1px solid rgba(127,208,138,0.4); }
    .pill.pending   { background: rgba(201,170,113,0.15); color: var(--gold); border: 1px solid rgba(201,170,113,0.4); }
    .pill.declined  { background: rgba(255,154,138,0.12); color: #ff9a8a; border: 1px solid rgba(255,154,138,0.35); }
    .pill.expired   { background: rgba(255,255,255,0.05); color: var(--cream-faint); border: 1px solid rgba(255,255,255,0.15); }
    .pill.completed { background: rgba(122,165,106,0.10); color: #b6dba0; border: 1px solid rgba(122,165,106,0.3); }
    .pill.cancelled { background: rgba(255,154,138,0.08); color: #ffb59d; border: 1px solid rgba(255,154,138,0.2); }

    /* ---------- Unified occupancy calendar ---------- */
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
      overflow: hidden; --fill: 0%;
    }
    /* Gold fill rises from the bottom of the cell proportional to occupancy */
    .m-cell.part,
    .m-cell.full {
      background:
        linear-gradient(
          to top,
          var(--gold) 0%,
          var(--gold) var(--fill),
          rgba(255,255,255,0.02) var(--fill),
          rgba(255,255,255,0.02) 100%
        );
    }
    .m-cell.full { color: var(--brown-deep); font-weight: 700; }
    .m-cell.empty { background: transparent; }
    .m-cell .d { position: relative; z-index: 1; }
    .m-cell .c {
      position: absolute; top: 2px; right: 4px; z-index: 2;
      font-size: 0.56rem; font-weight: 700; letter-spacing: 0.04em;
      color: var(--brown-deep);
      background: rgba(255,255,255,0.6);
      padding: 0 4px; border-radius: 6px;
      line-height: 1.3;
    }
    .m-cell.today { border-color: var(--gold); color: var(--gold); font-weight: 700; }
    .m-cell.today.part,
    .m-cell.today.full { color: var(--cream); }
    .m-cell.past { opacity: 0.55; }

    .m-legend {
      display: flex; gap: 1.2rem; flex-wrap: wrap; margin-top: 0.9rem;
      font-size: 0.72rem; color: var(--cream-faint); align-items: center;
    }
    .m-legend .scale {
      display: inline-flex; align-items: center; gap: 0.4rem;
    }
    .m-legend .bar {
      display: inline-block; width: 110px; height: 10px; border-radius: 2px;
      background: linear-gradient(to right, rgba(255,255,255,0.06), var(--gold));
      border: 1px solid rgba(201,170,113,0.25);
    }
    .m-legend .sw { display: inline-block; width: 10px; height: 10px; border-radius: 2px; vertical-align: middle; margin-right: 4px; }
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

    /* ---------- Tab switcher (Bookings / Guests) ---------- */
    .tab-row {
      display: flex; gap: 0.4rem; border-bottom: 1px solid rgba(201,170,113,0.25);
      margin: 0 0 1.5rem 0;
    }
    .tab-row a {
      color: var(--cream-dim); text-decoration: none; padding: 0.55rem 1.1rem;
      border-radius: 6px 6px 0 0; font-weight: 600; font-size: 0.92rem;
      border: 1px solid transparent; border-bottom: none;
      background: rgba(255,255,255,0.02);
    }
    .tab-row a:hover { color: var(--cream); background: rgba(201,170,113,0.08); }
    .tab-row a.is-active {
      color: var(--brown-deep, #2a1a08); background: var(--gold, #c9aa71);
      border-color: var(--gold, #c9aa71);
    }

    /* ---------- Guests tab ---------- */
    .guests-search {
      display: flex; gap: 0.6rem; margin-bottom: 1.2rem; align-items: center;
    }
    .guests-search input[type=search] {
      flex: 1; padding: 0.55rem 0.8rem; font-size: 0.95rem;
      background: rgba(0,0,0,0.3); color: var(--cream); font-family: inherit;
      border: 1px solid rgba(201,170,113,0.28); border-radius: 4px;
    }
    .guests-search input[type=search]:focus {
      outline: none; border-color: var(--gold, #c9aa71);
    }
    .guests-search button {
      padding: 0.55rem 1rem; background: var(--gold, #c9aa71);
      color: var(--brown-deep, #2a1a08); border: none; border-radius: 4px;
      font-weight: 600; cursor: pointer;
    }
    .guests-search .clear-link {
      color: var(--cream-faint); text-decoration: none; font-size: 0.85rem;
    }
    .guests-search .clear-link:hover { color: var(--cream); }

    .guests-table {
      width: 100%; border-collapse: collapse; font-size: 0.9rem;
    }
    .guests-table th, .guests-table td {
      padding: 0.6rem 0.7rem; text-align: left;
      border-bottom: 1px solid rgba(201,170,113,0.1);
    }
    .guests-table th {
      font-size: 0.68rem; letter-spacing: 0.16em; text-transform: uppercase;
      color: var(--cream-faint); font-weight: 600;
    }
    .guests-table td { color: var(--cream-dim); }
    .guests-table tr:hover td { background: rgba(201,170,113,0.04); }
    .guests-table td.name { color: var(--cream); font-weight: 600; }
    .guests-table td.num  { text-align: right; font-variant-numeric: tabular-nums; }
    .guests-table a.row-link {
      color: var(--gold, #c9aa71); text-decoration: none; font-weight: 600;
    }
    .guests-table a.row-link:hover { text-decoration: underline; }
    .guests-table .no-rows {
      text-align: center; padding: 2rem; color: var(--cream-faint);
    }

    /* Guest profile */
    .g-profile { display: grid; gap: 1.5rem; grid-template-columns: 1fr; }
    @media (min-width: 920px) {
      .g-profile { grid-template-columns: 320px 1fr; }
    }
    .g-card {
      background: rgba(255,255,255,0.03); border: 1px solid rgba(201,170,113,0.18);
      border-radius: 6px; padding: 1.2rem;
    }
    .g-card h3 {
      margin: 0 0 0.8rem 0; font-size: 0.78rem;
      letter-spacing: 0.18em; text-transform: uppercase;
      color: var(--cream-faint); font-weight: 600;
    }
    .g-contact p { margin: 0.25rem 0; color: var(--cream-dim); font-size: 0.92rem; }
    .g-contact .big-name { color: var(--cream); font-size: 1.2rem; font-weight: 600; margin-bottom: 0.4rem; }
    .g-contact a { color: var(--gold, #c9aa71); text-decoration: none; }
    .g-contact a:hover { text-decoration: underline; }

    .g-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-top: 1rem; }
    .g-stat {
      background: rgba(0,0,0,0.25); padding: 0.7rem; border-radius: 4px;
    }
    .g-stat .n  { color: var(--gold, #c9aa71); font-size: 1.4rem; font-weight: 700; font-family: "Archivo Black", sans-serif; }
    .g-stat .l  { color: var(--cream-faint); font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; }

    .g-list h3 { margin-top: 0; }
    .g-list ul { list-style: none; padding: 0; margin: 0; }
    .g-list li {
      padding: 0.55rem 0; border-bottom: 1px solid rgba(201,170,113,0.08);
      font-size: 0.9rem; color: var(--cream-dim);
      display: flex; justify-content: space-between; gap: 0.8rem; flex-wrap: wrap;
    }
    .g-list li:last-child { border-bottom: none; }
    .g-list li .lhs { color: var(--cream); font-weight: 600; }
    .g-list li .rhs { color: var(--cream-faint); font-size: 0.82rem; white-space: nowrap; }
    .g-list .empty { color: var(--cream-faint); font-style: italic; padding: 0.6rem 0; }

    .back-link {
      color: var(--gold, #c9aa71); text-decoration: none;
      font-size: 0.88rem; display: inline-block; margin-bottom: 1rem;
    }
    .back-link:hover { text-decoration: underline; }
  </style>
</head>
<body>
<?php knk_render_admin_nav($me); ?>
<div class="wrap">

  <header class="bar">
    <div class="title">
      <span class="eyebrow">Staff only</span>
      <h1 class="display-md">
        <?= $tab === "guests" ? "Guests" : "Bookings" ?> <em>admin</em>
      </h1>
    </div>
    <div class="actions">
      <a class="btn-mini" href="index.php" target="_blank">View site</a>
    </div>
  </header>

  <?php if ($can_see_guests): ?>
    <div class="tab-row">
      <a href="bookings.php"            class="<?= $tab === "bookings" ? "is-active" : "" ?>">Bookings</a>
      <a href="bookings.php?tab=guests" class="<?= $tab === "guests"   ? "is-active" : "" ?>">Guests</a>
    </div>
  <?php endif; ?>

  <?php if ($flash_msg): ?>
    <div class="flash"><?= h($flash_msg) ?></div>
  <?php endif; ?>

  <?php if ($tab === "bookings"): ?>

  <!-- ============================================================ -->
  <!-- TODAY SNAPSHOT — morning glance widget -->
  <!-- ============================================================ -->
  <section class="panel today-snap">
    <h2>Today <span class="sub"><?= h(date("l, j M", $now)) ?></span></h2>

    <div class="snap-grid">
      <div class="snap-tile">
        <div class="snap-l">In-house</div>
        <div class="snap-v"><?= count($in_house) ?>
          <span class="snap-cap"> / <?= (int)$totalRooms ?></span></div>
        <?php if (!empty($in_house)): ?>
          <ul class="snap-list">
            <?php foreach ($in_house as $h):
              $g = $h["guest"] ?? [];
            ?>
              <li><?= h($g["name"] ?? "(no name)") ?>
                <span class="snap-room"><?= h(ROOMS[$h["room"]] ?? $h["room"]) ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="snap-empty">Empty house tonight.</p>
        <?php endif; ?>
      </div>

      <div class="snap-tile">
        <div class="snap-l">Arriving today</div>
        <div class="snap-v"><?= count($today_arrivals) ?></div>
        <?php if (!empty($today_arrivals)): ?>
          <ul class="snap-list">
            <?php foreach ($today_arrivals as $h):
              $g = $h["guest"] ?? [];
              $sr = (string)($h["source"] ?? "");
            ?>
              <li><?= h($g["name"] ?? "(no name)") ?>
                <span class="snap-room"><?= h(ROOMS[$h["room"]] ?? $h["room"]) ?></span>
                <?php if ($sr !== "" && $sr !== "website"): ?>
                  <span class="snap-src"><?= h(ucfirst(str_replace("_", " ", $sr))) ?></span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="snap-empty">No check-ins today.</p>
        <?php endif; ?>
      </div>

      <div class="snap-tile">
        <div class="snap-l">Checking out today</div>
        <div class="snap-v"><?= count($today_departures) ?></div>
        <?php if (!empty($today_departures)): ?>
          <ul class="snap-list">
            <?php foreach ($today_departures as $h):
              $g = $h["guest"] ?? [];
            ?>
              <li><?= h($g["name"] ?? "(no name)") ?>
                <span class="snap-room"><?= h(ROOMS[$h["room"]] ?? $h["room"]) ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="snap-empty">Nobody leaving.</p>
        <?php endif; ?>
      </div>

      <div class="snap-tile">
        <div class="snap-l">Tomorrow</div>
        <div class="snap-v"><?= count($tomorrow_arrivals) ?></div>
        <?php if (!empty($tomorrow_arrivals)): ?>
          <ul class="snap-list">
            <?php foreach ($tomorrow_arrivals as $h):
              $g = $h["guest"] ?? [];
            ?>
              <li><?= h($g["name"] ?? "(no name)") ?>
                <span class="snap-room"><?= h(ROOMS[$h["room"]] ?? $h["room"]) ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="snap-empty">Nothing booked yet.</p>
        <?php endif; ?>
      </div>
    </div>
  </section>

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
          <?php
            $up_src = (string)($h["source"] ?? "");
            $up_src_lbl = [
                "airbnb"      => "Airbnb",
                "booking_com" => "Booking.com",
                "tripadvisor" => "Tripadvisor",
                "walk_in"     => "Walk-in",
                "phone"       => "Phone",
                "external"    => "External",
                "other"       => "Other",
            ];
          ?>
          <div class="card">
            <div class="row1">
              <span class="name">
                <?= h($guest["name"] ?? "(no name)") ?>
                <?php if ($isBlock): ?> <span class="pill expired" style="margin-left:6px;">Manual block</span><?php endif; ?>
                <?php if (!$isBlock && $up_src !== "" && isset($up_src_lbl[$up_src])): ?>
                  <span style="display:inline-block; margin-left:6px; padding:2px 8px; border-radius:999px; font-size:0.7rem; background:rgba(201,170,113,0.18); color:var(--gold,#c9aa71); font-weight:600;"><?= h($up_src_lbl[$up_src]) ?></span>
                  <?php if (!empty($h["external_ref"])): ?>
                    <span style="display:inline-block; margin-left:4px; padding:2px 6px; border-radius:4px; font-size:0.66rem; background:rgba(245,233,209,0.06); font-family:monospace;"><?= h($h["external_ref"]) ?></span>
                  <?php endif; ?>
                <?php endif; ?>
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
              <?php if (!$isBlock): ?>
                <a class="bill" href="bill.php?slug=<?= h($h["id"]) ?>" target="_blank">View bill</a>
              <?php endif; ?>
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
  <!-- UNIFIED OCCUPANCY CALENDAR -->
  <!-- ============================================================ -->
  <section class="panel">
    <h2>Occupancy <span class="sub">next <?= CALENDAR_MONTHS ?> months · <?= (int)$totalRooms ?> rooms total</span></h2>

    <div class="months">
      <?php
      $y = (int)date("Y");
      $m = (int)date("n");
      for ($i = 0; $i < CALENDAR_MONTHS; $i++) {
          echo render_month_calendar($y, $m, $occupancy, $dayGuests, $totalRooms);
          $m++;
          if ($m > 12) { $m = 1; $y++; }
      }
      ?>
    </div>

    <div class="m-legend">
      <span class="scale">Empty <span class="bar"></span> Full (<?= (int)$totalRooms ?>/<?= (int)$totalRooms ?>)</span>
      <span><span class="sw today"></span>Today</span>
      <span style="color:var(--cream-faint);">Hover a day to see which rooms are booked.</span>
    </div>
  </section>

  <!-- ============================================================ -->
  <!-- EXTERNAL BOOKING (Airbnb / Booking.com / etc.) -->
  <!-- ============================================================ -->
  <section class="panel">
    <h2>Add an external booking <span class="sub">Airbnb, Booking.com, Tripadvisor, walk-in</span></h2>
    <p class="footnote" style="margin:0 0 0.7rem;">
      Mirror an OTA reservation into our system so the dates block out everywhere
      (calendar, ICS feed, availability export). Auto-confirms — no 24-hour pending hold.
    </p>

    <form method="post" class="block-form">
      <input type="hidden" name="action" value="external_booking">
      <label>
        Room
        <select name="room" required>
          <?php foreach (ROOMS as $rid => $rlabel): ?>
            <option value="<?= h($rid) ?>"><?= h($rlabel) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        Source
        <select name="source" required>
          <option value="airbnb">Airbnb</option>
          <option value="booking_com">Booking.com</option>
          <option value="tripadvisor">Tripadvisor</option>
          <option value="walk_in">Walk-in</option>
          <option value="phone">Phone / WhatsApp</option>
          <option value="other">Other</option>
        </select>
      </label>
      <label>
        Check-in
        <input type="date" name="checkin" required>
      </label>
      <label>
        Check-out
        <input type="date" name="checkout" required>
      </label>
      <label>
        Guest name
        <input type="text" name="name" required maxlength="120" placeholder="As shown on the OTA">
      </label>
      <label>
        Guest email <span style="opacity:0.6">(optional)</span>
        <input type="email" name="email" maxlength="190">
      </label>
      <label>
        Phone <span style="opacity:0.6">(optional)</span>
        <input type="tel" name="phone" maxlength="40">
      </label>
      <label>
        Confirmation # <span style="opacity:0.6">(optional)</span>
        <input type="text" name="external_ref" maxlength="120" placeholder="e.g. HMABC123">
      </label>
      <label>
        Rate / night (VND) <span style="opacity:0.6">(optional)</span>
        <input type="text" name="price" inputmode="numeric" placeholder="e.g. 850000">
      </label>
      <label class="full-width">
        Notes <span style="opacity:0.6">(optional)</span>
        <input type="text" name="note" maxlength="200" placeholder="Late check-in, special requests, etc.">
      </label>
      <button type="submit" class="block-btn">Save booking</button>
    </form>
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
            <td>
              <span class="pill <?= h($s) ?>"><?= h($s) ?></span>
              <?php
                /* Source pill — only render for OTAs / external (not
                 * for the default 'website' source, since the lack of
                 * a tag implicitly means "came in through the public
                 * booking form"). Helps Simmo spot which bookings he
                 * mirrored from elsewhere. */
                $src = (string)($h["source"] ?? "");
                $src_lbl = [
                    "airbnb"      => "Airbnb",
                    "booking_com" => "Booking.com",
                    "tripadvisor" => "Tripadvisor",
                    "walk_in"     => "Walk-in",
                    "phone"       => "Phone",
                    "external"    => "External",
                    "other"       => "Other",
                ];
                if ($src !== "" && isset($src_lbl[$src])):
              ?>
                <br><span style="display:inline-block; margin-top:3px; padding:2px 8px; border-radius:999px; font-size:0.66rem; background:rgba(201,170,113,0.18); color:var(--gold,#c9aa71); font-weight:600;"><?= h($src_lbl[$src]) ?></span>
                <?php if (!empty($h["external_ref"])): ?>
                  <span style="display:inline-block; margin-top:3px; padding:2px 6px; border-radius:4px; font-size:0.66rem; background:rgba(245,233,209,0.06); font-family:monospace;"><?= h($h["external_ref"]) ?></span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="guest">
              <?= h($guest["name"] ?? "(no name)") ?>
              <?php if (!empty($guest["email"])): ?><br><a href="mailto:<?= h($guest["email"]) ?>" style="font-size:0.78rem;"><?= h($guest["email"]) ?></a><?php endif; ?>
            </td>
            <td><?= h(ROOMS[$h["room"]] ?? $h["room"]) ?></td>
            <td><?= h(fmt_date($h["checkin"])) ?> → <?= h(fmt_date($h["checkout"])) ?></td>
            <td style="font-size:0.8rem;"><?= h(fmt_datetime((int)($h["created_at"] ?? 0))) ?></td>
            <td style="white-space:nowrap;">
              <?php if ($s === "confirmed"): ?>
                <a class="bill" href="bill.php?slug=<?= h($h["id"]) ?>" target="_blank">View bill</a>
              <?php endif; ?>
              <form method="post" style="margin:0;display:inline-block;" onsubmit="return confirm('Delete this entry permanently?');">
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

  <?php // --------------------- END bookings tab --------------------- ?>

  <?php elseif ($tab === "guests"): ?>

  <?php if ($guest_detail): /* ========= SINGLE GUEST PROFILE ========= */ ?>
    <a class="back-link" href="bookings.php?tab=guests">← Back to all guests</a>
    <div class="g-profile">
      <!-- Left column: contact + stats -->
      <div>
        <div class="g-card g-contact">
          <h3>Contact</h3>
          <div class="big-name">
            <?= h($guest_detail["name"] ?: "(no name yet)") ?>
          </div>
          <p><a href="mailto:<?= h($guest_detail["email"]) ?>"><?= h($guest_detail["email"]) ?></a></p>
          <?php if (!empty($guest_detail["phone"])): ?>
            <p><a href="tel:<?= h($guest_detail["phone"]) ?>"><?= h($guest_detail["phone"]) ?></a></p>
          <?php endif; ?>
          <p style="color:var(--cream-faint);font-size:0.8rem;margin-top:0.6rem;">
            First seen <?= h(fmt_date((string)($guest_detail["first_seen_at"] ?? ""))) ?>
            <?php if (!empty($guest_detail["last_seen_at"])): ?>
              · last seen <?= h(fmt_date((string)$guest_detail["last_seen_at"])) ?>
            <?php endif; ?>
          </p>

          <div class="g-stats">
            <div class="g-stat">
              <div class="n"><?= (int)$guest_detail["bookings_count"] ?></div>
              <div class="l">Bookings</div>
            </div>
            <div class="g-stat">
              <div class="n"><?= (int)$guest_detail["orders_count"] ?></div>
              <div class="l">Drink orders</div>
            </div>
            <div class="g-stat" style="grid-column: span 2;">
              <div class="n"><?= h(knk_fmt_vnd((int)$guest_detail["total_vnd"])) ?></div>
              <div class="l">Total spend</div>
            </div>
            <?php if (!empty($guest_detail["favourite_item"])): ?>
              <div class="g-stat" style="grid-column: span 2;">
                <div class="n" style="font-size:1rem;font-family:'Inter',sans-serif;font-weight:600;">
                  <?= h($guest_detail["favourite_item"]) ?>
                </div>
                <div class="l">Favourite order</div>
              </div>
            <?php endif; ?>
            <?php if (!empty($guest_detail["favourite_day"])): ?>
              <div class="g-stat" style="grid-column: span 2;">
                <div class="n" style="font-size:1rem;font-family:'Inter',sans-serif;font-weight:600;">
                  <?= h($guest_detail["favourite_day"]) ?>
                </div>
                <div class="l">Usual day</div>
              </div>
            <?php endif; ?>
          </div>
        </div>

      </div>

      <!-- Right column: bookings + orders lists -->
      <div>
        <div class="g-card g-list">
          <h3>Bookings (<?= count($guest_bookings) ?>)</h3>
          <?php if (empty($guest_bookings)): ?>
            <p class="empty">No bookings on record.</p>
          <?php else: ?>
            <ul>
              <?php foreach ($guest_bookings as $b):
                $room_label = ROOMS[$b["room"] ?? ""] ?? ($b["room"] ?? "");
                $nights = (int)($b["nights"] ?? 0);
                $ppn    = (int)($b["price_vnd_per_night"] ?? 0);
                $total  = $ppn * $nights;
                $status = (string)($b["status"] ?? "pending");
              ?>
                <li>
                  <span class="lhs">
                    <?= h(fmt_date((string)($b["checkin"] ?? ""))) ?> → <?= h(fmt_date((string)($b["checkout"] ?? ""))) ?>
                    <span style="color:var(--cream-dim);font-weight:400;"> · <?= h($room_label) ?></span>
                    <span class="pill <?= h($status) ?>" style="margin-left:6px;"><?= h($status) ?></span>
                  </span>
                  <span class="rhs">
                    <?= $nights ?> night<?= $nights === 1 ? "" : "s" ?>
                    <?php if ($total > 0): ?> · <?= h(knk_fmt_vnd($total)) ?><?php endif; ?>
                    <?php if ($status === "confirmed"): ?>
                      · <a href="bill.php?slug=<?= h($b["id"] ?? "") ?>" target="_blank" style="color:var(--gold);text-decoration:none;">View bill</a>
                    <?php endif; ?>
                  </span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div class="g-card g-list" style="margin-top:1rem;">
          <h3>Drink orders (<?= count($guest_orders) ?>)</h3>
          <?php if (empty($guest_orders)): ?>
            <p class="empty">No drink orders on record.</p>
          <?php else: ?>
            <ul>
              <?php foreach ($guest_orders as $o):
                $created = (int)($o["created_at"] ?? 0);
                $items   = $o["items"] ?? [];
                $summary = [];
                foreach ($items as $it) {
                    $summary[] = ($it["qty"] ?? 1) . "× " . ($it["name"] ?? "?");
                }
                $summary_str = implode(", ", $summary);
                $status = (string)($o["status"] ?? "pending");
              ?>
                <li>
                  <span class="lhs">
                    <?= h(fmt_datetime($created)) ?>
                    <span class="pill <?= h($status) ?>" style="margin-left:6px;"><?= h($status) ?></span>
                    <div style="color:var(--cream-dim);font-weight:400;font-size:0.82rem;margin-top:0.2rem;">
                      <?= h($summary_str) ?>
                      <?php if (!empty($o["location"])): ?>
                        · <?= h($o["location"]) ?>
                        <?php if (!empty($o["room_number"])): ?> #<?= h($o["room_number"]) ?><?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </span>
                  <span class="rhs"><?= h(knk_fmt_vnd((int)($o["total_vnd"] ?? 0))) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>

  <?php else: /* ========= GUEST LIST ========= */ ?>
    <section class="panel">
      <form method="get" class="guests-search">
        <input type="hidden" name="tab" value="guests">
        <input type="search" name="q" value="<?= h($guests_q) ?>" placeholder="Search by name, email, or phone…">
        <button type="submit">Search</button>
        <?php if ($guests_q !== ""): ?>
          <a class="clear-link" href="bookings.php?tab=guests">Clear</a>
        <?php endif; ?>
      </form>

      <?php if (empty($guests_rows)): ?>
        <div class="empty">
          <?= $guests_q !== "" ? "No guests match \"" . h($guests_q) . "\"." : "No guests yet — profiles build up as enquiries and orders come in." ?>
        </div>
      <?php else: ?>
        <div class="history-wrap">
          <table class="guests-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th class="num">Bookings</th>
                <th class="num">Drink orders</th>
                <th class="num">Spend</th>
                <th>Last seen</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($guests_rows as $g):
                $link = "bookings.php?tab=guests&id=" . (int)$g["id"];
              ?>
                <tr>
                  <td class="name">
                    <a class="row-link" href="<?= h($link) ?>">
                      <?= h($g["name"] ?: "(no name)") ?>
                    </a>
                  </td>
                  <td><?= h($g["email"]) ?></td>
                  <td><?= h($g["phone"] ?? "") ?></td>
                  <td class="num"><?= (int)$g["bookings_count"] ?></td>
                  <td class="num"><?= (int)$g["orders_count"] ?></td>
                  <td class="num"><?= h(knk_fmt_vnd((int)$g["total_vnd"])) ?></td>
                  <td style="font-size:0.82rem;">
                    <?php
                      $ls = $g["last_seen_at"] ?? $g["first_seen_at"] ?? "";
                      echo $ls ? h(fmt_date((string)$ls)) : "—";
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php endif; /* end tab branches */ ?>

</div>
</body>
</html>

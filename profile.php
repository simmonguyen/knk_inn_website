<?php
/*
 * KnK Inn — /profile.php
 *
 * Guest profile (Phase 1). Lives at:
 *   - /bar.php?tab=profile  → wrapped in the bar shell (top-right
 *                             header avatar opens this)
 *   - /profile.php          → standalone (works without the shell)
 *
 * What it shows:
 *   1) Header — display name (editable) + claim status
 *   2) "My drinks orders"   — list, newest first
 *   3) "My song requests"   — list, newest first
 *   4) "My darts games"     — list, newest first
 *   5) "Use my email"       — claim flow: email a magic link that
 *                             merges the anon profile into a real
 *                             email so history follows the guest
 *                             across devices.
 *
 * Identity: same as /order.php — relies on $_SESSION["order_email"].
 * In bar shell mode, ensures an anon identity exists so the page
 * renders on first visit without a login gate. Outside the bar shell
 * we still ask for an email up front (consistent with order.php).
 */

session_start();

require_once __DIR__ . "/includes/profile_store.php";
require_once __DIR__ . "/includes/orders_store.php";
require_once __DIR__ . "/includes/guests_store.php";
require_once __DIR__ . "/includes/follows_store.php";
require_once __DIR__ . "/includes/notifications_store.php";
require_once __DIR__ . "/includes/avatar_store.php";
require_once __DIR__ . "/includes/feed_store.php";

/* Mirror order.php's anon-cookie identity setup so /profile.php works
 * standalone or via /bar.php?tab=profile without divergence. */
define("KNK_GUEST_COOKIE",          "knk_guest_email");
define("KNK_GUEST_COOKIE_TTL",      90 * 24 * 60 * 60);
if (!defined("KNK_GUEST_ANON_COOKIE"))     define("KNK_GUEST_ANON_COOKIE",     "knk_guest_anon");
if (!defined("KNK_GUEST_ANON_COOKIE_TTL")) define("KNK_GUEST_ANON_COOKIE_TTL", 365 * 24 * 60 * 60);
if (!defined("KNK_GUEST_ANON_DOMAIN"))     define("KNK_GUEST_ANON_DOMAIN",     "anon.knkinn.com");

/* Anon-identity helpers — local copies that match /order.php so this
 * file stands alone without including order.php. If order.php has
 * already declared them in the same request (e.g. when bar.php loads
 * order.php first), function_exists() guards it. */
if (!function_exists("knk_anon_email_from_token")) {
    function knk_anon_email_from_token(string $token): string {
        $token = preg_replace('/[^a-z0-9]/', '', strtolower($token));
        return "anon-" . $token . "@" . KNK_GUEST_ANON_DOMAIN;
    }
}
if (!function_exists("knk_ensure_anon_identity")) {
    function knk_ensure_anon_identity(): void {
        $token = isset($_COOKIE[KNK_GUEST_ANON_COOKIE])
            ? preg_replace('/[^a-z0-9]/', '', strtolower((string)$_COOKIE[KNK_GUEST_ANON_COOKIE]))
            : "";
        if (strlen($token) < 16) {
            try {
                $token = bin2hex(random_bytes(8));
            } catch (\Throwable $e) {
                $token = substr(hash("sha256", uniqid("", true) . microtime(true)), 0, 16);
            }
        }
        $_COOKIE[KNK_GUEST_ANON_COOKIE] = $token;
        $secure = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";
        setcookie(KNK_GUEST_ANON_COOKIE, $token, [
            "expires"  => time() + KNK_GUEST_ANON_COOKIE_TTL,
            "path"     => "/",
            "secure"   => $secure,
            "httponly" => true,
            "samesite" => "Lax",
        ]);
        $_SESSION["order_email"] = knk_anon_email_from_token($token);
    }
}

/* Re-seed the session from the long-lived email cookie if there's
 * one — same trick /order.php uses. */
if (empty($_SESSION["order_email"]) && !empty($_COOKIE[KNK_GUEST_COOKIE])) {
    $_remembered = strtolower(trim((string)$_COOKIE[KNK_GUEST_COOKIE]));
    if (filter_var($_remembered, FILTER_VALIDATE_EMAIL)) {
        $_SESSION["order_email"] = $_remembered;
    }
}

/* In bar shell mode: if we still don't have an email, mint an anon one
 * so the profile page renders straight away (matches order.php). */
if (defined('KNK_BAR_FRAME') && empty($_SESSION["order_email"])) {
    knk_ensure_anon_identity();
}

/* Build SITE_URL so the magic-link email points at the right host. */
$_scheme  = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
$_host    = $_SERVER["HTTP_HOST"] ?? "knkinn.com";
$SITE_URL = $_scheme . "://" . $_host;

/* Self URL — when included via /bar.php, links back to /bar.php?tab=profile. */
$KNK_SELF_URL = defined('KNK_BAR_FRAME') ? '/bar.php?tab=profile' : 'profile.php';

/* ------------ small helpers ------------ */
function ph($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function predirect($to) { header("Location: $to"); exit; }

$flash       = "";
$flash_kind  = "";   // "ok" or "err" — used for styling
$claim_sent  = false;

/* ------------ POST: email login (standalone only) ------------
 * In the bar shell we never show this form (the anon identity has
 * already been minted). On standalone /profile.php, we still let
 * a guest type their email so the page is useful at /profile.php
 * directly (e.g., when they're back home and want to see history). */
if (!defined('KNK_BAR_FRAME') && ($_POST["action"] ?? "") === "login") {
    $email = strtolower(trim($_POST["email"] ?? ""));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $flash = "That email doesn't look right. Try again.";
        $flash_kind = "err";
    } else {
        $_SESSION["order_email"] = $email;
        $secure = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";
        setcookie(KNK_GUEST_COOKIE, $email, [
            "expires"  => time() + KNK_GUEST_COOKIE_TTL,
            "path"     => "/",
            "secure"   => $secure,
            "httponly" => true,
            "samesite" => "Lax",
        ]);
        predirect($KNK_SELF_URL);
    }
}

/* ------------ POST: save display name ------------ */
if (($_POST["action"] ?? "") === "save_name") {
    $email = strtolower(trim((string)($_SESSION["order_email"] ?? "")));
    $name  = trim((string)($_POST["display_name"] ?? ""));
    if ($email === "") {
        $flash = "We need your email first.";
        $flash_kind = "err";
    } elseif ($name === "") {
        $flash = "Pick a name (or just your first name).";
        $flash_kind = "err";
    } elseif (mb_strlen($name) > 60) {
        $flash = "That name's too long — keep it under 60 characters.";
        $flash_kind = "err";
    } else {
        $ok = knk_profile_set_display_name($email, $name);
        if ($ok) {
            $flash = "Name saved.";
            $flash_kind = "ok";
        } else {
            $flash = "Couldn't save the name — try again.";
            $flash_kind = "err";
        }
    }
}

/* ------------ POST: request claim (magic link) ------------ */
if (($_POST["action"] ?? "") === "request_claim") {
    $anon_email = strtolower(trim((string)($_SESSION["order_email"] ?? "")));
    $real_email = strtolower(trim((string)($_POST["claim_email"] ?? "")));
    if (!knk_profile_is_anon_email($anon_email)) {
        $flash = "Your profile is already linked to a real email.";
        $flash_kind = "err";
    } elseif (!filter_var($real_email, FILTER_VALIDATE_EMAIL)) {
        $flash = "That email doesn't look right.";
        $flash_kind = "err";
    } elseif (knk_profile_is_anon_email($real_email)) {
        $flash = "Use your real email, not the anonymous one.";
        $flash_kind = "err";
    } else {
        $token = knk_profile_create_claim_token($anon_email, $real_email);
        if (!$token) {
            $flash = "Couldn't start the link-up. Try again in a sec.";
            $flash_kind = "err";
        } else {
            // Pull SMTP creds from config.php — the same config every
            // other email-sending page uses.
            $cfg = [];
            $configPath = __DIR__ . "/config.php";
            if (file_exists($configPath)) {
                $cfg = (array)require $configPath;
            }
            $sent = knk_profile_send_claim_email($cfg, $real_email, $token, $SITE_URL);
            if ($sent) {
                $claim_sent = true;
                $flash = "Check your inbox at " . $real_email . " — we just sent you a link. Click it within 30 minutes.";
                $flash_kind = "ok";
            } else {
                $flash = "Email couldn't send. Try again, or ask a staffer for a hand.";
                $flash_kind = "err";
            }
        }
    }
}

/* ------------ POST: avatar upload (multipart) ------------ */
if (($_POST["action"] ?? "") === "save_avatar") {
    $email = strtolower(trim((string)($_SESSION["order_email"] ?? "")));
    if ($email === "") {
        $flash = "We need your email first.";
        $flash_kind = "err";
    } elseif (empty($_FILES["avatar"])) {
        $flash = "Pick a photo first.";
        $flash_kind = "err";
    } else {
        $err = knk_avatar_save_upload($email, $_FILES["avatar"]);
        if ($err === null) {
            $flash = "Photo updated.";
            $flash_kind = "ok";
        } else {
            $flash = $err;
            $flash_kind = "err";
        }
    }
}

/* ------------ POST: clear avatar ------------ */
if (($_POST["action"] ?? "") === "clear_avatar") {
    $email = strtolower(trim((string)($_SESSION["order_email"] ?? "")));
    if ($email !== "" && knk_avatar_clear($email)) {
        $flash = "Photo removed.";
        $flash_kind = "ok";
    }
}

/* ------------ POST: mark notifications read ------------ */
if (($_POST["action"] ?? "") === "mark_notifications_read") {
    $email = strtolower(trim((string)($_SESSION["order_email"] ?? "")));
    if ($email !== "") {
        knk_notifications_mark_all_read($email);
    }
}

/* ------------ POST: follow / unfollow (inline form) ------------ */
if (($_POST["action"] ?? "") === "do_follow" || ($_POST["action"] ?? "") === "do_unfollow") {
    $email  = strtolower(trim((string)($_SESSION["order_email"] ?? "")));
    $target = strtolower(trim((string)($_POST["target_email"] ?? "")));
    if ($email !== "" && filter_var($target, FILTER_VALIDATE_EMAIL) && $email !== $target) {
        if ($_POST["action"] === "do_follow") {
            knk_follow($email, $target);
        } else {
            knk_unfollow($email, $target);
        }
    }
    /* Redirect back to clear the POST so a refresh doesn't re-submit. */
    predirect($KNK_SELF_URL);
}

/* ------------ Read profile state ------------ */
$email      = strtolower(trim((string)($_SESSION["order_email"] ?? "")));
$is_anon    = $email !== "" && knk_profile_is_anon_email($email);
$guest_row  = $email !== "" ? knk_guest_find_by_email($email) : null;
$disp_name  = $email !== "" ? knk_profile_display_name_for($email, $guest_row) : "";
$avatar_url = $email !== "" ? knk_avatar_url_for($email, $guest_row) : "";

/* Phase 2 — friends, feed, notifications. Only run if we have an email. */
$follow_counts  = ["followers" => 0, "following" => 0];
$followers_list = [];
$followees_list = [];
$at_bar         = [];
$suggestions    = [];
$friend_feed    = [];
$notifications  = [];
$unread_count   = 0;
if ($email !== "") {
    $follow_counts  = knk_follow_counts($email);
    $followers_list = knk_followers_for($email, 12);
    $followees_list = knk_followees_for($email, 12);
    $at_bar         = knk_at_bar_recent($email, 4, 15);
    $suggestions    = knk_friend_suggestions($email, 8);
    $friend_feed    = knk_feed_for($email, 25);
    $notifications  = knk_notifications_for($email, 20);
    $unread_count   = knk_notifications_unread_count($email);
}

/* The set of emails I currently follow — used to flip the button
 * on rows in "at the bar" / "suggestions" between Follow / Following. */
$following_set = [];
foreach ($followees_list as $f) $following_set[strtolower((string)$f["email"])] = true;
foreach (knk_followee_emails($email) as $e_x) $following_set[strtolower($e_x)] = true;

/* Activity — only run if we have an email. */
$orders = [];
$songs  = [];
$darts  = [];
if ($email !== "") {
    $orders = knk_profile_orders($email);
    $songs  = knk_profile_songs($email, 50);
    $darts  = knk_profile_darts($email, 50);
}

/* Friendly time format: "Today 14:32" / "Tue 22:10" / "Apr 18 14:32".
 * Accepts either a unix timestamp (int) or a "Y-m-d H:i:s" string.
 * Renders in the server's local time — Matbao runs Asia/Ho_Chi_Minh. */
function pfmt_when($v): string {
    if ($v === null || $v === "" || $v === 0) return "";
    $ts = is_numeric($v) ? (int)$v : strtotime((string)$v);
    if (!$ts) return "";
    $today = strtotime(date("Y-m-d"));
    if ($ts >= $today) return "Today " . date("H:i", $ts);
    if ($ts >= $today - 6 * 86400) return date("D H:i", $ts);
    return date("M j H:i", $ts);
}

/* Pretty game-type label for darts rows. */
function pfmt_darts_game(string $type): string {
    $type = strtolower($type);
    $map = [
        "501"        => "501",
        "301"        => "301",
        "cricket"    => "Cricket",
        "aroundclock"=> "Around the Clock",
        "killer"     => "Killer",
        "halveit"    => "Halve It",
    ];
    return $map[$type] ?? ucfirst($type);
}

/* Pretty result label for a finished darts game.
 *   - status='finished' + finishing_position=1     → "Won"
 *   - status='finished' + finishing_position>1     → "Finished #2" etc
 *   - format='doubles' + winner_team_no=my_team_no → "Won (team)"
 *   - status='abandoned'                           → "Abandoned"
 *   - status='playing'                             → "In progress"
 *   - else                                         → "Played"
 */
function pfmt_darts_result(array $g): string {
    $status = (string)($g["status"] ?? "");
    if ($status === "abandoned") return "Abandoned";
    if ($status === "playing")   return "In progress";
    if ($status !== "finished")  return "Played";
    $myslot   = (int)($g["slot_no"] ?? 0);
    $myteam   = (int)($g["my_team_no"] ?? 0);
    $winSlot  = isset($g["winner_slot_no"]) && $g["winner_slot_no"] !== null ? (int)$g["winner_slot_no"] : null;
    $winTeam  = isset($g["winner_team_no"]) && $g["winner_team_no"] !== null ? (int)$g["winner_team_no"] : null;
    $finPos   = isset($g["finishing_position"]) && $g["finishing_position"] !== null ? (int)$g["finishing_position"] : null;
    if ($myteam > 0 && $winTeam !== null) {
        return $winTeam === $myteam ? "Won (team)" : "Lost (team)";
    }
    if ($winSlot !== null) {
        if ($winSlot === $myslot) return "Won";
        return $finPos !== null ? ("Finished #" . $finPos) : "Lost";
    }
    return "Played";
}

?>
<?php if (!defined('KNK_BAR_FRAME')): ?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>My profile — KnK Inn</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/styles.css?v=12">
<?php endif; ?>
<style>
  /* Profile page styling — uses the same token palette as order.php
   * so visiting both inside the bar shell feels coherent. Scoped under
   * .pf- so it never collides with order/jukebox/darts CSS when the
   * shell stacks more than one tab in a single response. */
  :root {
    --brown-deep:#180c03; --brown-mid:#3d1f0d; --gold:#c9aa71; --gold-dark:#9c7f4a;
    --cream:#f4ede0; --cream-card:#fdf8ef; --border:#e7dcc2; --muted:#6e5d40;
  }
  body { background: var(--cream); color: var(--brown-deep); font-family: Inter, system-ui, sans-serif; }
  .pf-wrap { max-width: 720px; margin: 0 auto; padding: 22px 16px 40px; }

  .pf-hero { display: flex; align-items: center; gap: 14px; padding: 8px 4px 18px; }
  .pf-avatar { width: 56px; height: 56px; border-radius: 50%; background: var(--brown-deep); color: var(--gold);
               display: flex; align-items: center; justify-content: center;
               font-family: 'Archivo Black', sans-serif; font-size: 22px; line-height: 1;
               overflow: hidden; flex-shrink: 0; }
  .pf-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .pf-hero h1 { margin: 0; font-family: 'Archivo Black', sans-serif; font-size: 26px; line-height: 1.1; color: var(--brown-deep); }
  .pf-hero .sub { margin: 4px 0 0; color: var(--muted); font-size: 13px; }

  .pf-card { background: var(--cream-card); border: 1px solid var(--border); border-radius: 10px;
             padding: 16px 18px; margin: 12px 0; box-shadow: 0 1px 0 rgba(24,12,3,0.03); }
  .pf-card h2 { margin: 0 0 10px; font-size: 16px; letter-spacing: 0.04em; text-transform: uppercase; color: var(--brown-mid); font-family: 'Archivo Black', sans-serif; }
  .pf-card .muted { color: var(--muted); font-size: 13px; }
  .pf-card .empty { padding: 6px 0; color: var(--muted); font-size: 14px; }

  .pf-flash { padding: 10px 14px; border-radius: 6px; margin: 10px 0; font-size: 14px; }
  .pf-flash.ok  { background: #cfe8cf; color: #1f5a1f; border: 1px solid #a7cca7; }
  .pf-flash.err { background: #f7d8d8; color: #6c1a1a; border: 1px solid #e0a6a6; }

  /* ------- Display name editor ------- */
  .pf-name-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .pf-name-form input[type=text] { flex: 1; min-width: 180px; padding: 10px 12px;
                                   border: 1px solid var(--border); border-radius: 6px; font-size: 14px; }
  .pf-btn { display: inline-block; background: var(--brown-deep); color: var(--gold);
            border: none; padding: 10px 16px; border-radius: 6px; font-weight: 700; font-size: 14px;
            cursor: pointer; text-decoration: none; letter-spacing: 0.04em; }
  .pf-btn:hover { background: var(--brown-mid); }
  .pf-btn.ghost { background: transparent; color: var(--brown-mid); border: 1px solid var(--border); }
  .pf-btn.block { display: block; width: 100%; text-align: center; padding: 12px; font-size: 15px; }

  /* ------- Activity rows ------- */
  .pf-row { display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: center;
            padding: 8px 0; border-bottom: 1px dashed var(--border); font-size: 14px; }
  .pf-row:last-child { border-bottom: none; }
  .pf-row .pf-when { color: var(--muted); font-size: 12px; min-width: 70px; }
  .pf-row .pf-main { color: var(--brown-deep); }
  .pf-row .pf-main .ttl { font-weight: 600; }
  .pf-row .pf-main .ch  { color: var(--muted); font-size: 12px; display: block; }
  .pf-row .pf-aux { color: var(--muted); font-size: 12px; min-width: 80px; text-align: right; }

  .pf-status { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px;
               letter-spacing: 0.06em; text-transform: uppercase; font-weight: 700; }
  .pf-status.queued   { background: #f5e5c5; color: #6c511a; }
  .pf-status.playing  { background: #cfe8cf; color: #1f5a1f; }
  .pf-status.played   { background: #d7d7d7; color: #3a3a3a; }
  .pf-status.skipped  { background: #e9c6c6; color: #6c1a1a; }
  .pf-status.rejected { background: #e9c6c6; color: #6c1a1a; }
  .pf-status.pending  { background: #f5e5c5; color: #6c511a; }

  .pf-status.pending-o   { background: #f5e5c5; color: #6c511a; }
  .pf-status.received-o  { background: #cfe8cf; color: #1f5a1f; }
  .pf-status.paid-o      { background: #d7d7d7; color: #3a3a3a; }
  .pf-status.cancelled-o { background: #e9c6c6; color: #6c1a1a; }

  /* ------- Claim email box ------- */
  .pf-claim { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .pf-claim input[type=email] { flex: 1; min-width: 200px; padding: 10px 12px;
                                border: 1px solid var(--border); border-radius: 6px; font-size: 14px; }

  .pf-claim-done { background: #cfe8cf; color: #1f5a1f; padding: 10px 14px; border-radius: 6px;
                   font-size: 14px; border: 1px solid #a7cca7; }

  .pf-claimed { font-size: 14px; color: var(--muted); }
  .pf-claimed b { color: var(--brown-deep); }

  /* ============== Phase 2 — social ============== */

  /* Notifications card — only rendered when there's something to show. */
  .pf-notif-card { background: #fff7e3; border: 1px solid #e0c98a; }
  .pf-notif-card h2 { color: #6c511a; }
  .pf-notif-list { list-style: none; padding: 0; margin: 0; }
  .pf-notif-row { padding: 8px 0; border-bottom: 1px dashed var(--border); font-size: 14px;
                  display: flex; align-items: center; gap: 10px; }
  .pf-notif-row:last-child { border-bottom: none; }
  .pf-notif-row.is-unread { font-weight: 600; }
  .pf-notif-row .pf-when { color: var(--muted); font-size: 12px; min-width: 70px; }
  .pf-notif-row .pf-msg { flex: 1; color: var(--brown-deep); }
  .pf-notif-row .pf-msg b { color: var(--brown-deep); }
  .pf-notif-actions { display: flex; gap: 8px; margin-top: 10px; }

  /* Avatar editor — sits inside its own card. */
  .pf-avatar-edit { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
  .pf-avatar-edit .preview { width: 72px; height: 72px; border-radius: 50%;
                             overflow: hidden; flex-shrink: 0;
                             background: var(--brown-deep); color: var(--gold);
                             display: flex; align-items: center; justify-content: center;
                             font-family: 'Archivo Black', sans-serif; font-size: 28px; }
  .pf-avatar-edit .preview img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .pf-avatar-edit .controls { flex: 1; min-width: 180px; display: flex; flex-direction: column; gap: 6px; }
  .pf-avatar-edit input[type=file] { font-size: 14px; }
  .pf-avatar-edit .row-buttons { display: flex; gap: 8px; flex-wrap: wrap; }

  /* Friend / follower list rows — used by at-bar, suggestions, lists. */
  .pf-people-row { display: flex; align-items: center; gap: 10px;
                   padding: 8px 0; border-bottom: 1px dashed var(--border); }
  .pf-people-row:last-child { border-bottom: none; }
  .pf-people-av { width: 36px; height: 36px; border-radius: 50%;
                  background: var(--brown-deep); color: var(--gold);
                  display: flex; align-items: center; justify-content: center;
                  font-family: 'Archivo Black', sans-serif; font-size: 14px;
                  overflow: hidden; flex-shrink: 0; }
  .pf-people-av img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .pf-people-info { flex: 1; min-width: 0; }
  .pf-people-info .nm { font-weight: 600; color: var(--brown-deep);
                        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .pf-people-info .sub { font-size: 12px; color: var(--muted); }
  .pf-follow-btn { padding: 6px 14px; border-radius: 16px; border: 1px solid var(--brown-deep);
                   background: var(--brown-deep); color: var(--gold);
                   font-size: 12px; font-weight: 700; letter-spacing: 0.04em;
                   text-transform: uppercase; cursor: pointer; }
  .pf-follow-btn:hover { background: var(--brown-mid); }
  .pf-follow-btn.is-following { background: transparent; color: var(--brown-mid); border-color: var(--border); }

  /* Counts row — Followers / Following side-by-side. */
  .pf-counts { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 10px; }
  .pf-count { flex: 1; min-width: 110px; background: var(--cream); border: 1px solid var(--border);
              border-radius: 8px; padding: 10px 12px; }
  .pf-count .n { font-family: 'Archivo Black', sans-serif; font-size: 22px; color: var(--brown-deep); line-height: 1; }
  .pf-count .l { font-size: 12px; color: var(--muted); letter-spacing: 0.05em; text-transform: uppercase; }

  /* Feed rows — friend activity items, identical layout to .pf-row but with avatar slot. */
  .pf-feed-row { display: grid; grid-template-columns: 36px 1fr auto; gap: 10px; align-items: center;
                 padding: 8px 0; border-bottom: 1px dashed var(--border); font-size: 14px; }
  .pf-feed-row:last-child { border-bottom: none; }
  .pf-feed-row .pf-people-av { width: 36px; height: 36px; }
  .pf-feed-row .pf-msg { color: var(--brown-deep); min-width: 0; }
  .pf-feed-row .pf-msg .who { font-weight: 600; color: var(--brown-deep); }
  .pf-feed-row .pf-msg .what { color: var(--muted); }
  .pf-feed-row .pf-when { color: var(--muted); font-size: 12px; min-width: 60px; text-align: right; }

  .pf-kind { display: inline-block; padding: 1px 7px; border-radius: 9px; font-size: 10px;
             letter-spacing: 0.06em; text-transform: uppercase; font-weight: 700;
             margin-right: 6px; vertical-align: middle; }
  .pf-kind.order { background: #f5e5c5; color: #6c511a; }
  .pf-kind.song  { background: #d6e5d3; color: #1f5a1f; }
  .pf-kind.darts { background: #e9d6c6; color: #6c3a1a; }
</style>
<?php if (!defined('KNK_BAR_FRAME')): ?>
</head>
<body>
<?php endif; ?>

<div class="pf-wrap">

  <?php /* Hero — avatar + name + claim status */ ?>
  <?php if ($email !== ""): ?>
    <div class="pf-hero">
      <div class="pf-avatar">
        <?php if ($avatar_url !== ""): ?>
          <img src="<?= ph($avatar_url) ?>" alt="<?= ph($disp_name) ?>">
        <?php else: ?>
          <?= ph(mb_strtoupper(mb_substr($disp_name, 0, 1, "UTF-8"), "UTF-8")) ?>
        <?php endif; ?>
      </div>
      <div>
        <h1><?= ph($disp_name) ?></h1>
        <p class="sub">
          <?php if ($is_anon): ?>
            Linked to this device only. Use your email below to follow the same history across devices.
          <?php else: ?>
            Linked to <b><?= ph($email) ?></b>.
          <?php endif; ?>
        </p>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($flash !== ""): ?>
    <div class="pf-flash <?= ph($flash_kind) ?>"><?= ph($flash) ?></div>
  <?php endif; ?>

  <?php /* Standalone: ask for an email to land on a profile if there's none yet. */ ?>
  <?php if ($email === "" && !defined('KNK_BAR_FRAME')): ?>
    <div class="pf-card">
      <h2>Find my profile</h2>
      <p class="muted" style="margin: 0 0 10px;">Enter the email you used at the bar to see your orders, songs and darts.</p>
      <form method="post" autocomplete="on">
        <input type="hidden" name="action" value="login">
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <input type="email" name="email" required placeholder="you@example.com"
                 style="flex:1; min-width:200px; padding:10px 12px; border:1px solid var(--border); border-radius:6px; font-size:14px;">
          <button class="pf-btn" type="submit">Look up</button>
        </div>
      </form>
    </div>
  <?php else: ?>

    <?php /* ---------- Notifications (only if anything to show) ---------- */ ?>
    <?php if (!empty($notifications)): ?>
      <div class="pf-card pf-notif-card">
        <h2>Notifications<?= $unread_count > 0 ? " (" . (int)$unread_count . " new)" : "" ?></h2>
        <ul class="pf-notif-list">
          <?php foreach ($notifications as $n):
            $kind = (string)($n["kind"] ?? "");
            $payload = is_array($n["payload"] ?? null) ? $n["payload"] : [];
            $is_unread = empty($n["read_at"]);
            $actor = (string)($n["actor_email"] ?? "");
            $actor_name = (string)($payload["display_name"] ?? "");
            if ($actor_name === "" && $actor !== "") {
                $actor_name = knk_profile_display_name_for($actor);
            }
            $msg = "";
            if ($kind === "new_follower") {
                $msg = '<b>' . ph($actor_name !== "" ? $actor_name : "Someone") . '</b> is now following you.';
            } else {
                /* Open vocabulary — render kind verbatim if we don't recognise it. */
                $msg = ph(ucfirst(str_replace("_", " ", $kind)));
            }
          ?>
            <li class="pf-notif-row<?= $is_unread ? " is-unread" : "" ?>">
              <span class="pf-when"><?= ph(pfmt_when($n["created_at"] ?? null)) ?></span>
              <span class="pf-msg"><?= $msg ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($unread_count > 0): ?>
          <form method="post" class="pf-notif-actions">
            <input type="hidden" name="action" value="mark_notifications_read">
            <button class="pf-btn ghost" type="submit">Mark all as read</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php /* ---------- Display name ---------- */ ?>
    <div class="pf-card">
      <h2>Display name</h2>
      <form class="pf-name-form" method="post">
        <input type="hidden" name="action" value="save_name">
        <input type="text" name="display_name" maxlength="60"
               value="<?= ph($guest_row["display_name"] ?? "") ?>"
               placeholder="What should we call you?">
        <button class="pf-btn" type="submit">Save</button>
      </form>
      <p class="muted" style="margin: 8px 0 0;">Shown next to your songs in the queue and on darts scoreboards.</p>
    </div>

    <?php /* ---------- Avatar editor ---------- */ ?>
    <div class="pf-card">
      <h2>Profile photo</h2>
      <form class="pf-avatar-edit" method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_avatar">
        <div class="preview">
          <?php if ($avatar_url !== ""): ?>
            <img src="<?= ph($avatar_url) ?>" alt="">
          <?php else: ?>
            <?= ph(mb_strtoupper(mb_substr($disp_name, 0, 1, "UTF-8"), "UTF-8")) ?>
          <?php endif; ?>
        </div>
        <div class="controls">
          <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required>
          <div class="row-buttons">
            <button class="pf-btn" type="submit">Upload photo</button>
            <?php if ($avatar_url !== ""): ?>
              <button class="pf-btn ghost" type="submit" name="action" value="clear_avatar"
                      formnovalidate
                      onclick="return confirm('Remove your profile photo?');">Remove</button>
            <?php endif; ?>
          </div>
          <p class="muted" style="margin: 0;">JPEG, PNG, or WebP up to 6MB. We'll square-crop and resize for you.</p>
        </div>
      </form>
    </div>

    <?php /* ---------- Friends — counts + lists ---------- */ ?>
    <div class="pf-card">
      <h2>Friends</h2>
      <div class="pf-counts">
        <div class="pf-count">
          <div class="n"><?= (int)$follow_counts["following"] ?></div>
          <div class="l">Following</div>
        </div>
        <div class="pf-count">
          <div class="n"><?= (int)$follow_counts["followers"] ?></div>
          <div class="l">Followers</div>
        </div>
      </div>
      <?php if (empty($followees_list) && empty($followers_list)): ?>
        <div class="empty">No friends yet — follow someone from "At the bar tonight" or "Suggested" below to get started.</div>
      <?php endif; ?>

      <?php if (!empty($followees_list)): ?>
        <p class="muted" style="margin: 12px 0 4px; font-weight: 700;">Following</p>
        <?php foreach ($followees_list as $u):
          $u_email   = (string)$u["email"];
          $u_name    = knk_profile_display_name_for($u_email, $u);
          $u_av      = (string)($u["avatar_path"] ?? "");
        ?>
          <div class="pf-people-row">
            <div class="pf-people-av">
              <?php if ($u_av !== ""): ?>
                <img src="<?= ph($u_av) ?>" alt="">
              <?php else: ?>
                <?= ph(mb_strtoupper(mb_substr($u_name, 0, 1, "UTF-8"), "UTF-8")) ?>
              <?php endif; ?>
            </div>
            <div class="pf-people-info">
              <div class="nm"><?= ph($u_name) ?></div>
            </div>
            <form method="post" style="margin: 0;">
              <input type="hidden" name="action" value="do_unfollow">
              <input type="hidden" name="target_email" value="<?= ph($u_email) ?>">
              <button class="pf-follow-btn is-following" type="submit">Following</button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (!empty($followers_list)): ?>
        <p class="muted" style="margin: 14px 0 4px; font-weight: 700;">Followers</p>
        <?php foreach ($followers_list as $u):
          $u_email   = (string)$u["email"];
          $u_name    = knk_profile_display_name_for($u_email, $u);
          $u_av      = (string)($u["avatar_path"] ?? "");
          $is_following_back = isset($following_set[$u_email]);
        ?>
          <div class="pf-people-row">
            <div class="pf-people-av">
              <?php if ($u_av !== ""): ?>
                <img src="<?= ph($u_av) ?>" alt="">
              <?php else: ?>
                <?= ph(mb_strtoupper(mb_substr($u_name, 0, 1, "UTF-8"), "UTF-8")) ?>
              <?php endif; ?>
            </div>
            <div class="pf-people-info">
              <div class="nm"><?= ph($u_name) ?></div>
            </div>
            <?php if (!$is_following_back && $u_email !== $email): ?>
              <form method="post" style="margin: 0;">
                <input type="hidden" name="action" value="do_follow">
                <input type="hidden" name="target_email" value="<?= ph($u_email) ?>">
                <button class="pf-follow-btn" type="submit">Follow back</button>
              </form>
            <?php else: ?>
              <span class="pf-follow-btn is-following">Following</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php /* ---------- At the bar tonight ---------- */ ?>
    <?php if (!empty($at_bar)): ?>
      <div class="pf-card">
        <h2>At the bar tonight</h2>
        <p class="muted" style="margin: 0 0 8px;">Other guests who've been active in the last few hours.</p>
        <?php foreach ($at_bar as $u):
          $u_email = (string)$u["email"];
          $u_name  = (string)$u["display_name"];
          $u_av    = (string)$u["avatar_path"];
          $u_kind  = (string)$u["activity_kind"];
          $is_following = isset($following_set[$u_email]);
        ?>
          <div class="pf-people-row">
            <div class="pf-people-av">
              <?php if ($u_av !== ""): ?>
                <img src="<?= ph($u_av) ?>" alt="">
              <?php else: ?>
                <?= ph(mb_strtoupper(mb_substr($u_name, 0, 1, "UTF-8"), "UTF-8")) ?>
              <?php endif; ?>
            </div>
            <div class="pf-people-info">
              <div class="nm"><?= ph($u_name) ?></div>
              <div class="sub">
                <?php
                  $verb_map = ["order" => "ordered drinks", "song" => "queued a song", "darts" => "played darts"];
                  echo ph(($verb_map[$u_kind] ?? "was here") . " · " . pfmt_when($u["last_activity_at"]));
                ?>
              </div>
            </div>
            <?php if ($is_following): ?>
              <form method="post" style="margin: 0;">
                <input type="hidden" name="action" value="do_unfollow">
                <input type="hidden" name="target_email" value="<?= ph($u_email) ?>">
                <button class="pf-follow-btn is-following" type="submit">Following</button>
              </form>
            <?php else: ?>
              <form method="post" style="margin: 0;">
                <input type="hidden" name="action" value="do_follow">
                <input type="hidden" name="target_email" value="<?= ph($u_email) ?>">
                <button class="pf-follow-btn" type="submit">Follow</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php /* ---------- Suggested for you ---------- */ ?>
    <?php if (!empty($suggestions)): ?>
      <div class="pf-card">
        <h2>Suggested for you</h2>
        <p class="muted" style="margin: 0 0 8px;">People you've crossed paths with at the bar.</p>
        <?php foreach ($suggestions as $u):
          $u_email = (string)$u["email"];
          $u_name  = (string)$u["display_name"];
          $u_av    = (string)$u["avatar_path"];
          $reason  = (string)$u["reason"];
          $reason_text = $reason === "darts"
              ? "Played darts together"
              : "Queued songs around the same time";
        ?>
          <div class="pf-people-row">
            <div class="pf-people-av">
              <?php if ($u_av !== ""): ?>
                <img src="<?= ph($u_av) ?>" alt="">
              <?php else: ?>
                <?= ph(mb_strtoupper(mb_substr($u_name, 0, 1, "UTF-8"), "UTF-8")) ?>
              <?php endif; ?>
            </div>
            <div class="pf-people-info">
              <div class="nm"><?= ph($u_name) ?></div>
              <div class="sub"><?= ph($reason_text) ?></div>
            </div>
            <form method="post" style="margin: 0;">
              <input type="hidden" name="action" value="do_follow">
              <input type="hidden" name="target_email" value="<?= ph($u_email) ?>">
              <button class="pf-follow-btn" type="submit">Follow</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php /* ---------- Friend feed ---------- */ ?>
    <?php if (!empty($friend_feed)): ?>
      <div class="pf-card">
        <h2>What your friends are up to</h2>
        <?php foreach ($friend_feed as $f):
          $f_email = (string)$f["email"];
          $f_name  = (string)$f["display_name"];
          $f_av    = (string)$f["avatar_path"];
          $f_kind  = (string)$f["kind"];
          $f_text  = (string)$f["summary"];
          $f_ts    = (int)$f["ts"];
        ?>
          <div class="pf-feed-row">
            <div class="pf-people-av">
              <?php if ($f_av !== ""): ?>
                <img src="<?= ph($f_av) ?>" alt="">
              <?php else: ?>
                <?= ph(mb_strtoupper(mb_substr($f_name, 0, 1, "UTF-8"), "UTF-8")) ?>
              <?php endif; ?>
            </div>
            <div class="pf-msg">
              <span class="pf-kind <?= ph($f_kind) ?>"><?= ph($f_kind) ?></span>
              <span class="who"><?= ph($f_name) ?></span>
              <span class="what"> <?= ph($f_text) ?></span>
            </div>
            <div class="pf-when"><?= ph(pfmt_when($f_ts)) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php /* ---------- Drinks orders ---------- */ ?>
    <div class="pf-card">
      <h2>My drinks orders</h2>
      <?php if (empty($orders)): ?>
        <div class="empty">No drinks orders yet.</div>
      <?php else: ?>
        <?php foreach ($orders as $o):
          $items = "";
          $first = true;
          foreach (($o["items"] ?? []) as $it) {
            if (!$first) $items .= ", ";
            $items .= ((int)($it["qty"] ?? 1)) . "× " . ($it["name"] ?? "");
            $first = false;
          }
          $st = (string)($o["status"] ?? "pending");
        ?>
          <div class="pf-row">
            <div class="pf-when"><?= ph(pfmt_when($o["created_at"] ?? null)) ?></div>
            <div class="pf-main">
              <span class="ttl"><?= ph(knk_fmt_vnd((int)($o["total_vnd"] ?? 0))) ?></span>
              <span class="ch"><?= ph($items ?: "—") ?></span>
            </div>
            <div class="pf-aux"><span class="pf-status <?= ph($st) ?>-o"><?= ph($st) ?></span></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php /* ---------- Songs ---------- */ ?>
    <div class="pf-card">
      <h2>My song requests</h2>
      <?php if (empty($songs)): ?>
        <div class="empty">No song requests yet. Drop one from the Music tab.</div>
      <?php else: ?>
        <?php foreach ($songs as $s):
          $st = (string)($s["status"] ?? "queued");
          $title = (string)($s["youtube_title"] ?? "");
          $chan  = (string)($s["youtube_channel"] ?? "");
        ?>
          <div class="pf-row">
            <div class="pf-when"><?= ph(pfmt_when($s["submitted_at"] ?? null)) ?></div>
            <div class="pf-main">
              <span class="ttl"><?= ph($title !== "" ? $title : "(untitled)") ?></span>
              <?php if ($chan !== ""): ?><span class="ch"><?= ph($chan) ?></span><?php endif; ?>
            </div>
            <div class="pf-aux"><span class="pf-status <?= ph($st) ?>"><?= ph($st) ?></span></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php /* ---------- Darts ---------- */ ?>
    <div class="pf-card">
      <h2>My darts games</h2>
      <?php if (empty($darts)): ?>
        <div class="empty">No darts games yet. Hit the Darts tab and start one.</div>
      <?php else: ?>
        <?php foreach ($darts as $g):
          $when_v = $g["finished_at"] ?? ($g["started_at"] ?? null);
        ?>
          <div class="pf-row">
            <div class="pf-when"><?= ph(pfmt_when($when_v)) ?></div>
            <div class="pf-main">
              <span class="ttl"><?= ph(pfmt_darts_game((string)($g["game_type"] ?? ""))) ?></span>
              <span class="ch">Board <?= ph((string)($g["board_id"] ?? "?")) ?> · <?= ph(ucfirst((string)($g["format"] ?? "singles"))) ?></span>
            </div>
            <div class="pf-aux"><?= ph(pfmt_darts_result($g)) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php /* ---------- Claim with email ---------- */ ?>
    <?php if ($is_anon): ?>
      <div class="pf-card">
        <h2>Use my email</h2>
        <?php if ($claim_sent): ?>
          <div class="pf-claim-done">
            We just emailed a confirmation link. Tap it within 30 minutes and your history follows you across devices.
          </div>
        <?php else: ?>
          <p class="muted" style="margin: 0 0 10px;">Want this same history when you visit on another phone or laptop? Drop your email below — we'll send you a tap-once link.</p>
          <form class="pf-claim" method="post">
            <input type="hidden" name="action" value="request_claim">
            <input type="email" name="claim_email" required placeholder="you@example.com">
            <button class="pf-btn" type="submit">Email me a link</button>
          </form>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="pf-card">
        <h2>Account</h2>
        <p class="pf-claimed">This profile is linked to <b><?= ph($email) ?></b>. Sign out to switch accounts.</p>
        <p style="margin: 10px 0 0;">
          <a class="pf-btn ghost" href="<?= ph($KNK_SELF_URL) ?>?logout=1">Sign out</a>
        </p>
      </div>
    <?php endif; ?>

  <?php endif; ?>

</div>

<?php
/* Logout — clear session + cookies, redirect.
 * Done at bottom of file so the form action works regardless of frame. */
if (($_GET["logout"] ?? "") !== "") {
    unset($_SESSION["order_email"]);
    if (isset($_COOKIE[KNK_GUEST_COOKIE])) {
        $secure = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";
        setcookie(KNK_GUEST_COOKIE, "", [
            "expires"  => time() - 3600,
            "path"     => "/",
            "secure"   => $secure,
            "httponly" => true,
            "samesite" => "Lax",
        ]);
        unset($_COOKIE[KNK_GUEST_COOKIE]);
    }
    /* Note: we deliberately leave KNK_GUEST_ANON_COOKIE in place so a
     * sign-out drops the guest back to their anon identity, not a
     * brand-new one. They keep their device-local history. */
    predirect($KNK_SELF_URL);
}
?>

<?php if (!defined('KNK_BAR_FRAME')): ?>
</body>
</html>
<?php endif; ?>

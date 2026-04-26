<?php
/*
 * KnK Inn — /bar.php  (unified guest experience — Phase 1: shell only)
 *
 * Wraps the three guest-facing self-service pages into one mobile-app-style
 * experience with a bottom tab nav:
 *
 *   /bar.php?tab=drinks  →  order.php  (Drinks & orders)
 *   /bar.php?tab=music   →  jukebox.php (Jukebox requests)
 *   /bar.php?tab=darts   →  darts.php   (Darts scoreboard)
 *
 * The standalone pages still work on their own — QR codes that point at
 * /darts.php?board=2, /jukebox.php, /order.php etc. continue to land on
 * the bare page, which is what we want for table-side entry.
 *
 * How the framing works:
 *   - bar.php defines KNK_BAR_FRAME = 1 BEFORE including the inner page.
 *   - Each inner page checks `defined('KNK_BAR_FRAME')` and, when true,
 *     skips its own <!DOCTYPE>/<html>/<head>/<body> wrappers and the
 *     marketing-site top nav. The page-specific <style> block is hoisted
 *     so it still renders inside <body>. Self-referential links and
 *     redirect targets get remapped from "/order.php" → "/bar.php?tab=drinks"
 *     (etc.) via a $KNK_SELF_URL variable defined in each page.
 *
 * Phase 2 (later): a shared identity strip at the top — "I'm at <location>,
 * my name is <name>" — that auto-fills each tab's identity fields. Not in
 * this commit; the inner pages still each ask for their own identity.
 */

declare(strict_types=1);

session_start();

/* Tell the included page it's running inside the bar shell. */
define('KNK_BAR_FRAME', 1);

require_once __DIR__ . '/includes/profile_store.php';
require_once __DIR__ . '/includes/guests_store.php';

/* ---- Anon-cookie identity bootstrap (mirror of /order.php) ----
 *
 * We mint or re-use a long-lived anon cookie at the shell level so
 * the top-right avatar can show *something* on every tab — including
 * tabs (music, darts) whose inner pages don't currently set up the
 * anon identity themselves. Constants + helpers are guarded with
 * function_exists/defined so they cleanly co-exist with order.php
 * and profile.php (whichever ends up included by ob_start below).
 */
if (!defined("KNK_GUEST_COOKIE"))           define("KNK_GUEST_COOKIE",          "knk_guest_email");
if (!defined("KNK_GUEST_COOKIE_TTL"))       define("KNK_GUEST_COOKIE_TTL",      90 * 24 * 60 * 60);
if (!defined("KNK_GUEST_ANON_COOKIE"))      define("KNK_GUEST_ANON_COOKIE",     "knk_guest_anon");
if (!defined("KNK_GUEST_ANON_COOKIE_TTL"))  define("KNK_GUEST_ANON_COOKIE_TTL", 365 * 24 * 60 * 60);
if (!defined("KNK_GUEST_ANON_DOMAIN"))      define("KNK_GUEST_ANON_DOMAIN",     "anon.knkinn.com");

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

/* Re-seed the session from the long-lived "stay logged in" cookie. */
if (empty($_SESSION["order_email"]) && !empty($_COOKIE[KNK_GUEST_COOKIE])) {
    $_remembered = strtolower(trim((string)$_COOKIE[KNK_GUEST_COOKIE]));
    if (filter_var($_remembered, FILTER_VALIDATE_EMAIL)) {
        $_SESSION["order_email"] = $_remembered;
    }
}
/* Still nothing? Mint an anon. */
if (empty($_SESSION["order_email"])) {
    knk_ensure_anon_identity();
}

/* Resolve which tab to show.
 *
 * No ?tab= (or an unknown value) → "home" launcher screen with three
 * big buttons. The guest decides what they want to do FIRST, then the
 * tab opens. Bottom nav still works on the launcher — it's just that
 * none of the three tabs are highlighted as active.
 *
 * 'profile' is a valid tab but it isn't part of the bottom nav — it's
 * reached via the avatar in the top-right of the shell header. */
$BAR_TAB = (string)($_GET['tab'] ?? '');
$BAR_VALID_TABS = ['drinks', 'music', 'darts', 'profile'];
if (!in_array($BAR_TAB, $BAR_VALID_TABS, true)) {
    $BAR_TAB = 'home';
}

$BAR_TAB_PAGE = [
    'drinks'  => __DIR__ . '/order.php',
    'music'   => __DIR__ . '/jukebox.php',
    'darts'   => __DIR__ . '/darts.php',
    'profile' => __DIR__ . '/profile.php',
];

$BAR_TAB_LABEL = [
    'home'    => 'Welcome',
    'drinks'  => 'Drinks &amp; orders',
    'music'   => 'Jukebox',
    'darts'   => 'Darts',
    'profile' => 'My account',
];

/*
 * Buffer the inner page's output so we can wrap it in our shell.
 *
 * Important: inner pages may call header() + exit (e.g. POST → redirect
 * after submit). header() still works at this point because no body
 * output has been flushed yet — only buffered. exit() will skip the rest
 * of bar.php, which is the correct behaviour for a redirect.
 *
 * The "home" launcher has no inner page — it's a built-in screen below.
 */
$BAR_INNER = '';
if ($BAR_TAB !== 'home') {
    ob_start();
    include $BAR_TAB_PAGE[$BAR_TAB];
    $BAR_INNER = ob_get_clean();
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="robots" content="noindex,nofollow">
  <title>KnK Inn — Bar</title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    /*
     * Bar shell — kept under a `.bar-shell-*` namespace so it never fights
     * the inner page's own theme tokens. Each inner page sets `body`
     * background/colour for its tab content; the sticky header and bottom
     * tabnav both have their own opaque background so they stay coherent
     * regardless of the tab's body colour.
     */
    html, body { margin: 0; padding: 0; }
    body {
      font-family: "Inter", system-ui, sans-serif;
      min-height: 100vh;
      background: #1b0f04;
      color: #f5e9d1;
    }
    .bar-shell {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    .bar-shell-header {
      position: sticky; top: 0; z-index: 50;
      background: #1b0f04;
      color: #f5e9d1;
      border-bottom: 1px solid rgba(201,170,113,0.25);
      padding: 0.65rem 1rem calc(0.65rem + env(safe-area-inset-top, 0px));
      display: flex; align-items: center; justify-content: space-between;
      font-family: "Inter", system-ui, sans-serif;
    }
    .bar-shell-brand {
      font-family: "Archivo Black", sans-serif;
      letter-spacing: 0.04em;
      font-size: 1rem;
      color: #f5e9d1;
      text-decoration: none;
    }
    .bar-shell-brand em { color: #c9aa71; font-style: normal; }
    .bar-shell-tagline {
      font-size: 0.7rem; letter-spacing: 0.16em; text-transform: uppercase;
      color: #c9aa71;
      font-weight: 700;
    }
    /* Top-right avatar — links to /bar.php?tab=profile.
       Shows the first letter of the guest's display name on a circle of
       gold over the deep brown header. Active state (when ON the profile
       tab) gets a solid gold fill so it's visually obvious you're there. */
    .bar-shell-avatar {
      display: inline-flex; align-items: center; justify-content: center;
      width: 34px; height: 34px;
      border-radius: 50%;
      background: rgba(201,170,113,0.12);
      color: #c9aa71;
      border: 1px solid rgba(201,170,113,0.55);
      font-family: "Archivo Black", sans-serif;
      font-size: 0.95rem; line-height: 1;
      letter-spacing: 0;
      text-decoration: none;
      transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
      -webkit-tap-highlight-color: transparent;
    }
    .bar-shell-avatar:hover {
      background: rgba(201,170,113,0.25);
      color: #f5e9d1;
    }
    .bar-shell-avatar.is-active {
      background: #c9aa71;
      color: #1b0f04;
      border-color: #c9aa71;
    }
    .bar-shell-header-right {
      display: flex; align-items: center; gap: 0.65rem;
    }
    .bar-shell-main {
      flex: 1;
      /* Reserve room for the fixed bottom tabnav so inner content
         never sits under it. 72px = nav height + padding. */
      padding-bottom: calc(82px + env(safe-area-inset-bottom, 0px));
    }
    .bar-shell-tabnav {
      position: fixed; left: 0; right: 0; bottom: 0; z-index: 60;
      background: #1b0f04;
      border-top: 1px solid rgba(201,170,113,0.25);
      padding: 0.45rem 0 calc(0.45rem + env(safe-area-inset-bottom, 0px));
      display: grid; grid-template-columns: 1fr 1fr 1fr;
      font-family: "Inter", system-ui, sans-serif;
    }
    .bar-shell-tab {
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      gap: 0.18rem; padding: 0.4rem 0.2rem;
      color: #8a7858;
      text-decoration: none;
      font-size: 0.72rem; letter-spacing: 0.08em; text-transform: uppercase; font-weight: 700;
      transition: color 0.15s ease;
    }
    .bar-shell-tab:hover { color: #f5e9d1; }
    .bar-shell-tab.is-active { color: #c9aa71; }
    .bar-shell-tab .icon {
      font-size: 1.45rem; line-height: 1;
      filter: grayscale(0.15);
    }
    .bar-shell-tab.is-active .icon { filter: none; }

    /*
     * Home launcher — shown when /bar.php is hit with no ?tab= param.
     * Three big buttons, full-width, generous tap targets. Kept deliberately
     * plain: just an icon, the action name, and nothing else. Simmo's guests
     * don't need subtitles to know what "Order Drinks" means.
     */
    .bar-home {
      padding: 1.5rem 1.25rem 1rem;
      display: flex; flex-direction: column;
      gap: 0.9rem;
    }
    .bar-home-greeting {
      font-family: "Archivo Black", sans-serif;
      font-size: 1.6rem; line-height: 1.15;
      letter-spacing: 0.01em;
      color: #f5e9d1;
      margin: 0.5rem 0 0.25rem;
    }
    .bar-home-greeting em { color: #c9aa71; font-style: normal; }
    .bar-home-sub {
      font-size: 0.95rem;
      color: rgba(245,233,209,0.7);
      margin: 0 0 1rem;
    }
    .bar-home-btn {
      display: flex; align-items: center;
      gap: 1rem;
      padding: 1.15rem 1.25rem;
      background: rgba(201,170,113,0.08);
      border: 1px solid rgba(201,170,113,0.35);
      border-radius: 14px;
      color: #f5e9d1;
      text-decoration: none;
      font-family: "Inter", system-ui, sans-serif;
      font-weight: 700;
      font-size: 1.15rem;
      letter-spacing: 0.02em;
      transition: background 0.15s ease, border-color 0.15s ease, transform 0.05s ease;
      -webkit-tap-highlight-color: transparent;
    }
    .bar-home-btn:hover { background: rgba(201,170,113,0.16); border-color: rgba(201,170,113,0.6); }
    .bar-home-btn:active { transform: scale(0.99); }
    .bar-home-btn .icon {
      font-size: 2rem; line-height: 1;
      width: 2.4rem; text-align: center;
    }
    .bar-home-btn .label { flex: 1; }
    .bar-home-btn .chev {
      color: #c9aa71; font-size: 1.4rem; line-height: 1;
      opacity: 0.7;
    }
  </style>
</head>
<body>
<?php
    /* Compute the avatar's letter from the guest's display name.
     * After ob_get_clean() above, $_SESSION may have shifted (e.g. the
     * inner page handled a login POST), so we read it here just before
     * rendering the header. */
    $bar_email = (string)($_SESSION["order_email"] ?? "");
    $bar_guest_row = null;
    if ($bar_email !== "") {
        try {
            $bar_guest_row = knk_guest_find_by_email($bar_email);
        } catch (Throwable $e) {
            $bar_guest_row = null;
        }
    }
    $bar_display = $bar_email !== ""
        ? knk_profile_display_name_for($bar_email, $bar_guest_row)
        : "Guest";
    /* Use mb_substr + mb_strtoupper so a Vietnamese display name renders
     * its first character correctly. Fallback to "?" if somehow empty. */
    $bar_initial = $bar_display !== ""
        ? mb_strtoupper(mb_substr($bar_display, 0, 1, "UTF-8"), "UTF-8")
        : "?";
?>
  <div class="bar-shell">
    <header class="bar-shell-header">
      <a href="/" class="bar-shell-brand">KnK <em>Inn</em></a>
      <div class="bar-shell-header-right">
        <span class="bar-shell-tagline"><?= $BAR_TAB_LABEL[$BAR_TAB] ?></span>
        <a class="bar-shell-avatar<?= $BAR_TAB === 'profile' ? ' is-active' : '' ?>"
           href="/bar.php?tab=profile"
           aria-label="My account: <?= htmlspecialchars($bar_display, ENT_QUOTES, "UTF-8") ?>"
           title="My account"><?= htmlspecialchars($bar_initial, ENT_QUOTES, "UTF-8") ?></a>
      </div>
    </header>

    <main class="bar-shell-main">
      <?php if ($BAR_TAB === 'home'): ?>
        <div class="bar-home">
          <h1 class="bar-home-greeting">Welcome to <em>KnK Inn</em></h1>
          <p class="bar-home-sub">What would you like to do?</p>

          <a class="bar-home-btn" href="/bar.php?tab=darts">
            <span class="icon">🎯</span>
            <span class="label">Throw Darts</span>
            <span class="chev">›</span>
          </a>
          <a class="bar-home-btn" href="/bar.php?tab=music">
            <span class="icon">🎵</span>
            <span class="label">Music Request</span>
            <span class="chev">›</span>
          </a>
          <a class="bar-home-btn" href="/bar.php?tab=drinks">
            <span class="icon">🍸</span>
            <span class="label">Order Drinks</span>
            <span class="chev">›</span>
          </a>
        </div>
      <?php else: ?>
        <?= $BAR_INNER ?>
      <?php endif; ?>
    </main>

    <nav class="bar-shell-tabnav" aria-label="Bar sections">
      <a class="bar-shell-tab<?= $BAR_TAB === 'darts' ? ' is-active' : '' ?>" href="/bar.php?tab=darts">
        <span class="icon">🎯</span><span>Darts</span>
      </a>
      <a class="bar-shell-tab<?= $BAR_TAB === 'music' ? ' is-active' : '' ?>" href="/bar.php?tab=music">
        <span class="icon">🎵</span><span>Music</span>
      </a>
      <a class="bar-shell-tab<?= $BAR_TAB === 'drinks' ? ' is-active' : '' ?>" href="/bar.php?tab=drinks">
        <span class="icon">🍸</span><span>Drinks</span>
      </a>
    </nav>
  </div>
</body>
</html>

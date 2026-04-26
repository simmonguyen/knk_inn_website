<?php
/*
 * KnK Inn — /share.php
 *
 * "Crash the Market for Cheap Drinks" — the share-rally landing
 * page guests reach by scanning the alternating QR on the TV.
 *
 * Three platform buttons (Facebook / Google Maps / TripAdvisor),
 * each with a tier-N reward. Tapping any button:
 *   1. POSTs to /api/social_tap.php (records, fires the crash)
 *   2. Opens the platform's share/review URL in a new tab
 *   3. Updates the on-screen tier counter + shows a toast
 *
 * The page is honour-system: we trust the tap rather than try to
 * verify the post / review. A 24h cooldown per (guest, platform)
 * is the abuse guard.
 *
 * Identity: anon-cookie bootstrap on first visit, same shape as
 * /order.php / /bar.php. So a brand-new guest scanning the TV's
 * QR code gets a session immediately, no sign-in needed.
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . "/includes/social_share_store.php";
require_once __DIR__ . "/includes/hours.php";
require_once __DIR__ . "/includes/profile_store.php";
require_once __DIR__ . "/includes/guests_store.php";
require_once __DIR__ . "/includes/avatar_store.php";

/* ---- Anon-cookie identity bootstrap (mirror of /order.php / /bar.php) ---- */
if (!defined("KNK_GUEST_COOKIE"))           define("KNK_GUEST_COOKIE",          "knk_guest_email");
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
            try { $token = bin2hex(random_bytes(8)); }
            catch (\Throwable $e) {
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

/* Re-seed from the long-lived "stay logged in" cookie if present. */
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

$email      = (string)$_SESSION["order_email"];
$is_open    = knk_bar_is_open();
$status     = knk_share_status_for($email);
$enabled    = (bool)$status["enabled"];
$rally_n    = (int)$status["rally_count"];
$rally_max  = (int)$status["rally_total"];

/* Display name for the header — same helper /bar.php uses. */
$guest_row  = knk_guest_find_by_email($email);
$disp       = knk_profile_display_name_for($email, $guest_row);

function sh($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>KnK Inn — Crash the Market</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --brown-deep: #1b0f04;
    --brown-mid:  #2a1a08;
    --gold:       #c9aa71;
    --cream:      #f5e9d1;
    --cream-faint:rgba(245,233,209,0.55);
    --line:       rgba(201,170,113,0.25);
    --green:      #2fdc7a;
    --red:        #d94343;
  }
  html, body { margin: 0; padding: 0; }
  body {
    font-family: "Inter", system-ui, sans-serif;
    background: linear-gradient(180deg, #1b0f04 0%, #0f0905 100%);
    color: var(--cream);
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
  }
  .wrap {
    max-width: 520px;
    margin: 0 auto;
    padding: calc(1.2rem + env(safe-area-inset-top, 0px)) 1.2rem
             calc(2rem + env(safe-area-inset-bottom, 0px));
  }

  /* ---- Header ---- */
  .hdr {
    text-align: center;
    margin-bottom: 1.5rem;
  }
  .hdr-brand {
    font-family: "Archivo Black", sans-serif;
    font-size: 0.9rem; letter-spacing: 0.16em; text-transform: uppercase;
    color: var(--gold);
    margin: 0 0 0.4rem;
  }
  .hdr h1 {
    font-family: "Archivo Black", sans-serif;
    font-size: 2.1rem; line-height: 1.05; letter-spacing: 0.01em;
    margin: 0;
    color: var(--cream);
  }
  .hdr h1 em {
    font-style: normal;
    color: var(--gold);
  }
  .hdr-sub {
    font-size: 1rem; color: rgba(245,233,209,0.7);
    margin: 0.7rem 0 0;
  }

  /* ---- Closed-state notice ---- */
  .closed-banner {
    background: rgba(217,67,67,0.12);
    border: 1px solid rgba(217,67,67,0.4);
    color: #ffb1b1;
    padding: 0.7rem 1rem;
    border-radius: 10px;
    text-align: center;
    font-size: 0.9rem;
    margin: 0 0 1.2rem;
  }

  /* ---- Rally progress ---- */
  .rally {
    background: rgba(201,170,113,0.06);
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 1rem 1.1rem;
    margin: 0 0 1.4rem;
  }
  .rally-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 0.8rem;
  }
  .rally-label {
    font-size: 0.7rem; letter-spacing: 0.14em; text-transform: uppercase;
    color: var(--cream-faint); font-weight: 700;
  }
  .rally-num {
    font-family: "Archivo Black", sans-serif;
    font-size: 1.4rem;
    color: var(--gold);
    line-height: 1;
  }
  .rally-bar {
    height: 6px;
    background: rgba(201,170,113,0.15);
    border-radius: 3px;
    overflow: hidden;
    margin-top: 0.7rem;
  }
  .rally-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--gold), #ffd58a);
    transition: width 0.4s ease;
  }

  /* ---- Platform card buttons ---- */
  .platforms {
    display: flex; flex-direction: column;
    gap: 0.85rem;
  }
  .pf-btn {
    display: flex; align-items: center;
    gap: 1rem;
    padding: 1.1rem 1.1rem;
    background: rgba(201,170,113,0.08);
    border: 1px solid rgba(201,170,113,0.4);
    border-radius: 14px;
    color: var(--cream);
    font-family: "Inter", system-ui, sans-serif;
    font-size: 1.05rem;
    cursor: pointer;
    text-align: left;
    width: 100%;
    transition: background 0.15s ease, border-color 0.15s ease, transform 0.05s ease;
    -webkit-tap-highlight-color: transparent;
  }
  .pf-btn:hover  { background: rgba(201,170,113,0.16); border-color: rgba(201,170,113,0.65); }
  .pf-btn:active { transform: scale(0.99); }
  .pf-btn.is-cooldown {
    opacity: 0.55;
    cursor: not-allowed;
  }
  .pf-btn.is-tapped {
    border-color: var(--green);
    background: rgba(47,220,122,0.08);
  }
  .pf-icon {
    width: 44px; height: 44px;
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-family: "Archivo Black", sans-serif;
    font-size: 1.2rem;
    background: var(--brown-mid);
    color: var(--gold);
    border: 1px solid var(--line);
    flex-shrink: 0;
  }
  .pf-icon.fb { background: #1877f2; color: #fff; border-color: #1877f2; }
  .pf-icon.g  { background: #ea4335; color: #fff; border-color: #ea4335; }
  .pf-icon.ta { background: #00aa6c; color: #fff; border-color: #00aa6c; }

  .pf-body { flex: 1; min-width: 0; }
  .pf-title {
    font-weight: 700;
    line-height: 1.2;
  }
  .pf-meta {
    font-size: 0.78rem;
    color: var(--cream-faint);
    margin-top: 0.2rem;
    letter-spacing: 0.04em;
  }
  .pf-tier {
    flex-shrink: 0;
    background: var(--gold);
    color: var(--brown-deep);
    border-radius: 999px;
    padding: 0.32rem 0.7rem;
    font-family: "Archivo Black", sans-serif;
    font-size: 0.7rem; letter-spacing: 0.08em; text-transform: uppercase;
  }

  /* ---- How it works ---- */
  .how {
    margin-top: 1.6rem;
    background: rgba(201,170,113,0.04);
    border: 1px dashed var(--line);
    border-radius: 12px;
    padding: 1rem;
    font-size: 0.86rem; line-height: 1.55;
    color: rgba(245,233,209,0.75);
  }
  .how strong { color: var(--gold); }

  /* ---- Toast ---- */
  .toast-host {
    position: fixed;
    left: 0; right: 0; bottom: calc(1rem + env(safe-area-inset-bottom, 0px));
    display: flex; justify-content: center;
    pointer-events: none;
    z-index: 50;
  }
  .toast {
    pointer-events: auto;
    background: var(--brown-mid);
    border: 1px solid var(--gold);
    color: var(--cream);
    padding: 0.7rem 1.1rem;
    border-radius: 999px;
    font-size: 0.92rem;
    box-shadow: 0 6px 20px rgba(0,0,0,0.45);
    opacity: 0;
    transform: translateY(20px);
    transition: opacity 0.25s ease, transform 0.25s ease;
    max-width: 90%;
    text-align: center;
  }
  .toast.show { opacity: 1; transform: translateY(0); }
  .toast.is-error { border-color: var(--red); }

  /* ---- Footer hint ---- */
  .foot {
    text-align: center;
    margin-top: 2rem;
    font-size: 0.78rem;
    color: var(--cream-faint);
    letter-spacing: 0.1em;
    text-transform: uppercase;
  }
  .foot a { color: var(--gold); text-decoration: none; }
</style>
</head>
<body>
  <div class="wrap">
    <header class="hdr">
      <p class="hdr-brand">KnK <em style="font-style:normal;color:#fff;">Inn</em></p>
      <h1>Crash <em>the Market</em></h1>
      <p class="hdr-sub">Post about us — drink prices drop on the spot.</p>
    </header>

    <?php if (!$is_open): ?>
      <div class="closed-banner">
        🤙 The bar's closed right now — your post still helps, but the
        market only crashes during service hours.
      </div>
    <?php endif; ?>

    <?php if (!$enabled): ?>
      <div class="closed-banner">
        Share-rally is paused right now. Posts are still appreciated!
      </div>
    <?php endif; ?>

    <!-- Rally progress -->
    <div class="rally">
      <div class="rally-row">
        <div>
          <div class="rally-label">Rally progress</div>
          <div style="font-size:0.86rem; margin-top:0.15rem;">
            <span class="rally-num" id="rallyN"><?= (int)$rally_n ?></span>
            <span style="opacity:0.6;"> / <?= (int)$rally_max ?> platforms</span>
          </div>
        </div>
        <div style="text-align:right;">
          <div class="rally-label">Window</div>
          <div style="font-size:0.86rem; margin-top:0.15rem; color:var(--gold);">
            <?= (int)$status["window_minutes"] ?> min
          </div>
        </div>
      </div>
      <div class="rally-bar">
        <div class="rally-bar-fill" id="rallyFill"
             style="width: <?= $rally_max > 0 ? (int)round($rally_n / $rally_max * 100) : 0 ?>%;"></div>
      </div>
    </div>

    <!-- Platform buttons -->
    <div class="platforms">
      <?php
        $icon_class = [
          "facebook"    => "fb",
          "google"      => "g",
          "tripadvisor" => "ta",
        ];
        $icon_glyph = [
          "facebook"    => "f",
          "google"      => "G",
          "tripadvisor" => "T",
        ];
      ?>
      <?php foreach ($status["platforms"] as $p):
        $key       = (string)$p["key"];
        $cd        = !empty($p["on_cooldown"]);
        $tapped    = !empty($p["tapped_recently"]);
        $tier      = (int)$p["tier"];
        $drop      = (int)$p["drop_pct"];
        $dur       = (int)$p["duration_min"];
        $action    = (string)$p["action"];
        $action_ux = $action === "review" ? "Write a review" : "Share to";
        $btn_class = "pf-btn";
        if ($cd) $btn_class .= " is-cooldown";
        if ($tapped) $btn_class .= " is-tapped";
      ?>
        <button type="button"
                class="<?= sh($btn_class) ?>"
                data-platform="<?= sh($key) ?>"
                data-redirect="<?= sh((string)$p["redirect_url"]) ?>"
                <?= $cd ? 'disabled aria-disabled="true"' : '' ?>>
          <span class="pf-icon <?= sh($icon_class[$key] ?? '') ?>"><?= sh($icon_glyph[$key] ?? '?') ?></span>
          <span class="pf-body">
            <span class="pf-title"><?= $action_ux ?> <?= sh($p["label"]) ?></span>
            <span class="pf-meta">
              <?php if ($cd): ?>
                Already tapped today — back tomorrow
              <?php else: ?>
                Crashes top drinks <?= $drop ?>% for <?= $dur ?> min
              <?php endif; ?>
            </span>
          </span>
          <span class="pf-tier">Tier <?= $tier ?></span>
        </button>
      <?php endforeach; ?>
    </div>

    <!-- How it works -->
    <div class="how">
      <strong>How it works.</strong>
      Tap a button → your phone opens that platform with a post / review
      ready to go. The market crashes top-selling drinks for a few minutes,
      and your shout shows up to your friends. Each guest can rally each
      platform once a day. Google reviews count for bigger crashes &mdash;
      they&rsquo;re gold for us.
    </div>

    <p class="foot">
      <a href="/bar.php">Back to the bar</a>
    </p>
  </div>

  <div class="toast-host"><div id="toast" class="toast"></div></div>

<script>
(function () {
  var rallyMax = <?= (int)$rally_max ?>;
  var toastEl  = document.getElementById("toast");
  var rallyN   = document.getElementById("rallyN");
  var rallyFill= document.getElementById("rallyFill");
  var toastTimer = null;

  function showToast(text, isError) {
    if (!toastEl) return;
    toastEl.textContent = text;
    toastEl.classList.remove("is-error");
    if (isError) toastEl.classList.add("is-error");
    toastEl.classList.add("show");
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      toastEl.classList.remove("show");
    }, 4500);
  }

  function bumpRally() {
    if (!rallyN || !rallyFill) return;
    var n = parseInt(rallyN.textContent, 10) || 0;
    if (n < rallyMax) {
      n += 1;
      rallyN.textContent = String(n);
      rallyFill.style.width = (rallyMax > 0 ? (n / rallyMax * 100) : 0) + "%";
    }
  }

  document.querySelectorAll(".pf-btn").forEach(function (btn) {
    btn.addEventListener("click", function (e) {
      e.preventDefault();
      if (btn.disabled) return;
      var platform = btn.getAttribute("data-platform") || "";
      var fallbackUrl = btn.getAttribute("data-redirect") || "";

      // Open the new tab IMMEDIATELY (sync with the user gesture)
      // so iOS Safari doesn't block it. We'll fill the URL once
      // the API returns; if the API is slow we still use the
      // fallback URL we already had.
      var win = window.open("about:blank", "_blank");
      btn.disabled = true;

      var fd = new FormData();
      fd.append("platform", platform);
      fetch("/api/social_tap.php", { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          var url = (data && data.redirect_url) || fallbackUrl;
          if (win) {
            try { win.location = url; } catch (_) {}
          } else if (url) {
            window.location.href = url;
          }
          if (data && data.ok) {
            btn.classList.add("is-tapped");
            bumpRally();
            showToast(data.message || "Done!", false);
          } else {
            showToast((data && data.message) || "Couldn't record that — try again.", true);
            btn.disabled = false;
          }
        })
        .catch(function () {
          if (win && fallbackUrl) {
            try { win.location = fallbackUrl; } catch (_) {}
          }
          showToast("Network hiccup — your post can still help.", true);
          btn.disabled = false;
        });
    });
  });
})();
</script>
</body>
</html>

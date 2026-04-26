<?php
/*
 * KnK Inn — /claim-confirm.php?token=<40 hex>
 *
 * Magic-link landing page for guest profile claim.
 *
 * The flow is:
 *   1. Anonymous bar guest hits /bar.php?tab=profile, sees "claim
 *      with a real email" form, types their email, hits send.
 *   2. profile.php → knk_profile_create_claim_token() writes a
 *      40-char hex token + 30-min expiry on the guests row, then
 *      knk_profile_send_claim_email() emails the real address with
 *      a link to /claim-confirm.php?token=<hex>.
 *   3. Guest clicks the link in their inbox. We land here.
 *   4. We call knk_profile_apply_claim($token), which re-keys all
 *      activity (orders.json + jukebox_queue + darts_players) from
 *      the anon email to the real email, merges the guests row,
 *      and clears the claim token.
 *   5. We promote the visitor's session to the real email, drop a
 *      90-day "stay logged in" cookie, clear the anon cookie, and
 *      redirect to /bar.php?tab=profile.
 *
 * On any failure (bad token, expired, already-used) we render a
 * small standalone error page. The token can only be redeemed once
 * — apply_claim clears the columns at the end of step 4.
 */
session_start();

require_once __DIR__ . "/includes/profile_store.php";

/* Same constants order.php / profile.php use. We re-declare here
 * so claim-confirm.php works without including either of them. */
if (!defined("KNK_GUEST_COOKIE"))      define("KNK_GUEST_COOKIE",      "knk_guest_email");
if (!defined("KNK_GUEST_COOKIE_TTL"))  define("KNK_GUEST_COOKIE_TTL",  90 * 24 * 60 * 60);
if (!defined("KNK_GUEST_ANON_COOKIE")) define("KNK_GUEST_ANON_COOKIE", "knk_guest_anon");

function cc_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

$token = trim((string)($_GET["token"] ?? ""));
$error = "";

if ($token === "" || strlen($token) !== 40 || !ctype_xdigit($token)) {
    $error = "That link doesn't look right. Magic links are only valid for 30 minutes — try requesting a new one from your profile page.";
} else {
    $real_email = knk_profile_apply_claim($token);
    if (!$real_email) {
        $error = "This magic link has expired or already been used. Magic links are only valid for 30 minutes — head back to your profile and request a fresh one.";
    } else {
        /* Promote session to the real email. */
        $_SESSION["order_email"] = $real_email;

        $secure = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";

        /* Stay-logged-in cookie — same one /order.php reads on next visit. */
        setcookie(KNK_GUEST_COOKIE, $real_email, [
            "expires"  => time() + KNK_GUEST_COOKIE_TTL,
            "path"     => "/",
            "secure"   => $secure,
            "httponly" => true,
            "samesite" => "Lax",
        ]);

        /* Clear the anon cookie — the activity it pointed at has
         * been re-keyed to the real email, so the token is stale.
         * If the guest later signs out, order.php will mint a fresh
         * anon identity rather than reviving this dead one. */
        if (isset($_COOKIE[KNK_GUEST_ANON_COOKIE])) {
            setcookie(KNK_GUEST_ANON_COOKIE, "", [
                "expires"  => time() - 3600,
                "path"     => "/",
                "secure"   => $secure,
                "httponly" => true,
                "samesite" => "Lax",
            ]);
            unset($_COOKIE[KNK_GUEST_ANON_COOKIE]);
        }

        header("Location: /bar.php?tab=profile&claimed=1");
        exit;
    }
}

/* ---------- Error path: render a small standalone page ---------- */
http_response_code(400);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Magic link · KnK Inn</title>
    <style>
        :root {
            --brown-deep: #180c03;
            --brown-mid:  #2b1a0a;
            --gold:       #c9aa71;
            --cream:      #f4ede0;
            --cream-card: #fdf8ef;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Inter', -apple-system, system-ui, sans-serif;
            background: var(--brown-deep);
            color: var(--cream);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .cc-card {
            background: var(--cream-card);
            color: var(--brown-deep);
            border: 1px solid var(--gold);
            border-radius: 14px;
            padding: 28px 24px;
            max-width: 440px;
            width: 100%;
            text-align: center;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
        }
        .cc-card h1 {
            font-family: 'Archivo Black', Impact, sans-serif;
            margin: 0 0 12px;
            font-size: 22px;
            color: var(--brown-mid);
            letter-spacing: 0.5px;
        }
        .cc-card p {
            margin: 0 0 18px;
            line-height: 1.5;
            font-size: 15px;
        }
        .cc-icon {
            font-size: 38px;
            line-height: 1;
            margin-bottom: 10px;
        }
        .cc-btn {
            display: inline-block;
            background: var(--brown-mid);
            color: var(--cream);
            border: 1px solid var(--gold);
            padding: 11px 22px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
        }
        .cc-btn:hover { background: var(--brown-deep); }
        .cc-sub {
            margin-top: 14px;
            font-size: 13px;
            opacity: 0.7;
        }
    </style>
</head>
<body>
    <div class="cc-card">
        <div class="cc-icon">&#9888;</div>
        <h1>Link no longer works</h1>
        <p><?= cc_h($error) ?></p>
        <a class="cc-btn" href="/bar.php?tab=profile">Back to profile</a>
        <div class="cc-sub">KnK Inn &middot; District 1, Saigon</div>
    </div>
</body>
</html>

<?php
/*
 * KnK Inn — /settings.php
 *
 * Super Admin only. Two Simmo-facing toggles plus the notification
 * email Simmo should receive things at:
 *
 *   1. Marketing Reminders
 *      When ON, the daily cron script (cron/reminders.php) emails
 *      Simmo a week before each upcoming sports fixture, one email
 *      per match, so he can schedule social posts / cocktail specials.
 *
 *   2. Owner Order Alerts
 *      When ON, every new drinks order from the website sends a
 *      notification email to Simmo so he can keep an eye on what's
 *      selling in real time.
 *
 *   3. Notification Email
 *      The address both of the above go to. If blank, we fall back
 *      to Simmo's staff-user email.
 *
 * UI notes:
 *   · Each setting is its own tiny form with its own Save button,
 *     so Simmo can change one thing without being forced to
 *     re-confirm the others.
 *   · POST-redirect-GET avoids re-submits on refresh.
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/settings_store.php";

$me = knk_require_role(["super_admin"]);

/* --------------------------------------------------------------------
 * Find the owner user (if one exists) so we can show their email as
 * the fallback when the notification-email field is blank.
 * ------------------------------------------------------------------ */
function knk_settings_owner_email(): ?string {
    try {
        $stmt = knk_db()->prepare(
            "SELECT email FROM users
             WHERE role = 'owner' AND active = 1
             ORDER BY id LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ? (string)$row["email"] : null;
    } catch (Throwable $e) {
        return null;
    }
}

/* --------------------------------------------------------------------
 * POST actions — one setting per submit
 * ------------------------------------------------------------------ */
$flash = "";
$error = "";
$action = (string)($_POST["action"] ?? "");
$me_id  = (int)$me["id"];

if ($action === "save_marketing_reminders") {
    $on = !empty($_POST["enabled"]) ? "1" : "0";
    knk_setting_set("marketing_reminders_enabled", $on, $me_id);
    knk_audit("settings.update", "settings", "marketing_reminders_enabled", ["value" => $on]);
    $flash = $on === "1"
        ? "Marketing reminder emails are now ON."
        : "Marketing reminder emails are now OFF.";
}
elseif ($action === "save_owner_alerts") {
    $on = !empty($_POST["enabled"]) ? "1" : "0";
    knk_setting_set("owner_order_notifications_enabled", $on, $me_id);
    knk_audit("settings.update", "settings", "owner_order_notifications_enabled", ["value" => $on]);
    $flash = $on === "1"
        ? "Owner order alerts are now ON."
        : "Owner order alerts are now OFF.";
}
elseif ($action === "save_notification_email") {
    $email = strtolower(trim((string)($_POST["email"] ?? "")));
    if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "That doesn't look like a valid email address.";
    } else {
        knk_setting_set("owner_notification_email", $email, $me_id);
        knk_audit("settings.update", "settings", "owner_notification_email", ["value" => $email]);
        $flash = $email === ""
            ? "Notification email cleared — we'll fall back to the Owner's staff email."
            : "Notification email saved: {$email}";
    }
}
elseif ($action === "save_bar_force_open") {
    $on = !empty($_POST["enabled"]) ? "1" : "0";
    knk_setting_set("bar_force_open", $on, $me_id);
    knk_audit("settings.update", "settings", "bar_force_open", ["value" => $on]);
    $flash = $on === "1"
        ? "Bar is now FORCED OPEN (testing). Service-hour gate is overridden."
        : "Bar is now back on the normal schedule.";
}
elseif ($action === "save_share_rally") {
    $on = !empty($_POST["enabled"]) ? "1" : "0";
    knk_setting_set("share_rally_enabled", $on, $me_id);
    knk_audit("settings.update", "settings", "share_rally_enabled", ["value" => $on]);
    $flash = $on === "1"
        ? "Share rally is ON — guests can crash the market via /share.php."
        : "Share rally is OFF — /share.php still loads but no crashes fire.";
}
elseif ($action === "save_darts_loud") {
    $on = !empty($_POST["enabled"]) ? "1" : "0";
    knk_setting_set("darts_loud_mode", $on, $me_id);
    knk_audit("settings.update", "settings", "darts_loud_mode", ["value" => $on]);
    $flash = $on === "1"
        ? "Darts celebrations are LOUD — banners + banter on big shots."
        : "Darts celebrations are QUIET — small badges, no audio.";
}
elseif ($action === "save_share_urls") {
    $fb_url = trim((string)($_POST["share_url_facebook"] ?? ""));
    $g_url  = trim((string)($_POST["share_url_google"] ?? ""));
    $ta_url = trim((string)($_POST["share_url_tripadvisor"] ?? ""));
    foreach (["share_url_facebook" => $fb_url,
              "share_url_google" => $g_url,
              "share_url_tripadvisor" => $ta_url] as $k => $v) {
        knk_setting_set($k, $v, $me_id);
    }
    knk_audit("settings.update", "settings", "share_urls", [
        "facebook" => $fb_url, "google" => $g_url, "tripadvisor" => $ta_url,
    ]);
    $flash = "Share-rally URLs saved.";
}
elseif ($action === "save_ip_whitelist") {
    /* Self-lockout protection: refuse to enable the whitelist
     * unless the caller's own IP is on the list, otherwise the
     * next request would 403 us out of /settings.php. */
    $on   = !empty($_POST["enabled"]) ? "1" : "0";
    $list = trim((string)($_POST["staff_ip_whitelist"] ?? ""));
    $caller_ip = (string)($_SERVER["REMOTE_ADDR"] ?? "");
    if ($on === "1" && $caller_ip !== "" && !knk_ip_in_whitelist($caller_ip, $list)) {
        $error = "Refusing to lock you out. Add your current IP ("
               . htmlspecialchars($caller_ip, ENT_QUOTES, "UTF-8")
               . ") to the list before turning the gate on.";
    } else {
        knk_setting_set("staff_ip_whitelist_enabled", $on, $me_id);
        knk_setting_set("staff_ip_whitelist", $list, $me_id);
        knk_audit("settings.update", "settings", "staff_ip_whitelist", [
            "enabled" => $on, "list" => $list,
        ]);
        $flash = $on === "1"
            ? "Staff IP whitelist is ON — only listed addresses can reach admin pages."
            : "Staff IP whitelist is OFF.";
    }
}
elseif ($action === "save_rates_export_key") {
    /* Room-rates export key. Lets the OTA channel manager call
     * /api/room_rates_export.php?key=… to pull the next 90 days
     * of nightly rates. We accept either a fresh key (value) or a
     * "regenerate" action that creates one server-side. Treat the
     * field as opaque text — Booking.com/Airbnb don't care about
     * format, they just store the URL. */
    $regen = !empty($_POST["regenerate"]);
    if ($regen) {
        $val = bin2hex(random_bytes(16));
        knk_setting_set("room_rates_export_key", $val, $me_id);
        knk_audit("settings.update", "settings", "room_rates_export_key", ["regenerated" => true]);
        $flash = "Generated a new export key. Update the URL in your channel-manager settings.";
    } else {
        $val = trim((string)($_POST["rates_export_key"] ?? ""));
        if ($val === "" || mb_strlen($val) >= 16) {
            knk_setting_set("room_rates_export_key", $val, $me_id);
            knk_audit("settings.update", "settings", "room_rates_export_key", ["set" => ($val !== "")]);
            $flash = $val === ""
                ? "Export key cleared — channel manager can no longer fetch rates."
                : "Export key saved.";
        } else {
            $error = "Key must be at least 16 characters (or hit Regenerate).";
        }
    }
}
elseif ($action === "save_hostess_email") {
    $he = trim((string)($_POST["hostess_email"] ?? ""));
    if ($he !== "" && !filter_var($he, FILTER_VALIDATE_EMAIL)) {
        $error = "That doesn't look like a valid email.";
    } else {
        knk_setting_set("hostess_email", $he, $me_id);
        knk_audit("settings.update", "settings", "hostess_email", ["value" => $he]);
        $flash = $he !== ""
            ? "Hostess email saved — drink orders + bills + lonely-looker alerts go to " . $he . "."
            : "Hostess email cleared — falls back to the bar inbox.";
    }
}

if ($action !== "") {
    $qs = [];
    if ($flash) $qs["msg"] = $flash;
    if ($error) $qs["err"] = $error;
    header("Location: /settings.php" . ($qs ? "?" . http_build_query($qs) : ""));
    exit;
}

$flash = (string)($_GET["msg"] ?? "");
$error = (string)($_GET["err"] ?? "");

/* --------------------------------------------------------------------
 * Read current values for display
 * ------------------------------------------------------------------ */
$mark_on        = knk_setting_bool("marketing_reminders_enabled", true);
$alerts_on      = knk_setting_bool("owner_order_notifications_enabled", true);
$notif_email    = (string)knk_setting("owner_notification_email", "");
$days_before    = knk_setting_int("marketing_reminder_days_before", 7);
$owner_email    = knk_settings_owner_email();
$effective      = $notif_email !== "" ? $notif_email : ($owner_email ?: "(none set yet)");
$force_open_on  = knk_setting_bool("bar_force_open", false);
$share_rally_on = knk_setting_bool("share_rally_enabled", true);
$share_url_fb   = (string)knk_setting("share_url_facebook", "");
$share_url_g    = (string)knk_setting("share_url_google", "");
$share_url_ta   = (string)knk_setting("share_url_tripadvisor", "");
$darts_loud_on  = knk_setting_bool("darts_loud_mode", true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>KnK Inn — Settings</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Caveat:wght@700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/styles.css?v=12">
  <style>
    body { background: #1b0f04; color: var(--cream, #f5e9d1); font-family: "Inter", system-ui, sans-serif; margin: 0; }
    main.wrap { max-width: 900px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
    h1.display-md { margin: 1.6rem 0 0.3rem; }
    .lede { color: var(--cream-dim, #d8c9ab); margin-bottom: 2rem; }
    .flash { padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1.2rem; font-size: 0.95rem; }
    .flash.ok  { background: rgba(142,212,154,0.12); color: #8ed49a; border: 1px solid rgba(142,212,154,0.28); }
    .flash.err { background: rgba(255,154,138,0.1);  color: #ff9a8a; border: 1px solid rgba(255,154,138,0.3); }
    section.card {
      background: rgba(24,12,3,0.6); border: 1px solid rgba(201,170,113,0.22);
      border-radius: 6px; padding: 1.5rem; margin-bottom: 1.5rem;
    }
    section.card h2 { margin: 0 0 0.4rem; font-family: "Archivo Black", sans-serif; letter-spacing: .02em; font-size: 1.25rem; }
    section.card p.explain { color: var(--cream-dim, #d8c9ab); font-size: 0.92rem; margin: 0.2rem 0 1rem; line-height: 1.55; }
    .status-row { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; margin-top: 0.4rem; }
    .status-pill {
      display: inline-flex; align-items: center; gap: 0.4rem;
      padding: 0.35rem 0.85rem; border-radius: 999px;
      font-size: 0.8rem; letter-spacing: 0.08em; text-transform: uppercase;
      font-weight: 600;
    }
    .status-pill.on  { background: rgba(142,212,154,0.12); color: #8ed49a; border: 1px solid rgba(142,212,154,0.3); }
    .status-pill.off { background: rgba(255,154,138,0.1);  color: #ff9a8a; border: 1px solid rgba(255,154,138,0.3); }
    .status-pill .dot { width: 0.55rem; height: 0.55rem; border-radius: 50%; background: currentColor; }
    label { display: block; font-size: 0.75rem; letter-spacing: 0.14em; text-transform: uppercase; color: var(--cream-dim, #d8c9ab); margin: 0 0 0.3rem 0.15rem; }
    input[type="email"] {
      width: 100%; padding: 0.7rem 0.85rem; background: rgba(255,255,255,0.04);
      border: 1px solid rgba(201,170,113,0.3); color: var(--cream, #f5e9d1);
      font-size: 0.95rem; font-family: inherit; border-radius: 4px; box-sizing: border-box;
    }
    input[type="email"]:focus { outline: none; border-color: var(--gold, #c9aa71); }
    button, .btn {
      display: inline-block; padding: 0.55rem 1rem; background: var(--gold, #c9aa71);
      color: var(--brown-deep, #2a1a08); border: none; font-weight: 700;
      letter-spacing: 0.12em; text-transform: uppercase; font-size: 0.72rem;
      cursor: pointer; border-radius: 4px; font-family: inherit; text-decoration: none;
    }
    button:hover, .btn:hover { background: #d8c08b; }
    button.ghost { background: transparent; color: var(--cream-dim, #d8c9ab); border: 1px solid rgba(201,170,113,0.3); }
    button.ghost:hover { background: rgba(201,170,113,0.12); color: var(--cream, #f5e9d1); }
    .muted { color: var(--cream-dim, #d8c9ab); font-size: 0.85rem; }
    .email-current { color: var(--gold, #c9aa71); font-weight: 600; }
    .inline-form { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
    .inline-form input[type="email"] { flex: 1; min-width: 260px; width: auto; }
    .cron-hint {
      margin-top: 1rem; padding: 1rem 1.1rem; border-radius: 4px;
      background: rgba(201,170,113,0.06); border: 1px dashed rgba(201,170,113,0.35);
      font-size: 0.88rem; color: var(--cream-dim, #d8c9ab); line-height: 1.55;
    }
    .cron-hint strong { color: var(--cream, #f5e9d1); }
    code {
      background: rgba(0,0,0,0.35); border: 1px solid rgba(201,170,113,0.22);
      padding: 0.12rem 0.4rem; border-radius: 3px; font-size: 0.85em;
      color: var(--cream, #f5e9d1); font-family: "SFMono-Regular", Menlo, Consolas, monospace;
    }
  </style>
</head>
<body>
  <?php knk_render_admin_nav($me); ?>
  <main class="wrap">
    <span class="eyebrow">Super Admin</span>
    <h1 class="display-md">Settings</h1>
    <p class="lede">Turn automatic emails on or off, and choose where they go.</p>

    <?php if ($flash): ?><div class="flash ok"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="flash err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Notification email (shown first — both settings below depend on it) -->
    <section class="card">
      <h2>Notification email</h2>
      <p class="explain">
        Where the automatic emails below get sent to. Leave blank and we'll fall
        back to the Owner's staff-login email
        <?php if ($owner_email): ?>
          (<span class="email-current"><?= htmlspecialchars($owner_email) ?></span>)
        <?php else: ?>
          (<em>no Owner account yet — add one in Users</em>)
        <?php endif; ?>.
      </p>

      <form method="post" class="inline-form">
        <input type="hidden" name="action" value="save_notification_email">
        <input type="email" name="email" placeholder="e.g. simmo@example.com"
               value="<?= htmlspecialchars($notif_email) ?>"
               autocomplete="off">
        <button type="submit">Save email</button>
        <?php if ($notif_email !== ""): ?>
          <button type="submit" class="ghost" onclick="this.form.email.value='';" title="Clear and save">Clear</button>
        <?php endif; ?>
      </form>
      <p class="muted" style="margin-top:0.7rem">
        Emails are currently going to <strong class="email-current"><?= htmlspecialchars($effective) ?></strong>.
      </p>
    </section>

    <!-- Marketing reminders -->
    <section class="card">
      <h2>Marketing reminder emails</h2>
      <p class="explain">
        A week before each big upcoming sports fixture — the ones shown on the
        homepage — the system sends a short email with the match details.
        That way Simmo has time to schedule social posts, line up drink
        specials, or rope in a few regulars. One email per match; never
        sent twice for the same game.
      </p>

      <div class="status-row">
        <?php if ($mark_on): ?>
          <span class="status-pill on"><span class="dot"></span>On</span>
        <?php else: ?>
          <span class="status-pill off"><span class="dot"></span>Off</span>
        <?php endif; ?>
        <span class="muted"><?= (int)$days_before ?> days before kickoff</span>
      </div>

      <form method="post" style="margin-top:1rem; display:flex; gap:0.6rem; flex-wrap:wrap">
        <input type="hidden" name="action" value="save_marketing_reminders">
        <?php if ($mark_on): ?>
          <!-- Off switch: omit `enabled` so it evaluates to "0" server-side -->
          <button type="submit" class="ghost">Turn off reminders</button>
        <?php else: ?>
          <input type="hidden" name="enabled" value="1">
          <button type="submit">Turn on reminders</button>
        <?php endif; ?>
      </form>

      <div class="cron-hint">
        <strong>Heads up:</strong> the emails only go out if the daily scheduled
        task is running on the hosting server. See the <em>Daily schedule</em>
        box at the bottom of this page for setup — it's a one-time paste into
        Matbao's Cron Jobs panel.
      </div>
    </section>

    <!-- Owner order alerts -->
    <section class="card">
      <h2>Owner order alerts</h2>
      <p class="explain">
        When a guest places a drinks order from the phone, Simmo gets a copy
        of the order-received email. Handy for keeping a pulse on what's
        selling when he's not behind the bar.
      </p>

      <div class="status-row">
        <?php if ($alerts_on): ?>
          <span class="status-pill on"><span class="dot"></span>On</span>
        <?php else: ?>
          <span class="status-pill off"><span class="dot"></span>Off</span>
        <?php endif; ?>
      </div>

      <form method="post" style="margin-top:1rem; display:flex; gap:0.6rem; flex-wrap:wrap">
        <input type="hidden" name="action" value="save_owner_alerts">
        <?php if ($alerts_on): ?>
          <button type="submit" class="ghost">Turn off alerts</button>
        <?php else: ?>
          <input type="hidden" name="enabled" value="1">
          <button type="submit">Turn on alerts</button>
        <?php endif; ?>
      </form>
    </section>

    <!-- Force bar open (testing override) -->
    <section class="card">
      <h2>Force bar open (testing)</h2>
      <p class="explain">
        Normally the bar self-gates outside service hours
        (08:00&ndash;12:00 and 16:30&ndash;23:00 Saigon time) &mdash;
        drinks orders, jukebox requests, and darts games all show a
        &ldquo;closed&rdquo; splash, and price ticks freeze. Flip this
        on to override the gate and keep everything open 24/7. Useful
        for testing or for showing the menu to a friend at lunchtime.
        <strong>Remember to switch it off again before service.</strong>
      </p>

      <div class="status-row">
        <?php if ($force_open_on): ?>
          <span class="status-pill on"><span class="dot"></span>Forced open</span>
        <?php else: ?>
          <span class="status-pill off"><span class="dot"></span>Normal hours</span>
        <?php endif; ?>
      </div>

      <form method="post" style="margin-top:1rem; display:flex; gap:0.6rem; flex-wrap:wrap">
        <input type="hidden" name="action" value="save_bar_force_open">
        <?php if ($force_open_on): ?>
          <button type="submit" class="ghost">Back to normal hours</button>
        <?php else: ?>
          <input type="hidden" name="enabled" value="1">
          <button type="submit">Force bar open</button>
        <?php endif; ?>
      </form>
    </section>

    <!-- Share rally (Facebook / Google / TripAdvisor crash-the-market) -->
    <section class="card">
      <h2>Share rally — &ldquo;Crash the Market&rdquo;</h2>
      <p class="explain">
        Guests scan the alternating QR on the TV (or visit
        <code>knkinn.com/share.php</code>) and tap a platform to share or
        write a review. Each tap fires a market crash on the top trending
        drinks &mdash; <strong>tier&nbsp;1</strong> Facebook (10%, 2&nbsp;min),
        <strong>tier&nbsp;2</strong> Google review (20%, 2&nbsp;min).
        24h cooldown per guest per platform so one regular can't spam it.
        <br><br>
        <em>TripAdvisor is parked until the listing is approved. Once
        the URL field below is filled in we&rsquo;ll re-enable it as
        tier&nbsp;3 (35%, 5&nbsp;min).</em>
      </p>

      <div class="status-row">
        <?php if ($share_rally_on): ?>
          <span class="status-pill on"><span class="dot"></span>On</span>
        <?php else: ?>
          <span class="status-pill off"><span class="dot"></span>Off</span>
        <?php endif; ?>
      </div>

      <form method="post" style="margin-top:1rem; display:flex; gap:0.6rem; flex-wrap:wrap">
        <input type="hidden" name="action" value="save_share_rally">
        <?php if ($share_rally_on): ?>
          <button type="submit" class="ghost">Turn off share rally</button>
        <?php else: ?>
          <input type="hidden" name="enabled" value="1">
          <button type="submit">Turn on share rally</button>
        <?php endif; ?>
      </form>

      <hr style="border:0;border-top:1px solid var(--line,rgba(201,170,113,0.25));margin:1.2rem 0;">

      <h3 style="margin:0 0 0.4rem;">Platform URLs (optional)</h3>
      <p class="explain" style="margin-top:0;">
        Where each platform button sends the guest. Leave a row blank
        to use the search-fallback default (works fine, just less precise
        than a direct write-review link).
        <br><br>
        <strong>Google Maps:</strong> paste either the full
        <code>https://search.google.com/local/writereview?placeid=...</code>
        URL, or just <code>placeid:XXXXX</code> &mdash; we'll build the URL.
        Find the place ID at
        <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank" rel="noopener">
        developers.google.com/maps/documentation/places/web-service/place-id</a>.
        <br>
        <strong>TripAdvisor:</strong> paste the full
        <code>https://www.tripadvisor.com/UserReviewEdit-...</code> URL
        from your listing page.
      </p>

      <form method="post" style="margin-top:0.6rem; display:flex; flex-direction:column; gap:0.5rem;">
        <input type="hidden" name="action" value="save_share_urls">
        <label>
          <span style="display:block;font-size:0.85rem;color:var(--cream-faint,#aaa);">Facebook share URL</span>
          <input type="url" name="share_url_facebook"
                 value="<?= htmlspecialchars($share_url_fb, ENT_QUOTES, "UTF-8") ?>"
                 placeholder="https://www.facebook.com/sharer/sharer.php?u=https://knkinn.com/"
                 style="width:100%; padding:0.5rem;">
        </label>
        <label>
          <span style="display:block;font-size:0.85rem;color:var(--cream-faint,#aaa);">Google Maps review URL or <code>placeid:XXX</code></span>
          <input type="text" name="share_url_google"
                 value="<?= htmlspecialchars($share_url_g, ENT_QUOTES, "UTF-8") ?>"
                 placeholder="placeid:ChIJN1t_..."
                 style="width:100%; padding:0.5rem;">
        </label>
        <label>
          <span style="display:block;font-size:0.85rem;color:var(--cream-faint,#aaa);">
            TripAdvisor review URL <em>(currently parked &mdash; listing pending approval)</em>
          </span>
          <input type="url" name="share_url_tripadvisor"
                 value="<?= htmlspecialchars($share_url_ta, ENT_QUOTES, "UTF-8") ?>"
                 placeholder="https://www.tripadvisor.com/UserReviewEdit-..."
                 style="width:100%; padding:0.5rem;">
        </label>
        <div><button type="submit">Save URLs</button></div>
      </form>
    </section>

    <!-- Hostess email -->
    <section class="card">
      <h2>Hostess email</h2>
      <p class="explain">
        Where the bar-side notifications land — drink orders from
        /bar.php, "Check Bill" requests from a guest's tab, and the
        "🎯 Someone's waiting for darts" alerts after 10 minutes
        without a challenger. Empty falls back to the main bar inbox.
      </p>
      <?php $hostess_email = (string)knk_setting("hostess_email", ""); ?>
      <form method="post" class="inline-form" style="margin-top:0.6rem;">
        <input type="hidden" name="action" value="save_hostess_email">
        <input type="email" name="hostess_email"
               placeholder="thirsty@knkinn.com"
               value="<?= htmlspecialchars($hostess_email, ENT_QUOTES, "UTF-8") ?>"
               autocomplete="off">
        <button type="submit">Save email</button>
      </form>
      <?php if ($hostess_email !== ""): ?>
        <p class="muted" style="margin-top:0.7rem">
          Currently routing to <strong><?= htmlspecialchars($hostess_email, ENT_QUOTES, "UTF-8") ?></strong>.
        </p>
      <?php endif; ?>
    </section>

    <!-- Room-rates export key (for the OTA channel manager) -->
    <section class="card">
      <h2>Room-rates export key</h2>
      <p class="explain">
        Channel managers (Airbnb, Booking.com, Tripadvisor) read your
        nightly rates from <code>/api/room_rates_export.php</code> and
        availability from <code>/api/room_availability_export.php</code>.
        Both endpoints share this one key — paste a value below or hit
        Regenerate to spin a fresh one.
      </p>
      <?php $rates_key = (string)knk_setting("room_rates_export_key", ""); ?>
      <form method="post" class="inline-form" style="margin-top:0.6rem;">
        <input type="hidden" name="action" value="save_rates_export_key">
        <input type="text" name="rates_export_key"
               placeholder="paste a key (≥16 chars) or hit Regenerate"
               value="<?= htmlspecialchars($rates_key, ENT_QUOTES, "UTF-8") ?>"
               autocomplete="off" spellcheck="false"
               style="font-family: monospace; min-width: 320px;">
        <button type="submit">Save key</button>
      </form>
      <form method="post" class="inline-form" style="margin-top:0.4rem;">
        <input type="hidden" name="action"     value="save_rates_export_key">
        <input type="hidden" name="regenerate" value="1">
        <button type="submit" style="background:rgba(245,233,209,0.06); color:var(--cream,#f5e9d1); border:1px solid rgba(245,233,209,0.2);">Regenerate</button>
      </form>
      <?php if ($rates_key !== ""):
        $sample = $_SERVER['HTTP_HOST'] ?? 'knkinn.com';
      ?>
        <p class="muted" style="margin-top:0.7rem; font-size:0.85rem;">
          Example URL for the VIP F3 room, next 90 days:<br>
          <code style="word-break: break-all;">https://<?= htmlspecialchars($sample, ENT_QUOTES, "UTF-8") ?>/api/room_rates_export.php?key=<?= htmlspecialchars($rates_key, ENT_QUOTES, "UTF-8") ?>&amp;room=vip-3&amp;days=90</code>
        </p>
      <?php endif; ?>
    </section>

    <!-- Staff IP whitelist -->
    <section class="card">
      <h2>Staff IP whitelist</h2>
      <p class="explain">
        Locks every staff page (bookings, orders, photos, settings,
        users, market-admin, jukebox-admin, darts-admin, menu) to a
        list of approved IP addresses. Customers using <code>/bar.php</code>
        and the public site stay reachable from anywhere — only the
        staff side gets gated.
      </p>
      <?php
        $ipw_on   = knk_setting_bool("staff_ip_whitelist_enabled", false);
        $ipw_list = (string)knk_setting("staff_ip_whitelist", "");
        $caller   = (string)($_SERVER["REMOTE_ADDR"] ?? "");
      ?>
      <p class="explain" style="margin-top:0.4rem;">
        Comma- or newline-separated. Each entry can be a single IPv4
        (<code>27.74.115.220</code>) or a CIDR range
        (<code>192.168.1.0/24</code>). Your current IP is
        <strong><?= htmlspecialchars($caller, ENT_QUOTES, "UTF-8") ?: "—" ?></strong>
        — make sure it's on the list before turning the gate on, or you'll lock yourself out.
      </p>
      <div class="status-row" style="margin-top:0.6rem;">
        <?php if ($ipw_on): ?>
          <span class="status-pill on"><span class="dot"></span>On</span>
        <?php else: ?>
          <span class="status-pill off"><span class="dot"></span>Off</span>
        <?php endif; ?>
      </div>
      <form method="post" style="margin-top:0.7rem; display:flex; flex-direction:column; gap:0.5rem;">
        <input type="hidden" name="action" value="save_ip_whitelist">
        <label>
          <span style="display:block;font-size:0.85rem;color:var(--cream-faint,#aaa);">Allowed addresses (comma- or newline-separated)</span>
          <textarea name="staff_ip_whitelist" rows="4"
                    placeholder="27.74.115.220&#10;192.168.1.0/24"
                    style="width:100%; padding:0.5rem; font-family:monospace;"><?= htmlspecialchars($ipw_list, ENT_QUOTES, "UTF-8") ?></textarea>
        </label>
        <label style="display:flex;align-items:center;gap:0.4rem;">
          <input type="checkbox" name="enabled" value="1"<?= $ipw_on ? " checked" : "" ?>>
          <span>Enforce — anyone not on the list gets a 403 before login</span>
        </label>
        <div><button type="submit">Save whitelist</button></div>
      </form>
    </section>

    <!-- Darts loud-mode toggle (TV celebrations + banter) -->
    <section class="card">
      <h2>Darts celebrations</h2>
      <p class="explain">
        On the bar TV, every dart now appears tap-by-tap with each
        round's totals. When this is <strong>ON</strong>, big shots
        (180s, ton-plus rounds, checkouts) trigger a full-screen
        banner with Aussie pub banter (&ldquo;You little ripper!&rdquo;
        on a 180; &ldquo;Crikey, that's a stinker&rdquo; on a 0&ndash;15
        round) and a gold flash. Turn it <strong>OFF</strong> for slow
        afternoons or when the volume would be obnoxious &mdash; the
        tap-by-tap stays, just dialled down to a small badge with no
        audio.
      </p>

      <div class="status-row">
        <?php if ($darts_loud_on): ?>
          <span class="status-pill on"><span class="dot"></span>Loud</span>
        <?php else: ?>
          <span class="status-pill off"><span class="dot"></span>Quiet</span>
        <?php endif; ?>
      </div>

      <form method="post" style="margin-top:1rem; display:flex; gap:0.6rem; flex-wrap:wrap">
        <input type="hidden" name="action" value="save_darts_loud">
        <?php if ($darts_loud_on): ?>
          <button type="submit" class="ghost">Quiet mode</button>
        <?php else: ?>
          <input type="hidden" name="enabled" value="1">
          <button type="submit">Loud mode</button>
        <?php endif; ?>
      </form>
    </section>

    <!-- Cron setup note (read-only, purely instructional) -->
    <section class="card">
      <h2>Daily schedule (one-time setup)</h2>
      <p class="explain">
        The marketing reminders above only work if the hosting server runs the
        reminder script once a day. You set this up <strong>once</strong> in
        Matbao's control panel and then forget about it — it runs forever.
      </p>
      <p class="muted" style="margin:0.4rem 0 0.6rem">
        In <strong>Matbao → Hosting Manager → Cron Jobs</strong>, add a new
        job that runs daily and calls this URL:
      </p>
      <p>
        <code>curl -s "https://knkinn.com/cron/reminders.php?key=YOUR-ADMIN-PASSWORD"</code>
      </p>
      <p class="muted" style="margin-top:0.6rem">
        Replace <code>YOUR-ADMIN-PASSWORD</code> with the same admin password
        used for the migration page. A good time to run it is
        <strong>09:00 Saigon time</strong> — morning emails tend to get read.
      </p>
    </section>
  </main>
</body>
</html>

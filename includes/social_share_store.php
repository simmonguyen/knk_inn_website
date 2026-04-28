<?php
/*
 * KnK Inn — social share-rally store.
 *
 * Backs the /share.php "Crash the Market for Cheap Drinks" page.
 *
 * What it does:
 *   - Records one row per platform tap (table social_share_taps)
 *   - Per-platform 24h cooldown per guest so a single regular can't
 *     farm the same platform repeatedly
 *   - Each platform has a fixed tier (1=Facebook, 2=Google,
 *     3=TripAdvisor) — bigger tier = bigger crash. Tapping all
 *     three within 10 min means the guest gets all three crashes
 *     stacked, escalating in magnitude.
 *   - Each tap fires a crash on the top-K trending drinks (same
 *     "social crash" path the market-admin button uses).
 *
 * What it deliberately doesn't do:
 *   - Verify the user actually shared anything. Honour-system —
 *     the tap is the trigger. Cooldown is the abuse guard.
 *
 * Identity is the same lower-cased email used everywhere else:
 * anon-…@anon.knkinn.com counts as a real guest for share purposes.
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/market_engine.php";
require_once __DIR__ . "/menu_store.php";
require_once __DIR__ . "/settings_store.php";
require_once __DIR__ . "/auth.php";    // for knk_audit

/* =========================================================
   PLATFORMS — config + URL helpers
   ========================================================= */

if (!defined("KNK_SHARE_RALLY_WINDOW_SEC")) {
    /** Rolling window in which "rally progress" counts. */
    define("KNK_SHARE_RALLY_WINDOW_SEC", 10 * 60);
}
if (!defined("KNK_SHARE_PLATFORM_COOLDOWN_SEC")) {
    /** Cooldown per (guest, platform) before they can tap again. */
    define("KNK_SHARE_PLATFORM_COOLDOWN_SEC", 24 * 60 * 60);
}

/**
 * The platforms we currently rally with. Order matters — it's the
 * tier order shown on /share.php (smallest → biggest reward).
 *
 * Each entry:
 *   key             — short identifier stored in the DB
 *   label           — display name on the button
 *   tier            — 1..N (used for the "TIER N" badge)
 *   drop_pct        — % drop (5..60) applied to top-K drinks
 *   duration_min    — minutes the crash holds before unwinding
 *   action          — "share" | "review" — drives the button copy
 *   deep_link       — fallback URL if no setting override
 */
function knk_share_platforms(): array {
    return [
        "facebook" => [
            "key"          => "facebook",
            "label"        => "Facebook",
            "tier"         => 1,
            "drop_pct"     => 10,
            "duration_min" => 2,   // ~90s rounded up to 2 minutes
            // delay_sec — how long the queue waits before firing
            // the crash, so it lands just as the guest's post is
            // realistically going live (typing + tapping post).
            "delay_sec"    => 45,
            "action"       => "share",
            "deep_link"    => "https://www.facebook.com/sharer/sharer.php?u=" . rawurlencode("https://knkinn.com/"),
        ],
        "google" => [
            "key"          => "google",
            "label"        => "Google Maps",
            "tier"         => 2,
            "drop_pct"     => 20,
            "duration_min" => 2,
            "delay_sec"    => 90,
            "action"       => "review",
            // If a Google Place ID is configured in settings we
            // build the writereview URL; otherwise we fall back to
            // a search-based URL (knk_share_resolve_url() handles
            // the override).
            "deep_link"    => "https://www.google.com/maps/search/" . rawurlencode("KnK Inn 96 De Tham District 1 Saigon"),
        ],
        // TripAdvisor — enabled 2026-04-28 once the listing was
        // approved. settings.share_url_tripadvisor wins over the
        // deep_link below if Simmo wants to point at the
        // /UserReviewEdit-... URL specifically (deep-links straight
        // to the review form). Default deep_link is the public
        // hotel-review page so guests landing without a settings
        // override still get to the right listing.
        "tripadvisor" => [
            "key"          => "tripadvisor",
            "label"        => "TripAdvisor",
            "tier"         => 3,
            "drop_pct"     => 35,
            "duration_min" => 5,
            "delay_sec"    => 150,
            "action"       => "review",
            "deep_link"    => "https://www.tripadvisor.com/Hotel_Review-g293925-d34346393-Reviews-KnK_Inn_Homestay_Sports_Pub_Rooftop_Garden-Ho_Chi_Minh_City.html",
        ],
    ];
}

/**
 * Resolve the actual URL we redirect a guest to for a platform.
 * Uses settings overrides if present, falls back to deep_link.
 *
 * Setting keys:
 *   share_url_facebook       — full URL or empty (use default)
 *   share_url_google         — full URL or "placeid:XXXXX" or empty
 *   share_url_tripadvisor    — full URL or empty
 */
function knk_share_resolve_url(string $platform): string {
    $platforms = knk_share_platforms();
    if (!isset($platforms[$platform])) return "";
    $default = (string)$platforms[$platform]["deep_link"];

    $override = (string)knk_setting("share_url_" . $platform, "");
    $override = trim($override);
    if ($override === "") return $default;

    // Convenience: if the Google override is just a place ID
    // (no scheme), turn it into the writereview URL.
    if ($platform === "google" && strpos($override, "http") !== 0) {
        if (strpos($override, "placeid:") === 0) {
            $override = substr($override, strlen("placeid:"));
        }
        return "https://search.google.com/local/writereview?placeid=" . rawurlencode($override);
    }
    return $override;
}

/* =========================================================
   ON / OFF — feature toggle
   ========================================================= */

/** Master toggle for the whole share-rally feature. Default on. */
function knk_share_enabled(): bool {
    return knk_setting_bool("share_rally_enabled", true);
}

/* =========================================================
   READ — status / cooldowns / progress
   ========================================================= */

/**
 * Status snapshot for /share.php.
 *
 * Returns:
 *   {
 *     enabled: bool,
 *     window_minutes: int,
 *     platforms: [
 *       {
 *         key, label, tier, drop_pct, duration_min, action,
 *         redirect_url, on_cooldown: bool, cooldown_until: ?string,
 *         tapped_recently: bool   // tapped in the rally window
 *       }, ...
 *     ],
 *     rally_count: int,           // platforms tapped in the window
 *     rally_total: int            // total platforms in the rally
 *   }
 */
function knk_share_status_for(string $email): array {
    $email = strtolower(trim($email));
    $platforms = knk_share_platforms();
    $now = time();

    $out = [
        "enabled"        => knk_share_enabled(),
        "window_minutes" => (int)(KNK_SHARE_RALLY_WINDOW_SEC / 60),
        "platforms"      => [],
        "rally_count"    => 0,
        "rally_total"    => count($platforms),
    ];

    // One query: latest tap per platform for this guest, within
    // the larger of (cooldown window, rally window).
    $latest = [];
    if ($email !== "" && !empty($platforms)) {
        try {
            $stmt = knk_db()->prepare(
                "SELECT platform, MAX(created_at) AS last_at
                   FROM social_share_taps
                  WHERE guest_email = ?
                    AND created_at >= (NOW() - INTERVAL ? SECOND)
                  GROUP BY platform"
            );
            $stmt->execute([
                $email,
                max(KNK_SHARE_PLATFORM_COOLDOWN_SEC, KNK_SHARE_RALLY_WINDOW_SEC),
            ]);
            while ($r = $stmt->fetch()) {
                $latest[(string)$r["platform"]] = (string)$r["last_at"];
            }
        } catch (Throwable $e) {
            error_log("knk_share_status_for: " . $e->getMessage());
        }
    }

    foreach ($platforms as $key => $p) {
        $last_at = $latest[$key] ?? null;
        $last_ts = $last_at ? strtotime($last_at) : 0;

        $cooldown_until = $last_ts > 0
            ? $last_ts + KNK_SHARE_PLATFORM_COOLDOWN_SEC
            : 0;
        $on_cooldown = $cooldown_until > $now;

        $tapped_recently = $last_ts > 0
            && ($now - $last_ts) < KNK_SHARE_RALLY_WINDOW_SEC;
        if ($tapped_recently) $out["rally_count"]++;

        $out["platforms"][] = array_merge($p, [
            "redirect_url"     => knk_share_resolve_url($key),
            "on_cooldown"      => $on_cooldown,
            "cooldown_until"   => $on_cooldown
                ? date("c", $cooldown_until) : null,
            "tapped_recently"  => $tapped_recently,
        ]);
    }
    return $out;
}

/* =========================================================
   WRITE — record one tap, fire the crash
   ========================================================= */

/**
 * Record a guest tap on a platform button. If the cooldown is
 * clear we also fire a market crash on the top-K trending drinks
 * (same path the market-admin "Social crash" button uses).
 *
 * Returns:
 *   ["ok" => bool, "tier" => int, "drop_pct" => int,
 *    "duration_min" => int, "redirect_url" => string,
 *    "message" => string, "items" => [codes...] ]
 *
 * On cooldown: ok=false, message explains. redirect_url still
 * filled in so the page can still send them to the platform if
 * they want (no crash will fire, but we won't pretend the
 * cooldown is a hard block).
 */
function knk_share_record_tap(
    string $email,
    string $platform,
    ?string $ip = null,
    ?string $ua = null
): array {
    $email = strtolower(trim($email));
    $platform = strtolower(trim($platform));

    $platforms = knk_share_platforms();
    if (!isset($platforms[$platform])) {
        return [
            "ok" => false, "tier" => 0, "drop_pct" => 0,
            "duration_min" => 0, "redirect_url" => "",
            "message" => "Unknown platform.", "items" => [],
        ];
    }
    $p = $platforms[$platform];
    $redirect = knk_share_resolve_url($platform);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            "ok" => false, "tier" => 0, "drop_pct" => 0,
            "duration_min" => 0, "redirect_url" => $redirect,
            "message" => "Sign in first to count your share.",
            "items"   => [],
        ];
    }
    if (!knk_share_enabled()) {
        return [
            "ok" => false, "tier" => 0, "drop_pct" => 0,
            "duration_min" => 0, "redirect_url" => $redirect,
            "message" => "Share rally is paused right now.",
            "items"   => [],
        ];
    }

    // Cooldown check.
    try {
        $stmt = knk_db()->prepare(
            "SELECT MAX(created_at) FROM social_share_taps
              WHERE guest_email = ? AND platform = ?"
        );
        $stmt->execute([$email, $platform]);
        $last_at = $stmt->fetchColumn();
        if ($last_at) {
            $age = time() - strtotime((string)$last_at);
            if ($age < KNK_SHARE_PLATFORM_COOLDOWN_SEC) {
                $hours_left = (int)ceil((KNK_SHARE_PLATFORM_COOLDOWN_SEC - $age) / 3600);
                return [
                    "ok" => false, "tier" => (int)$p["tier"],
                    "drop_pct" => 0, "duration_min" => 0,
                    "redirect_url" => $redirect,
                    "message" => "You've already shared on " . $p["label"]
                        . " today. Try again in " . $hours_left . "h.",
                    "items" => [],
                ];
            }
        }
    } catch (Throwable $e) {
        error_log("knk_share_record_tap (cooldown): " . $e->getMessage());
    }

    // ENQUEUE the crash instead of firing it immediately. Drainer
    // (knk_share_drain_pending_crashes — called from
    // /cron/market_tick.php every 5 min) walks the table and
    // applies any rows whose due_at has passed. The delay makes
    // the crash *appear* to land because of the guest's post
    // rather than at the moment they tap the share button.
    //
    // Override: settings.social_share_delay_sec (int seconds) wins
    // over the per-platform default if set. 0 = fire immediately
    // (the legacy behaviour, useful for testing).
    $delay = (int)($p["delay_sec"] ?? 0);
    $override = (int)knk_setting_int("social_share_delay_sec", -1);
    if ($override >= 0) $delay = $override;
    $due_ts = time() + $delay;
    try {
        $stmt = knk_db()->prepare(
            "INSERT INTO social_share_pending_crashes
                (guest_email, platform, tier, drop_pct, duration_min, due_at)
             VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?))"
        );
        $stmt->execute([
            $email, $platform,
            (int)$p["tier"], (int)$p["drop_pct"], (int)$p["duration_min"],
            $due_ts,
        ]);
    } catch (Throwable $e) {
        error_log("knk_share_record_tap (enqueue): " . $e->getMessage());
    }
    // For UX continuity: report the *expected* victim list so the
    // share-page toast can name the drinks that'll crash. The
    // crash hasn't actually fired yet — it'll fire when the cron
    // drains the queue.
    $cfg = knk_market_config();
    $k   = max(1, (int)$cfg["crash_items_max"]);
    $eligible = knk_market_eligible_codes();
    $fired    = array_slice($eligible, 0, $k);

    // Insert the tap row.
    try {
        $stmt = knk_db()->prepare(
            "INSERT INTO social_share_taps
                (guest_email, platform, ip, user_agent, tier,
                 drop_bp, duration_min)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $email,
            $platform,
            $ip !== null ? mb_substr($ip, 0, 45) : null,
            $ua !== null ? mb_substr($ua, 0, 255) : null,
            (int)$p["tier"],
            (int)$p["drop_pct"] * 100,   // store as basis-points-ish for forward compat
            (int)$p["duration_min"],
        ]);
    } catch (Throwable $e) {
        error_log("knk_share_record_tap (insert): " . $e->getMessage());
    }

    // Audit-log so the market admin's events trail can show where
    // the crash came from. delay_sec exposes whether the crash
    // is delayed (queued) or fired immediately.
    try {
        knk_audit("market.share_crash", "social_share_taps", null, [
            "platform"    => $platform,
            "tier"        => (int)$p["tier"],
            "drop_pct"    => (int)$p["drop_pct"],
            "duration_min"=> (int)$p["duration_min"],
            "delay_sec"   => $delay,
            "items"       => $fired,
            "guest_email" => $email,
        ]);
    } catch (Throwable $e) {
        // audit failures are non-fatal
    }

    /* Wording reflects the delay — the crash hasn't happened yet
     * but is about to. If $delay <= 0 (e.g. settings override) we
     * fall back to the old "Boom!" copy. */
    if ($delay > 0) {
        $msg = "Thanks! Post your " . ($p["action"] === "review" ? "review" : "share")
             . " — drinks are about to crash " . (int)$p["drop_pct"]
             . "% on the top " . count($fired) . ".";
    } else {
        $msg = "Boom! " . $p["label"] . " " . ($p["action"] === "review" ? "review" : "share")
             . " counted — " . (int)$p["drop_pct"] . "% off the top "
             . count($fired) . " for " . (int)$p["duration_min"] . " min.";
    }

    return [
        "ok"           => true,
        "tier"         => (int)$p["tier"],
        "drop_pct"     => (int)$p["drop_pct"],
        "duration_min" => (int)$p["duration_min"],
        "delay_sec"    => $delay,
        "redirect_url" => $redirect,
        "message"      => $msg,
        "items"        => $fired,
    ];
}

/* ============================================================
 *   DRAIN — fire any pending crashes whose due_at has passed
 * ============================================================
 *
 * Called from /cron/market_tick.php every 5 min. Walks rows
 * where fired_at IS NULL AND due_at <= NOW(), applies the crash
 * to the top-K trending drinks, marks the row fired.
 *
 * Returns an array of summary rows the caller can log:
 *   [ ["id"=>1, "platform"=>"facebook", "fired"=>["BIA","JUL"]], ... ]
 *
 * Resilient — if any one row fails to apply, we log and skip it
 * but keep going so a single broken row doesn't stall the queue.
 */
function knk_share_drain_pending_crashes(): array {
    if (!function_exists("knk_market_eligible_codes")) {
        require_once __DIR__ . "/market_engine.php";
    }
    $log = [];

    /* Stale-row sweep — kill any pending row whose due_at is more
     * than 30 minutes old. Protects against the scenario where
     * a tap happens right before bar-close: the cron exits early
     * (bar closed), the queue builds up, and at 7:30am the next
     * morning a stack of crashes would otherwise fire on a quiet
     * coffee bar. We mark them fired with empty items so they
     * don't keep matching the SELECT below.
     *
     * Override: settings.social_share_max_age_min (int). 0 = never
     * expire (legacy behaviour). */
    $max_age_min = (int)knk_setting_int("social_share_max_age_min", 30);
    if ($max_age_min > 0) {
        try {
            knk_db()->prepare(
                "UPDATE social_share_pending_crashes
                    SET fired_at = NOW(), fired_items = 'expired'
                  WHERE fired_at IS NULL
                    AND due_at < NOW() - INTERVAL ? MINUTE"
            )->execute([$max_age_min]);
        } catch (Throwable $e) {
            error_log("knk_share_drain (expire): " . $e->getMessage());
        }
    }

    try {
        $stmt = knk_db()->prepare(
            "SELECT id, guest_email, platform, tier, drop_pct, duration_min
               FROM social_share_pending_crashes
              WHERE fired_at IS NULL
                AND due_at <= NOW()
           ORDER BY due_at ASC
              LIMIT 50"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log("knk_share_drain_pending_crashes (read): " . $e->getMessage());
        return $log;
    }
    if (empty($rows)) return $log;

    $cfg = knk_market_config();
    $k   = max(1, (int)$cfg["crash_items_max"]);

    foreach ($rows as $r) {
        $eligible = knk_market_eligible_codes();
        $victims  = array_slice($eligible, 0, $k);
        $fired    = [];
        foreach ($victims as $code) {
            try {
                if (knk_market_apply_crash(
                    $code,
                    (int)$r["drop_pct"],
                    (int)$r["duration_min"],
                    "crash_staff",
                    null
                )) {
                    $fired[] = $code;
                }
            } catch (Throwable $e) {
                error_log("knk_share_drain_pending_crashes (crash {$code}): " . $e->getMessage());
            }
        }
        try {
            $upd = knk_db()->prepare(
                "UPDATE social_share_pending_crashes
                    SET fired_at = NOW(), fired_items = ?
                  WHERE id = ?"
            );
            $upd->execute([implode(",", $fired), (int)$r["id"]]);
        } catch (Throwable $e) {
            error_log("knk_share_drain_pending_crashes (mark): " . $e->getMessage());
        }
        $log[] = [
            "id"       => (int)$r["id"],
            "platform" => (string)$r["platform"],
            "fired"    => $fired,
        ];
    }
    return $log;
}

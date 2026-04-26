<?php
/*
 * KnK Inn — bar opening hours.
 *
 * Single source of truth for "is the bar open right now?" — used by
 * the bar-shell tabs (drinks/music/darts) and cron/market_tick.php
 * to gate activity outside opening hours.
 *
 * Service windows in Saigon time (Asia/Ho_Chi_Minh):
 *
 *   07:30 – 12:30   (morning coffee + brunch)
 *   16:00 – 23:30   (evening bar)
 *
 * Outside those windows:
 *   - bar.php?tab=drinks blocks new orders with a "We're closed" splash
 *     showing the next opening time
 *   - bar.php?tab=music  blocks jukebox submissions (same splash)
 *   - bar.php?tab=darts  blocks darts game start (same splash)
 *   - cron/market_tick.php skips its tick (prices freeze overnight)
 *
 * The TV display (tv.php) deliberately stays on outside hours — it's
 * brand presence and ambient signage, even when the bar's empty.
 *
 * Matbao runs UTC; we always force Asia/Ho_Chi_Minh in the helpers
 * below so the host clock can drift without affecting opening hours.
 */

declare(strict_types=1);

if (!defined("KNK_BAR_TZ")) {
    define("KNK_BAR_TZ", "Asia/Ho_Chi_Minh");
}

/**
 * The two daily GATE windows — what knk_bar_is_open() enforces.
 *
 * These are 30 minutes WIDER than the public-facing hours on each
 * side, on purpose: customers sometimes arrive a bit early or trail
 * out a bit late, and we don't want the gate to slam in their face.
 * The wider window also gives the cron a more reasonable hand-off
 * before/after the bar physically opens or closes.
 *
 * Times use 24h "HH:MM" format; the end is exclusive (you're closed
 * AT that minute).
 */
function knk_bar_windows(): array {
    return [
        ["07:30", "12:30"],
        ["16:00", "23:30"],
    ];
}

/**
 * The PUBLIC-facing service windows — what we tell customers on the
 * "We're closed" splash. Tighter than knk_bar_windows() by 30 min
 * each side; the difference is the buffer that lets early/late
 * arrivals still get through.
 *
 * If you want to advertise different hours, change THIS array (not
 * knk_bar_windows). The gate stays generous.
 */
function knk_bar_public_windows(): array {
    return [
        ["08:00", "12:00"],
        ["16:30", "23:00"],
    ];
}

/** True if the bar is currently open in Saigon time. */
function knk_bar_is_open(?int $unix_now = null): bool {
    $minutes = knk_bar_now_minutes($unix_now);
    foreach (knk_bar_windows() as $w) {
        $s = knk_bar_hhmm_to_minutes($w[0]);
        $e = knk_bar_hhmm_to_minutes($w[1]);
        if ($minutes >= $s && $minutes < $e) return true;
    }
    return false;
}

/**
 * Next PUBLIC opening time as a "HH:MM" string in Saigon time, used
 * by the closed splash. We deliberately use the public windows here
 * (08:00 / 16:30) rather than the wider gate windows (07:30 / 16:00)
 * so the customer-facing message matches the customer-facing hours.
 *
 * The 30-minute buffer means a customer who arrives early sees
 * "Back at 16:30" but the gate actually flips at 16:00 — surprise to
 * the upside, never the other way round.
 */
function knk_bar_next_open_hhmm(?int $unix_now = null): string {
    $minutes = knk_bar_now_minutes($unix_now);
    $windows = knk_bar_public_windows();
    foreach ($windows as $w) {
        $s = knk_bar_hhmm_to_minutes($w[0]);
        if ($s > $minutes) return $w[0];
    }
    // No window left today — first window tomorrow.
    return $windows[0][0];
}

/**
 * The end-of-day close time as "HH:MM". When we're open, this is the
 * close of the current window. When we're closed, this is the close
 * of the next window (i.e. "we'll be open until X").
 */
function knk_bar_window_end_hhmm(?int $unix_now = null): string {
    $minutes = knk_bar_now_minutes($unix_now);
    foreach (knk_bar_windows() as $w) {
        $s = knk_bar_hhmm_to_minutes($w[0]);
        $e = knk_bar_hhmm_to_minutes($w[1]);
        if ($minutes >= $s && $minutes < $e) return $w[1];   // currently open
        if ($s > $minutes) return $w[1];                      // next future window
    }
    // No future window today — first window of tomorrow.
    return knk_bar_windows()[0][1];
}

/* ---------- internals ---------- */

function knk_bar_now_minutes(?int $unix_now = null): int {
    $tz  = new DateTimeZone(KNK_BAR_TZ);
    $now = new DateTimeImmutable("@" . ($unix_now ?? time()));
    $now = $now->setTimezone($tz);
    return (int)$now->format("H") * 60 + (int)$now->format("i");
}

function knk_bar_hhmm_to_minutes(string $hhmm): int {
    $parts = explode(":", $hhmm);
    return (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
}

/**
 * Build the "We're closed" splash HTML (CSS + card) and return it as
 * a string. Used by bar.php to inline the splash inside the bar shell
 * (without exiting), and by knk_bar_render_closed_and_exit() to
 * power the standalone exit-after-render path.
 *
 * $tab_label: optional label for the area (e.g. "Drinks orders"),
 * used in the body copy.
 */
function knk_bar_closed_html(string $tab_label = ""): string {
    $next_open = knk_bar_next_open_hhmm();
    $area = $tab_label !== "" ? htmlspecialchars($tab_label, ENT_QUOTES, "UTF-8") : "The bar";

    /* Build the "Open daily" line from the public windows so the
     * displayed text always matches the source of truth. The dot
     * separator (·) keeps the line tight on a phone width. */
    $window_strs = [];
    foreach (knk_bar_public_windows() as $w) {
        $window_strs[] = $w[0] . ' – ' . $w[1];
    }
    $hours_line = implode(' · ', $window_strs);

    $card = '<div class="knk-closed-card" role="alert">'
          . '<div class="knk-closed-icon" aria-hidden="true">😴</div>'
          . '<h2 class="knk-closed-title">We\'re having a kip</h2>'
          . '<p class="knk-closed-sub">' . $area . ' is closed right now.<br>'
          . 'Back at <strong>' . htmlspecialchars($next_open, ENT_QUOTES, "UTF-8") . '</strong>.</p>'
          . '<p class="knk-closed-hours">Open daily<br>'
          . htmlspecialchars($hours_line, ENT_QUOTES, "UTF-8")
          . '</p>'
          . '</div>';

    $css = '<style>
        .knk-closed-card {
            max-width: 420px; margin: 3rem auto; padding: 2.5rem 1.5rem;
            text-align: center;
            background: linear-gradient(180deg, #1b0f04 0%, #0f0905 100%);
            border: 1px solid rgba(201,170,113,0.35);
            border-radius: 14px;
            color: #f5e9d1;
            font-family: "Inter", system-ui, sans-serif;
        }
        .knk-closed-icon { font-size: 3.4rem; line-height: 1; margin-bottom: 0.6rem; }
        .knk-closed-title {
            font-family: "Archivo Black", sans-serif;
            font-size: 1.7rem; letter-spacing: 0.02em;
            color: #c9aa71; margin: 0 0 0.5rem;
        }
        .knk-closed-sub  { font-size: 1.05rem; line-height: 1.5; margin: 0 0 1.4rem; }
        .knk-closed-sub strong { color: #c9aa71; }
        .knk-closed-hours {
            font-size: 0.78rem; letter-spacing: 0.14em; text-transform: uppercase;
            color: rgba(245,233,209,0.55); margin: 0;
        }
    </style>';

    return $css . $card;
}

/**
 * Render the closed splash and exit. Frame-aware: when called inside
 * the bar shell (KNK_BAR_FRAME defined) it emits only the inner card
 * (the shell will frame it), then exits. When called standalone it
 * wraps with a minimal HTML document.
 *
 * Used by direct entry points (order.php / jukebox.php / darts.php
 * when hit via QR code outside the bar shell).
 */
function knk_bar_render_closed_and_exit(string $tab_label = ""): void {
    $body = knk_bar_closed_html($tab_label);
    if (defined("KNK_BAR_FRAME")) {
        echo $body;
        exit;
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>KnK Inn — Closed</title>'
       . '<link rel="icon" type="image/svg+xml" href="/favicon.svg">'
       . '<link rel="preconnect" href="https://fonts.googleapis.com">'
       . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
       . '<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">'
       . '</head><body style="background:#1b0f04;margin:0;">'
       . $body
       . '</body></html>';
    exit;
}

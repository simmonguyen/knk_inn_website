<?php
/*
 * KnK Inn — /api/social_tap.php
 *
 * One endpoint behind the three platform buttons on /share.php.
 *
 * POST body (form-encoded):
 *   platform   — "facebook" | "google" | "tripadvisor"
 *
 * Response JSON:
 *   {
 *     ok: bool,
 *     tier: int,
 *     drop_pct: int,
 *     duration_min: int,
 *     redirect_url: string,    // open this in a new tab
 *     message: string,         // friendly status to flash
 *     items: [codes...]        // drinks that crashed (for the toast)
 *   }
 *
 * Identity is taken from $_SESSION["order_email"] — the bar-shell
 * bootstrap mints a long-lived anon cookie if no real email is on
 * file, so a brand-new guest can still rally without signing in.
 *
 * Bar-hours gated: if the bar is closed we still let the redirect
 * happen (writing a Google review at lunch is fine!) but no crash
 * fires and the response says so.
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . "/../includes/social_share_store.php";
require_once __DIR__ . "/../includes/hours.php";
require_once __DIR__ . "/../includes/client_ip.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$out = [
    "ok" => false, "tier" => 0, "drop_pct" => 0, "duration_min" => 0,
    "redirect_url" => "", "message" => "", "items" => [],
];

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        throw new RuntimeException("POST only.");
    }
    $email = strtolower(trim((string)($_SESSION["order_email"] ?? "")));
    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("Open the page from /share.php first.");
    }
    $platform = strtolower(trim((string)($_POST["platform"] ?? "")));
    if ($platform === "") {
        throw new RuntimeException("Pick a platform.");
    }

    $ip = knk_real_client_ip();
    $ua = (string)($_SERVER["HTTP_USER_AGENT"] ?? "");

    // Outside service hours we still record the tap (so the rally
    // window state is consistent) and still return the redirect URL,
    // but we don't fire a crash — the price gate is closed and
    // there's nothing to discount.
    if (!knk_bar_is_open()) {
        $redirect = knk_share_resolve_url($platform);
        $out = [
            "ok" => true,
            "tier" => 0,
            "drop_pct" => 0,
            "duration_min" => 0,
            "redirect_url" => $redirect,
            "message" => "Thanks! Your post still helps — the market"
                       . " crashes when we're open.",
            "items" => [],
        ];
    } else {
        $out = knk_share_record_tap($email, $platform, $ip, $ua);
    }
} catch (Throwable $e) {
    $out["message"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

<?php
/*
 * KnK Inn — staff-area i18n.
 *
 * Tiny translation system, just for staff pages (login.php, the
 * top nav, and eventually each admin page). The public marketing
 * site has its own much-richer JSON/JS i18n in /assets/lang/ —
 * this is intentionally separate.
 *
 * Usage:
 *   require_once __DIR__ . "/includes/i18n.php";
 *   echo knk_t("nav.bookings");                // looks up active language
 *   echo knk_t("flash.deleted", ["id" => 7]);  // {id} placeholder
 *
 * Active language resolution (first match wins):
 *   1. Session override   — set by /set-language.php
 *   2. User's `language`  — column on users (en|vi)
 *   3. Cookie             — `knk_lang`, used pre-login on /login.php
 *   4. 'en'               — final fallback
 *
 * Dictionaries live in includes/i18n/<lang>.php and return a
 * flat array keyed by dotted strings. Missing keys fall back to
 * the English dictionary, then to the key itself (so a typo is
 * visible in the page rather than printing nothing).
 */

declare(strict_types=1);

if (!defined("KNK_LANGS_AVAILABLE")) {
    define("KNK_LANGS_AVAILABLE", ["en", "vi"]);
}
if (!defined("KNK_LANG_COOKIE")) {
    define("KNK_LANG_COOKIE", "knk_lang");
}

/** Display label for a language code (used in pickers). */
function knk_lang_label(string $lang): string {
    $map = [
        "en" => "English",
        "vi" => "Tiếng Việt",
    ];
    return $map[$lang] ?? $lang;
}

/** Short 2-letter code for the in-nav toggle button. */
function knk_lang_short(string $lang): string {
    $map = [
        "en" => "EN",
        "vi" => "VI",
    ];
    return $map[$lang] ?? strtoupper($lang);
}

/**
 * Resolve the active staff-area language for the current request.
 * Memoised once per request so it's cheap to call repeatedly.
 */
function knk_current_lang(?array $me = null): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    // 1. Session override (set by /set-language.php).
    if (function_exists("knk_session_start")) {
        knk_session_start();
    } elseif (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $sess = $_SESSION["knk_lang"] ?? "";
    if ($sess !== "" && in_array($sess, KNK_LANGS_AVAILABLE, true)) {
        return $cached = $sess;
    }

    // 2. User's stored default.
    if ($me === null && function_exists("knk_current_user")) {
        $me = knk_current_user();
    }
    if (is_array($me) && !empty($me["language"])) {
        $u_lang = (string)$me["language"];
        if (in_array($u_lang, KNK_LANGS_AVAILABLE, true)) {
            return $cached = $u_lang;
        }
    }

    // 3. Pre-login cookie.
    $cookie = $_COOKIE[KNK_LANG_COOKIE] ?? "";
    if ($cookie !== "" && in_array($cookie, KNK_LANGS_AVAILABLE, true)) {
        return $cached = $cookie;
    }

    return $cached = "en";
}

/** Drop the memoised language — used after we mutate the session. */
function knk_lang_reset_cache(): void {
    // Nothing to clean up directly; the static in knk_current_lang is
    // request-scoped, so just rely on the next request reading fresh.
    // This stub exists so callers have somewhere to point.
}

/** Set the session-level language override (called from /set-language.php). */
function knk_set_session_lang(string $lang): void {
    if (!in_array($lang, KNK_LANGS_AVAILABLE, true)) return;
    if (function_exists("knk_session_start")) {
        knk_session_start();
    } elseif (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $_SESSION["knk_lang"] = $lang;

    // Also drop a cookie so the choice survives logout / login screens.
    $params = session_get_cookie_params();
    setcookie(
        KNK_LANG_COOKIE,
        $lang,
        time() + (180 * 24 * 60 * 60), // 180 days
        $params["path"] ?: "/",
        $params["domain"] ?? "",
        (bool)($params["secure"] ?? !empty($_SERVER["HTTPS"])),
        false // not httponly — the JS toggle on the public site reads it too
    );
}

/** Lazily load + memoise a dictionary for the requested language. */
function knk_lang_dict(string $lang): array {
    static $cache = [];
    if (!in_array($lang, KNK_LANGS_AVAILABLE, true)) $lang = "en";
    if (isset($cache[$lang])) return $cache[$lang];

    $path = __DIR__ . "/i18n/" . $lang . ".php";
    if (!file_exists($path)) {
        return $cache[$lang] = [];
    }
    $data = include $path;
    if (!is_array($data)) $data = [];
    return $cache[$lang] = $data;
}

/**
 * Translate a key. Falls back to en, then to the key itself.
 * Optional $params performs simple {placeholder} substitution.
 */
function knk_t(string $key, array $params = [], ?string $lang = null): string {
    if ($lang === null) $lang = knk_current_lang();

    $dict = knk_lang_dict($lang);
    $val  = $dict[$key] ?? null;

    if ($val === null && $lang !== "en") {
        $en  = knk_lang_dict("en");
        $val = $en[$key] ?? null;
    }
    if ($val === null) $val = $key;

    if ($params) {
        foreach ($params as $k => $v) {
            $val = str_replace("{" . $k . "}", (string)$v, $val);
        }
    }
    return $val;
}

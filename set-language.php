<?php
/*
 * KnK Inn — /set-language.php
 *
 * Tiny endpoint that flips the staff-area language for the current
 * session. Called by the EN/VI toggle in the top nav (and by the
 * pre-login footer link on /login.php).
 *
 * GET ?lang=vi&next=/bookings.php
 *   - validates lang against KNK_LANGS_AVAILABLE
 *   - writes the choice to the session AND a 180-day cookie
 *   - bounces back to ?next= (same-origin path only)
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/i18n.php";

$lang = (string)($_GET["lang"] ?? "");
if (in_array($lang, KNK_LANGS_AVAILABLE, true)) {
    knk_set_session_lang($lang);
}

/* Same-origin redirect target only — never blindly trust ?next=. */
$next = (string)($_GET["next"] ?? "");
if ($next === "" || $next[0] !== "/" || substr($next, 0, 2) === "//") {
    $next = "/";
}
header("Location: " . $next);
exit;

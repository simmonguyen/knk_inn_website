<?php
/*
 * KnK Inn — /logout.php
 * Clear the session and bounce to the login page.
 */
declare(strict_types=1);
require_once __DIR__ . "/includes/auth.php";
knk_logout();
header("Location: /login.php");
exit;

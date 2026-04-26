<?php
/*
 * KnK Inn — /order-admin.php  (legacy redirect → /orders.php)
 *
 * Renamed in #88 (V2 cleanup). This stub catches any cached
 * bookmarks / external links / browser history entries pointing
 * at the old path and 301-redirects to the new location,
 * preserving any query string (?filter=open, ?msg=…).
 *
 * Safe to delete after a few months once the bartender phones'
 * histories age out. Costs ~zero to leave in place.
 */

declare(strict_types=1);

$qs = isset($_SERVER["QUERY_STRING"]) && $_SERVER["QUERY_STRING"] !== ""
    ? "?" . $_SERVER["QUERY_STRING"]
    : "";

http_response_code(301);
header("Location: /orders.php" . $qs);
exit;

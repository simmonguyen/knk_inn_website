<?php
/* --------------------------------------------------------------------
 * Real client IP helper.
 *
 * When knkinn.com sits behind Cloudflare, $_SERVER["REMOTE_ADDR"] is
 * a Cloudflare edge IP, not the actual visitor's IP. Cloudflare
 * passes the real client IP in the CF-Connecting-IP header — but we
 * only trust that header when the request actually came from a known
 * Cloudflare IP. Otherwise, an attacker who reaches our origin
 * server directly could spoof CF-Connecting-IP and bypass the staff
 * IP whitelist.
 *
 * Returns the visitor's IP as a string, or "" when unavailable
 * (CLI, malformed request, loopback).
 *
 * IP ranges below are Cloudflare's published edge ranges; refresh
 * from https://www.cloudflare.com/ips-v4 + /ips-v6 if Cloudflare
 * announces additions.
 * ------------------------------------------------------------------ */

function knk_real_client_ip(): string {
    $remote = (string)($_SERVER["REMOTE_ADDR"] ?? "");
    if ($remote === "") return "";

    $cf = (string)($_SERVER["HTTP_CF_CONNECTING_IP"] ?? "");
    if ($cf === "") return $remote;

    if (knk_ip_is_cloudflare($remote) && filter_var($cf, FILTER_VALIDATE_IP)) {
        return $cf;
    }
    return $remote;
}

function knk_ip_is_cloudflare(string $ip): bool {
    static $v4 = [
        "173.245.48.0/20",  "103.21.244.0/22",  "103.22.200.0/22",
        "103.31.4.0/22",    "141.101.64.0/18",  "108.162.192.0/18",
        "190.93.240.0/20",  "188.114.96.0/20",  "197.234.240.0/22",
        "198.41.128.0/17",  "162.158.0.0/15",   "104.16.0.0/13",
        "104.24.0.0/14",    "172.64.0.0/13",    "131.0.72.0/22",
    ];
    static $v6 = [
        "2400:cb00::/32",  "2606:4700::/32",  "2803:f800::/32",
        "2405:b500::/32",  "2405:8100::/32",  "2a06:98c0::/29",
        "2c0f:f248::/32",
    ];

    if (strpos($ip, ":") !== false) {
        foreach ($v6 as $cidr) {
            if (knk_ip6_in_cidr($ip, $cidr)) return true;
        }
        return false;
    }

    $ipLong = ip2long($ip);
    if ($ipLong === false) return false;
    foreach ($v4 as $cidr) {
        list($net, $bits) = explode("/", $cidr);
        $netLong = ip2long($net);
        $mask = ($bits == 0) ? 0 : (~((1 << (32 - (int)$bits)) - 1)) & 0xFFFFFFFF;
        if (($ipLong & $mask) === ($netLong & $mask)) return true;
    }
    return false;
}

function knk_ip6_in_cidr(string $ip, string $cidr): bool {
    list($net, $bits) = explode("/", $cidr);
    $bits = (int)$bits;
    $ipBin  = inet_pton($ip);
    $netBin = inet_pton($net);
    if ($ipBin === false || $netBin === false) return false;
    $bytes = intdiv($bits, 8);
    $rem   = $bits % 8;
    if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) return false;
    if ($rem === 0) return true;
    $mask = chr((0xFF << (8 - $rem)) & 0xFF);
    return (ord($ipBin[$bytes]) & ord($mask)) === (ord($netBin[$bytes]) & ord($mask));
}

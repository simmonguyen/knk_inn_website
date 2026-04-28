<?php
/*
 * KnK Inn — /api/triplej_now.php
 *
 * Server-side proxy to ABC's "now playing" endpoint for Triple J,
 * so the TV can show the current track under the radio splash card
 * while the bar's queue is empty.
 *
 * Why a proxy and not a direct fetch from the TV:
 *   • CORS — ABC's endpoint doesn't return permissive headers.
 *   • Caching — many TVs in the wild × ABC's API ≠ a great look.
 *   • Resilience — if ABC change the response shape, we adapt
 *     server-side rather than 50 TVs simultaneously breaking.
 *
 * Cache: 45 seconds in /tmp via a tiny file. ABC updates in real-
 * time but the bar TV doesn't need second-by-second.
 *
 * Response:
 *   { ok: true,  artist: "...", title: "...", ts: <unix> }
 *   { ok: false, error: "..." }       (TV hides the chip on falsy)
 *
 * No auth — same posture as the other read-only TV feeds.
 */

declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$out = ["ok" => false, "artist" => "", "title" => "", "ts" => 0, "error" => null];

/* ---- Cache layer ---- */
$cache_file = sys_get_temp_dir() . "/knk_triplej_now.json";
$cache_ttl  = 45; // seconds
if (is_file($cache_file) && (time() - filemtime($cache_file) < $cache_ttl)) {
    $cached = @file_get_contents($cache_file);
    if ($cached !== false && $cached !== "") {
        echo $cached;
        exit;
    }
}

/* ---- Fetch ABC's endpoint ----
 * The published-but-undocumented URL is on music.abcradio.net.au.
 * The exact path has shifted a couple of times over the years; if
 * this 404s, swap the URL below — the TV just falls back to "—". */
$url = "https://music.abcradio.net.au/api/v1/plays/search.json?service=triplej&order=desc&limit=1";

try {
    if (!function_exists("curl_init")) {
        throw new RuntimeException("curl missing");
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "KnKInn-TV/1.0");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Accept: application/json"]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code >= 400 || $resp === "") {
        throw new RuntimeException("ABC returned " . $code);
    }

    $data = json_decode((string)$resp, true);
    if (!is_array($data)) throw new RuntimeException("not json");

    /* Response shape (current): { items: [ { recording: { title, artists: [{name}] }, played_time: "..." } ] }
     * We probe a few plausible paths so a small reshuffle doesn't
     * break us. */
    $artist = "";
    $title  = "";

    $first = $data["items"][0] ?? $data["plays"][0] ?? $data[0] ?? null;
    if (is_array($first)) {
        $rec = $first["recording"] ?? $first["release"] ?? $first;
        if (is_array($rec)) {
            $title = (string)($rec["title"] ?? "");
            $artists = $rec["artists"] ?? null;
            if (is_array($artists) && !empty($artists)) {
                $names = [];
                foreach ($artists as $a) {
                    if (is_array($a) && !empty($a["name"])) $names[] = (string)$a["name"];
                    elseif (is_string($a))                    $names[] = $a;
                }
                $artist = implode(", ", $names);
            } elseif (!empty($rec["artist"])) {
                $artist = (string)$rec["artist"];
            }
        }
        if ($title === "" && !empty($first["title"]))   $title  = (string)$first["title"];
        if ($artist === "" && !empty($first["artist"])) $artist = (string)$first["artist"];
    }

    if ($title === "") throw new RuntimeException("no title in response");

    $out = [
        "ok"     => true,
        "artist" => mb_substr($artist, 0, 120),
        "title"  => mb_substr($title, 0, 200),
        "ts"     => time(),
        "error"  => null,
    ];
} catch (Throwable $e) {
    // Don't 500 the TV — return a clean falsy payload so the chip hides.
    $out["error"] = "lookup_failed";
    error_log("triplej_now: " . $e->getMessage());
}

$json = json_encode($out, JSON_UNESCAPED_UNICODE);

// Stash to cache only if the lookup succeeded — don't cache failures
// so a transient ABC blip doesn't suppress the next 45 s of attempts.
if ($out["ok"]) {
    @file_put_contents($cache_file, $json);
}

echo $json;

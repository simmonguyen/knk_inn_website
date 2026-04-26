<?php
/*
 * KnK Inn — /api/darts_lobby.php
 *
 * One endpoint behind the looking-for-opponent UI on
 * /bar.php?tab=darts. Unified action-based dispatch:
 *
 *   POST action=set_looking [&board_pref=N]
 *      Adds the calling guest to the lobby (or refreshes their
 *      30-min expiry).  Returns the current lobby state.
 *
 *   POST action=clear
 *      Removes the calling guest from the lobby.
 *
 *   POST action=challenge&target_email=…
 *      Fires a pending challenge at the given looker. Drops a
 *      'darts_challenge' notification on them.
 *
 *   POST action=respond&challenge_id=N&accept=1|0
 *      Looker accepts or declines an incoming challenge. On
 *      accept we create a fresh 1v1 501 game on the first free
 *      board, with the looker as host; both players are
 *      redirected to that game id.
 *
 *   POST action=state
 *      Read-only — returns the current lobby state for the calling
 *      guest (am-I-looking, list of others looking, pending
 *      incoming challenges).  Used by the polling loop on the
 *      darts tab.
 *
 * Bar-hours gated. Identity via $_SESSION["order_email"].
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . "/../includes/darts_lobby.php";
require_once __DIR__ . "/../includes/hours.php";
require_once __DIR__ . "/../includes/darts.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

function knk_lobby_state_payload(string $email): array {
    return [
        "am_looking"           => knk_darts_lobby_is_looking($email),
        "lookers"              => knk_darts_lobby_active_lookers($email, 25),
        "incoming_challenges"  => knk_darts_lobby_pending_challenges($email, 5),
        "recent_accept"        => knk_lobby_recent_accept_for($email),
    ];
}

/**
 * If $email has a recently-accepted (last 5 min) challenge that
 * routed them into a fresh game, return {game_id, session_token}
 * so the polling client can set the seat cookie and redirect.
 * Otherwise null. Same payload is sent both to the looker (caller
 * of action=respond) and the challenger (whose state poll picks it
 * up on the next tick).
 */
function knk_lobby_recent_accept_for(string $email): ?array {
    $email = strtolower(trim($email));
    if ($email === "") return null;
    try {
        $pdo = knk_db();
        $stmt = $pdo->prepare(
            "SELECT c.game_id
               FROM darts_challenges c
              WHERE c.status = 'accepted'
                AND c.game_id IS NOT NULL
                AND c.responded_at >= (NOW() - INTERVAL 5 MINUTE)
                AND (c.challenger_email = ? OR c.looker_email = ?)
           ORDER BY c.responded_at DESC LIMIT 1"
        );
        $stmt->execute([$email, $email]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $game_id = (int)$row["game_id"];

        $tStmt = $pdo->prepare(
            "SELECT session_token
               FROM darts_players
              WHERE game_id = ? AND guest_email = ?
              LIMIT 1"
        );
        $tStmt->execute([$game_id, $email]);
        $tok = (string)($tStmt->fetchColumn() ?: "");
        if ($tok === "") return null;

        return [
            "game_id"       => $game_id,
            "session_token" => $tok,
        ];
    } catch (Throwable $e) {
        error_log("knk_lobby_recent_accept_for: " . $e->getMessage());
        return null;
    }
}

/**
 * Set the per-game seat cookie so /darts.php?game=N recognises this
 * browser as already-seated. Mirrors the same cookie /api/darts_join.php
 * sets for joiners (name "darts_token_<game_id>", path=/, ~1 day).
 */
function knk_lobby_set_seat_cookie(int $game_id, string $token): void {
    if ($game_id <= 0 || $token === "") return;
    $secure = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";
    setcookie("darts_token_" . $game_id, $token, [
        "expires"  => time() + 86400,
        "path"     => "/",
        "secure"   => $secure,
        "httponly" => false, /* JS in /bar.php sometimes reads it for
                              * route redirects; the token is short-
                              * lived per-game, low-stakes. */
        "samesite" => "Lax",
    ]);
}

$out = [
    "ok"      => false,
    "error"   => null,
    "action"  => "",
    "state"   => null,
    "game_id" => null,
];

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        throw new RuntimeException("POST only.");
    }
    $email = strtolower(trim((string)($_SESSION["order_email"] ?? "")));
    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("Open the page from /bar.php first.");
    }
    if (!knk_bar_is_open()) {
        // The lobby state read still works (so the UI can show the
        // closed banner properly), but no writes when the bar is
        // closed.
        $action = (string)($_POST["action"] ?? "state");
        if ($action !== "state") {
            throw new RuntimeException("The bar's closed — try again at opening time.");
        }
    }

    $action = (string)($_POST["action"] ?? "");
    $out["action"] = $action;

    if ($action === "set_looking") {
        $bp_raw = $_POST["board_pref"] ?? "";
        $bp = ($bp_raw === "" || $bp_raw === null) ? null : (int)$bp_raw;
        $id = knk_darts_lobby_set_looking($email, $bp);
        if (!$id) throw new RuntimeException("Couldn't enter the lobby — try again.");
        $out["ok"] = true;
        $out["state"] = knk_lobby_state_payload($email);

    } elseif ($action === "clear") {
        knk_darts_lobby_clear($email);
        $out["ok"] = true;
        $out["state"] = knk_lobby_state_payload($email);

    } elseif ($action === "challenge") {
        $target = strtolower(trim((string)($_POST["target_email"] ?? "")));
        if (!filter_var($target, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Bad target email.");
        }
        if ($target === $email) {
            throw new RuntimeException("Can't challenge yourself.");
        }
        $cid = knk_darts_lobby_challenge_create($email, $target);
        if (!$cid) {
            throw new RuntimeException("Couldn't fire the challenge — they may have left the lobby.");
        }
        $out["ok"] = true;
        $out["state"] = knk_lobby_state_payload($email);

    } elseif ($action === "respond") {
        $cid    = (int)($_POST["challenge_id"] ?? 0);
        $accept = !empty($_POST["accept"]);
        $resp = knk_darts_lobby_challenge_respond($cid, $email, $accept);
        if (!$resp["ok"]) {
            throw new RuntimeException($resp["error"] ?? "Couldn't respond.");
        }
        $out["ok"] = true;
        $out["game_id"] = $resp["game_id"];
        // On accept: set the looker's seat cookie BEFORE the state
        // payload is built so the same response that surfaces the
        // game_id also primes the cookie for the redirect.
        if ($accept && !empty($resp["game_id"])) {
            $tStmt = knk_db()->prepare(
                "SELECT session_token FROM darts_players
                  WHERE game_id = ? AND guest_email = ? LIMIT 1"
            );
            $tStmt->execute([(int)$resp["game_id"], $email]);
            $myTok = (string)($tStmt->fetchColumn() ?: "");
            knk_lobby_set_seat_cookie((int)$resp["game_id"], $myTok);
        }
        $out["state"] = knk_lobby_state_payload($email);

    } elseif ($action === "state") {
        $out["ok"] = true;
        $out["state"] = knk_lobby_state_payload($email);

    } else {
        throw new RuntimeException("Unknown action.");
    }
} catch (Throwable $e) {
    $out["error"] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

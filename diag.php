<?php
/*
 * KnK Inn — /diag.php  (one-shot diagnostic, DELETE AFTER USE).
 *
 * Renders the state of the darts lobby + game tables so we can
 * trace the SQLSTATE 1062 'Duplicate entry "X-1"' surfaced by the
 * Looking-for-Opponent challenge accept flow.
 *
 * Gated by config.php's admin_password. Reads only.
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/db.php";

header("Content-Type: text/plain; charset=utf-8");
header("Cache-Control: no-store");

$cfg   = knk_config();
$guard = $cfg["admin_password"] ?? "";
$key   = $_GET["key"] ?? "";
if ($guard === "" || !hash_equals($guard, (string)$key)) {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

function run(string $label, string $sql, array $params = []): void {
    echo "==== {$label} ====\n{$sql}\n";
    if ($params) echo "params: " . json_encode($params) . "\n";
    try {
        $stmt = knk_db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        echo "rows: " . count($rows) . "\n";
        foreach ($rows as $r) {
            echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
        }
    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

echo "KnK Inn diag — " . date("c") . "\n\n";

run("schema_migrations (last 8)",
    "SELECT name, applied_at FROM schema_migrations ORDER BY id DESC LIMIT 8");

run("darts_lobby + darts_challenges tables exist?",
    "SELECT TABLE_NAME, TABLE_ROWS
       FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('darts_lobby','darts_challenges','darts_games','darts_players')
      ORDER BY TABLE_NAME");

run("darts_games — last 12, all statuses",
    "SELECT id, board_id, status, current_slot_no, finished_at, created_at
       FROM darts_games ORDER BY id DESC LIMIT 12");

run("darts_games — currently 'lobby' or 'playing'",
    "SELECT id, board_id, status, current_slot_no, created_at
       FROM darts_games WHERE status IN ('lobby','playing') ORDER BY id DESC");

run("darts_players — for active games (lobby/playing)",
    "SELECT p.game_id, p.slot_no, p.team_no, p.is_host, p.name,
            LEFT(p.guest_email, 40) AS email_l40, p.created_at
       FROM darts_players p
       JOIN darts_games g ON g.id = p.game_id
      WHERE g.status IN ('lobby','playing')
      ORDER BY p.game_id DESC, p.slot_no");

run("darts_players — slot collisions (should be 0 rows)",
    "SELECT game_id, slot_no, COUNT(*) c
       FROM darts_players
      GROUP BY game_id, slot_no HAVING c > 1");

run("darts_lobby — current lookers",
    "SELECT id, looker_email, display_name, board_pref, expires_at, created_at
       FROM darts_lobby ORDER BY id DESC LIMIT 20");

run("darts_challenges — last 10",
    "SELECT id, challenger_email, looker_email, status, game_id, created_at, responded_at
       FROM darts_challenges ORDER BY id DESC LIMIT 10");

run("darts_boards — config",
    "SELECT id, name, enabled, sort_order FROM darts_boards ORDER BY sort_order, id");

echo "==== done ====\n";

<?php
/*
 * KnK Inn — migration runner.
 *
 * Hit this URL with ?key=<admin_password> to apply any new migration
 * files in includes/migrations/. Idempotent — migrations already
 * recorded in schema_migrations are skipped.
 *
 * Example:
 *   https://knkinn.com/migrate.php?key=...
 *
 * Remove the file (or tighten the guard) once the V2 build stabilises.
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/db.php";

header("Content-Type: text/plain; charset=utf-8");

$cfg = knk_config();
$guard = $cfg["admin_password"] ?? "";
$key   = $_GET["key"] ?? "";

if ($guard === "" || !hash_equals($guard, $key)) {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

try {
    $applied = knk_migrate();
    if (empty($applied)) {
        echo "No new migrations. Schema is up to date.\n";
    } else {
        echo "Applied migrations:\n";
        foreach ($applied as $f) echo "  - {$f}\n";
    }

    // Quick sanity summary
    $pdo = knk_db();
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN, 0);
    echo "\nTables now in DB (" . count($tables) . "):\n";
    foreach ($tables as $t) echo "  - {$t}\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}

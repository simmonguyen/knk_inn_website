<?php
/*
 * KnK Inn — database helper.
 *
 * One place to open a PDO connection to the Matbao MariaDB
 * (or any MySQL-compatible server configured under config.php's "db" key).
 *
 * Usage:
 *   $pdo = knk_db();
 *   $stmt = $pdo->prepare("SELECT * FROM guests WHERE email = ?");
 *   $stmt->execute([$email]);
 *   $guest = $stmt->fetch();
 *
 * The PDO instance is memoised for the request, so calling knk_db()
 * repeatedly is cheap.
 */

if (!function_exists("knk_config")) {
    function knk_config(): array {
        static $cfg = null;
        if ($cfg === null) {
            $path = __DIR__ . "/../config.php";
            if (!file_exists($path)) {
                throw new RuntimeException("config.php not found — copy config.example.php and fill it in.");
            }
            $cfg = require $path;
            if (!is_array($cfg)) {
                throw new RuntimeException("config.php must return an array.");
            }
        }
        return $cfg;
    }
}

/**
 * Get a memoised PDO connection. Throws RuntimeException if "db" is missing
 * or the connection fails (so callers can catch and render a helpful page).
 */
function knk_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $cfg = knk_config();
    $db  = $cfg["db"] ?? null;
    if (!is_array($db)) {
        throw new RuntimeException("Missing 'db' section in config.php.");
    }

    $host    = $db["host"]     ?? "localhost";
    $name    = $db["name"]     ?? "";
    $user    = $db["user"]     ?? "";
    $pass    = $db["password"] ?? "";
    $charset = $db["charset"]  ?? "utf8mb4";

    if ($name === "" || $user === "") {
        throw new RuntimeException("config.php db.name / db.user must be set.");
    }

    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+07:00'",  // Saigon
    ];

    $pdo = new PDO($dsn, $user, $pass, $options);
    return $pdo;
}

/**
 * Apply all SQL files in includes/migrations/ that have a numeric prefix,
 * in ascending order. Tracks applied migrations in the `schema_migrations`
 * table. Safe to run many times.
 *
 * Returns a list of files that were applied this run.
 */
function knk_migrate(): array {
    $pdo = knk_db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        filename    VARCHAR(190) NOT NULL PRIMARY KEY,
        applied_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $applied = [];
    $dir = __DIR__ . "/migrations";
    $files = glob($dir . "/[0-9]*.sql") ?: [];
    sort($files, SORT_STRING);

    $already = [];
    $stmt = $pdo->query("SELECT filename FROM schema_migrations");
    foreach ($stmt as $row) $already[$row["filename"]] = true;

    foreach ($files as $path) {
        $base = basename($path);
        if (isset($already[$base])) continue;

        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("Could not read migration $base");
        }

        // MySQL driver does not reliably accept multi-statement strings,
        // so split on semicolon-at-end-of-line outside of strings. For the
        // relatively simple DDL in our migrations this is good enough;
        // if we ever need stored procedures we'll switch to a proper parser.
        $statements = knk_split_sql($sql);
        foreach ($statements as $stmt_sql) {
            $stmt_sql = trim($stmt_sql);
            if ($stmt_sql === "") continue;
            $pdo->exec($stmt_sql);
        }

        $pdo->prepare("INSERT INTO schema_migrations (filename) VALUES (?)")
            ->execute([$base]);
        $applied[] = $base;
    }

    return $applied;
}

/** Split a SQL file on semicolons that end a statement, ignoring
 *  semicolons inside single-quoted strings and line comments. */
function knk_split_sql(string $sql): array {
    $out = [];
    $buf = "";
    $len = strlen($sql);
    $in_string = false;
    $i = 0;
    while ($i < $len) {
        $c = $sql[$i];

        // line comment
        if (!$in_string && $c === "-" && $i+1 < $len && $sql[$i+1] === "-") {
            while ($i < $len && $sql[$i] !== "\n") { $buf .= $sql[$i]; $i++; }
            continue;
        }
        // block comment /* ... */
        if (!$in_string && $c === "/" && $i+1 < $len && $sql[$i+1] === "*") {
            $buf .= "/*";
            $i += 2;
            while ($i < $len && !($sql[$i] === "*" && $i+1 < $len && $sql[$i+1] === "/")) {
                $buf .= $sql[$i];
                $i++;
            }
            if ($i < $len) { $buf .= "*/"; $i += 2; }
            continue;
        }

        if ($c === "'") {
            // toggle, handling escaped '' and \'
            $buf .= $c;
            $i++;
            if ($in_string) {
                if ($i < $len && $sql[$i] === "'") {   // escaped ''
                    $buf .= "'"; $i++;
                    continue;
                }
                $in_string = false;
            } else {
                $in_string = true;
            }
            continue;
        }

        if ($c === "\\" && $in_string && $i+1 < $len) {
            $buf .= $c . $sql[$i+1];
            $i += 2;
            continue;
        }

        if ($c === ";" && !$in_string) {
            $out[] = $buf;
            $buf = "";
            $i++;
            continue;
        }

        $buf .= $c;
        $i++;
    }
    if (trim($buf) !== "") $out[] = $buf;
    return $out;
}

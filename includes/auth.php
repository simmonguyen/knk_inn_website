<?php
/*
 * KnK Inn — authentication & role guards.
 *
 * Drop this at the top of any staff page:
 *
 *   require_once __DIR__ . "/includes/auth.php";
 *   $me = knk_require_role(["super_admin", "owner", "reception"]);
 *
 * Four roles (see /includes/migrations/001_initial_schema.sql):
 *   super_admin  — Ben. Everything, including user management.
 *   owner        — Simmo. All data, no user management.
 *   reception    — Hotel Reception. Bookings + guest profiles.
 *   bartender    — Bartender / Hostess. Orders + drinks histories.
 *
 * Session keys we set on login:
 *   $_SESSION["knk_user_id"]     int
 *   $_SESSION["knk_user_role"]   enum string
 *   $_SESSION["knk_user_email"]  string (for display)
 *   $_SESSION["knk_user_name"]   string (for display)
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";

/* --------------------------------------------------------------------
 * Session bootstrap — long-lived cookie so Simmo isn't forced to
 * re-login mid-shift. 30 days.
 * ------------------------------------------------------------------ */
function knk_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $lifetime = 30 * 24 * 60 * 60; // 30 days
    session_set_cookie_params([
        "lifetime" => $lifetime,
        "path"     => "/",
        "httponly" => true,
        "samesite" => "Lax",
        "secure"   => !empty($_SERVER["HTTPS"]),
    ]);
    ini_set("session.gc_maxlifetime", (string)$lifetime);
    session_start();
}

/* --------------------------------------------------------------------
 * Load the currently-logged-in user (or null).
 * Re-checks the DB each request so a deactivated user is kicked out
 * immediately, but the result is memoised for the request.
 * ------------------------------------------------------------------ */
function knk_current_user(): ?array {
    static $cached = false;
    if ($cached !== false) return $cached;

    knk_session_start();
    $uid = $_SESSION["knk_user_id"] ?? null;
    if (!$uid) return $cached = null;

    $stmt = knk_db()->prepare(
        "SELECT id, email, name, role, active, created_at, last_login_at
         FROM users WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$uid]);
    $row = $stmt->fetch();

    if (!$row || !(int)$row["active"]) {
        // Account vanished or was deactivated — clear the session.
        $_SESSION = [];
        return $cached = null;
    }

    return $cached = $row;
}

/* --------------------------------------------------------------------
 * Try to log in with email + password. Returns ["ok" => bool, ...].
 * On success, writes the session keys and returns the user row.
 * ------------------------------------------------------------------ */
function knk_login_attempt(string $email, string $password): array {
    $email = strtolower(trim($email));
    if ($email === "" || $password === "") {
        return ["ok" => false, "error" => "Enter both email and password."];
    }

    $pdo  = knk_db();
    $stmt = $pdo->prepare(
        "SELECT id, email, name, role, password_hash, active
         FROM users WHERE email = ? LIMIT 1"
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    // Generic error wording — don't leak which accounts exist.
    $generic = ["ok" => false, "error" => "Wrong email or password."];

    if (!$row) return $generic;
    if (!(int)$row["active"]) {
        return ["ok" => false, "error" => "That account has been deactivated. Ask Ben to re-enable it."];
    }
    if (!password_verify($password, $row["password_hash"])) return $generic;

    // Rehash if PHP has since upgraded the default algorithm.
    if (password_needs_rehash($row["password_hash"], PASSWORD_BCRYPT)) {
        $new_hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([$new_hash, $row["id"]]);
    }

    $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")
        ->execute([$row["id"]]);

    knk_session_start();
    session_regenerate_id(true);
    $_SESSION["knk_user_id"]    = (int)$row["id"];
    $_SESSION["knk_user_role"]  = $row["role"];
    $_SESSION["knk_user_email"] = $row["email"];
    $_SESSION["knk_user_name"]  = $row["name"];

    knk_audit("user.login", "users", (string)$row["id"], [
        "email" => $row["email"],
        "role"  => $row["role"],
    ]);

    return ["ok" => true, "user" => $row];
}

/* --------------------------------------------------------------------
 * Destroy the session cleanly.
 * ------------------------------------------------------------------ */
function knk_logout(): void {
    knk_session_start();
    $uid = $_SESSION["knk_user_id"] ?? null;
    if ($uid) {
        knk_audit("user.logout", "users", (string)$uid);
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), "", time() - 42000,
            $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    session_destroy();
}

/* --------------------------------------------------------------------
 * Gate a page. Redirects to /login.php if not logged in.
 * ------------------------------------------------------------------ */
function knk_require_login(): array {
    $u = knk_current_user();
    if (!$u) {
        $next = $_SERVER["REQUEST_URI"] ?? "/";
        header("Location: /login.php?next=" . urlencode($next));
        exit;
    }
    return $u;
}

/* --------------------------------------------------------------------
 * Gate a page to a set of roles. Shows a friendly 403 otherwise.
 * ------------------------------------------------------------------ */
function knk_require_role(array $roles): array {
    $u = knk_require_login();
    if (!in_array($u["role"], $roles, true)) {
        http_response_code(403);
        $role_label = htmlspecialchars(knk_role_label($u["role"]));
        $home       = htmlspecialchars(knk_role_home($u["role"]));
        echo "<!doctype html><meta charset=utf-8><title>403 — Not allowed</title>"
           . "<style>body{font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem;color:#2a1a08}"
           . "a{color:#b38a3b}</style>"
           . "<h1>403 — Not allowed</h1>"
           . "<p>You're signed in as <strong>{$role_label}</strong>, which doesn't have access to this page.</p>"
           . "<p><a href=\"{$home}\">Back to your dashboard</a> · <a href=\"/logout.php\">Log out</a></p>";
        exit;
    }
    return $u;
}

/* --------------------------------------------------------------------
 * Small display helpers.
 * ------------------------------------------------------------------ */
function knk_role_label(string $role): string {
    return [
        "super_admin" => "Super Admin",
        "owner"       => "Owner",
        "reception"   => "Hotel Reception",
        "bartender"   => "Bartender / Hostess",
    ][$role] ?? $role;
}

/** Where should this role land after login? */
function knk_role_home(string $role): string {
    if ($role === "bartender") return "/order-admin.php";
    return "/bookings.php";
}

/** Which pages should each role see in the nav bar? */
function knk_role_nav(string $role): array {
    $bookings = ["href" => "/bookings.php",                "label" => "Bookings"];
    $orders   = ["href" => "/order-admin.php",             "label" => "Orders"];
    $guests   = ["href" => "/bookings.php?tab=guests",     "label" => "Guests"];
    $sales    = ["href" => "/sales.php",                   "label" => "Sales"];
    $menu     = ["href" => "/menu.php",                    "label" => "Menu"];
    $market   = ["href" => "/market-admin.php",            "label" => "Market"];
    $jukebox  = ["href" => "/jukebox-admin.php",           "label" => "Jukebox"];
    $darts    = ["href" => "/darts-admin.php",             "label" => "Darts"];
    $photos   = ["href" => "/photos.php",                  "label" => "Photos"];
    $settings = ["href" => "/settings.php",                "label" => "Settings"];
    $users    = ["href" => "/users.php",                   "label" => "Users"];
    switch ($role) {
        case "super_admin": return [$bookings, $orders, $guests, $sales, $menu, $market, $jukebox, $darts, $photos, $settings, $users];
        case "owner":       return [$bookings, $orders, $guests, $sales, $menu, $market, $jukebox, $darts, $photos];
        case "reception":   return [$bookings];
        case "bartender":   return [$orders, $jukebox, $darts];
        default:            return [];
    }
}

/* --------------------------------------------------------------------
 * Are there any users yet? /setup.php uses this to self-disable once
 * the first Super Admin has been created.
 * ------------------------------------------------------------------ */
function knk_users_exist(): bool {
    try {
        $pdo = knk_db();
        return (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0;
    } catch (Throwable $e) {
        // Table probably doesn't exist yet — treat as "no users".
        return false;
    }
}

/* --------------------------------------------------------------------
 * Create a user. Throws RuntimeException on bad input or duplicate email.
 * Returns the new user id.
 * ------------------------------------------------------------------ */
function knk_create_user(string $email, string $name, string $password, string $role): int {
    $email = strtolower(trim($email));
    $name  = trim($name);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("Enter a valid email address.");
    }
    if ($name === "") {
        throw new RuntimeException("Enter a name.");
    }
    if (strlen($password) < 8) {
        throw new RuntimeException("Password must be at least 8 characters.");
    }
    if (!in_array($role, ["super_admin", "owner", "reception", "bartender"], true)) {
        throw new RuntimeException("Unknown role: {$role}");
    }

    $pdo = knk_db();

    // Reject duplicates up-front with a friendlier message than the DB error.
    $check = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $check->execute([$email]);
    if ($check->fetch()) {
        throw new RuntimeException("Someone is already using that email.");
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare(
        "INSERT INTO users (email, name, password_hash, role, active, created_at)
         VALUES (?, ?, ?, ?, 1, NOW())"
    );
    $stmt->execute([$email, $name, $hash, $role]);
    $id = (int)$pdo->lastInsertId();

    knk_audit("user.create", "users", (string)$id, [
        "email" => $email,
        "role"  => $role,
    ]);
    return $id;
}

/* --------------------------------------------------------------------
 * Tiny audit log helper — every login/logout/user create/role change.
 * Failures are swallowed; auditing must never break the main flow.
 * ------------------------------------------------------------------ */
function knk_audit(string $action, ?string $entity = null, ?string $entity_id = null, array $meta = []): void {
    try {
        knk_session_start();
        $actor = $_SESSION["knk_user_id"] ?? null;
        $ip    = $_SERVER["REMOTE_ADDR"] ?? null;
        $stmt  = knk_db()->prepare(
            "INSERT INTO audit_log (user_id, action, entity, entity_id, details, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $actor, $action, $entity, $entity_id,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            $ip,
        ]);
    } catch (Throwable $e) {
        // Don't blow up the page just because we failed to log.
    }
}

/* --------------------------------------------------------------------
 * Render a small top nav bar on admin pages so staff know who they
 * are and can jump to the other pages they're allowed to see.
 * Echo directly — meant to sit just inside <body>.
 * ------------------------------------------------------------------ */
function knk_render_admin_nav(array $me): void {
    $name  = htmlspecialchars($me["name"]);
    $role  = htmlspecialchars(knk_role_label($me["role"]));
    $links = knk_role_nav($me["role"]);
    $current_script = $_SERVER["SCRIPT_NAME"] ?? "";
    // For query-string-aware active-state (e.g. bookings.php?tab=guests),
    // pick out the tab/filter keys from the current URL.
    $current_tab    = $_GET["tab"]    ?? "";
    $current_filter = $_GET["filter"] ?? "";
    ?>
    <nav class="knk-admin-nav">
      <div class="knk-admin-nav__brand">
        <strong>KnK</strong> <em>Staff</em>
      </div>
      <div class="knk-admin-nav__links">
        <?php foreach ($links as $l):
            // Split href into path + query so we can match both parts.
            $href = $l["href"];
            $qs_pos = strpos($href, "?");
            $link_path = $qs_pos === false ? $href : substr($href, 0, $qs_pos);
            $link_tab  = "";
            if ($qs_pos !== false) {
                parse_str(substr($href, $qs_pos + 1), $link_qs);
                $link_tab = $link_qs["tab"] ?? "";
            }
            $path_needle = ltrim($link_path, "/");
            $path_match  = (substr($current_script, -strlen($path_needle)) === $path_needle);
            // Same path — match only if the tabs agree.
            $tab_match = ($link_tab === "" && $current_tab === "")
                      || ($link_tab !== "" && $link_tab === $current_tab);
            $active = ($path_match && $tab_match) ? " is-active" : "";
            ?>
          <a class="knk-admin-nav__link<?= $active ?>" href="<?= htmlspecialchars($l["href"]) ?>">
            <?= htmlspecialchars($l["label"]) ?>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="knk-admin-nav__me">
        <span class="knk-admin-nav__user"><?= $name ?> <span class="knk-admin-nav__role">· <?= $role ?></span></span>
        <a class="knk-admin-nav__logout" href="/logout.php">Log out</a>
      </div>
    </nav>
    <style>
      .knk-admin-nav {
        display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap;
        padding: 0.75rem 1.25rem; background: rgba(24,12,3,0.85);
        border-bottom: 1px solid rgba(201,170,113,0.22);
        font-family: "Inter", system-ui, sans-serif; font-size: 0.9rem;
        color: var(--cream, #f5e9d1);
      }
      .knk-admin-nav__brand { font-family: "Archivo Black", sans-serif; letter-spacing: .04em; }
      .knk-admin-nav__brand em { color: var(--gold, #c9aa71); font-style: normal; }
      .knk-admin-nav__links { display: flex; gap: 0.25rem; flex: 1; min-width: 0; flex-wrap: wrap; }
      .knk-admin-nav__link {
        color: var(--cream-dim, #d8c9ab); text-decoration: none;
        padding: 0.35rem 0.75rem; border-radius: 4px; font-weight: 500;
      }
      .knk-admin-nav__link:hover { background: rgba(201,170,113,0.12); color: var(--cream, #f5e9d1); }
      .knk-admin-nav__link.is-active { background: var(--gold, #c9aa71); color: var(--brown-deep, #2a1a08); }
      .knk-admin-nav__me { display: flex; gap: 0.9rem; align-items: center; }
      .knk-admin-nav__user { color: var(--cream, #f5e9d1); }
      .knk-admin-nav__role { color: var(--cream-dim, #d8c9ab); font-weight: 400; }
      .knk-admin-nav__logout {
        color: var(--gold, #c9aa71); text-decoration: none; font-weight: 600;
        border: 1px solid rgba(201,170,113,0.35); padding: 0.3rem 0.7rem; border-radius: 4px;
      }
      .knk-admin-nav__logout:hover { background: var(--gold, #c9aa71); color: var(--brown-deep, #2a1a08); }
      @media (max-width: 640px) {
        .knk-admin-nav { gap: 0.75rem; }
        .knk-admin-nav__me { width: 100%; justify-content: space-between; }
      }
    </style>
    <?php
}

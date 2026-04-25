<?php
/*
 * KnK Inn — authentication & permission guards.
 *
 * Drop this at the top of any staff page:
 *
 *   require_once __DIR__ . "/includes/auth.php";
 *   $me = knk_require_permission("bookings");
 *
 * Each user has nine on/off permissions (see migration 015):
 *   bookings · orders · guests · sales · menu · market ·
 *   jukebox  · darts  · photos
 *
 * Defaults are pre-filled from their role at creation time,
 * then editable per-user from /users.php. Super Admins always
 * pass every permission check — protects against self-lockout.
 *
 * The four roles still exist for default-pickng, the user-
 * management screen, and the system-settings screen:
 *   super_admin  — Ben. Everything, including user management.
 *   owner        — Simmo. All data, no user management.
 *   reception    — Hotel Reception.
 *   bartender    — Bartender / Hostess.
 *
 * Settings + Users are role-gated to super_admin only — they
 * deliberately don't appear in the per-user toggle matrix.
 *
 * Session keys we set on login:
 *   $_SESSION["knk_user_id"]     int
 *   $_SESSION["knk_user_role"]   enum string
 *   $_SESSION["knk_user_email"]  string (for display)
 *   $_SESSION["knk_user_name"]   string (for display)
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/i18n.php";

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

    /* `language` is best-effort — older deployments without migration 016
     * fall back to a SELECT without it. */
    try {
        $stmt = knk_db()->prepare(
            "SELECT id, email, name, role, `language`, active, created_at, last_login_at
             FROM users WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        $stmt = knk_db()->prepare(
            "SELECT id, email, name, role, active, created_at, last_login_at
             FROM users WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
    }

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
 *
 * Most pages should now use knk_require_permission() instead — roles
 * still gate the user-management + system-settings screens (Super
 * Admin only) where a stray permission row must never be able to
 * grant access.
 * ------------------------------------------------------------------ */
function knk_require_role(array $roles): array {
    $u = knk_require_login();
    if (!in_array($u["role"], $roles, true)) {
        knk_render_403($u);
    }
    return $u;
}

/* --------------------------------------------------------------------
 * Gate a page to a single permission key (see knk_permissions()).
 * Super Admins always pass — protects against accidental self-lockout
 * and matches the "Super-Admin always Yes, locked" rule in the UI.
 * ------------------------------------------------------------------ */
function knk_require_permission(string $permission): array {
    $u = knk_require_login();
    if (!knk_user_can($u, $permission)) {
        knk_render_403($u);
    }
    return $u;
}

/* --------------------------------------------------------------------
 * Shared 403 page. Sends the response and exit()s.
 * ------------------------------------------------------------------ */
function knk_render_403(array $u): void {
    http_response_code(403);
    $role_label = htmlspecialchars(knk_role_label($u["role"]));
    $home       = htmlspecialchars(knk_role_home_for($u));
    $title      = htmlspecialchars(knk_t("403.title"));
    $body_html  = knk_t("403.body", ["role" => $role_label]); // already-escaped role
    $back       = htmlspecialchars(knk_t("403.back"));
    $logout     = htmlspecialchars(knk_t("403.logout"));
    echo "<!doctype html><meta charset=utf-8><title>{$title}</title>"
       . "<style>body{font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem;color:#2a1a08}"
       . "a{color:#b38a3b}</style>"
       . "<h1>{$title}</h1>"
       . "<p>{$body_html}</p>"
       . "<p><a href=\"{$home}\">{$back}</a> · <a href=\"/logout.php\">{$logout}</a></p>";
    exit;
}

/* --------------------------------------------------------------------
 * Small display helpers.
 * ------------------------------------------------------------------ */
function knk_role_label(string $role): string {
    if (!in_array($role, ["super_admin", "owner", "reception", "bartender"], true)) {
        return $role;
    }
    return knk_t("role." . $role);
}

/** Where should this role land after login? Falls back to a sensible default. */
function knk_role_home(string $role): string {
    if ($role === "bartender") return "/order-admin.php";
    return "/bookings.php";
}

/** Where should this specific user land after login?
 *  Picks the first nav item they can actually see, so we never bounce
 *  someone into a 403 right after they type their password. */
function knk_role_home_for(array $me): string {
    $links = knk_user_nav($me);
    if (!empty($links)) return $links[0]["href"];
    // No permissions at all — fall back to the role-level guess.
    return knk_role_home($me["role"]);
}

/* --------------------------------------------------------------------
 * Permission system.
 * The nine toggleable permissions mirror the matrix in users.php
 * (see migration 015). Settings + Users stay Super-Admin-only.
 * ------------------------------------------------------------------ */
function knk_permissions(): array {
    return ["bookings", "orders", "guests", "sales", "menu", "market", "jukebox", "darts", "photos"];
}

function knk_permission_label(string $permission): string {
    $labels = [
        "bookings" => "Bookings",
        "orders"   => "Orders",
        "guests"   => "Guests",
        "sales"    => "Sales",
        "menu"     => "Menu",
        "market"   => "Market",
        "jukebox"  => "Jukebox",
        "darts"    => "Darts",
        "photos"   => "Photos",
    ];
    return $labels[$permission] ?? $permission;
}

/** Default ON/OFF matrix per role — must mirror migration 015. */
function knk_role_default_permissions(string $role): array {
    $matrix = [
        "super_admin" => [
            "bookings" => 1, "orders" => 1, "guests" => 1, "sales" => 1,
            "menu"     => 1, "market" => 1, "jukebox" => 1, "darts" => 1, "photos" => 1,
        ],
        "owner" => [
            "bookings" => 1, "orders" => 1, "guests" => 0, "sales" => 0,
            "menu"     => 1, "market" => 0, "jukebox" => 0, "darts" => 0, "photos" => 1,
        ],
        "reception" => [
            "bookings" => 1, "orders" => 1, "guests" => 0, "sales" => 0,
            "menu"     => 0, "market" => 0, "jukebox" => 0, "darts" => 0, "photos" => 0,
        ],
        "bartender" => [
            "bookings" => 0, "orders" => 1, "guests" => 0, "sales" => 0,
            "menu"     => 0, "market" => 0, "jukebox" => 0, "darts" => 0, "photos" => 0,
        ],
    ];
    if (isset($matrix[$role])) return $matrix[$role];
    return array_fill_keys(knk_permissions(), 0);
}

/** Read a user's stored permission map. Returns ['bookings'=>0|1, ...] for all 9 keys. */
function knk_user_permissions(int $user_id): array {
    static $cache = [];
    if (isset($cache[$user_id])) return $cache[$user_id];

    $out = array_fill_keys(knk_permissions(), 0);
    try {
        $stmt = knk_db()->prepare("SELECT permission, granted FROM user_permissions WHERE user_id = ?");
        $stmt->execute([$user_id]);
        foreach ($stmt->fetchAll() as $r) {
            if (array_key_exists($r["permission"], $out)) {
                $out[$r["permission"]] = (int)$r["granted"] ? 1 : 0;
            }
        }
    } catch (Throwable $e) {
        // Table missing (migration not yet run) — leave all-zeros so the
        // page falls through to a friendly 403 rather than a fatal error.
    }
    return $cache[$user_id] = $out;
}

/** Can this user access $permission? Super Admins always pass. */
function knk_user_can(array $me, string $permission): bool {
    if (($me["role"] ?? "") === "super_admin") return true;
    if (!in_array($permission, knk_permissions(), true)) return false;
    $perms = knk_user_permissions((int)$me["id"]);
    return !empty($perms[$permission]);
}

/** Persist a permission map for a user. $perms is a partial or full array of perm => 0|1.
 *  Missing keys are written as 0 so the row always covers all nine permissions. */
function knk_set_user_permissions(int $user_id, array $perms): void {
    $pdo = knk_db();
    $stmt = $pdo->prepare(
        "INSERT INTO user_permissions (user_id, permission, granted)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE granted = VALUES(granted)"
    );
    foreach (knk_permissions() as $perm) {
        $granted = !empty($perms[$perm]) ? 1 : 0;
        $stmt->execute([$user_id, $perm, $granted]);
    }
}

/* --------------------------------------------------------------------
 * Per-user nav. Replaces the old role-only switch — looks up each
 * link's permission and shows it only if the user has it. Settings
 * and Users stay hardcoded super_admin-only.
 * ------------------------------------------------------------------ */
function knk_user_nav(array $me): array {
    $items = [
        "bookings" => ["href" => "/bookings.php",            "label" => knk_t("nav.bookings")],
        "orders"   => ["href" => "/order-admin.php",         "label" => knk_t("nav.orders")],
        "guests"   => ["href" => "/bookings.php?tab=guests", "label" => knk_t("nav.guests")],
        "sales"    => ["href" => "/sales.php",               "label" => knk_t("nav.sales")],
        "menu"     => ["href" => "/menu.php",                "label" => knk_t("nav.menu")],
        "market"   => ["href" => "/market-admin.php",        "label" => knk_t("nav.market")],
        "jukebox"  => ["href" => "/jukebox-admin.php",       "label" => knk_t("nav.jukebox")],
        "darts"    => ["href" => "/darts-admin.php",         "label" => knk_t("nav.darts")],
        "photos"   => ["href" => "/photos.php",              "label" => knk_t("nav.photos")],
    ];
    $out = [];
    foreach ($items as $perm => $link) {
        if (knk_user_can($me, $perm)) $out[] = $link;
    }
    if (($me["role"] ?? "") === "super_admin") {
        $out[] = ["href" => "/settings.php", "label" => knk_t("nav.settings")];
        $out[] = ["href" => "/users.php",    "label" => knk_t("nav.users")];
    }
    return $out;
}

/** @deprecated Kept so anything still calling it doesn't crash. Prefer knk_user_nav($me). */
function knk_role_nav(string $role): array {
    return knk_user_nav(["role" => $role, "id" => 0]);
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

    // Seed permission toggles from the role's defaults. Each user gets
    // their own row set so Ben can later flip individual switches.
    try {
        knk_set_user_permissions($id, knk_role_default_permissions($role));
    } catch (Throwable $e) {
        // Migration 015 not applied yet — user record still exists,
        // permission rows will be filled in by the migration's backfill.
    }

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
    $links = knk_user_nav($me);
    $current_script = $_SERVER["SCRIPT_NAME"] ?? "";
    // For query-string-aware active-state (e.g. bookings.php?tab=guests),
    // pick out the tab/filter keys from the current URL.
    $current_tab    = $_GET["tab"]    ?? "";
    $current_filter = $_GET["filter"] ?? "";

    // Language toggle — links to /set-language.php?lang=…&next=current_url.
    // We bounce back to whatever URL the user was on so the click feels in-place.
    $cur_lang  = knk_current_lang($me);
    $other     = ($cur_lang === "vi") ? "en" : "vi";
    $next_url  = $_SERVER["REQUEST_URI"] ?? "/";
    $brand_lbl = htmlspecialchars(knk_t("nav.brand_staff"));
    $logout_l  = htmlspecialchars(knk_t("nav.logout"));
    ?>
    <nav class="knk-admin-nav">
      <div class="knk-admin-nav__brand">
        <strong>KnK</strong> <em><?= $brand_lbl ?></em>
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
        <a class="knk-admin-nav__lang"
           href="/set-language.php?lang=<?= htmlspecialchars($other) ?>&amp;next=<?= htmlspecialchars(urlencode($next_url)) ?>"
           title="<?= htmlspecialchars(knk_t("lang.tooltip")) ?>"
           aria-label="<?= htmlspecialchars(knk_t("lang.tooltip")) ?>">
          <span class="knk-admin-nav__lang-current"><?= htmlspecialchars(knk_lang_short($cur_lang)) ?></span>
          <span class="knk-admin-nav__lang-sep">·</span>
          <span class="knk-admin-nav__lang-other"><?= htmlspecialchars(knk_lang_short($other)) ?></span>
        </a>
        <span class="knk-admin-nav__user"><?= $name ?> <span class="knk-admin-nav__role">· <?= $role ?></span></span>
        <a class="knk-admin-nav__logout" href="/logout.php"><?= $logout_l ?></a>
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
      .knk-admin-nav__lang {
        display: inline-flex; align-items: center; gap: 0.25rem;
        text-decoration: none; font-size: 0.78rem; letter-spacing: 0.08em; font-weight: 600;
        padding: 0.25rem 0.55rem; border-radius: 4px;
        border: 1px solid rgba(201,170,113,0.25);
        color: var(--cream-dim, #d8c9ab);
      }
      .knk-admin-nav__lang:hover { background: rgba(201,170,113,0.12); color: var(--cream, #f5e9d1); }
      .knk-admin-nav__lang-current { color: var(--gold, #c9aa71); }
      .knk-admin-nav__lang-sep     { opacity: 0.5; }
      .knk-admin-nav__lang-other   { opacity: 0.7; }
      @media (max-width: 640px) {
        .knk-admin-nav { gap: 0.75rem; }
        .knk-admin-nav__me { width: 100%; justify-content: space-between; }
      }
    </style>
    <?php
}

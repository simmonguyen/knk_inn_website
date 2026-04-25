<?php
/*
 * KnK Inn — /users.php
 *
 * Super Admin only. Create and manage staff accounts:
 *   · Add a new user (email, name, role, initial password)
 *   · Rename / change role
 *   · Reset password
 *   · Deactivate / reactivate (we never hard-delete — FKs depend on users.id)
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/auth.php";

$me = knk_require_role(["super_admin"]);

/* --------------------------------------------------------------------
 * POST actions
 * ------------------------------------------------------------------ */
$flash = "";
$error = "";

$action = $_POST["action"] ?? "";

if ($action === "create") {
    $email    = (string)($_POST["email"]    ?? "");
    $name     = (string)($_POST["name"]     ?? "");
    $password = (string)($_POST["password"] ?? "");
    $role     = (string)($_POST["role"]     ?? "");
    try {
        $new_id = knk_create_user($email, $name, $password, $role);
        $flash = "Created " . htmlspecialchars($email) . " as " . htmlspecialchars(knk_role_label($role)) . ".";
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

elseif ($action === "update") {
    $id   = (int)($_POST["id"] ?? 0);
    $name = trim((string)($_POST["name"] ?? ""));
    $role = (string)($_POST["role"] ?? "");

    if ($id <= 0 || $name === "" || !in_array($role, ["super_admin","owner","reception","bartender"], true)) {
        $error = "Missing or invalid fields.";
    } elseif ($id === (int)$me["id"] && $role !== "super_admin") {
        // Guard-rail: Ben can't accidentally strip his own super_admin.
        $error = "You can't remove your own Super Admin role. Ask another Super Admin to do it.";
    } else {
        $stmt = knk_db()->prepare("UPDATE users SET name = ?, role = ? WHERE id = ?");
        $stmt->execute([$name, $role, $id]);
        knk_audit("user.update", "users", (string)$id, ["name" => $name, "role" => $role]);
        $flash = "Updated user #{$id}.";
    }
}

elseif ($action === "reset_password") {
    $id  = (int)($_POST["id"] ?? 0);
    $pw  = (string)($_POST["password"] ?? "");
    if ($id <= 0 || strlen($pw) < 8) {
        $error = "New password must be at least 8 characters.";
    } else {
        $hash = password_hash($pw, PASSWORD_BCRYPT);
        knk_db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([$hash, $id]);
        knk_audit("user.reset_password", "users", (string)$id);
        $flash = "Password reset for user #{$id}.";
    }
}

elseif ($action === "toggle_active") {
    $id     = (int)($_POST["id"]     ?? 0);
    $active = (int)($_POST["active"] ?? 0) ? 1 : 0;
    if ($id === (int)$me["id"] && $active === 0) {
        $error = "You can't deactivate yourself.";
    } elseif ($id > 0) {
        knk_db()->prepare("UPDATE users SET active = ? WHERE id = ?")
                ->execute([$active, $id]);
        knk_audit($active ? "user.activate" : "user.deactivate", "users", (string)$id);
        $flash = $active ? "User #{$id} re-activated." : "User #{$id} deactivated.";
    }
}

elseif ($action === "update_permissions") {
    $id = (int)($_POST["id"] ?? 0);
    if ($id <= 0) {
        $error = "Missing user.";
    } else {
        // Find the target user's role so we know whether to lock-on
        // Super Admin's toggles regardless of what the form posted.
        $stmt = knk_db()->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $target_role = (string)($stmt->fetchColumn() ?: "");

        $submitted = (array)($_POST["perm"] ?? []);
        $perms = [];
        foreach (knk_permissions() as $p) {
            $perms[$p] = !empty($submitted[$p]) ? 1 : 0;
        }
        // Super Admins always have everything on — never let the form
        // strip a perm from another super_admin.
        if ($target_role === "super_admin") {
            foreach ($perms as $k => $_v) $perms[$k] = 1;
        }
        knk_set_user_permissions($id, $perms);
        knk_audit("user.update_permissions", "users", (string)$id, $perms);
        $flash = "Permissions updated for user #{$id}.";
    }
}

if ($action !== "") {
    // POST-redirect-GET so refreshes don't re-submit the form.
    $qs = [];
    if ($flash) $qs["msg"] = $flash;
    if ($error) $qs["err"] = $error;
    header("Location: /users.php" . ($qs ? "?" . http_build_query($qs) : ""));
    exit;
}

$flash = (string)($_GET["msg"] ?? "");
$error = (string)($_GET["err"] ?? "");

/* --------------------------------------------------------------------
 * Load data
 * ------------------------------------------------------------------ */
$users = knk_db()
    ->query("SELECT id, email, name, role, active, created_at, last_login_at
             FROM users ORDER BY active DESC, role, name")
    ->fetchAll();

// Load all user permissions in a single query, then group by user_id so
// each row's <details> block can render its toggle matrix without
// firing a query per user.
$user_perms = [];
foreach ($users as $u) {
    $user_perms[(int)$u["id"]] = array_fill_keys(knk_permissions(), 0);
}
if (!empty($user_perms)) {
    foreach (knk_db()->query("SELECT user_id, permission, granted FROM user_permissions")->fetchAll() as $r) {
        $uid  = (int)$r["user_id"];
        $perm = $r["permission"];
        if (isset($user_perms[$uid][$perm])) {
            $user_perms[$uid][$perm] = (int)$r["granted"] ? 1 : 0;
        }
    }
}

$role_options = [
    "super_admin" => "Super Admin",
    "owner"       => "Owner",
    "reception"   => "Hotel Reception",
    "bartender"   => "Bartender / Hostess",
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>KnK Inn — Users</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Caveat:wght@700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/styles.css?v=12">
  <style>
    body { background: #1b0f04; color: var(--cream, #f5e9d1); font-family: "Inter", system-ui, sans-serif; margin: 0; }
    main.wrap { max-width: 1100px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
    h1.display-md { margin: 1.6rem 0 0.3rem; }
    .lede { color: var(--cream-dim, #d8c9ab); margin-bottom: 2rem; }
    .flash { padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1.2rem; font-size: 0.95rem; }
    .flash.ok  { background: rgba(142,212,154,0.12); color: #8ed49a; border: 1px solid rgba(142,212,154,0.28); }
    .flash.err { background: rgba(255,154,138,0.1);  color: #ff9a8a; border: 1px solid rgba(255,154,138,0.3); }
    section.card {
      background: rgba(24,12,3,0.6); border: 1px solid rgba(201,170,113,0.22);
      border-radius: 6px; padding: 1.5rem; margin-bottom: 2rem;
    }
    section.card h2 { margin: 0 0 1rem; font-family: "Archivo Black", sans-serif; letter-spacing: .02em; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.9rem; }
    label { display: block; font-size: 0.75rem; letter-spacing: 0.14em; text-transform: uppercase; color: var(--cream-dim, #d8c9ab); margin: 0 0 0.3rem 0.15rem; }
    input, select {
      width: 100%; padding: 0.7rem 0.85rem; background: rgba(255,255,255,0.04);
      border: 1px solid rgba(201,170,113,0.3); color: var(--cream, #f5e9d1);
      font-size: 0.95rem; font-family: inherit; border-radius: 4px; box-sizing: border-box;
    }
    input:focus, select:focus { outline: none; border-color: var(--gold, #c9aa71); }
    button, .btn {
      display: inline-block; padding: 0.55rem 1rem; background: var(--gold, #c9aa71);
      color: var(--brown-deep, #2a1a08); border: none; font-weight: 700;
      letter-spacing: 0.12em; text-transform: uppercase; font-size: 0.72rem;
      cursor: pointer; border-radius: 4px; font-family: inherit; text-decoration: none;
    }
    button:hover, .btn:hover { background: #d8c08b; }
    button.ghost { background: transparent; color: var(--cream-dim, #d8c9ab); border: 1px solid rgba(201,170,113,0.3); }
    button.ghost:hover { background: rgba(201,170,113,0.12); color: var(--cream, #f5e9d1); }
    button.danger { background: #a74a3a; color: #fff; }
    button.danger:hover { background: #c15a48; }
    /* Permission toggle matrix */
    .perm-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
      gap: 0.4rem 0.75rem;
      margin: 0.6rem 0 0.4rem;
    }
    .perm-toggle {
      display: flex; align-items: center; gap: 0.5rem;
      padding: 0.35rem 0.55rem; border-radius: 4px;
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(201,170,113,0.18);
      font-size: 0.85rem;
      cursor: pointer; user-select: none;
    }
    .perm-toggle input { width: auto; margin: 0; accent-color: var(--gold, #c9aa71); }
    .perm-toggle:hover { background: rgba(201,170,113,0.08); }
    .perm-toggle.locked {
      opacity: 0.6; cursor: not-allowed;
      background: rgba(201,170,113,0.12);
      border-color: rgba(201,170,113,0.3);
    }
    .perm-toggle.locked input { cursor: not-allowed; }
    .perm-section { margin-top: 0.6rem; }
    .perm-section h4 {
      margin: 0 0 0.2rem; font-size: 0.72rem;
      letter-spacing: 0.14em; text-transform: uppercase;
      color: var(--cream-dim, #d8c9ab); font-weight: 600;
    }
    .perm-section .muted { font-size: 0.78rem; }
    table.users {
      width: 100%; border-collapse: collapse; font-size: 0.9rem;
    }
    table.users th, table.users td {
      text-align: left; padding: 0.75rem 0.5rem; border-bottom: 1px solid rgba(201,170,113,0.15);
      vertical-align: top;
    }
    table.users th { font-size: 0.72rem; letter-spacing: 0.12em; text-transform: uppercase; color: var(--cream-dim, #d8c9ab); font-weight: 600; }
    tr.inactive { opacity: 0.55; }
    .pill {
      display: inline-block; padding: 0.15rem 0.55rem; border-radius: 999px;
      font-size: 0.7rem; letter-spacing: 0.08em; text-transform: uppercase;
      background: rgba(201,170,113,0.15); color: var(--gold, #c9aa71);
    }
    .pill.off { background: rgba(255,154,138,0.12); color: #ff9a8a; }
    .row-form { display: flex; gap: 0.35rem; flex-wrap: wrap; align-items: center; margin-top: 0.4rem; }
    .row-form input, .row-form select { width: auto; min-width: 0; padding: 0.45rem 0.65rem; font-size: 0.85rem; }
    .row-form button { padding: 0.45rem 0.8rem; font-size: 0.7rem; }
    .muted { color: var(--cream-dim, #d8c9ab); font-size: 0.82rem; }
    details summary { cursor: pointer; color: var(--gold, #c9aa71); font-size: 0.85rem; font-weight: 600; }
    details { margin-top: 0.35rem; }
  </style>
</head>
<body>
  <?php knk_render_admin_nav($me); ?>
  <main class="wrap">
    <span class="eyebrow">Super Admin</span>
    <h1 class="display-md">Staff <em>users</em></h1>
    <p class="lede">Create accounts for Simmo and the team, reset passwords, or deactivate someone who's moved on.</p>

    <?php if ($flash): ?><div class="flash ok"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="flash err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <section class="card">
      <h2>Add a new user</h2>
      <form method="post">
        <input type="hidden" name="action" value="create">
        <div class="grid">
          <div>
            <label for="name">Name</label>
            <input id="name" name="name" type="text" required>
          </div>
          <div>
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required>
          </div>
          <div>
            <label for="role">Role</label>
            <select id="role" name="role" required>
              <?php foreach ($role_options as $val => $lbl): ?>
                <option value="<?= htmlspecialchars($val) ?>" <?= $val === "bartender" ? "selected" : "" ?>><?= htmlspecialchars($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="password">Initial password (min 8)</label>
            <input id="password" name="password" type="text" required minlength="8" autocomplete="off">
          </div>
        </div>
        <div style="margin-top:1rem; display:flex; gap:.75rem; align-items:center">
          <button type="submit">Create user</button>
          <span class="muted">They'll use this password at their first login — they can change it later.</span>
        </div>
      </form>
    </section>

    <section class="card">
      <h2>Existing users (<?= count($users) ?>)</h2>
      <table class="users">
        <thead>
          <tr>
            <th>Name / email</th>
            <th>Role</th>
            <th>Status</th>
            <th>Last login</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr class="<?= ((int)$u["active"] ? "" : "inactive") ?>">
              <td>
                <strong><?= htmlspecialchars($u["name"]) ?></strong>
                <?php if ((int)$u["id"] === (int)$me["id"]): ?>
                  <span class="muted">(you)</span>
                <?php endif; ?>
                <div class="muted"><?= htmlspecialchars($u["email"]) ?></div>
              </td>
              <td>
                <?= htmlspecialchars(knk_role_label($u["role"])) ?>
              </td>
              <td>
                <?php if ((int)$u["active"]): ?>
                  <span class="pill">Active</span>
                <?php else: ?>
                  <span class="pill off">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="muted">
                <?php
                  $ll = $u["last_login_at"];
                  echo $ll ? htmlspecialchars(date("j M Y, H:i", strtotime($ll))) : "—";
                ?>
              </td>
              <td style="text-align:right">
                <details>
                  <summary>Edit</summary>
                  <form method="post" class="row-form">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int)$u["id"] ?>">
                    <input name="name" type="text" value="<?= htmlspecialchars($u["name"]) ?>" required>
                    <select name="role">
                      <?php foreach ($role_options as $val => $lbl): ?>
                        <option value="<?= htmlspecialchars($val) ?>" <?= $val === $u["role"] ? "selected" : "" ?>><?= htmlspecialchars($lbl) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="ghost">Save</button>
                  </form>

                  <form method="post" class="row-form" onsubmit="return confirm('Reset password for <?= htmlspecialchars($u["email"], ENT_QUOTES) ?>?')">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="id" value="<?= (int)$u["id"] ?>">
                    <input name="password" type="text" placeholder="New password (min 8)" minlength="8" required autocomplete="off">
                    <button type="submit" class="ghost">Reset password</button>
                  </form>

                  <?php
                  // Per-user permission matrix. Super Admin's switches
                  // are visually locked-on so Ben can't accidentally
                  // strip the kill-switch from another Super Admin.
                  $is_super  = $u["role"] === "super_admin";
                  $row_perms = $user_perms[(int)$u["id"]] ?? array_fill_keys(knk_permissions(), 0);
                  ?>
                  <form method="post" class="perm-section">
                    <input type="hidden" name="action" value="update_permissions">
                    <input type="hidden" name="id" value="<?= (int)$u["id"] ?>">
                    <h4>Permissions</h4>
                    <div class="muted" style="margin-bottom:0.45rem">
                      <?php if ($is_super): ?>
                        Super Admins always have access to everything.
                      <?php else: ?>
                        Tick the sections this user can open. Settings &amp; Users stay Super Admin-only.
                      <?php endif; ?>
                    </div>
                    <div class="perm-grid">
                      <?php foreach (knk_permissions() as $p):
                          $on     = $is_super ? 1 : (int)($row_perms[$p] ?? 0);
                          $locked = $is_super;
                      ?>
                        <label class="perm-toggle<?= $locked ? " locked" : "" ?>">
                          <input type="checkbox" name="perm[<?= htmlspecialchars($p) ?>]" value="1"
                                 <?= $on ? "checked" : "" ?>
                                 <?= $locked ? "disabled" : "" ?>>
                          <?= htmlspecialchars(knk_permission_label($p)) ?>
                        </label>
                      <?php endforeach; ?>
                    </div>
                    <div class="row-form" style="margin-top:0.4rem">
                      <button type="submit" class="ghost"<?= $is_super ? " disabled" : "" ?>>
                        Save permissions
                      </button>
                    </div>
                  </form>

                  <?php if ((int)$u["id"] !== (int)$me["id"]): ?>
                    <form method="post" class="row-form" onsubmit="return confirm('<?= (int)$u["active"] ? "Deactivate" : "Re-activate" ?> <?= htmlspecialchars($u["email"], ENT_QUOTES) ?>?')">
                      <input type="hidden" name="action" value="toggle_active">
                      <input type="hidden" name="id" value="<?= (int)$u["id"] ?>">
                      <input type="hidden" name="active" value="<?= (int)$u["active"] ? 0 : 1 ?>">
                      <?php if ((int)$u["active"]): ?>
                        <button type="submit" class="danger">Deactivate</button>
                      <?php else: ?>
                        <button type="submit">Re-activate</button>
                      <?php endif; ?>
                    </form>
                  <?php endif; ?>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  </main>
</body>
</html>

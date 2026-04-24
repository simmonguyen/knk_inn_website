<?php
/*
 * KnK Inn — /setup.php
 *
 * One-time Super Admin bootstrap.
 * Guarded by the admin_password from config.php — same key that guards
 * /migrate.php. Self-disables once any user exists, so it's safe to
 * leave in place (but feel free to delete once stable).
 *
 * Flow:
 *   1. Hit /setup.php?key=<admin_password>
 *   2. Form asks for your email, name, password.
 *   3. Creates you as role=super_admin.
 *   4. Subsequent visits return 404 (users table non-empty).
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/auth.php";

/* If anyone already exists, this page is done. */
if (knk_users_exist()) {
    http_response_code(404);
    echo "<!doctype html><meta charset=utf-8><title>Not found</title>"
       . "<style>body{font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem;color:#2a1a08}a{color:#b38a3b}</style>"
       . "<h1>Setup already complete</h1>"
       . "<p>The first Super Admin has been created. New users are managed at "
       . "<a href=\"/users.php\">/users.php</a> once you're logged in.</p>"
       . "<p><a href=\"/login.php\">Go to login</a></p>";
    exit;
}

/* Guard: admin_password from config.php. */
$cfg   = knk_config();
$guard = (string)($cfg["admin_password"] ?? "");
$key   = (string)($_GET["key"] ?? ($_POST["key"] ?? ""));

if ($guard === "" || !hash_equals($guard, $key)) {
    http_response_code(403);
    echo "<!doctype html><meta charset=utf-8><title>Forbidden</title>"
       . "<style>body{font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem;color:#2a1a08}code{background:#f3ead6;padding:2px 6px;border-radius:3px}</style>"
       . "<h1>Forbidden</h1>"
       . "<p>This page needs the admin password as a URL key:</p>"
       . "<p><code>/setup.php?key=YOUR_ADMIN_PASSWORD</code></p>"
       . "<p>It's the same key you used on <code>/migrate.php</code>. Set it in <code>config.php</code>.</p>";
    exit;
}

$error = "";
$ok    = false;
$form  = ["email" => "", "name" => ""];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $form["email"] = trim((string)($_POST["email"] ?? ""));
    $form["name"]  = trim((string)($_POST["name"]  ?? ""));
    $password      = (string)($_POST["password"] ?? "");
    $confirm       = (string)($_POST["confirm"]  ?? "");

    if ($password !== $confirm) {
        $error = "Passwords don't match.";
    } else {
        try {
            knk_create_user($form["email"], $form["name"], $password, "super_admin");
            $ok = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>KnK Inn — First-time Setup</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Caveat:wght@700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/styles.css?v=12">
  <style>
    body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 2rem; }
    .card {
      background: rgba(24,12,3,0.6); border: 1px solid rgba(201,170,113,0.22);
      padding: 2.4rem 2rem; border-radius: 6px; width: 100%; max-width: 440px;
      backdrop-filter: blur(8px);
    }
    .card h1 { margin-bottom: 0.4rem; text-align: center; }
    .card > p { color: var(--cream-dim, #d8c9ab); font-size: 0.9rem; margin-bottom: 1.6rem; text-align: center; }
    .card label {
      display: block; text-align: left; font-size: 0.75rem;
      letter-spacing: 0.14em; text-transform: uppercase; color: var(--cream-dim, #d8c9ab);
      margin: 0 0 0.3rem 0.15rem;
    }
    .card input {
      width: 100%; padding: 0.85rem 1rem; margin-bottom: 1rem;
      background: rgba(255,255,255,0.04); border: 1px solid rgba(201,170,113,0.3);
      color: var(--cream, #f5e9d1); font-size: 1rem; font-family: inherit; border-radius: 4px;
      box-sizing: border-box;
    }
    .card input:focus { outline: none; border-color: var(--gold, #c9aa71); }
    .card button {
      width: 100%; padding: 0.85rem; background: var(--gold, #c9aa71); color: var(--brown-deep, #2a1a08);
      border: none; font-weight: 700; letter-spacing: 0.15em; text-transform: uppercase;
      font-size: 0.8rem; cursor: pointer; border-radius: 4px; font-family: inherit;
    }
    .err  { color: #ff9a8a; font-size: 0.85rem; margin-bottom: 1rem; }
    .ok   { color: #8ed49a; font-size: 0.95rem; text-align: center; }
  </style>
</head>
<body>
  <div class="card">
    <span class="eyebrow" style="display:block;text-align:center">First-time setup</span>
    <h1 class="display-md">Create <em>Super Admin</em></h1>
    <p>This is a one-time page. Once you submit, setup self-disables and new accounts are created from <strong>/users.php</strong>.</p>

    <?php if ($ok): ?>
      <p class="ok">All done. You can now <a href="/login.php" style="color:var(--gold,#c9aa71)">log in</a> with the email and password you just set.</p>
    <?php else: ?>
      <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="key" value="<?= htmlspecialchars($key) ?>">

        <label for="name">Your name</label>
        <input id="name" name="name" type="text" required value="<?= htmlspecialchars($form["name"]) ?>">

        <label for="email">Email</label>
        <input id="email" name="email" type="email" required value="<?= htmlspecialchars($form["email"]) ?>">

        <label for="password">Password (min 8 chars)</label>
        <input id="password" name="password" type="password" required minlength="8">

        <label for="confirm">Confirm password</label>
        <input id="confirm" name="confirm" type="password" required minlength="8">

        <button type="submit">Create account</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>

<?php
/*
 * KnK Inn — /login.php
 *
 * Unified staff login. Replaces the per-page shared-password flow on
 * bookings.php / order-admin.php / photos.php.
 *
 * Accepts ?next=/some/page to bounce the user back where they came from.
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/auth.php";

// Already logged in? Send them to their role's home page (or ?next=).
$me = knk_current_user();
if ($me) {
    $next = $_GET["next"] ?? knk_role_home($me["role"]);
    header("Location: " . knk_safe_next($next, $me["role"]));
    exit;
}

// If the schema is live but no users exist yet, nudge to /setup.php.
if (!knk_users_exist()) {
    header("Location: /setup.php");
    exit;
}

$error = "";
$email = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim((string)($_POST["email"] ?? ""));
    $password = (string)($_POST["password"] ?? "");

    $res = knk_login_attempt($email, $password);
    if ($res["ok"]) {
        $next = $_POST["next"] ?? knk_role_home($res["user"]["role"]);
        header("Location: " . knk_safe_next($next, $res["user"]["role"]));
        exit;
    }
    $error = $res["error"];
}

/* Only bounce to same-origin paths — never blindly trust ?next=. */
function knk_safe_next(string $next, string $role): string {
    if ($next === "" || $next[0] !== "/" || str_starts_with($next, "//")) {
        return knk_role_home($role);
    }
    // Don't loop back to login / logout / setup.
    $path = parse_url($next, PHP_URL_PATH) ?? "";
    if (in_array($path, ["/login.php", "/logout.php", "/setup.php"], true)) {
        return knk_role_home($role);
    }
    return $next;
}

$next_val = $_GET["next"] ?? ($_POST["next"] ?? "");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>KnK Inn — Staff Log In</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Caveat:wght@700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/styles.css?v=12">
  <style>
    body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 2rem; }
    .lock-card {
      background: rgba(24,12,3,0.6); border: 1px solid rgba(201,170,113,0.22);
      padding: 2.4rem 2rem; border-radius: 6px; width: 100%; max-width: 400px;
      text-align: center; backdrop-filter: blur(8px);
    }
    .lock-card h1 { margin-bottom: 0.6rem; }
    .lock-card p  { color: var(--cream-dim, #d8c9ab); font-size: 0.9rem; margin-bottom: 1.6rem; }
    .lock-card label {
      display: block; text-align: left; font-size: 0.75rem;
      letter-spacing: 0.14em; text-transform: uppercase; color: var(--cream-dim, #d8c9ab);
      margin: 0 0 0.3rem 0.15rem;
    }
    .lock-card input {
      width: 100%; padding: 0.85rem 1rem; margin-bottom: 1rem;
      background: rgba(255,255,255,0.04); border: 1px solid rgba(201,170,113,0.3);
      color: var(--cream, #f5e9d1); font-size: 1rem; font-family: inherit; border-radius: 4px;
      box-sizing: border-box;
    }
    .lock-card input:focus { outline: none; border-color: var(--gold, #c9aa71); }
    .lock-card button {
      width: 100%; padding: 0.85rem; background: var(--gold, #c9aa71); color: var(--brown-deep, #2a1a08);
      border: none; font-weight: 700; letter-spacing: 0.15em; text-transform: uppercase;
      font-size: 0.8rem; cursor: pointer; border-radius: 4px; font-family: inherit;
    }
    .lock-card button:hover { background: #d8c08b; }
    .err  { color: #ff9a8a; font-size: 0.85rem; margin-bottom: 1rem; text-align: left; }
    .hint { margin-top: 1.3rem; font-size: 0.78rem; color: var(--cream-dim, #d8c9ab); }
    .hint a { color: var(--gold, #c9aa71); }
  </style>
</head>
<body>
  <form class="lock-card" method="post" autocomplete="on">
    <span class="eyebrow">Staff only</span>
    <h1 class="display-md">KnK <em>Staff</em></h1>
    <p>Sign in to manage bookings, orders and photos.</p>

    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <input type="hidden" name="next" value="<?= htmlspecialchars((string)$next_val) ?>">

    <label for="email">Email</label>
    <input id="email" name="email" type="email" required autocomplete="username"
           value="<?= htmlspecialchars($email) ?>" autofocus>

    <label for="password">Password</label>
    <input id="password" name="password" type="password" required autocomplete="current-password">

    <button type="submit">Log in</button>

    <div class="hint">
      Forgot your password? Ask Ben to reset it for you.
    </div>
  </form>
</body>
</html>

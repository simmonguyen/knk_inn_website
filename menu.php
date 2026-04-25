<?php
/*
 * KnK Inn — /menu.php
 *
 * Drinks menu admin. Super Admin + Owner only.
 *
 * Simmo can:
 *   - Change a drink's price, name, ingredients, visibility
 *   - Add a new drink into any category
 *   - Remove a drink
 *   - Nudge a drink up or down within its category
 *
 * Behaviour on the customer side:
 *   · /order.php reads this same table for the live menu.
 *   · /drinks.php (public page) also renders from this table.
 *   · Past orders are unaffected by edits here — order_items
 *     snapshots the name and price at the time of the order.
 *
 * Design notes:
 *   · Each drink row is its own <form>, so saving one drink never
 *     clobbers pending edits on another.
 *   · All write actions go through POST + redirect-after-post, so
 *     Simmo can refresh without resubmitting.
 *   · The "Add a drink" form sits collapsed at the top; a small
 *     toggle opens it. Simmo picks the category from a dropdown
 *     or types a new category name.
 */

declare(strict_types=1);

require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/menu_store.php";

$me    = knk_require_permission("menu");
$me_id = (int)$me["id"];

/* ------------------------------------------------------------------
 * POST handlers — one action per submit.
 * ------------------------------------------------------------------ */
$flash = "";
$error = "";
$action = (string)($_POST["action"] ?? "");

if ($action === "update_row") {
    $id = (int)($_POST["id"] ?? 0);
    $row = knk_menu_find($id);
    if (!$row) {
        $error = "That drink no longer exists. Refresh the page.";
    } else {
        $name        = trim((string)($_POST["name"] ?? ""));
        $ingredients = trim((string)($_POST["ingredients"] ?? ""));
        $price       = (int)preg_replace('/[^0-9]/', '', (string)($_POST["price_vnd"] ?? "0"));
        $visible     = !empty($_POST["is_visible"]) ? 1 : 0;
        $cat_display = trim((string)($_POST["category"] ?? $row["category"]));
        $cat_slug    = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim((string)($_POST["category_slug"] ?? $row["category_slug"]))));
        if ($cat_slug === "") $cat_slug = $row["category_slug"];

        if ($name === "") {
            $error = "Drink name can't be empty.";
        } elseif ($price < 0) {
            $error = "Price has to be 0 or more.";
        } else {
            knk_menu_update($id, [
                "name"          => $name,
                "ingredients"   => $ingredients !== "" ? $ingredients : null,
                "price_vnd"     => $price,
                "is_visible"    => $visible,
                "category"      => $cat_display !== "" ? $cat_display : $row["category"],
                "category_slug" => $cat_slug,
            ], $me_id);
            $flash = "Saved: {$name}.";
        }
    }
}
elseif ($action === "toggle_visible") {
    $id = (int)($_POST["id"] ?? 0);
    $row = knk_menu_find($id);
    if ($row) {
        $new = ((int)$row["is_visible"] === 1) ? 0 : 1;
        knk_menu_update($id, ["is_visible" => $new], $me_id);
        $flash = $new === 1
            ? "Showing {$row["name"]} on the menu again."
            : "Hidden {$row["name"]} from the menu.";
    }
}
elseif ($action === "move_up" || $action === "move_down") {
    $id = (int)($_POST["id"] ?? 0);
    $row = knk_menu_find($id);
    if ($row) {
        // Swap sort_order with neighbour in same category.
        $pdo = knk_db();
        $op  = $action === "move_up" ? "<" : ">";
        $dir = $action === "move_up" ? "DESC" : "ASC";
        $stmt = $pdo->prepare(
            "SELECT id, sort_order FROM menu_drinks
             WHERE category_slug = ? AND sort_order {$op} ?
             ORDER BY sort_order {$dir} LIMIT 1"
        );
        $stmt->execute([$row["category_slug"], (int)$row["sort_order"]]);
        $nb = $stmt->fetch();
        if ($nb) {
            knk_menu_update((int)$row["id"], ["sort_order" => (int)$nb["sort_order"]], $me_id);
            knk_menu_update((int)$nb["id"],  ["sort_order" => (int)$row["sort_order"]], $me_id);
            $flash = "Reordered {$row["name"]}.";
        } else {
            $flash = "{$row["name"]} is already at the end.";
        }
    }
}
elseif ($action === "delete_row") {
    $id = (int)($_POST["id"] ?? 0);
    $row = knk_menu_find($id);
    if ($row) {
        knk_menu_delete($id);
        $flash = "Removed {$row["name"]}.";
    }
}
elseif ($action === "create_row") {
    $name        = trim((string)($_POST["name"] ?? ""));
    $ingredients = trim((string)($_POST["ingredients"] ?? ""));
    $price       = (int)preg_replace('/[^0-9]/', '', (string)($_POST["price_vnd"] ?? "0"));
    $cat_slug    = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim((string)($_POST["category_slug"] ?? ""))));
    $cat_display = trim((string)($_POST["category"] ?? ""));
    // If existing slug was picked, inherit its display name.
    if ($cat_slug !== "" && $cat_display === "") {
        foreach (knk_menu_categories() as $c) {
            if ($c["slug"] === $cat_slug) { $cat_display = $c["title"]; break; }
        }
    }
    if ($name === "") {
        $error = "Need a drink name.";
    } elseif ($cat_slug === "" || $cat_display === "") {
        $error = "Pick a category or type a new one.";
    } else {
        $code = knk_menu_item_code_for($cat_slug, $name);
        knk_menu_create([
            "item_code"     => $code,
            "name"          => $name,
            "ingredients"   => $ingredients !== "" ? $ingredients : null,
            "price_vnd"     => $price,
            "category"      => $cat_display,
            "category_slug" => $cat_slug,
            "i18n_key"      => null,       // new drinks aren't translated yet
            "is_visible"    => 1,
        ], $me_id);
        $flash = "Added {$name}.";
    }
}

if ($action !== "") {
    $qs = [];
    if ($flash) $qs["msg"] = $flash;
    if ($error) $qs["err"] = $error;
    header("Location: /menu.php" . ($qs ? "?" . http_build_query($qs) : ""));
    exit;
}

$flash = (string)($_GET["msg"] ?? "");
$error = (string)($_GET["err"] ?? "");

/* ------------------------------------------------------------------
 * Read-for-render
 * ------------------------------------------------------------------ */
$groups     = knk_menu_grouped(false);       // include hidden
$categories = knk_menu_categories();

function money_input_value(int $v): string { return number_format($v, 0, ".", ","); }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>KnK Inn — Menu</title>
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

    .toolbar {
      display: flex; justify-content: space-between; align-items: center; gap: 1rem;
      margin-bottom: 1.2rem; flex-wrap: wrap;
    }
    .toolbar .count { color: var(--cream-dim, #d8c9ab); font-size: 0.9rem; }

    details.add {
      background: rgba(201,170,113,0.06); border: 1px dashed rgba(201,170,113,0.35);
      border-radius: 4px; padding: 0.8rem 1.1rem; margin-bottom: 1.5rem;
    }
    details.add > summary { cursor: pointer; font-weight: 600; color: var(--gold, #c9aa71); list-style: none; }
    details.add > summary::before { content: "+ "; color: var(--gold, #c9aa71); }
    details.add[open] > summary::before { content: "− "; }
    .add-grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; margin-top: 1rem;
    }
    .add-grid .full { grid-column: 1 / -1; }

    section.category {
      margin-bottom: 2rem;
    }
    section.category h2 {
      font-family: "Archivo Black", sans-serif; letter-spacing: .02em; font-size: 1.15rem;
      margin: 0 0 0.4rem; color: var(--gold, #c9aa71); text-transform: uppercase;
    }
    table.menu-tbl {
      width: 100%; border-collapse: collapse; font-size: 0.92rem;
    }
    table.menu-tbl th, table.menu-tbl td {
      text-align: left; padding: 0.55rem 0.65rem; border-bottom: 1px solid rgba(201,170,113,0.15);
      vertical-align: middle;
    }
    table.menu-tbl th { color: var(--cream-dim, #d8c9ab); font-weight: 600; font-size: 0.75rem; letter-spacing: 0.1em; text-transform: uppercase; }
    table.menu-tbl tr.hidden-row td { opacity: 0.45; }

    .col-name   { width: 28%; }
    .col-ingr   { width: 26%; }
    .col-price  { width: 14%; }
    .col-vis    { width: 8%;  text-align:center; }
    .col-move   { width: 8%;  text-align:center; white-space: nowrap; }
    .col-act    { width: 16%; text-align:right;  white-space: nowrap; }

    input[type="text"], input[type="number"] {
      width: 100%; padding: 0.42rem 0.55rem; background: rgba(255,255,255,0.04);
      border: 1px solid rgba(201,170,113,0.3); color: var(--cream, #f5e9d1);
      font-size: 0.9rem; font-family: inherit; border-radius: 3px; box-sizing: border-box;
    }
    input[type="text"]:focus, input[type="number"]:focus { outline: none; border-color: var(--gold, #c9aa71); }

    label.chk { display: inline-flex; align-items: center; gap: 0.35rem; cursor: pointer; font-size: 0.85rem; color: var(--cream-dim, #d8c9ab); }
    label.chk input { width: auto; margin: 0; }

    button, .btn {
      display: inline-block; padding: 0.4rem 0.75rem; background: var(--gold, #c9aa71);
      color: var(--brown-deep, #2a1a08); border: none; font-weight: 700;
      letter-spacing: 0.08em; text-transform: uppercase; font-size: 0.7rem;
      cursor: pointer; border-radius: 3px; font-family: inherit; text-decoration: none;
    }
    button:hover, .btn:hover { background: #d8c08b; }
    button.ghost { background: transparent; color: var(--cream-dim, #d8c9ab); border: 1px solid rgba(201,170,113,0.3); }
    button.ghost:hover { background: rgba(201,170,113,0.12); color: var(--cream, #f5e9d1); }
    button.danger { background: transparent; color: #ff9a8a; border: 1px solid rgba(255,154,138,0.3); }
    button.danger:hover { background: rgba(255,154,138,0.1); }
    button.mini { padding: 0.25rem 0.45rem; font-size: 0.8rem; line-height: 1; min-width: 26px; }

    .row-form { display: contents; }
    .pill-hidden {
      display: inline-block; font-size: 0.6rem; letter-spacing: 0.12em; text-transform: uppercase;
      padding: 2px 7px; border-radius: 999px; background: rgba(255,154,138,0.1); color: #ff9a8a;
      border: 1px solid rgba(255,154,138,0.3); margin-left: 0.4rem;
    }
    .muted { color: var(--cream-dim, #d8c9ab); font-size: 0.82rem; }

    .cat-meta { display: inline-flex; align-items: baseline; gap: 0.9rem; margin-bottom: 0.3rem; }
    .cat-meta .muted { font-size: 0.78rem; text-transform: none; letter-spacing: 0; }
  </style>
</head>
<body>
  <?php knk_render_admin_nav($me); ?>
  <main class="wrap">
    <span class="eyebrow">Admin</span>
    <h1 class="display-md">Drinks menu</h1>
    <p class="lede">Edit prices, rename drinks, add a new one, or hide a drink you're out of.</p>

    <?php if ($flash): ?><div class="flash ok"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>

    <div class="toolbar">
      <div class="count">
        <?php
          $totalVisible = 0; $totalHidden = 0;
          foreach ($groups as $g) foreach ($g["items"] as $it) {
              if ((int)$it["is_visible"] === 1) $totalVisible++; else $totalHidden++;
          }
        ?>
        <?= $totalVisible ?> on the menu<?php if ($totalHidden): ?>, <?= $totalHidden ?> hidden<?php endif; ?>.
      </div>
    </div>

    <!-- Add a drink ------------------------------------------------ -->
    <details class="add">
      <summary>Add a drink</summary>
      <form method="post" action="/menu.php">
        <input type="hidden" name="action" value="create_row">
        <div class="add-grid">
          <div>
            <label for="new_name" class="muted">Name</label>
            <input type="text" id="new_name" name="name" required placeholder="e.g. Icy Mojito">
          </div>
          <div>
            <label for="new_price" class="muted">Price (VND)</label>
            <input type="number" id="new_price" name="price_vnd" min="0" step="1000" value="0">
          </div>
          <div>
            <label for="new_category_picker" class="muted">Category (pick existing)</label>
            <select id="new_category_picker" style="width:100%; padding:0.42rem 0.55rem; background:rgba(255,255,255,0.04); border:1px solid rgba(201,170,113,0.3); color:var(--cream,#f5e9d1); font-size:0.9rem; font-family:inherit; border-radius:3px; box-sizing:border-box;">
              <option value="">— pick a category —</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= h($c["slug"]) ?>" data-title="<?= h($c["title"]) ?>"><?= h($c["title"]) ?></option>
              <?php endforeach; ?>
              <option value="__new__">+ New category…</option>
            </select>
          </div>
          <div id="new_category_custom" style="display:none;">
            <label for="new_category" class="muted">New category — display name + slug</label>
            <input type="text" id="new_category" name="category" placeholder="e.g. Smoothies">
            <input type="text" id="new_category_slug_input" placeholder="slug (lowercase, no spaces) — e.g. smoothies" style="margin-top:0.4rem;">
          </div>
          <div class="full">
            <label for="new_ingredients" class="muted">Ingredients (optional — shown on /drinks.php)</label>
            <input type="text" id="new_ingredients" name="ingredients" placeholder="e.g. white rum, lime, mint, soda">
          </div>
          <div class="full" style="text-align:right;">
            <button type="submit">Add drink</button>
          </div>
        </div>
        <!-- Hidden field that actually gets POSTed. Driven by the picker + slug input below. -->
        <input type="hidden" name="category_slug" id="new_category_slug_hidden" value="">
      </form>
    </details>

    <!-- Category tables ------------------------------------------- -->
    <?php if (!$groups): ?>
      <p class="muted">No drinks yet. Add one above.</p>
    <?php endif; ?>

    <?php foreach ($groups as $g): ?>
      <section class="category">
        <div class="cat-meta">
          <h2><?= h($g["title"]) ?></h2>
          <span class="muted"><?= count($g["items"]) ?> item<?= count($g["items"]) === 1 ? "" : "s" ?> · slug <code><?= h($g["slug"]) ?></code></span>
        </div>

        <table class="menu-tbl">
          <thead>
            <tr>
              <th class="col-name">Name</th>
              <th class="col-ingr">Ingredients</th>
              <th class="col-price">Price (VND)</th>
              <th class="col-vis">On menu</th>
              <th class="col-move">Order</th>
              <th class="col-act">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($g["items"] as $it): ?>
              <tr class="<?= ((int)$it["is_visible"] === 0) ? "hidden-row" : "" ?>">
                <td colspan="6" style="padding:0;">
                  <form method="post" action="/menu.php" class="row-form">
                    <input type="hidden" name="action" value="update_row">
                    <input type="hidden" name="id" value="<?= (int)$it["id"] ?>">
                    <input type="hidden" name="category_slug" value="<?= h($g["slug"]) ?>">
                    <input type="hidden" name="category" value="<?= h($g["title"]) ?>">
                    <table style="width:100%; border-collapse:collapse;">
                      <tr>
                        <td class="col-name">
                          <input type="text" name="name" value="<?= h($it["name"]) ?>" required>
                          <?php if ((int)$it["is_visible"] === 0): ?>
                            <span class="pill-hidden">hidden</span>
                          <?php endif; ?>
                        </td>
                        <td class="col-ingr">
                          <input type="text" name="ingredients" value="<?= h($it["ingredients"] ?? "") ?>" placeholder="—">
                        </td>
                        <td class="col-price">
                          <input type="number" name="price_vnd" min="0" step="1000" value="<?= (int)$it["price_vnd"] ?>">
                        </td>
                        <td class="col-vis">
                          <label class="chk">
                            <input type="checkbox" name="is_visible" value="1" <?= (int)$it["is_visible"] === 1 ? "checked" : "" ?>>
                            show
                          </label>
                        </td>
                        <td class="col-move">
                          <button type="button" class="ghost mini" onclick="submitSub(this,'move_up',<?= (int)$it["id"] ?>)" title="Move up">▲</button>
                          <button type="button" class="ghost mini" onclick="submitSub(this,'move_down',<?= (int)$it["id"] ?>)" title="Move down">▼</button>
                        </td>
                        <td class="col-act">
                          <button type="submit">Save</button>
                          <button type="button" class="danger" onclick="if(confirm('Remove <?= h(addslashes($it["name"])) ?> from the menu?')) submitSub(this,'delete_row',<?= (int)$it["id"] ?>);">Remove</button>
                        </td>
                      </tr>
                    </table>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php endforeach; ?>

  </main>

  <!-- Hidden sub-action form (reused for move up/down + delete) -->
  <form id="subForm" method="post" action="/menu.php" style="display:none;">
    <input type="hidden" name="action" id="subAction">
    <input type="hidden" name="id"     id="subId">
  </form>

  <script>
    // Category picker — show the "new category" fields when "+ New category…" is picked.
    // The hidden input #new_category_slug_hidden is the actual POST field; we
    // sync it from whichever source is live (dropdown or free-text slug).
    (function () {
      const picker = document.getElementById('new_category_picker');
      const custom = document.getElementById('new_category_custom');
      const hidden = document.getElementById('new_category_slug_hidden');
      const slugInput = document.getElementById('new_category_slug_input');
      if (!picker) return;

      function sync() {
        if (picker.value === '__new__') {
          custom.style.display = 'block';
          hidden.value = (slugInput.value || '').toLowerCase().replace(/[^a-z0-9_-]/g, '');
        } else {
          custom.style.display = 'none';
          hidden.value = picker.value || '';
        }
      }
      picker.addEventListener('change', sync);
      slugInput.addEventListener('input', sync);
      sync();
    })();

    // Shared dispatcher for move up / move down / delete. Keeps the per-row
    // form limited to updates, and lets the small buttons submit one-shot actions.
    function submitSub(btn, action, id) {
      document.getElementById('subAction').value = action;
      document.getElementById('subId').value = id;
      document.getElementById('subForm').submit();
    }
  </script>
</body>
</html>

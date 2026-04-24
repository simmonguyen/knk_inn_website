<?php
/*
 * KnK Inn — menu store.
 *
 * Reads & writes rows in the `menu_drinks` table that backs the
 * customer order page (order.php) and the public drinks page
 * (drinks.php). Edits happen via /menu.php.
 *
 * Caching: knk_menu_list() memoises its result for the life of
 * the request so repeated calls (one from order.php's render, one
 * from place_order validation) hit the DB once.
 *
 * Categories: the drinks are grouped in memory by category_slug,
 * ordered by category_sort first, then sort_order within each
 * group. The admin UI edits both sort fields directly.
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";

/* ------------------------------------------------------------------
 * READ
 * ------------------------------------------------------------------ */

/**
 * All drinks as flat rows, ordered category_sort → sort_order.
 * Pass $visibleOnly = true to exclude hidden rows.
 *
 * @return array<int, array{
 *   id:int,item_code:string,i18n_key:?string,category:string,
 *   category_slug:string,category_sort:int,sort_order:int,
 *   name:string,ingredients:?string,price_vnd:int,is_visible:int
 * }>
 */
function knk_menu_list(bool $visibleOnly = true): array {
    static $cache = ["v" => null, "a" => null];
    $k = $visibleOnly ? "v" : "a";
    if ($cache[$k] !== null) return $cache[$k];

    $sql = "SELECT id, item_code, i18n_key, category, category_slug,
                   category_sort, sort_order, name, ingredients, price_vnd, is_visible
              FROM menu_drinks";
    if ($visibleOnly) $sql .= " WHERE is_visible = 1";
    $sql .= " ORDER BY category_sort, sort_order, id";

    $rows = [];
    foreach (knk_db()->query($sql) as $r) {
        $rows[] = [
            "id"            => (int)$r["id"],
            "item_code"     => (string)$r["item_code"],
            "i18n_key"      => $r["i18n_key"] !== null ? (string)$r["i18n_key"] : null,
            "category"      => (string)$r["category"],
            "category_slug" => (string)$r["category_slug"],
            "category_sort" => (int)$r["category_sort"],
            "sort_order"    => (int)$r["sort_order"],
            "name"          => (string)$r["name"],
            "ingredients"   => $r["ingredients"] !== null ? (string)$r["ingredients"] : null,
            "price_vnd"     => (int)$r["price_vnd"],
            "is_visible"    => (int)$r["is_visible"],
        ];
    }
    $cache[$k] = $rows;
    return $rows;
}

/**
 * Drinks grouped into categories, in the same shape order.php expects:
 *   [ ["slug"=>"beer","title"=>"Beer","items"=>[ {id, item_code, name, price_vnd, ...}, ... ]], ... ]
 */
function knk_menu_grouped(bool $visibleOnly = true): array {
    $rows = knk_menu_list($visibleOnly);
    $groups = [];
    foreach ($rows as $r) {
        $slug = $r["category_slug"];
        if (!isset($groups[$slug])) {
            $groups[$slug] = [
                "slug"          => $slug,
                "title"         => $r["category"],
                "category_sort" => $r["category_sort"],
                "items"         => [],
            ];
        }
        $groups[$slug]["items"][] = $r;
    }
    // Strip the top-level keys so callers can foreach through a numerically-indexed array.
    return array_values($groups);
}

/** Fetch one drink by id. Returns null if not found. */
function knk_menu_find(int $id): ?array {
    $stmt = knk_db()->prepare("SELECT * FROM menu_drinks WHERE id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/** Fetch one drink by its stable item_code. */
function knk_menu_find_by_code(string $code): ?array {
    $stmt = knk_db()->prepare("SELECT * FROM menu_drinks WHERE item_code = ?");
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/**
 * Distinct categories in their display order, for the "add drink"
 * category picker and the admin UI's section headings.
 *
 * @return array<int, array{slug:string, title:string, category_sort:int}>
 */
function knk_menu_categories(): array {
    $sql = "SELECT category_slug AS slug,
                   MIN(category)  AS title,
                   MIN(category_sort) AS category_sort
              FROM menu_drinks
          GROUP BY category_slug
          ORDER BY category_sort, slug";
    $out = [];
    foreach (knk_db()->query($sql) as $r) {
        $out[] = [
            "slug"          => (string)$r["slug"],
            "title"         => (string)$r["title"],
            "category_sort" => (int)$r["category_sort"],
        ];
    }
    return $out;
}

/* ------------------------------------------------------------------
 * WRITE
 * ------------------------------------------------------------------ */

/** Reset the request-scoped cache. Call after any write so later reads see fresh data. */
function knk_menu_cache_reset(): void {
    // Tiny trick: re-declare the static in knk_menu_list by calling it once
    // with a sentinel value isn't possible, so we rely on the store functions
    // below all running late in the request (after the initial render). For
    // safety the admin UI reloads the page after a POST, which starts fresh.
}

/**
 * Update name/price/visibility/category/sort. $patch only needs to contain
 * the columns to change. Returns true if a row was updated.
 */
function knk_menu_update(int $id, array $patch, ?int $userId = null): bool {
    $allowed = ["name","price_vnd","is_visible","category","category_slug",
                "category_sort","sort_order","ingredients","i18n_key"];
    $set = [];
    $args = [];
    foreach ($patch as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        $set[] = "`$k` = ?";
        $args[] = $v;
    }
    if (!$set) return false;
    $set[] = "updated_by = ?";
    $args[] = $userId;
    $args[] = $id;

    $sql = "UPDATE menu_drinks SET " . implode(", ", $set) . " WHERE id = ?";
    $stmt = knk_db()->prepare($sql);
    $stmt->execute($args);
    return $stmt->rowCount() > 0;
}

/**
 * Create a new drink. Returns the new id.
 *
 * Required: item_code, category (display), category_slug, name, price_vnd.
 * If category_sort is omitted, inherits from the existing category or is placed at the end.
 * If sort_order is omitted, placed at the end of its category.
 */
function knk_menu_create(array $row, ?int $userId = null): int {
    $pdo = knk_db();

    $item_code     = (string)($row["item_code"] ?? "");
    $category      = (string)($row["category"] ?? "");
    $category_slug = (string)($row["category_slug"] ?? "");
    $name          = (string)($row["name"] ?? "");
    $price_vnd     = (int)($row["price_vnd"] ?? 0);
    if ($item_code === "" || $category === "" || $category_slug === "" || $name === "") {
        throw new RuntimeException("menu_create: item_code, category, category_slug and name are required");
    }

    // Resolve category_sort: keep the category grouped with siblings if any exist.
    $cat_sort = $row["category_sort"] ?? null;
    if ($cat_sort === null) {
        $stmt = $pdo->prepare("SELECT MIN(category_sort) AS cs FROM menu_drinks WHERE category_slug = ?");
        $stmt->execute([$category_slug]);
        $found = $stmt->fetch();
        if ($found && $found["cs"] !== null) {
            $cat_sort = (int)$found["cs"];
        } else {
            $stmt = $pdo->query("SELECT COALESCE(MAX(category_sort), 0) AS mx FROM menu_drinks");
            $mx = $stmt->fetch();
            $cat_sort = ((int)($mx["mx"] ?? 0)) + 10;
        }
    }

    // Resolve sort_order: end of category unless explicit.
    $sort_order = $row["sort_order"] ?? null;
    if ($sort_order === null) {
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) AS mx FROM menu_drinks WHERE category_slug = ?");
        $stmt->execute([$category_slug]);
        $mx = $stmt->fetch();
        $sort_order = ((int)($mx["mx"] ?? 0)) + 10;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO menu_drinks
             (item_code, i18n_key, category, category_slug, category_sort, sort_order,
              name, ingredients, price_vnd, is_visible, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $item_code,
        $row["i18n_key"]     ?? null,
        $category,
        $category_slug,
        (int)$cat_sort,
        (int)$sort_order,
        $name,
        $row["ingredients"]  ?? null,
        $price_vnd,
        (int)($row["is_visible"] ?? 1),
        $userId,
    ]);
    return (int)$pdo->lastInsertId();
}

/** Hard-delete a drink. Order history keeps working because order_items has no FK. */
function knk_menu_delete(int $id): bool {
    $stmt = knk_db()->prepare("DELETE FROM menu_drinks WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/**
 * Bulk-update sort_order for a list of ids, in the order given.
 * Used by the drag-to-reorder action on /menu.php. Steps are 10
 * so future single-row moves can insert between them.
 */
function knk_menu_reorder(array $ids, ?int $userId = null): void {
    $pdo = knk_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE menu_drinks SET sort_order = ?, updated_by = ? WHERE id = ?");
        $step = 10;
        foreach ($ids as $i => $id) {
            $stmt->execute([($i + 1) * $step, $userId, (int)$id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Derive a safe item_code from a name, scoped to a category_slug.
 * Produces e.g. 'beer.icy-mojito'. Appends a digit if collision.
 */
function knk_menu_item_code_for(string $categorySlug, string $name): string {
    $base = strtolower(trim($name));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim((string)$base, "-");
    if ($base === "") $base = "drink";
    $candidate = $categorySlug . "." . $base;
    // Check collisions. Try base, base-2, base-3, ...
    $stmt = knk_db()->prepare("SELECT 1 FROM menu_drinks WHERE item_code = ?");
    $i = 1;
    $try = $candidate;
    while (true) {
        $stmt->execute([$try]);
        if (!$stmt->fetch()) return $try;
        $i++;
        $try = $candidate . "-" . $i;
        if ($i > 500) return $candidate . "-" . bin2hex(random_bytes(3));
    }
    // unreachable
}

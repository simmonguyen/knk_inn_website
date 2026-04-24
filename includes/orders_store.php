<?php
/*
 * KnK Inn — orders store (flat-file, flock-protected).
 *
 * orders.json schema:
 * {
 *   "orders": [
 *     { "id": "o_abc123", "token": "tok_<32hex>",
 *       "email": "guest@example.com",
 *       "location": "rooftop|floor-5|floor-1|room",
 *       "room_number": "301" | null,
 *       "items": [ { "id": "beer.tiger", "name": "Tiger", "price_vnd": 60000, "qty": 2 }, ... ],
 *       "subtotal_vnd": 120000,
 *       "vat_vnd": 12000,
 *       "total_vnd": 132000,
 *       "notes": "...",
 *       "status": "pending|received|paid|cancelled",
 *       "created_at": 1714000000,
 *       "received_at": null,
 *       "paid_at": null
 *     }, ...
 *   ]
 * }
 *
 * The menu itself is parsed on demand from drinks.html so there's ONE source
 * of truth — Simmo edits drinks.html, the order page picks up the change.
 */

if (!defined("KNK_ORDERS_PATH")) define("KNK_ORDERS_PATH", __DIR__ . "/../orders.json");
if (!defined("KNK_DRINKS_HTML")) define("KNK_DRINKS_HTML", __DIR__ . "/../drinks.html");
if (!defined("KNK_VAT_RATE"))    define("KNK_VAT_RATE", 0.10);  // Vietnam standard VAT 10%

/* =========================================================
   MENU — parsed from drinks.html
   ========================================================= */

/**
 * Parse drinks.html → [ ["title"=>"Beer", "items"=>[ ["id"=>..., "name"=>..., "price_vnd"=>...], ... ] ], ... ]
 *
 * Cached for the request.
 */
function knk_menu(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $html = @file_get_contents(KNK_DRINKS_HTML);
    if ($html === false) return $cache = [];

    // Load into DOMDocument (suppress HTML5 warnings)
    $prev = libxml_use_internal_errors(true);
    $dom  = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $xp = new DOMXPath($dom);
    $cats = $xp->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' drink-cat ')]");

    $menu = [];
    foreach ($cats as $cat) {
        $titleNode = $xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' drink-cat-title ')]", $cat)->item(0);
        if (!$titleNode) continue;
        $title = trim($titleNode->textContent);

        $items = [];
        $rows = $xp->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' drink-item ')]", $cat);
        foreach ($rows as $row) {
            $nameNode  = $xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' drink-name ')]",  $row)->item(0);
            $priceNode = $xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' drink-price ')]", $row)->item(0);
            if (!$nameNode || !$priceNode) continue;

            $name = trim($nameNode->textContent);
            $priceRaw = trim($priceNode->textContent);
            // "45,000đ" or "1,000,000đ" → int
            $digits = preg_replace('/[^0-9]/', '', $priceRaw);
            if ($digits === '') continue;
            $price = (int)$digits;

            // Stable id — prefer the data-i18n key from the drink-name span, else hash of name
            $key = $nameNode->getAttribute("data-i18n");
            $id  = $key !== "" ? $key : "x." . substr(md5($name), 0, 10);

            $items[] = [
                "id"        => $id,
                "name"      => $name,
                "price_vnd" => $price,
            ];
        }
        if ($items) {
            $menu[] = ["title" => $title, "items" => $items];
        }
    }
    return $cache = $menu;
}

/** Flatten menu to id → item lookup. */
function knk_menu_lookup(): array {
    static $lk = null;
    if ($lk !== null) return $lk;
    $lk = [];
    foreach (knk_menu() as $cat) {
        foreach ($cat["items"] as $it) {
            $lk[$it["id"]] = $it;
        }
    }
    return $lk;
}

/* =========================================================
   ORDERS STORE (mirrors bookings_store.php pattern)
   ========================================================= */

function orders_open(): array {
    $path = KNK_ORDERS_PATH;
    if (!file_exists($path)) {
        @file_put_contents($path, json_encode(["orders" => []], JSON_PRETTY_PRINT));
    }
    $fp = fopen($path, "r+");
    if (!$fp) throw new RuntimeException("Cannot open orders store");
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException("Cannot lock orders store");
    }
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: '{"orders":[]}', true);
    if (!is_array($data) || !isset($data["orders"])) $data = ["orders" => []];
    return [$fp, $data];
}

function orders_save($fp, array $data): void {
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function orders_close($fp): void {
    flock($fp, LOCK_UN);
    fclose($fp);
}

/** Read-only snapshot of all orders. */
function orders_all(): array {
    [$fp, $data] = orders_open();
    orders_close($fp);
    return $data["orders"];
}

/** All orders for a given email, newest first. */
function orders_for_email(string $email): array {
    $email = strtolower(trim($email));
    $out = [];
    foreach (orders_all() as $o) {
        if (strtolower($o["email"] ?? "") === $email) $out[] = $o;
    }
    usort($out, fn($a, $b) => ($b["created_at"] ?? 0) <=> ($a["created_at"] ?? 0));
    return $out;
}

/** Create a new order. Returns the persisted order including id + token. */
function orders_create(array $o): array {
    [$fp, $data] = orders_open();
    $o["id"]          = $o["id"]          ?? ("o_" . substr(bin2hex(random_bytes(6)), 0, 10));
    $o["token"]       = $o["token"]       ?? ("tok_" . bin2hex(random_bytes(16)));
    $o["status"]      = $o["status"]      ?? "pending";
    $o["created_at"]  = $o["created_at"]  ?? time();
    $o["received_at"] = $o["received_at"] ?? null;
    $o["paid_at"]     = $o["paid_at"]     ?? null;
    $data["orders"][] = $o;
    orders_save($fp, $data);
    return $o;
}

/** Find one order by id. */
function orders_find_by_id(string $id): ?array {
    foreach (orders_all() as $o) if (($o["id"] ?? "") === $id) return $o;
    return null;
}

/** Find one order by token. */
function orders_find_by_token(string $token): ?array {
    foreach (orders_all() as $o) if (($o["token"] ?? "") === $token) return $o;
    return null;
}

/** Flip an order's status. Returns the updated order (or null). */
function orders_set_status_by_token(string $token, string $status): ?array {
    if (!in_array($status, ["pending", "received", "paid", "cancelled"], true)) return null;
    [$fp, $data] = orders_open();
    $hit = null;
    foreach ($data["orders"] as &$o) {
        if (($o["token"] ?? "") === $token) {
            $o["status"] = $status;
            if ($status === "received" && empty($o["received_at"])) $o["received_at"] = time();
            if ($status === "paid"     && empty($o["paid_at"]))     $o["paid_at"]     = time();
            $hit = $o;
            break;
        }
    }
    unset($o);
    orders_save($fp, $data);
    return $hit;
}

function orders_set_status_by_id(string $id, string $status): ?array {
    if (!in_array($status, ["pending", "received", "paid", "cancelled"], true)) return null;
    [$fp, $data] = orders_open();
    $hit = null;
    foreach ($data["orders"] as &$o) {
        if (($o["id"] ?? "") === $id) {
            $o["status"] = $status;
            if ($status === "received" && empty($o["received_at"])) $o["received_at"] = time();
            if ($status === "paid"     && empty($o["paid_at"]))     $o["paid_at"]     = time();
            $hit = $o;
            break;
        }
    }
    unset($o);
    orders_save($fp, $data);
    return $hit;
}

/* =========================================================
   Formatting helpers
   ========================================================= */

function knk_vnd(int $n): string {
    return number_format($n, 0, '.', ',') . "đ";
}

function knk_location_label(string $loc, ?string $room = null): string {
    switch ($loc) {
        case "rooftop": return "Rooftop (Level 6)";
        case "floor-5": return "Level 5 bar";
        case "floor-1": return "Ground-floor bar";
        case "room":    return "Room " . ($room !== null && $room !== "" ? $room : "(no number given)");
        default:        return $loc ?: "Unknown";
    }
}

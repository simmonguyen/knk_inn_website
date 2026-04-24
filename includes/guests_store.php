<?php
/*
 * KnK Inn — guests store (DB-backed, email-keyed).
 *
 * Every time a booking enquiry or drink order comes in with an email
 * address, we upsert a row in the `guests` table. That row accumulates
 * a cached summary of the guest's visits — #bookings, #orders, total
 * spend in VND, favourite drink, favourite day-of-week.
 *
 * History itself (individual bookings, individual orders) still lives
 * in bookings.json and orders.json during V2. The admin profile page
 * pulls history from those JSON stores, filtered by email — so even a
 * brand-new guest row immediately shows all past visits.
 *
 * Gating: Phase 3 exposes guests to super_admin + owner only. See
 * knk_role_nav() in includes/auth.php.
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/bookings_store.php";
require_once __DIR__ . "/orders_store.php";

/* =========================================================
   UPSERT — called from enquire.php and order.php
   ========================================================= */

/**
 * Upsert a guest row by email. Returns the guest id, or null on failure.
 *
 * Behaviour:
 *   - normalises the email (lowercased + trimmed)
 *   - on INSERT: sets first_seen_at = NOW(), last_seen_at = NOW()
 *   - on UPDATE: bumps last_seen_at = NOW(); fills in name/phone only
 *                if we didn't already have one (so a later enquiry can't
 *                overwrite a better contact record)
 *
 * Safe to call on every enquiry/order — idempotent and cheap.
 * DB errors are swallowed (returns null) so write paths never fail
 * because of a guest-table issue.
 */
function knk_guest_upsert(string $email, ?string $name = null, ?string $phone = null): ?int {
    $email = strtolower(trim($email));
    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    $name  = $name  !== null ? trim($name)  : null;
    $phone = $phone !== null ? trim($phone) : null;
    if ($name  === "") $name  = null;
    if ($phone === "") $phone = null;

    try {
        $pdo = knk_db();

        // Fast path: does the row exist?
        $sel = $pdo->prepare("SELECT id, name, phone FROM guests WHERE email = ? LIMIT 1");
        $sel->execute([$email]);
        $row = $sel->fetch();

        if ($row) {
            $id = (int)$row["id"];
            // Only fill in missing contact fields; never overwrite.
            $fill_name  = (empty($row["name"])  && $name  !== null);
            $fill_phone = (empty($row["phone"]) && $phone !== null);
            if ($fill_name || $fill_phone) {
                $sets = [];
                $args = [];
                if ($fill_name)  { $sets[] = "name = ?";  $args[] = $name;  }
                if ($fill_phone) { $sets[] = "phone = ?"; $args[] = $phone; }
                $sets[] = "last_seen_at = NOW()";
                $args[] = $id;
                $pdo->prepare("UPDATE guests SET " . implode(", ", $sets) . " WHERE id = ?")
                    ->execute($args);
            } else {
                $pdo->prepare("UPDATE guests SET last_seen_at = NOW() WHERE id = ?")
                    ->execute([$id]);
            }
            return $id;
        }

        // Insert new row.
        $ins = $pdo->prepare(
            "INSERT INTO guests (email, name, phone, first_seen_at, last_seen_at)
             VALUES (?, ?, ?, NOW(), NOW())"
        );
        $ins->execute([$email, $name, $phone]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log("knk_guest_upsert: " . $e->getMessage());
        return null;
    }
}

/* =========================================================
   STATS REFRESH
   ========================================================= */

/**
 * Recompute the cached counters for one guest from bookings.json +
 * orders.json. Writes back the updated numbers. Swallows errors.
 *
 * Called after every new booking or order for that guest so the list
 * view stays in sync. Cheap enough — the JSON files are small.
 */
function knk_guest_refresh_stats(int $guest_id): void {
    try {
        $g = knk_guest_get($guest_id);
        if (!$g) return;
        $email = strtolower(trim((string)$g["email"]));
        if ($email === "") return;

        // --- bookings ---
        $bookings = knk_guest_bookings_for_email($email);
        $bookings_count = 0;
        $visits_count   = 0; // counted as confirmed bookings
        $total_booking_vnd = 0;
        $day_tally = []; // 'Mon' => n
        foreach ($bookings as $b) {
            $status = (string)($b["status"] ?? "pending");
            if ($status === "declined" || $status === "expired" || $status === "cancelled") continue;
            $bookings_count++;
            if ($status === "confirmed" || $status === "completed") $visits_count++;
            $nights = (int)($b["nights"] ?? 0);
            $ppn    = (int)($b["price_vnd_per_night"] ?? 0);
            $total_booking_vnd += $nights * $ppn;
            // day-of-week tally — check-in day
            $ci = (string)($b["checkin"] ?? "");
            if ($ci !== "") {
                $t = strtotime($ci);
                if ($t) {
                    $dow = date("D", $t); // Mon, Tue, ...
                    $day_tally[$dow] = ($day_tally[$dow] ?? 0) + 1;
                }
            }
        }

        // --- orders ---
        $orders = knk_guest_orders_for_email($email);
        $orders_count = 0;
        $total_order_vnd = 0;
        $item_tally = []; // item_name => quantity
        foreach ($orders as $o) {
            $status = (string)($o["status"] ?? "pending");
            if ($status === "cancelled") continue;
            $orders_count++;
            $total_order_vnd += (int)($o["total_vnd"] ?? 0);
            foreach (($o["items"] ?? []) as $it) {
                $name = trim((string)($it["name"] ?? ""));
                if ($name === "") continue;
                $qty  = (int)($it["qty"] ?? 1);
                $item_tally[$name] = ($item_tally[$name] ?? 0) + $qty;
            }
            // order day tally (supplemental — pubs often see same-day regulars)
            $ct = (int)($o["created_at"] ?? 0);
            if ($ct) {
                $dow = date("D", $ct);
                $day_tally[$dow] = ($day_tally[$dow] ?? 0) + 1;
            }
        }

        // Favourite item + day (top-count, alphabetical tiebreak for stability).
        $fav_item = null;
        if (!empty($item_tally)) {
            arsort($item_tally);
            $fav_item = (string)array_key_first($item_tally);
        }
        $fav_day = null;
        if (!empty($day_tally)) {
            arsort($day_tally);
            $fav_day = (string)array_key_first($day_tally);
        }

        $total_vnd = $total_booking_vnd + $total_order_vnd;

        $pdo = knk_db();
        $pdo->prepare(
            "UPDATE guests
                SET visits_count    = ?,
                    orders_count    = ?,
                    bookings_count  = ?,
                    total_vnd       = ?,
                    favourite_item  = ?,
                    favourite_day   = ?,
                    last_seen_at    = NOW()
              WHERE id = ?"
        )->execute([
            $visits_count, $orders_count, $bookings_count,
            $total_vnd, $fav_item, $fav_day, $guest_id,
        ]);
    } catch (Throwable $e) {
        error_log("knk_guest_refresh_stats({$guest_id}): " . $e->getMessage());
    }
}

/* =========================================================
   READ — for the admin UI
   ========================================================= */

/** Look up a guest by id. */
function knk_guest_get(int $guest_id): ?array {
    if ($guest_id <= 0) return null;
    try {
        $stmt = knk_db()->prepare("SELECT * FROM guests WHERE id = ? LIMIT 1");
        $stmt->execute([$guest_id]);
        $row = $stmt->fetch();
        return $row ? $row : null;
    } catch (Throwable $e) {
        error_log("knk_guest_get: " . $e->getMessage());
        return null;
    }
}

/** Look up a guest by email (already normalised or not). */
function knk_guest_find_by_email(string $email): ?array {
    $email = strtolower(trim($email));
    if ($email === "") return null;
    try {
        $stmt = knk_db()->prepare("SELECT * FROM guests WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ? $row : null;
    } catch (Throwable $e) {
        error_log("knk_guest_find_by_email: " . $e->getMessage());
        return null;
    }
}

/**
 * List guests for the admin table. Optional search query matches
 * name / email / phone. Sorted by last_seen_at DESC (most recent first).
 */
function knk_guests_list(string $q = "", int $limit = 200): array {
    $limit = max(1, min(1000, $limit));
    try {
        $pdo = knk_db();
        $q = trim($q);
        if ($q === "") {
            $stmt = $pdo->prepare(
                "SELECT * FROM guests
                  ORDER BY COALESCE(last_seen_at, first_seen_at) DESC
                  LIMIT {$limit}"
            );
            $stmt->execute();
        } else {
            $like = "%" . $q . "%";
            $stmt = $pdo->prepare(
                "SELECT * FROM guests
                  WHERE email LIKE ? OR name LIKE ? OR phone LIKE ?
                  ORDER BY COALESCE(last_seen_at, first_seen_at) DESC
                  LIMIT {$limit}"
            );
            $stmt->execute([$like, $like, $like]);
        }
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log("knk_guests_list: " . $e->getMessage());
        return [];
    }
}

/* =========================================================
   HISTORY — read from the JSON stores
   ========================================================= */

/** All bookings where the saved guest email matches. Newest first. */
function knk_guest_bookings_for_email(string $email): array {
    $email = strtolower(trim($email));
    if ($email === "") return [];
    try {
        $all = bookings_list_all(false); // don't expire-bump while reading profile
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($all as $b) {
        $be = strtolower(trim((string)(($b["guest"]["email"] ?? "") ?: "")));
        if ($be === $email) $out[] = $b;
    }
    usort($out, function ($a, $b) {
        return ((int)($b["created_at"] ?? 0)) <=> ((int)($a["created_at"] ?? 0));
    });
    return $out;
}

/** All drink orders for this email. Newest first. Reuses orders_for_email. */
function knk_guest_orders_for_email(string $email): array {
    $email = strtolower(trim($email));
    if ($email === "") return [];
    try {
        return orders_for_email($email);
    } catch (Throwable $e) {
        return [];
    }
}

/* =========================================================
   NOTES — free-text field on the profile
   ========================================================= */

function knk_guest_update_notes(int $guest_id, string $notes, int $user_id): bool {
    if ($guest_id <= 0) return false;
    $notes = trim($notes);
    // Soft cap — the column is TEXT so MySQL won't truncate, but a UI
    // limit keeps the admin table sane.
    if (strlen($notes) > 8000) $notes = substr($notes, 0, 8000);
    try {
        $pdo = knk_db();
        $pdo->prepare("UPDATE guests SET notes = ? WHERE id = ?")
            ->execute([$notes !== "" ? $notes : null, $guest_id]);
        if (function_exists("knk_audit")) {
            knk_audit("guest.notes.update", "guests", (string)$guest_id, [
                "length"  => strlen($notes),
                "user_id" => $user_id,
            ]);
        }
        return true;
    } catch (Throwable $e) {
        error_log("knk_guest_update_notes: " . $e->getMessage());
        return false;
    }
}

/* =========================================================
   DISPLAY HELPERS
   ========================================================= */

/** Format VND amount as "1,234,000 ₫" (no decimals, thousands grouped). */
function knk_fmt_vnd(int $vnd): string {
    return number_format($vnd, 0, ".", ",") . " ₫";
}

/** Short display label for a guest: "Name <email>" or just the email. */
function knk_guest_display(array $g): string {
    $name  = trim((string)($g["name"]  ?? ""));
    $email = trim((string)($g["email"] ?? ""));
    if ($name !== "" && $email !== "") return $name . " <" . $email . ">";
    if ($name !== "") return $name;
    return $email;
}

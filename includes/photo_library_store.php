<?php
/*
 * KnK Inn — photo_library store.
 *
 * Master index of every photo available on the site. Drives:
 *
 *   - The Gallery wall in /photos.php (browse + tag + hide + delete)
 *   - The "Pick from gallery" picker in the slot Replace dialog
 *
 * Photo sources (matches the `source` ENUM in migration 011):
 *
 *   seed          — files in /assets/img/ (the original ex_/nw_/rm_ shots
 *                   shipped with the site). Never deletable.
 *   gallery_live  — files in /assets/img/gallery-live/ (Simmo's bulk
 *                   uploads from the gallery wall).
 *   slot_upload   — files in /assets/img/slots/ (uploads done from
 *                   inside a homepage slot's Replace dialog).
 *
 * `filename` is stored as the path RELATIVE to /assets/img/ — i.e. the
 * same convention photo_slots uses. So 'ex_06.jpg' for a seed file,
 * 'gallery-live/...jpg' for a bulk upload, 'slots/...jpg' for a slot
 * upload. knk_photo_src() in photo_slots_store.php prefixes 'assets/img/'.
 *
 * `tags` is a JSON array of tag strings — the whitelist is in
 * knk_photo_tags() below. Anything not in the whitelist is dropped on
 * save; case is normalised on the way in.
 *
 * Auto-scan: knk_photo_library_scan() walks the three directories and
 * INSERT IGNOREs any rows it doesn't already have, applying obvious
 * auto-tags (rm_* → Rooms, ex_* → Exterior). Called once when the
 * Gallery wall tab loads, so new files just appear.
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/photo_slots_store.php";   // KNK_SLOTS_DIR / KNK_SLOTS_URL_PREFIX

/* ------------------------------------------------------------------
 * Tag whitelist.
 *
 * Keep this short — the picker shows them all as filter chips, and
 * the photo card only has room for two or three. Edit here to add /
 * remove. Values must be ASCII / safe for JSON.
 *
 * The order here is the order they show up in the UI.
 * ------------------------------------------------------------------ */
function knk_photo_tags(): array {
    return [
        "Rooms",
        "Rooftop",
        "Lounge",
        "Darts",
        "Exterior",
        "Sports",
        "People",
        "Other",
    ];
}

/* Normalise + filter a user-supplied list of tags down to the whitelist. */
function knk_photo_tags_clean($input): array {
    if (!is_array($input)) return [];
    $allowed = knk_photo_tags();
    $by_lower = [];
    foreach ($allowed as $t) { $by_lower[strtolower($t)] = $t; }

    $out = [];
    foreach ($input as $raw) {
        if (!is_string($raw)) continue;
        $key = strtolower(trim($raw));
        if ($key === "") continue;
        if (!isset($by_lower[$key])) continue;
        $canon = $by_lower[$key];
        if (!in_array($canon, $out, true)) $out[] = $canon;
    }
    return $out;
}

/* Decode the `tags` TEXT column into an array (always returns array). */
function knk_photo_tags_decode($json): array {
    if ($json === null || $json === "") return [];
    $arr = json_decode((string)$json, true);
    if (!is_array($arr)) return [];
    return knk_photo_tags_clean($arr);
}

/* ------------------------------------------------------------------
 * Auto-tag rules — applied once on first scan of a file.
 *
 * Only fires for `seed` files where the filename pattern is obvious.
 * Everything else (gallery_live, slot_upload, nw_*) starts untagged
 * and Ben/Simmo tag it from the gallery wall.
 * ------------------------------------------------------------------ */
function knk_photo_auto_tags(string $filename, string $source): array {
    if ($source !== "seed") return [];
    $base = strtolower(basename($filename));
    if (strncmp($base, "rm_", 3) === 0) return ["Rooms"];
    if (strncmp($base, "ex_", 3) === 0) return ["Exterior"];
    return [];
}

/* ------------------------------------------------------------------
 * Scan the three photo directories and INSERT IGNORE any rows we
 * don't already have. Returns the number of new rows added.
 *
 * Cheap to call (one INSERT IGNORE per file, ~150 files) — safe to
 * run on every Gallery wall page load.
 * ------------------------------------------------------------------ */
function knk_photo_library_scan(): int {
    try {
        $pdo = knk_db();
    } catch (Throwable $e) {
        return 0;
    }

    $img_root = __DIR__ . "/../assets/img";

    $found = []; // [filename => source]

    // --- seed: top-level ex_/nw_/rm_/etc.
    if (is_dir($img_root)) {
        $dh = @opendir($img_root);
        if ($dh) {
            while (($f = readdir($dh)) !== false) {
                if ($f === "." || $f === "..") continue;
                $path = $img_root . "/" . $f;
                if (!is_file($path)) continue;
                if (!preg_match('/\.(jpe?g|png|webp)$/i', $f)) continue;
                $found[$f] = "seed";
            }
            closedir($dh);
        }
    }

    // --- gallery_live
    $gl_dir = $img_root . "/gallery-live";
    if (is_dir($gl_dir)) {
        $dh = @opendir($gl_dir);
        if ($dh) {
            while (($f = readdir($dh)) !== false) {
                if ($f === "." || $f === "..") continue;
                $path = $gl_dir . "/" . $f;
                if (!is_file($path)) continue;
                if (!preg_match('/\.(jpe?g|png|webp)$/i', $f)) continue;
                $found["gallery-live/" . $f] = "gallery_live";
            }
            closedir($dh);
        }
    }

    // --- slot_upload
    $sl_dir = $img_root . "/slots";
    if (is_dir($sl_dir)) {
        $dh = @opendir($sl_dir);
        if ($dh) {
            while (($f = readdir($dh)) !== false) {
                if ($f === "." || $f === "..") continue;
                $path = $sl_dir . "/" . $f;
                if (!is_file($path)) continue;
                if (!preg_match('/\.(jpe?g|png|webp)$/i', $f)) continue;
                $found["slots/" . $f] = "slot_upload";
            }
            closedir($dh);
        }
    }

    if (empty($found)) return 0;

    // Find the current max sort_order so new rows get appended.
    $next_sort = 0;
    try {
        $r = $pdo->query("SELECT COALESCE(MAX(sort_order), 0) AS m FROM photo_library")->fetch();
        $next_sort = (int)($r["m"] ?? 0);
    } catch (Throwable $e) {
        // Pre-migration-012 schema — sort_order column doesn't exist yet.
        $next_sort = -1;
    }

    if ($next_sort >= 0) {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO photo_library (filename, source, tags, sort_order)
                  VALUES (?, ?, ?, ?)"
        );
    } else {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO photo_library (filename, source, tags)
                  VALUES (?, ?, ?)"
        );
    }

    $added = 0;
    foreach ($found as $fn => $source) {
        $tags = knk_photo_auto_tags($fn, $source);
        $tags_json = json_encode($tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        try {
            if ($next_sort >= 0) {
                $next_sort++;
                $stmt->execute([$fn, $source, $tags_json, $next_sort]);
            } else {
                $stmt->execute([$fn, $source, $tags_json]);
            }
            if ($stmt->rowCount() > 0) $added++;
        } catch (Throwable $e) {
            // Ignore — bad row shouldn't kill the scan.
        }
    }
    return $added;
}

/* ------------------------------------------------------------------
 * Insert a single photo into the library. Used by the slot Replace
 * upload path so a fresh slot upload immediately appears on the
 * gallery wall (rather than waiting for the next scan).
 *
 * Idempotent — if filename already exists, just leaves it.
 * ------------------------------------------------------------------ */
function knk_photo_library_register(string $filename, string $source, array $tags = []): void {
    if ($filename === "") return;
    try {
        $pdo  = knk_db();
        $tags = knk_photo_tags_clean($tags);
        $tj   = json_encode($tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Assign next sort_order so the new photo appears at the end of
        // the gallery (graceful fallback if column doesn't exist yet).
        try {
            $r = $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS m FROM photo_library")->fetch();
            $next = (int)($r["m"] ?? 1);
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO photo_library (filename, source, tags, sort_order) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$filename, $source, $tj, $next]);
        } catch (Throwable $e) {
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO photo_library (filename, source, tags) VALUES (?, ?, ?)"
            );
            $stmt->execute([$filename, $source, $tj]);
        }
    } catch (Throwable $e) {
        // best-effort
    }
}

/* ------------------------------------------------------------------
 * List photos. Filters are all optional:
 *   $opts['tag']         — string, only photos with this tag
 *   $opts['source']      — 'seed' | 'gallery_live' | 'slot_upload'
 *   $opts['include_hidden'] — bool, default false
 *
 * Returns rows sorted by source then filename, with `tags` already
 * decoded into an array.
 * ------------------------------------------------------------------ */
function knk_photo_library_list(array $opts = []): array {
    try {
        $pdo = knk_db();
    } catch (Throwable $e) {
        return [];
    }

    // Pull sort_order if the column exists (post-migration-012).
    // Falls back to a constant if not, so the page still renders before
    // migration 012 is run.
    $has_sort = false;
    try {
        $pdo->query("SELECT sort_order FROM photo_library LIMIT 0");
        $has_sort = true;
    } catch (Throwable $e) {
        $has_sort = false;
    }

    $select_sort = $has_sort ? ", sort_order" : ", 0 AS sort_order";
    $sql    = "SELECT id, filename, source, tags, hidden, updated_at" . $select_sort . " FROM photo_library";
    $where  = [];
    $params = [];

    if (empty($opts["include_hidden"])) {
        $where[] = "hidden = 0";
    }
    if (!empty($opts["source"])) {
        $where[]  = "source = ?";
        $params[] = (string)$opts["source"];
    }
    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    if ($has_sort) {
        $sql .= " ORDER BY sort_order, filename";
    } else {
        $sql .= " ORDER BY FIELD(source,'seed','gallery_live','slot_upload'), filename";
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    $tag_filter = isset($opts["tag"]) ? trim((string)$opts["tag"]) : "";
    if ($tag_filter !== "") {
        $allowed = knk_photo_tags();
        $by_lower = [];
        foreach ($allowed as $t) { $by_lower[strtolower($t)] = $t; }
        $tag_filter_canon = $by_lower[strtolower($tag_filter)] ?? "";
    } else {
        $tag_filter_canon = "";
    }

    $out = [];
    foreach ($rows as $r) {
        $tags = knk_photo_tags_decode($r["tags"] ?? "");
        if ($tag_filter_canon !== "" && !in_array($tag_filter_canon, $tags, true)) continue;
        $r["tags"]       = $tags;
        $r["hidden"]     = (int)$r["hidden"];
        $r["sort_order"] = (int)($r["sort_order"] ?? 0);
        $r["url"]        = "assets/img/" . $r["filename"];
        $out[] = $r;
    }
    return $out;
}

/* ------------------------------------------------------------------
 * Reorder photos. $filenames is a list of filenames in the desired
 * order — gets assigned sort_order values 1, 2, 3, ...
 *
 * Anything not in the list keeps its existing sort_order (so the
 * caller can send a partial list — e.g. only the visible filtered
 * tiles — and the rest fall in around them).
 *
 * Returns ['ok' => bool, 'updated' => int, 'error' => string].
 * ------------------------------------------------------------------ */
function knk_photo_library_reorder(array $filenames): array {
    if (empty($filenames)) return ["ok" => true, "updated" => 0];
    try {
        $pdo  = knk_db();
        $stmt = $pdo->prepare("UPDATE photo_library SET sort_order = ? WHERE filename = ?");
        $updated = 0;
        $i = 0;
        foreach ($filenames as $fn) {
            if (!is_string($fn) || $fn === "") continue;
            $i++;
            $stmt->execute([$i, $fn]);
            $updated += $stmt->rowCount();
        }
    } catch (Throwable $e) {
        return ["ok" => false, "error" => "DB error during reorder."];
    }
    if (function_exists("knk_audit")) {
        knk_audit("photo_library.reorder", "photo", "(batch)", ["count" => $updated]);
    }
    return ["ok" => true, "updated" => $updated];
}

/* Counts per tag (incl. an "(untagged)" bucket). Powers the chip badges. */
function knk_photo_library_tag_counts(): array {
    $rows = knk_photo_library_list(["include_hidden" => false]);
    $counts = ["__all" => count($rows), "__untagged" => 0];
    foreach (knk_photo_tags() as $t) $counts[$t] = 0;
    foreach ($rows as $r) {
        if (empty($r["tags"])) {
            $counts["__untagged"]++;
            continue;
        }
        foreach ($r["tags"] as $t) {
            if (isset($counts[$t])) $counts[$t]++;
        }
    }
    return $counts;
}

/* ------------------------------------------------------------------
 * Set tags for a photo. $tags is an array — the whitelist is enforced.
 * Returns ['ok' => bool, 'tags' => array, 'error' => string].
 * ------------------------------------------------------------------ */
function knk_photo_library_set_tags(string $filename, array $tags): array {
    if ($filename === "") return ["ok" => false, "error" => "Missing filename."];
    $clean = knk_photo_tags_clean($tags);
    $tj = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    try {
        $pdo  = knk_db();
        $stmt = $pdo->prepare("UPDATE photo_library SET tags = ? WHERE filename = ?");
        $stmt->execute([$tj, $filename]);
        if ($stmt->rowCount() === 0) {
            // Row may not exist yet (e.g. file in /slots/ uploaded just now,
            // before next scan). Insert it on demand.
            $src = "seed";
            if (strncmp($filename, "gallery-live/", 13) === 0) $src = "gallery_live";
            else if (strncmp($filename, "slots/", 6) === 0)    $src = "slot_upload";
            $ins = $pdo->prepare(
                "INSERT IGNORE INTO photo_library (filename, source, tags) VALUES (?, ?, ?)"
            );
            $ins->execute([$filename, $src, $tj]);
        }
    } catch (Throwable $e) {
        return ["ok" => false, "error" => "DB error."];
    }
    if (function_exists("knk_audit")) {
        knk_audit("photo_library.tags", "photo", $filename, ["tags" => $clean]);
    }
    return ["ok" => true, "tags" => $clean];
}

/* Set / unset hidden. */
function knk_photo_library_set_hidden(string $filename, bool $hidden): array {
    if ($filename === "") return ["ok" => false, "error" => "Missing filename."];
    try {
        $pdo  = knk_db();
        $stmt = $pdo->prepare("UPDATE photo_library SET hidden = ? WHERE filename = ?");
        $stmt->execute([$hidden ? 1 : 0, $filename]);
    } catch (Throwable $e) {
        return ["ok" => false, "error" => "DB error."];
    }
    if (function_exists("knk_audit")) {
        knk_audit("photo_library.hidden", "photo", $filename, ["hidden" => $hidden]);
    }
    return ["ok" => true, "hidden" => $hidden];
}

/* ------------------------------------------------------------------
 * Delete a photo. Only allowed for gallery_live + slot_upload — seed
 * files are protected (they ship with the site and are referenced by
 * the slot defaults / migration 001).
 *
 * Removes the file on disk AND the photo_library row. If a slot is
 * currently pointing at this filename, it's reset to its default to
 * avoid a broken image on the public site.
 * ------------------------------------------------------------------ */
function knk_photo_library_delete(string $filename, int $user_id): array {
    if ($filename === "") return ["ok" => false, "error" => "Missing filename."];

    try {
        $pdo  = knk_db();
        $stmt = $pdo->prepare("SELECT source FROM photo_library WHERE filename = ? LIMIT 1");
        $stmt->execute([$filename]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return ["ok" => false, "error" => "DB error."];
    }

    // Even if no row, allow deletion if the file is one of our managed
    // upload paths — covers files that exist on disk but were missed by scan.
    $source = $row ? (string)$row["source"] : "";
    if ($source === "" ) {
        if (strncmp($filename, "gallery-live/", 13) === 0) $source = "gallery_live";
        else if (strncmp($filename, "slots/", 6) === 0)    $source = "slot_upload";
    }

    if ($source === "seed") {
        return ["ok" => false, "error" => "Built-in photos can't be deleted (only hidden)."];
    }
    if ($source !== "gallery_live" && $source !== "slot_upload") {
        return ["ok" => false, "error" => "Unknown photo."];
    }

    // Reset any photo_slots that reference it
    try {
        $stmt = $pdo->prepare("SELECT section, slot_index FROM photo_slots WHERE filename = ?");
        $stmt->execute([$filename]);
        $defaults = knk_slot_defaults();
        foreach ($stmt->fetchAll() as $sl) {
            $key = $sl["section"] . "#" . (int)$sl["slot_index"];
            $def = $defaults[$key] ?? "";
            if ($def !== "") {
                knk_slot_set_filename($sl["section"], (int)$sl["slot_index"], $def, $user_id);
            }
        }
    } catch (Throwable $e) {
        // Soft-fail — proceed with delete anyway.
    }

    // Remove file on disk
    $abs = __DIR__ . "/../assets/img/" . $filename;
    $abs_real = realpath($abs);
    $img_root_real = realpath(__DIR__ . "/../assets/img");
    if ($abs_real && $img_root_real && strncmp($abs_real, $img_root_real, strlen($img_root_real)) === 0) {
        if (is_file($abs_real)) @unlink($abs_real);
    }

    // Remove DB row
    try {
        $stmt = $pdo->prepare("DELETE FROM photo_library WHERE filename = ?");
        $stmt->execute([$filename]);
    } catch (Throwable $e) {
        return ["ok" => false, "error" => "DB error during delete."];
    }

    if (function_exists("knk_audit")) {
        knk_audit("photo_library.delete", "photo", $filename, []);
    }
    return ["ok" => true];
}

/* ------------------------------------------------------------------
 * Look up a single photo row by filename — null if not in library.
 * ------------------------------------------------------------------ */
function knk_photo_library_get(string $filename): ?array {
    if ($filename === "") return null;
    try {
        $pdo  = knk_db();
        // Try the post-012 schema first; fall back to the older one.
        try {
            $stmt = $pdo->prepare(
                "SELECT id, filename, source, tags, hidden, sort_order, updated_at
                   FROM photo_library WHERE filename = ? LIMIT 1"
            );
            $stmt->execute([$filename]);
        } catch (Throwable $e) {
            $stmt = $pdo->prepare(
                "SELECT id, filename, source, tags, hidden, 0 AS sort_order, updated_at
                   FROM photo_library WHERE filename = ? LIMIT 1"
            );
            $stmt->execute([$filename]);
        }
        $r = $stmt->fetch();
        if (!$r) return null;
        $r["tags"]       = knk_photo_tags_decode($r["tags"] ?? "");
        $r["hidden"]     = (int)$r["hidden"];
        $r["sort_order"] = (int)($r["sort_order"] ?? 0);
        $r["url"]        = "assets/img/" . $r["filename"];
        return $r;
    } catch (Throwable $e) {
        return null;
    }
}

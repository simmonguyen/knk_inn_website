<?php
/*
 * KnK Inn — photo_slots store.
 *
 * Reads & updates rows in the `photo_slots` table that drives
 * the 22 managed photos on index.php + drinks.php.
 *
 * Filename convention (filename column value):
 *   - Seeded defaults look like 'ex_06.jpg' — they live in /assets/img/
 *   - Uploads from the admin UI are saved under /assets/img/slots/
 *     and stored with a 'slots/' prefix, e.g. 'slots/home_carousel-1-...'
 *   - knk_photo_src() resolves both cases by prefixing 'assets/img/'.
 *
 * The uploads folder `assets/img/slots/` is excluded from the FTP deploy
 * (see .github/workflows/deploy.yml) so pushes don't wipe live photos.
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";

/* ------------------------------------------------------------------
 * Filesystem constants
 * ------------------------------------------------------------------ */
if (!defined("KNK_SLOTS_DIR")) {
    define("KNK_SLOTS_DIR", __DIR__ . "/../assets/img/slots");
}
if (!defined("KNK_SLOTS_URL_PREFIX")) {
    // DB value prefix for slot-uploaded files
    define("KNK_SLOTS_URL_PREFIX", "slots/");
}
if (!defined("KNK_SLOT_MAX_UPLOAD")) {
    define("KNK_SLOT_MAX_UPLOAD", 15 * 1024 * 1024);   // 15 MB
}
if (!defined("KNK_SLOT_ALLOWED_MIMES")) {
    define("KNK_SLOT_ALLOWED_MIMES", "image/jpeg,image/jpg,image/png,image/webp,image/heic,image/heif");
}

/* ------------------------------------------------------------------
 * Section manifest — drives the admin UI layout.
 *
 * Each entry describes a homepage section, the number of photo slots
 * it has, and optional per-slot labels (shown on the admin card).
 * ------------------------------------------------------------------ */
function knk_photo_sections(): array {
    return [
        [
            "key"     => "home_carousel",
            "display" => "Home carousel",
            "where"   => "index.php — the rotating hero images at the very top",
            "blurb"   => "Big, wide, cinematic shots. They fade in and out on the homepage hero.",
            "slots"   => 4,
            "labels"  => [1 => null, 2 => null, 3 => null, 4 => null],
        ],
        [
            "key"     => "piece_of_home",
            "display" => "A piece of home (About)",
            "where"   => "index.php — the About section",
            "blurb"   => "The main About photo and a smaller inset next to it.",
            "slots"   => 2,
            "labels"  => [1 => "Top (main)", 2 => "Bottom (inset)"],
        ],
        [
            "key"     => "four_things",
            "display" => "Four things, done well",
            "where"   => "index.php — the photo strip under the four feature cards",
            "blurb"   => "One photo per card: coffee, wine, sports bar, rooms.",
            "slots"   => 4,
            "labels"  => [1 => "Coffee", 2 => "Wine", 3 => "Sports Bar", 4 => "Rooms"],
        ],
        [
            "key"     => "up_above",
            "display" => "Up above (Rooftop)",
            "where"   => "index.php — the rooftop garden hero image",
            "blurb"   => "One big portrait-ish photo for the rooftop section.",
            "slots"   => 1,
            "labels"  => [1 => null],
        ],
        [
            "key"     => "drinks",
            "display" => "Drinks page",
            "where"   => "drinks.php — the two bar photos alongside the menu",
            "blurb"   => "Two photos stacked on the drinks page.",
            "slots"   => 2,
            "labels"  => [1 => "Top", 2 => "Bottom"],
        ],
        [
            "key"     => "sports_look_around",
            "display" => "Have a look around",
            "where"   => "index.php — the 8-photo preview grid near Sports",
            "blurb"   => "Eight smaller photos that give a feel for the place.",
            "slots"   => 8,
            "labels"  => [1 => null, 2 => null, 3 => null, 4 => null, 5 => null, 6 => null, 7 => null, 8 => null],
        ],
        [
            "key"     => "find_us",
            "display" => "Find us (contact banner)",
            "where"   => "index.php — the contact section",
            "blurb"   => "Two photos: the small one on the contact card next to the address, and the big banner sitting behind the ‘Come in. Stay a while.’ call-to-action.",
            "slots"   => 2,
            "labels"  => [1 => "Banner", 2 => "Contact card photo"],
        ],
        [
            "key"     => "rooms_types",
            "display" => "Rooms page — room cards",
            "where"   => "rooms.php — the three big room-type cards near the top",
            "blurb"   => "One hero photo per room type. Shown on the Rooms page and as the link image guests click to open each room.",
            "slots"   => 3,
            "labels"  => [
                1 => "Standard · No Window",
                2 => "Standard · Balcony",
                3 => "VIP · Private Bathtub",
            ],
        ],
        [
            "key"     => "rooms_common",
            "display" => "Rooms page — common spaces",
            "where"   => "rooms.php — the six tiles under ‘Common spaces’",
            "blurb"   => "Six photos that show off the shared parts of the building: street, bars, lift, darts room, rooftop.",
            "slots"   => 6,
            "labels"  => [
                1 => "Street",
                2 => "Ground Bar",
                3 => "Elevator",
                4 => "5th Floor Bar",
                5 => "Darts Room",
                6 => "Rooftop",
            ],
        ],
    ];
}

/* ------------------------------------------------------------------
 * Seeded defaults — identical to migration 001's INSERT values
 * (plus the 002 fix for sports-bar). Used when resetting a slot.
 * ------------------------------------------------------------------ */
function knk_slot_defaults(): array {
    return [
        "home_carousel#1"      => "ex_06.jpg",
        "home_carousel#2"      => "nw_26.jpg",
        "home_carousel#3"      => "nw_51.jpg",
        "home_carousel#4"      => "nw_62.jpg",
        "piece_of_home#1"      => "nw_26.jpg",
        "piece_of_home#2"      => "nw_51.jpg",
        "four_things#1"        => "nw_69.jpg",
        "four_things#2"        => "nw_56.jpg",
        "four_things#3"        => "rm_15.jpg",
        "four_things#4"        => "rm_23.jpg",
        "up_above#1"           => "rm_12.jpg",
        "drinks#1"             => "nw_69.jpg",
        "drinks#2"             => "nw_56.jpg",
        "sports_look_around#1" => "ex_06.jpg",
        "sports_look_around#2" => "ex_07.jpg",
        "sports_look_around#3" => "nw_03.jpg",
        "sports_look_around#4" => "nw_12.jpg",
        "sports_look_around#5" => "nw_25.jpg",
        "sports_look_around#6" => "nw_30.jpg",
        "sports_look_around#7" => "nw_52.jpg",
        "sports_look_around#8" => "nw_69.jpg",
        "find_us#1"            => "nw_05.jpg",
        "find_us#2"            => "nw_33.jpg",
        "rooms_types#1"        => "rm_00.jpg",
        "rooms_types#2"        => "rm_02.jpg",
        "rooms_types#3"        => "rm_04.jpg",
        "rooms_common#1"       => "ex_01.jpg",
        "rooms_common#2"       => "ex_10.jpg",
        "rooms_common#3"       => "nw_57.jpg",
        "rooms_common#4"       => "nw_11.jpg",
        "rooms_common#5"       => "rm_15.jpg",
        "rooms_common#6"       => "ex_08.jpg",
    ];
}

/* ------------------------------------------------------------------
 * Load every slot row, keyed by "section#slot_index".
 *
 * Returns [] if the table is missing (lets index.php/drinks.php
 * keep rendering defaults even if migrations haven't been run yet).
 * ------------------------------------------------------------------ */
function knk_slots_load(): array {
    try {
        $pdo = knk_db();
        $rows = $pdo->query("SELECT section, slot_index, label, filename, alt_text, caption, updated_at FROM photo_slots")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $key = $r["section"] . "#" . (int)$r["slot_index"];
        $out[$key] = $r;
    }
    return $out;
}

/* ------------------------------------------------------------------
 * Resolve a slot to a ready-to-use URL.
 *
 *   $slots       — array from knk_slots_load() (or [])
 *   $section     — 'home_carousel' etc.
 *   $slot_index  — 1-based
 *   $default     — fallback filename (no path) if DB has nothing
 *
 * Always returns a URL relative to the site root, e.g.
 *   'assets/img/slots/home_carousel-1-20260425-aa11.jpg'
 *   'assets/img/ex_06.jpg'
 * ------------------------------------------------------------------ */
function knk_photo_src(array $slots, string $section, int $slot_index, string $default = ""): string {
    $key = $section . "#" . $slot_index;
    $fn  = "";
    if (isset($slots[$key]) && isset($slots[$key]["filename"])) {
        $fn = trim((string)$slots[$key]["filename"]);
    }
    if ($fn === "") $fn = $default;
    if ($fn === "") return "";
    return "assets/img/" . $fn;
}

/* Alt text fallback — callers pass their existing alt as $default. */
function knk_photo_alt(array $slots, string $section, int $slot_index, string $default = ""): string {
    $key = $section . "#" . $slot_index;
    if (isset($slots[$key]) && isset($slots[$key]["alt_text"])) {
        $a = trim((string)$slots[$key]["alt_text"]);
        if ($a !== "") return $a;
    }
    return $default;
}

/* ------------------------------------------------------------------
 * Is the DB filename one of ours (i.e. a managed upload under
 * assets/img/slots/)?  Used by the admin UI to show "Reset to default".
 * ------------------------------------------------------------------ */
function knk_slot_is_custom(array $slots, string $section, int $slot_index): bool {
    $key = $section . "#" . $slot_index;
    if (!isset($slots[$key])) return false;
    $fn = (string)($slots[$key]["filename"] ?? "");
    if ($fn === "") return false;
    $defaults = knk_slot_defaults();
    $default  = $defaults[$key] ?? "";
    return $fn !== $default;
}

/* ------------------------------------------------------------------
 * Write a new filename into a slot (and audit).
 *
 * Does NOT touch the filesystem — caller is responsible for moving
 * the uploaded file into KNK_SLOTS_DIR first.
 * ------------------------------------------------------------------ */
function knk_slot_set_filename(string $section, int $slot_index, string $filename, int $user_id): bool {
    $pdo = knk_db();
    $stmt = $pdo->prepare(
        "UPDATE photo_slots
            SET filename = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
          WHERE section = ? AND slot_index = ?"
    );
    $stmt->execute([$filename, $user_id, $section, $slot_index]);
    $ok = $stmt->rowCount() >= 0;   // rowCount returns 0 if no change, still "ok"

    if (function_exists("knk_audit")) {
        knk_audit("photo_slot.update", "photo_slot", $section . "#" . $slot_index, [
            "filename" => $filename,
        ]);
    }
    return $ok;
}

/* ------------------------------------------------------------------
 * Accept an uploaded file ($_FILES entry), resize/normalise it, save
 * it under assets/img/slots/, and update the DB row.
 *
 * Returns ['ok' => bool, 'error' => str, 'url' => str, 'filename' => str]
 * ------------------------------------------------------------------ */
function knk_slot_replace_from_upload(array $file_entry, string $section, int $slot_index, int $user_id): array {
    // Basic guard
    if (empty($file_entry) || ($file_entry["error"] ?? -1) !== UPLOAD_ERR_OK) {
        return ["ok" => false, "error" => "Upload failed (" . (string)($file_entry["error"] ?? "no file") . ")"];
    }
    if (($file_entry["size"] ?? 0) > KNK_SLOT_MAX_UPLOAD) {
        return ["ok" => false, "error" => "File too big (>15 MB)."];
    }

    $tmp = (string)($file_entry["tmp_name"] ?? "");
    if ($tmp === "" || !is_uploaded_file($tmp)) {
        return ["ok" => false, "error" => "Missing uploaded file."];
    }

    $mime = function_exists("mime_content_type")
        ? (string)mime_content_type($tmp)
        : (string)($file_entry["type"] ?? "");
    $mime = strtolower($mime);
    $allowed = explode(",", KNK_SLOT_ALLOWED_MIMES);
    if (!in_array($mime, $allowed, true)) {
        return ["ok" => false, "error" => "Not an image (" . $mime . ")"];
    }

    // Make sure dir exists
    if (!is_dir(KNK_SLOTS_DIR)) {
        @mkdir(KNK_SLOTS_DIR, 0755, true);
    }
    if (!is_dir(KNK_SLOTS_DIR) || !is_writable(KNK_SLOTS_DIR)) {
        return ["ok" => false, "error" => "Can't write to assets/img/slots/ — check file permissions."];
    }

    // Choose output extension based on input
    $ext = "jpg";
    if ($mime === "image/png")  $ext = "png";
    if ($mime === "image/webp") $ext = "webp";

    $stamp = date("Ymd-His");
    $rand  = substr(bin2hex(random_bytes(3)), 0, 6);
    $base  = preg_replace('/[^a-z0-9_]/i', "_", $section) . "-" . (int)$slot_index;
    $name  = $base . "-" . $stamp . "-" . $rand . "." . $ext;
    $dest  = KNK_SLOTS_DIR . "/" . $name;

    if (!move_uploaded_file($tmp, $dest)) {
        return ["ok" => false, "error" => "Could not save file."];
    }

    // EXIF auto-rotate (JPEG only, best-effort)
    if ($ext === "jpg" && function_exists("exif_read_data") && function_exists("imagecreatefromjpeg")) {
        try {
            $exif = @exif_read_data($dest);
            if (!empty($exif["Orientation"]) && (int)$exif["Orientation"] > 1) {
                $img = @imagecreatefromjpeg($dest);
                if ($img) {
                    $angle = 0;
                    switch ((int)$exif["Orientation"]) {
                        case 3: $angle = 180; break;
                        case 6: $angle = -90; break;
                        case 8: $angle =  90; break;
                    }
                    if ($angle !== 0) {
                        $rot = imagerotate($img, $angle, 0);
                        imagejpeg($rot, $dest, 88);
                        imagedestroy($rot);
                    }
                    imagedestroy($img);
                }
            }
        } catch (Throwable $e) {
            // ignore — client already resized & rotated via canvas
        }
    }

    @chmod($dest, 0644);

    $db_filename = KNK_SLOTS_URL_PREFIX . $name;
    $ok = knk_slot_set_filename($section, $slot_index, $db_filename, $user_id);
    if (!$ok) {
        return ["ok" => false, "error" => "Saved file but DB update failed."];
    }

    return [
        "ok"       => true,
        "filename" => $db_filename,
        "url"      => "assets/img/" . $db_filename,
    ];
}

/* ------------------------------------------------------------------
 * Reset a slot back to its seeded default filename.
 * Does NOT delete the old file (leaves it on disk in case you want
 * to flip back; admins can tidy up via FTP if needed).
 * ------------------------------------------------------------------ */
function knk_slot_reset(string $section, int $slot_index, int $user_id): array {
    $defaults = knk_slot_defaults();
    $key = $section . "#" . $slot_index;
    if (!isset($defaults[$key])) {
        return ["ok" => false, "error" => "Unknown slot."];
    }
    $default = $defaults[$key];
    $ok = knk_slot_set_filename($section, $slot_index, $default, $user_id);
    if (!$ok) {
        return ["ok" => false, "error" => "DB update failed."];
    }
    return [
        "ok"       => true,
        "filename" => $default,
        "url"      => "assets/img/" . $default,
    ];
}

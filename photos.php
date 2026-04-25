<?php
/* =========================================================
   KnK Inn — Photo manager (V2.1 — gallery-backed picker)
   --------------------------------------------------------
   Tab 1 — Homepage slots:
       Replace any of the managed photos that drive
       index.php + drinks.php. Backed by photo_slots.
       "Replace" now opens a modal with two choices:
         (a) Pick from gallery   — calls slot_pick
         (b) Upload new photo    — calls slot_replace
       Uploads land in assets/img/slots/ and immediately
       appear in the gallery wall.

   Tab 2 — Gallery wall:
       Master index of every photo on the site (seeded
       defaults + live uploads + slot uploads). Backed by
       the photo_library table. Filter by tag, set tags,
       hide / unhide, delete (uploads only — seeded photos
       are protected).

   Role-gated: super_admin + owner only.
   Target PHP 7.4 (Matbao shared hosting).
   ========================================================= */

require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/photo_slots_store.php";
require_once __DIR__ . "/includes/photo_library_store.php";

$me = knk_require_role(["super_admin", "owner"]);

/* ----------------------------------------------------------
 * Gallery-wall constants (unchanged)
 * ---------------------------------------------------------- */
if (!defined("PHOTOS_DIR"))         define("PHOTOS_DIR",         __DIR__ . "/assets/img/gallery-live");
if (!defined("PHOTOS_URL"))         define("PHOTOS_URL",         "assets/img/gallery-live");
if (!defined("GALLERY_MAX_UPLOAD")) define("GALLERY_MAX_UPLOAD", 15 * 1024 * 1024);
$GALLERY_ALLOWED_MIMES = ["image/jpeg","image/jpg","image/png","image/webp","image/heic","image/heif"];

if (!is_dir(PHOTOS_DIR))    @mkdir(PHOTOS_DIR,    0755, true);
if (!is_dir(KNK_SLOTS_DIR)) @mkdir(KNK_SLOTS_DIR, 0755, true);

/* ----------------------------------------------------------
 * Dispatch POST / GET actions.
 *   slot_replace      — AJAX, JSON. Multipart upload + slot update.
 *   slot_reset        — AJAX, JSON.
 *   slot_pick         — AJAX, JSON. Set a slot's filename from the library.
 *   gallery_upload    — AJAX, JSON. Drop-zone bulk upload.
 *   library_set_tags  — AJAX, JSON. tags[] for a single photo.
 *   library_set_hidden— AJAX, JSON. 0/1.
 *   library_delete    — AJAX, JSON. Delete an upload (never a seed).
 * ---------------------------------------------------------- */
$action = $_POST["action"] ?? ($_GET["action"] ?? "");

if ($action === "slot_replace") {
    header("Content-Type: application/json; charset=utf-8");
    $section    = (string)($_POST["section"] ?? "");
    $slot_index = (int)($_POST["slot_index"] ?? 0);
    if ($section === "" || $slot_index < 1) {
        echo json_encode(["ok" => false, "error" => "Missing section / slot_index"]);
        exit;
    }
    $file = $_FILES["photo"] ?? [];
    $res  = knk_slot_replace_from_upload($file, $section, $slot_index, (int)$me["id"]);
    // Register fresh upload in the library so the gallery picker shows it.
    if (!empty($res["ok"]) && !empty($res["filename"])) {
        knk_photo_library_register($res["filename"], "slot_upload");
    }
    echo json_encode($res);
    exit;
}

if ($action === "slot_reset") {
    header("Content-Type: application/json; charset=utf-8");
    $section    = (string)($_POST["section"] ?? "");
    $slot_index = (int)($_POST["slot_index"] ?? 0);
    if ($section === "" || $slot_index < 1) {
        echo json_encode(["ok" => false, "error" => "Missing section / slot_index"]);
        exit;
    }
    $res = knk_slot_reset($section, $slot_index, (int)$me["id"]);
    echo json_encode($res);
    exit;
}

if ($action === "slot_pick") {
    header("Content-Type: application/json; charset=utf-8");
    $section    = (string)($_POST["section"] ?? "");
    $slot_index = (int)($_POST["slot_index"] ?? 0);
    $filename   = (string)($_POST["filename"] ?? "");
    if ($section === "" || $slot_index < 1 || $filename === "") {
        echo json_encode(["ok" => false, "error" => "Missing section / slot_index / filename"]);
        exit;
    }
    // Confirm the picked filename actually exists in the library
    // (and isn't hidden — defence against stale UI).
    $row = knk_photo_library_get($filename);
    if (!$row) {
        echo json_encode(["ok" => false, "error" => "Photo not in library."]);
        exit;
    }
    $ok = knk_slot_set_filename($section, $slot_index, $filename, (int)$me["id"]);
    if (!$ok) {
        echo json_encode(["ok" => false, "error" => "DB update failed."]);
        exit;
    }
    echo json_encode([
        "ok"       => true,
        "filename" => $filename,
        "url"      => "assets/img/" . $filename,
    ]);
    exit;
}

if ($action === "gallery_upload") {
    header("Content-Type: application/json; charset=utf-8");

    if (empty($_FILES["photo"]) || ($_FILES["photo"]["error"] ?? -1) !== UPLOAD_ERR_OK) {
        echo json_encode(["ok" => false, "error" => "Upload failed (" . ($_FILES["photo"]["error"] ?? "no file") . ")"]);
        exit;
    }
    $tmp  = $_FILES["photo"]["tmp_name"];
    $size = (int)$_FILES["photo"]["size"];
    if ($size > GALLERY_MAX_UPLOAD) {
        echo json_encode(["ok" => false, "error" => "File too big (>15 MB)."]);
        exit;
    }
    $mime = function_exists("mime_content_type") ? mime_content_type($tmp) : ($_FILES["photo"]["type"] ?? "");
    $mime = strtolower((string)$mime);
    if (!in_array($mime, $GALLERY_ALLOWED_MIMES, true)) {
        echo json_encode(["ok" => false, "error" => "Not an image: " . $mime]);
        exit;
    }

    $stamp = date("Ymd-His");
    $rand  = substr(bin2hex(random_bytes(3)), 0, 6);
    $ext   = ($mime === "image/png")  ? "png"
           : (($mime === "image/webp") ? "webp" : "jpg");
    $name  = $stamp . "-" . $rand . "." . $ext;
    $dest  = PHOTOS_DIR . "/" . $name;

    if (!move_uploaded_file($tmp, $dest)) {
        echo json_encode(["ok" => false, "error" => "Could not save file."]);
        exit;
    }

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
        } catch (Throwable $e) { /* ignore */ }
    }

    @chmod($dest, 0644);

    $lib_filename = "gallery-live/" . $name;
    knk_photo_library_register($lib_filename, "gallery_live");

    echo json_encode([
        "ok"       => true,
        "name"     => $name,
        "filename" => $lib_filename,
        "url"      => "assets/img/" . $lib_filename,
    ]);
    exit;
}

if ($action === "library_set_tags") {
    header("Content-Type: application/json; charset=utf-8");
    $filename = (string)($_POST["filename"] ?? "");
    $tags_in  = $_POST["tags"] ?? [];
    if (is_string($tags_in)) {
        $tags_in = $tags_in === "" ? [] : explode(",", $tags_in);
    }
    if (!is_array($tags_in)) $tags_in = [];
    echo json_encode(knk_photo_library_set_tags($filename, $tags_in));
    exit;
}

if ($action === "library_set_hidden") {
    header("Content-Type: application/json; charset=utf-8");
    $filename = (string)($_POST["filename"] ?? "");
    $hidden   = !empty($_POST["hidden"]) && $_POST["hidden"] !== "0";
    echo json_encode(knk_photo_library_set_hidden($filename, $hidden));
    exit;
}

if ($action === "library_delete") {
    header("Content-Type: application/json; charset=utf-8");
    $filename = (string)($_POST["filename"] ?? "");
    echo json_encode(knk_photo_library_delete($filename, (int)$me["id"]));
    exit;
}

if ($action === "library_reorder") {
    header("Content-Type: application/json; charset=utf-8");
    $filenames = $_POST["filenames"] ?? [];
    if (is_string($filenames)) {
        $filenames = $filenames === "" ? [] : explode(",", $filenames);
    }
    if (!is_array($filenames)) $filenames = [];
    echo json_encode(knk_photo_library_reorder($filenames));
    exit;
}

/* ----------------------------------------------------------
 * Default: render the page.
 * ---------------------------------------------------------- */
$tab = (string)($_GET["tab"] ?? "slots");
if ($tab !== "slots" && $tab !== "gallery") $tab = "slots";

// Auto-scan: cheap (one INSERT IGNORE per file) — keeps the library
// up to date with anything FTP'd or uploaded outside the admin.
knk_photo_library_scan();

$slots    = knk_slots_load();
$sections = knk_photo_sections();

$library     = knk_photo_library_list(["include_hidden" => true]);
$tag_counts  = knk_photo_library_tag_counts();
$all_tags    = knk_photo_tags();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>KnK Inn — Photo manager</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Caveat:wght@700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/styles.css?v=12">
  <style>
    body { padding: 1.2rem 1rem 4rem; }
    .wrap { max-width: 1080px; margin: 0 auto; }

    header.bar { margin-bottom: 1.4rem; }
    header.bar h1 { margin: 0.2rem 0 0.4rem; }

    /* Tab strip */
    .tab-strip {
      display: flex; gap: 0.3rem; border-bottom: 1px solid rgba(201,170,113,0.2);
      margin-bottom: 2rem; flex-wrap: wrap;
    }
    .tab-strip a {
      padding: 0.7rem 1.2rem; color: var(--cream-dim); text-decoration: none;
      font-size: 0.8rem; letter-spacing: 0.15em; text-transform: uppercase;
      border-bottom: 2px solid transparent; margin-bottom: -1px;
    }
    .tab-strip a.active { color: var(--gold); border-bottom-color: var(--gold); }
    .tab-strip a:hover { color: var(--gold); }

    .tab-panel { display: none; }
    .tab-panel.active { display: block; }

    /* Section card (slots tab) */
    .section-card {
      background: rgba(24,12,3,0.5); border: 1px solid rgba(201,170,113,0.18);
      border-radius: 6px; padding: 1.2rem 1.2rem 1.4rem; margin-bottom: 1.5rem;
    }
    .section-card .sc-head { margin-bottom: 1rem; }
    .section-card .sc-head h2 { margin: 0.2rem 0 0.1rem; font-size: 1.3rem; font-family: 'Archivo Black', sans-serif; }
    .section-card .sc-head .where { font-size: 0.72rem; letter-spacing: 0.16em; text-transform: uppercase; color: var(--gold); }
    .section-card .sc-head p { margin: 0.3rem 0 0; font-size: 0.88rem; color: var(--cream-dim); max-width: 58ch; }

    .slot-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 0.9rem;
    }
    .slot-card {
      background: rgba(255,255,255,0.03); border: 1px solid rgba(201,170,113,0.14);
      border-radius: 5px; overflow: hidden; display: flex; flex-direction: column;
      position: relative;
    }
    .slot-card .thumb {
      aspect-ratio: 1 / 1; width: 100%; background: rgba(0,0,0,0.25);
      display: flex; align-items: center; justify-content: center; overflow: hidden;
    }
    .slot-card .thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .slot-card .thumb .missing { color: var(--cream-faint); font-size: 0.7rem; text-align: center; padding: 1rem; }

    .slot-card .meta {
      padding: 0.55rem 0.7rem 0.5rem; font-size: 0.78rem; color: var(--cream-dim);
      display: flex; flex-direction: column; gap: 0.1rem;
    }
    .slot-card .meta .lbl { color: var(--cream); font-weight: 600; }
    .slot-card .meta .lbl-small { font-size: 0.68rem; letter-spacing: 0.12em; text-transform: uppercase; color: var(--cream-faint); }

    .slot-actions { display: flex; gap: 0.35rem; padding: 0 0.7rem 0.7rem; flex-wrap: wrap; }
    .slot-actions .btn {
      flex: 1; min-width: 0; padding: 0.5rem 0.55rem; font-size: 0.72rem;
      letter-spacing: 0.13em; text-transform: uppercase; font-weight: 600;
      background: var(--gold); color: var(--brown-deep); border: none; border-radius: 3px;
      cursor: pointer; font-family: inherit; text-align: center;
    }
    .slot-actions .btn:hover { background: #d8c08b; }
    .slot-actions .btn.ghost {
      background: transparent; color: var(--cream-dim); border: 1px solid rgba(201,170,113,0.3);
    }
    .slot-actions .btn.ghost:hover { color: var(--gold); border-color: var(--gold); }
    .slot-card input[type="file"] { display: none; }

    .slot-card .custom-pill {
      position: absolute; top: 0.5rem; right: 0.5rem;
      background: rgba(201,170,113,0.9); color: var(--brown-deep);
      font-size: 0.62rem; font-weight: 700; padding: 2px 7px;
      border-radius: 10px; letter-spacing: 0.1em; text-transform: uppercase;
    }

    .slot-card .status {
      display: none; font-size: 0.72rem; color: var(--cream-dim);
      padding: 0 0.7rem 0.5rem;
    }
    .slot-card.busy .status { display: block; color: var(--gold); }
    .slot-card.err .status  { display: block; color: #ff9a8a; }
    .slot-card.ok .status   { display: block; color: #7fd08a; }

    /* Drop zone (gallery tab) */
    .drop {
      border: 2px dashed rgba(201,170,113,0.4); border-radius: 6px; padding: 1.6rem 1.4rem;
      text-align: center; background: rgba(24,12,3,0.4); cursor: pointer;
      transition: all 0.3s var(--ease-out); margin-bottom: 1rem;
    }
    .drop:hover, .drop.drag { border-color: var(--gold); background: rgba(201,170,113,0.06); }
    .drop p { color: var(--cream-dim); margin: 0.3rem 0; font-size: 0.9rem; }
    .drop strong { color: var(--gold); display: block; font-size: 1.05rem; margin-bottom: 0.3rem; }
    .drop input { display: none; }
    .drop .hint { font-size: 0.76rem; color: var(--cream-faint); margin-top: 0.5rem; }

    #gallery-status { margin-top: 0.5rem; }
    .row {
      display: flex; align-items: center; gap: 0.8rem; padding: 0.7rem 0.9rem;
      margin-bottom: 0.5rem; border-radius: 4px; background: rgba(255,255,255,0.03);
      font-size: 0.9rem;
    }
    .row .bar-outer { flex: 1; height: 6px; background: rgba(201,170,113,0.15); border-radius: 3px; overflow: hidden; }
    .row .bar-inner { height: 100%; background: var(--gold); width: 0%; transition: width 0.2s; }
    .row.done .bar-inner { background: #7fd08a; }
    .row.err  { color: #ff9a8a; }
    .row .fn  { flex: 0 1 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    /* Filter chips */
    .chips {
      display: flex; gap: 0.4rem; flex-wrap: wrap; margin: 1.2rem 0 0.8rem;
    }
    .chip {
      padding: 0.4rem 0.85rem; font-size: 0.74rem; letter-spacing: 0.1em;
      text-transform: uppercase; font-weight: 600; cursor: pointer;
      background: rgba(255,255,255,0.04); color: var(--cream-dim);
      border: 1px solid rgba(201,170,113,0.2); border-radius: 99px;
      font-family: inherit; transition: all 0.18s var(--ease-out);
      display: inline-flex; align-items: center; gap: 0.4rem;
    }
    .chip:hover { color: var(--gold); border-color: var(--gold); }
    .chip.active { background: var(--gold); color: var(--brown-deep); border-color: var(--gold); }
    .chip .n {
      font-size: 0.66rem; opacity: 0.7; font-weight: 500;
    }
    .chip.active .n { opacity: 0.85; }

    .gallery-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.6rem;
    }
    .tile {
      position: relative; aspect-ratio: 1 / 1; border-radius: 4px; overflow: hidden;
      background: rgba(255,255,255,0.04); cursor: pointer;
      border: 2px solid transparent;
    }
    .tile:hover { border-color: rgba(201,170,113,0.4); }
    .tile.hidden-photo img { opacity: 0.35; filter: grayscale(0.6); }
    .tile img { width: 100%; height: 100%; object-fit: cover; display: block; }

    /* Top-left source pill */
    .tile .src {
      position: absolute; top: 5px; left: 5px;
      font-size: 0.58rem; letter-spacing: 0.1em; text-transform: uppercase;
      font-weight: 700; padding: 2px 6px; border-radius: 3px;
      color: var(--brown-deep);
    }
    .tile .src.seed   { background: rgba(245,233,209,0.85); }
    .tile .src.live   { background: rgba(127,208,138,0.85); }
    .tile .src.slot   { background: rgba(201,170,113,0.85); }

    /* Hidden indicator */
    .tile .hidden-flag {
      position: absolute; bottom: 5px; left: 5px;
      background: rgba(0,0,0,0.7); color: var(--cream); font-size: 0.6rem;
      padding: 2px 6px; border-radius: 3px; letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .empty { color: var(--cream-faint); text-align: center; padding: 2rem; font-size: 0.9rem; }
    .count { color: var(--cream-dim); font-size: 0.82rem; margin-bottom: 0.4rem; }
    .reorder-hint {
      color: var(--cream-faint); font-size: 0.78rem; margin-bottom: 0.6rem;
      display: flex; align-items: center; gap: 0.4rem;
    }
    .reorder-hint .grip {
      display: inline-block; width: 14px; height: 14px;
      background:
        linear-gradient(to bottom, var(--gold) 2px, transparent 2px) 0 0/100% 5px repeat-y;
      opacity: 0.7;
    }
    /* Sortable.js drag states */
    .tile.sortable-ghost { opacity: 0.3; }
    .tile.sortable-chosen { box-shadow: 0 0 0 2px var(--gold); }
    .tile.sortable-drag { opacity: 0.9; transform: scale(1.04); }
    #library-grid.is-saving { opacity: 0.7; pointer-events: none; }
    .save-flash {
      position: fixed; bottom: 1.5rem; left: 50%; transform: translateX(-50%);
      background: rgba(127,208,138,0.95); color: #14260f;
      padding: 0.55rem 1rem; border-radius: 4px; font-size: 0.82rem;
      font-weight: 600; letter-spacing: 0.05em; opacity: 0; pointer-events: none;
      transition: opacity 0.25s var(--ease-out); z-index: 200;
    }
    .save-flash.show { opacity: 1; }
    .save-flash.err { background: rgba(255,154,138,0.95); color: #4a1d16; }

    /* ---------- Modals ---------- */
    .modal-backdrop {
      position: fixed; inset: 0; background: rgba(0,0,0,0.78);
      display: none; align-items: center; justify-content: center;
      z-index: 100; padding: 1.2rem;
    }
    .modal-backdrop.open { display: flex; }
    .modal {
      background: #1a0e04; border: 1px solid rgba(201,170,113,0.3);
      border-radius: 6px; max-width: 720px; width: 100%;
      max-height: 90vh; overflow-y: auto;
      box-shadow: 0 16px 50px rgba(0,0,0,0.6);
    }
    .modal.lg { max-width: 960px; }
    .modal-head {
      padding: 1rem 1.2rem; border-bottom: 1px solid rgba(201,170,113,0.18);
      display: flex; align-items: center; justify-content: space-between;
    }
    .modal-head h3 {
      margin: 0; font-size: 1rem; letter-spacing: 0.18em;
      text-transform: uppercase; color: var(--gold); font-weight: 700;
    }
    .modal-head .x {
      background: none; border: none; color: var(--cream-dim); font-size: 1.5rem;
      cursor: pointer; padding: 0 0.4rem; line-height: 1; font-family: inherit;
    }
    .modal-head .x:hover { color: var(--gold); }
    .modal-body { padding: 1.2rem; }

    /* Replace-options modal */
    .opt-grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;
    }
    @media (max-width: 520px) { .opt-grid { grid-template-columns: 1fr; } }
    .opt-card {
      padding: 1.4rem 1.2rem; background: rgba(255,255,255,0.03);
      border: 1px solid rgba(201,170,113,0.2); border-radius: 5px;
      text-align: center; cursor: pointer; transition: all 0.2s var(--ease-out);
      font-family: inherit; color: var(--cream); font-size: 0.92rem;
    }
    .opt-card:hover { border-color: var(--gold); background: rgba(201,170,113,0.06); }
    .opt-card .ico {
      font-size: 2rem; margin-bottom: 0.5rem; color: var(--gold); display: block;
    }
    .opt-card .lbl-big { display: block; font-weight: 600; margin-bottom: 0.3rem; }
    .opt-card .lbl-small { display: block; font-size: 0.78rem; color: var(--cream-dim); }

    /* Gallery picker modal */
    #picker-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
      gap: 0.5rem; margin-top: 0.8rem;
    }
    #picker-grid .tile { cursor: pointer; }
    #picker-grid .tile:hover { border-color: var(--gold); }

    /* Photo detail modal */
    .pd-row {
      display: flex; gap: 1.2rem; align-items: flex-start;
      flex-wrap: wrap;
    }
    .pd-thumb {
      flex: 0 0 240px; aspect-ratio: 1 / 1; border-radius: 5px; overflow: hidden;
      background: rgba(0,0,0,0.3);
    }
    .pd-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .pd-info { flex: 1 1 240px; min-width: 0; }
    .pd-info .pd-fn {
      font-family: monospace; font-size: 0.78rem; color: var(--cream-dim);
      word-break: break-all; margin-bottom: 0.4rem;
    }
    .pd-info .pd-src {
      display: inline-block; font-size: 0.68rem; padding: 2px 8px; border-radius: 3px;
      letter-spacing: 0.1em; text-transform: uppercase; font-weight: 700;
      color: var(--brown-deep); margin-bottom: 0.8rem;
    }
    .pd-info .pd-src.seed { background: rgba(245,233,209,0.85); }
    .pd-info .pd-src.live { background: rgba(127,208,138,0.85); }
    .pd-info .pd-src.slot { background: rgba(201,170,113,0.85); }

    .pd-tags-label {
      font-size: 0.7rem; letter-spacing: 0.16em; text-transform: uppercase;
      color: var(--cream-faint); margin: 0.6rem 0 0.4rem;
    }
    .pd-tag-chips { display: flex; flex-wrap: wrap; gap: 0.35rem; }
    .pd-tag-chips .chip { font-size: 0.7rem; padding: 0.3rem 0.7rem; }

    .pd-actions {
      display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 1.2rem;
      padding-top: 1rem; border-top: 1px solid rgba(201,170,113,0.18);
    }
    .pd-actions .btn {
      padding: 0.55rem 0.9rem; font-size: 0.74rem; letter-spacing: 0.13em;
      text-transform: uppercase; font-weight: 600; cursor: pointer;
      border-radius: 3px; font-family: inherit; border: 1px solid transparent;
    }
    .pd-actions .btn-primary { background: var(--gold); color: var(--brown-deep); border-color: var(--gold); }
    .pd-actions .btn-primary:hover { background: #d8c08b; }
    .pd-actions .btn-ghost {
      background: transparent; color: var(--cream-dim);
      border-color: rgba(201,170,113,0.3);
    }
    .pd-actions .btn-ghost:hover { color: var(--gold); border-color: var(--gold); }
    .pd-actions .btn-danger {
      background: transparent; color: #ff9a8a; border-color: rgba(255,154,138,0.4);
    }
    .pd-actions .btn-danger:hover { color: #fff; background: #c04a3a; border-color: #c04a3a; }
    .pd-actions .spacer { flex: 1; }
    .pd-status { margin-top: 0.6rem; font-size: 0.78rem; min-height: 1em; }
    .pd-status.ok { color: #7fd08a; }
    .pd-status.err { color: #ff9a8a; }
  </style>
</head>
<body>
<?php knk_render_admin_nav($me); ?>
<div class="wrap">

  <header class="bar">
    <span class="eyebrow">KnK Inn</span>
    <h1 class="display-md">Photo <em>manager</em></h1>
    <p style="color: var(--cream-dim); font-size: 0.9rem; max-width: 60ch; margin: 0.4rem 0 0;">
      Swap the photos that visitors see on the homepage and the drinks page, or browse and tag every photo on the site.
      Changes go live straight away.
    </p>
  </header>

  <div class="tab-strip">
    <a href="?tab=slots"   id="tab-slots-link"   class="<?= $tab === 'slots'   ? 'active' : '' ?>">Homepage photos</a>
    <a href="?tab=gallery" id="tab-gallery-link" class="<?= $tab === 'gallery' ? 'active' : '' ?>">Gallery wall</a>
  </div>

  <!-- ===================== TAB: HOMEPAGE SLOTS ===================== -->
  <div id="tab-slots" class="tab-panel <?= $tab === 'slots' ? 'active' : '' ?>">

    <?php foreach ($sections as $s): ?>
      <section class="section-card">
        <div class="sc-head">
          <span class="where"><?= htmlspecialchars($s["where"]) ?></span>
          <h2><?= htmlspecialchars($s["display"]) ?></h2>
          <p><?= htmlspecialchars($s["blurb"]) ?></p>
        </div>

        <div class="slot-grid">
          <?php for ($i = 1; $i <= $s["slots"]; $i++):
            $section_key = $s["key"];
            $label       = $s["labels"][$i] ?? null;
            $key         = $section_key . "#" . $i;
            $filename    = $slots[$key]["filename"] ?? "";
            $src         = $filename !== "" ? ("assets/img/" . $filename) : "";
            $is_custom   = knk_slot_is_custom($slots, $section_key, $i);
            $slot_id     = "slot-" . $section_key . "-" . $i;
          ?>
            <div class="slot-card" id="<?= htmlspecialchars($slot_id) ?>"
                 data-section="<?= htmlspecialchars($section_key) ?>"
                 data-slot-index="<?= $i ?>">

              <?php if ($is_custom): ?>
                <span class="custom-pill">Custom</span>
              <?php endif; ?>

              <div class="thumb">
                <?php if ($src !== ""): ?>
                  <img src="<?= htmlspecialchars($src) ?>?v=<?= time() ?>" alt="" loading="lazy">
                <?php else: ?>
                  <span class="missing">No photo</span>
                <?php endif; ?>
              </div>

              <div class="meta">
                <?php if ($label !== null && $label !== ""): ?>
                  <span class="lbl-small">Slot <?= $i ?></span>
                  <span class="lbl"><?= htmlspecialchars($label) ?></span>
                <?php else: ?>
                  <span class="lbl">Slot <?= $i ?></span>
                <?php endif; ?>
              </div>

              <div class="slot-actions">
                <button class="btn slot-replace" type="button">Replace</button>
                <input type="file" accept="image/*" class="slot-file">
                <?php if ($is_custom): ?>
                  <button class="btn ghost slot-reset" type="button">Reset</button>
                <?php endif; ?>
              </div>

              <div class="status">Ready</div>
            </div>
          <?php endfor; ?>
        </div>
      </section>
    <?php endforeach; ?>

  </div>

  <!-- ===================== TAB: GALLERY WALL ===================== -->
  <div id="tab-gallery" class="tab-panel <?= $tab === 'gallery' ? 'active' : '' ?>">

    <label class="drop" id="drop">
      <strong>Tap to add photos</strong>
      <p>Or drag &amp; drop JPG / PNG / iPhone photos here</p>
      <p class="hint">Each photo is auto-resized &amp; rotated. New uploads land in the gallery and become available for slot picking.</p>
      <input type="file" id="gallery-fileinput" accept="image/*" multiple>
    </label>

    <div id="gallery-status"></div>

    <div class="chips" id="library-chips">
      <button type="button" class="chip active" data-tag="__all">All <span class="n"><?= (int)$tag_counts["__all"] ?></span></button>
      <button type="button" class="chip"        data-tag="__untagged">Untagged <span class="n"><?= (int)$tag_counts["__untagged"] ?></span></button>
      <?php foreach ($all_tags as $t): ?>
        <button type="button" class="chip" data-tag="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?> <span class="n"><?= (int)($tag_counts[$t] ?? 0) ?></span></button>
      <?php endforeach; ?>
    </div>

    <p class="count" id="library-count"><?= count($library) ?> photo<?= count($library) === 1 ? '' : 's' ?> total</p>
    <p class="reorder-hint"><span class="grip"></span> Drag a photo to reorder. Click it to edit tags.</p>

    <?php if (empty($library)): ?>
      <div class="empty">No photos yet — upload one above.</div>
    <?php else: ?>
      <div class="gallery-grid" id="library-grid">
        <?php foreach ($library as $row):
          $src_class = $row["source"] === "seed" ? "seed" : ($row["source"] === "gallery_live" ? "live" : "slot");
          $src_label = $row["source"] === "seed" ? "Built-in" : ($row["source"] === "gallery_live" ? "Live" : "Slot");
          $tags_csv  = implode(",", $row["tags"]);
        ?>
          <div class="tile<?= $row["hidden"] ? " hidden-photo" : "" ?>"
               data-filename="<?= htmlspecialchars($row["filename"]) ?>"
               data-source="<?= htmlspecialchars($row["source"]) ?>"
               data-tags="<?= htmlspecialchars($tags_csv) ?>"
               data-hidden="<?= (int)$row["hidden"] ?>">
            <span class="src <?= $src_class ?>"><?= htmlspecialchars($src_label) ?></span>
            <img src="<?= htmlspecialchars($row["url"]) ?>" alt="" loading="lazy">
            <?php if ($row["hidden"]): ?>
              <span class="hidden-flag">Hidden</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>

</div>

<!-- ===================== Modal: Replace options ===================== -->
<div class="modal-backdrop" id="m-replace">
  <div class="modal">
    <div class="modal-head">
      <h3>Replace photo</h3>
      <button type="button" class="x" data-close>&times;</button>
    </div>
    <div class="modal-body">
      <div class="opt-grid">
        <button type="button" class="opt-card" id="opt-pick">
          <span class="ico">▦</span>
          <span class="lbl-big">Pick from gallery</span>
          <span class="lbl-small">Choose from photos already on the site</span>
        </button>
        <button type="button" class="opt-card" id="opt-upload">
          <span class="ico">↑</span>
          <span class="lbl-big">Upload new photo</span>
          <span class="lbl-small">From your phone or computer</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ===================== Modal: Gallery picker ===================== -->
<div class="modal-backdrop" id="m-picker">
  <div class="modal lg">
    <div class="modal-head">
      <h3>Pick a photo</h3>
      <button type="button" class="x" data-close>&times;</button>
    </div>
    <div class="modal-body">
      <div class="chips" id="picker-chips">
        <button type="button" class="chip active" data-tag="__all">All</button>
        <button type="button" class="chip" data-tag="__untagged">Untagged</button>
        <?php foreach ($all_tags as $t): ?>
          <button type="button" class="chip" data-tag="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></button>
        <?php endforeach; ?>
      </div>
      <div id="picker-grid"></div>
    </div>
  </div>
</div>

<!-- ===================== Modal: Photo detail ===================== -->
<div class="modal-backdrop" id="m-detail">
  <div class="modal">
    <div class="modal-head">
      <h3>Photo details</h3>
      <button type="button" class="x" data-close>&times;</button>
    </div>
    <div class="modal-body">
      <div class="pd-row">
        <div class="pd-thumb"><img id="pd-img" src="" alt=""></div>
        <div class="pd-info">
          <div class="pd-fn" id="pd-fn"></div>
          <span class="pd-src" id="pd-src"></span>
          <div class="pd-tags-label">Tags</div>
          <div class="pd-tag-chips" id="pd-tag-chips"></div>
        </div>
      </div>
      <div class="pd-actions">
        <button type="button" class="btn btn-primary" id="pd-save">Save tags</button>
        <button type="button" class="btn btn-ghost"   id="pd-toggle-hidden">Hide</button>
        <span class="spacer"></span>
        <button type="button" class="btn btn-danger"  id="pd-delete" style="display:none">Delete</button>
      </div>
      <div class="pd-status" id="pd-status"></div>
    </div>
  </div>
</div>

<div id="save-flash" class="save-flash"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
<script>
/* Library data — used by the picker + detail modals + chip filtering. */
window.KNK_LIBRARY = <?= json_encode($library, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
window.KNK_TAGS    = <?= json_encode($all_tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

/* ============================================================
   Tab switching
   ============================================================ */
(function () {
  function showTab(tab) {
    document.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.remove('active'); });
    document.querySelectorAll('.tab-strip a').forEach(function (a) { a.classList.remove('active'); });
    var panel = document.getElementById('tab-' + tab);
    var link  = document.getElementById('tab-' + tab + '-link');
    if (panel) panel.classList.add('active');
    if (link)  link.classList.add('active');
    if (history && history.replaceState) {
      var url = new URL(window.location.href);
      url.searchParams.set('tab', tab);
      history.replaceState(null, '', url.toString());
    }
  }
  document.querySelectorAll('.tab-strip a').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var tab = this.id.replace('tab-', '').replace('-link', '');
      showTab(tab);
    });
  });
})();

/* ============================================================
   Modal helpers
   ============================================================ */
function openModal(id)  { var m = document.getElementById(id); if (m) m.classList.add('open'); }
function closeModal(id) { var m = document.getElementById(id); if (m) m.classList.remove('open'); }
document.querySelectorAll('.modal-backdrop').forEach(function (mb) {
  mb.addEventListener('click', function (e) { if (e.target === mb) mb.classList.remove('open'); });
  mb.querySelectorAll('[data-close]').forEach(function (x) {
    x.addEventListener('click', function () { mb.classList.remove('open'); });
  });
});

/* ============================================================
   Shared: client-side image prep (resize + re-encode to JPEG)
   ============================================================ */
var MAX_EDGE = 2000;
var JPEG_Q   = 0.86;

async function prepareImage(file) {
  var bitmap;
  try { bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' }); }
  catch (_) { bitmap = await loadViaImage(file); }
  var w = bitmap.width, h = bitmap.height, tw = w, th = h;
  var long = Math.max(w, h);
  if (long > MAX_EDGE) {
    var s = MAX_EDGE / long;
    tw = Math.round(w * s); th = Math.round(h * s);
  }
  var canvas = document.createElement('canvas');
  canvas.width = tw; canvas.height = th;
  canvas.getContext('2d').drawImage(bitmap, 0, 0, tw, th);
  if (bitmap.close) bitmap.close();
  return await new Promise(function (resolve, reject) {
    canvas.toBlob(function (b) { b ? resolve(b) : reject(new Error('Encode failed')); }, 'image/jpeg', JPEG_Q);
  });
}
function loadViaImage(file) {
  return new Promise(function (resolve, reject) {
    var url = URL.createObjectURL(file);
    var img = new Image();
    img.onload = function () {
      Object.defineProperty(img, 'width',  { value: img.naturalWidth,  configurable: true });
      Object.defineProperty(img, 'height', { value: img.naturalHeight, configurable: true });
      img.close = function () { URL.revokeObjectURL(url); };
      resolve(img);
    };
    img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('Could not read image')); };
    img.src = url;
  });
}

/* ============================================================
   Slots tab — Replace flow
   ============================================================ */
(function () {
  var currentCard = null;

  document.querySelectorAll('.slot-card').forEach(function (card) {
    var section  = card.getAttribute('data-section');
    var slotIdx  = card.getAttribute('data-slot-index');
    var fileIn   = card.querySelector('.slot-file');
    var resetBtn = card.querySelector('.slot-reset');
    var replBtn  = card.querySelector('.slot-replace');

    replBtn.addEventListener('click', function () {
      currentCard = card;
      openModal('m-replace');
    });

    fileIn.addEventListener('change', async function (e) {
      var f = e.target.files && e.target.files[0];
      if (!f) return;
      setBusy(card, 'Preparing…');
      try {
        var blob = await prepareImage(f);
        setBusy(card, 'Uploading…');
        var res = await uploadSlot(blob, f.name, section, slotIdx);
        if (!res.ok) throw new Error(res.error || 'Upload failed');
        refreshCardImage(card, res.url);
        setOk(card, 'Updated');
      } catch (err) {
        setErr(card, err.message || String(err));
      } finally {
        fileIn.value = '';
      }
    });

    if (resetBtn) {
      resetBtn.addEventListener('click', async function () {
        if (!confirm('Reset this photo back to the default?')) return;
        setBusy(card, 'Resetting…');
        try {
          var res = await resetSlot(section, slotIdx);
          if (!res.ok) throw new Error(res.error || 'Reset failed');
          refreshCardImage(card, res.url);
          setOk(card, 'Back to default');
          setTimeout(function () { location.reload(); }, 700);
        } catch (err) {
          setErr(card, err.message || String(err));
        }
      });
    }
  });

  // Replace-options modal — wire the two big choices
  document.getElementById('opt-upload').addEventListener('click', function () {
    if (!currentCard) return;
    closeModal('m-replace');
    var fi = currentCard.querySelector('.slot-file');
    if (fi) fi.click();
  });
  document.getElementById('opt-pick').addEventListener('click', function () {
    if (!currentCard) return;
    closeModal('m-replace');
    openPicker(currentCard);
  });

  // ----- Gallery picker -----
  var pickerActiveTag = '__all';
  function openPicker(card) {
    pickerActiveTag = '__all';
    document.querySelectorAll('#picker-chips .chip').forEach(function (c) {
      c.classList.toggle('active', c.getAttribute('data-tag') === '__all');
    });
    renderPicker(card);
    openModal('m-picker');
  }
  function renderPicker(card) {
    var grid = document.getElementById('picker-grid');
    grid.innerHTML = '';
    var rows = (window.KNK_LIBRARY || []).filter(function (r) {
      if (r.hidden) return false;
      if (pickerActiveTag === '__all') return true;
      if (pickerActiveTag === '__untagged') return !r.tags || r.tags.length === 0;
      return (r.tags || []).indexOf(pickerActiveTag) !== -1;
    });
    if (rows.length === 0) {
      grid.innerHTML = '<div class="empty">No photos in this filter.</div>';
      return;
    }
    rows.forEach(function (r) {
      var t = document.createElement('div');
      t.className = 'tile';
      var srcCls = r.source === 'seed' ? 'seed' : (r.source === 'gallery_live' ? 'live' : 'slot');
      var srcLbl = r.source === 'seed' ? 'Built-in' : (r.source === 'gallery_live' ? 'Live' : 'Slot');
      t.innerHTML = '<span class="src ' + srcCls + '">' + srcLbl + '</span>' +
                    '<img src="' + r.url + '" alt="" loading="lazy">';
      t.addEventListener('click', function () {
        if (!confirm('Use this photo?')) return;
        pickFromGallery(card, r.filename);
      });
      grid.appendChild(t);
    });
  }
  document.querySelectorAll('#picker-chips .chip').forEach(function (c) {
    c.addEventListener('click', function () {
      document.querySelectorAll('#picker-chips .chip').forEach(function (x) { x.classList.remove('active'); });
      this.classList.add('active');
      pickerActiveTag = this.getAttribute('data-tag') || '__all';
      renderPicker(currentCard);
    });
  });

  async function pickFromGallery(card, filename) {
    var section = card.getAttribute('data-section');
    var slotIdx = card.getAttribute('data-slot-index');
    setBusy(card, 'Updating…');
    try {
      var res = await postForm({
        action: 'slot_pick', section: section, slot_index: slotIdx, filename: filename,
      });
      if (!res.ok) throw new Error(res.error || 'Pick failed');
      closeModal('m-picker');
      refreshCardImage(card, res.url);
      setOk(card, 'Updated');
      setTimeout(function () { location.reload(); }, 700);
    } catch (err) {
      setErr(card, err.message || String(err));
    }
  }

  function uploadSlot(blob, originalName, section, slotIdx) {
    return new Promise(function (resolve, reject) {
      var fd = new FormData();
      fd.append('action', 'slot_replace');
      fd.append('section', section);
      fd.append('slot_index', slotIdx);
      fd.append('photo', blob, (originalName || 'photo').replace(/\.[^.]+$/, '') + '.jpg');
      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'photos.php', true);
      xhr.onload  = function () { try { resolve(JSON.parse(xhr.responseText)); } catch (e) { reject(new Error('Bad server response')); } };
      xhr.onerror = function () { reject(new Error('Network error')); };
      xhr.send(fd);
    });
  }
  function resetSlot(section, slotIdx) {
    return postForm({ action: 'slot_reset', section: section, slot_index: slotIdx });
  }

  function refreshCardImage(card, url) {
    var thumb = card.querySelector('.thumb');
    if (!thumb) return;
    thumb.innerHTML = '<img src="' + url + '?v=' + Date.now() + '" alt="" loading="lazy">';
  }
  function setBusy(card, msg) { card.classList.remove('ok','err'); card.classList.add('busy'); card.querySelector('.status').textContent = msg; }
  function setOk(card, msg)   { card.classList.remove('busy','err'); card.classList.add('ok');   card.querySelector('.status').textContent = msg; }
  function setErr(card, msg)  { card.classList.remove('busy','ok');  card.classList.add('err');  card.querySelector('.status').textContent = 'Error: ' + msg; }
})();

/* ============================================================
   Generic POST helper (form-encoded; handles arrays via repeats)
   ============================================================ */
function postForm(obj) {
  return new Promise(function (resolve, reject) {
    var fd = new FormData();
    Object.keys(obj).forEach(function (k) {
      var v = obj[k];
      if (Array.isArray(v)) {
        v.forEach(function (item) { fd.append(k + '[]', item); });
      } else {
        fd.append(k, v);
      }
    });
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'photos.php', true);
    xhr.onload  = function () { try { resolve(JSON.parse(xhr.responseText)); } catch (e) { reject(new Error('Bad server response')); } };
    xhr.onerror = function () { reject(new Error('Network error')); };
    xhr.send(fd);
  });
}

/* ============================================================
   Gallery wall — chip filtering + tile click → detail modal
   ============================================================ */
(function () {
  var grid = document.getElementById('library-grid');
  var countEl = document.getElementById('library-count');
  var activeTag = '__all';

  function applyFilter() {
    if (!grid) return;
    var shown = 0, hidden = 0;
    grid.querySelectorAll('.tile').forEach(function (t) {
      var tagsCsv = t.getAttribute('data-tags') || '';
      var tags = tagsCsv === '' ? [] : tagsCsv.split(',');
      var match = false;
      if (activeTag === '__all') match = true;
      else if (activeTag === '__untagged') match = tags.length === 0;
      else match = tags.indexOf(activeTag) !== -1;
      t.style.display = match ? '' : 'none';
      if (match) shown++; else hidden++;
    });
    if (countEl) {
      countEl.textContent = shown + ' photo' + (shown === 1 ? '' : 's') + ' shown';
    }
  }

  document.querySelectorAll('#library-chips .chip').forEach(function (c) {
    c.addEventListener('click', function () {
      document.querySelectorAll('#library-chips .chip').forEach(function (x) { x.classList.remove('active'); });
      this.classList.add('active');
      activeTag = this.getAttribute('data-tag') || '__all';
      applyFilter();
    });
  });

  // Suppress click-to-open-detail when a drag has just happened.
  var justDragged = false;
  if (grid) {
    grid.querySelectorAll('.tile').forEach(function (t) {
      t.addEventListener('click', function () {
        if (justDragged) { justDragged = false; return; }
        openDetail(t);
      });
    });

    // Drag-to-reorder via Sortable.js
    if (typeof Sortable !== 'undefined') {
      Sortable.create(grid, {
        animation: 160,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        dragClass: 'sortable-drag',
        // Touch hold delay so a tap-to-open still works on mobile.
        delay: 180,
        delayOnTouchOnly: true,
        touchStartThreshold: 4,
        onStart: function () { justDragged = false; },
        onEnd:   function (evt) {
          if (evt.oldIndex === evt.newIndex) return;
          justDragged = true;
          saveReorder();
        },
      });
    }

    function saveReorder() {
      var filenames = [];
      grid.querySelectorAll('.tile').forEach(function (t) {
        var fn = t.getAttribute('data-filename');
        if (fn) filenames.push(fn);
      });
      grid.classList.add('is-saving');
      flash('Saving order…', '');
      postForm({ action: 'library_reorder', filenames: filenames }).then(function (res) {
        grid.classList.remove('is-saving');
        if (res && res.ok) flash('Order saved', 'ok');
        else                flash('Save failed: ' + ((res && res.error) || 'unknown'), 'err');
      }).catch(function (e) {
        grid.classList.remove('is-saving');
        flash('Save failed: ' + (e.message || e), 'err');
      });
    }

    function flash(msg, kind) {
      var el = document.getElementById('save-flash');
      el.textContent = msg;
      el.className = 'save-flash show' + (kind ? ' ' + kind : '');
      clearTimeout(el._t);
      el._t = setTimeout(function () { el.className = 'save-flash' + (kind ? ' ' + kind : ''); }, 1400);
    }
  }
})();

/* ============================================================
   Photo detail modal
   ============================================================ */
(function () {
  var current = null; // { filename, source, tags, hidden, tile }

  window.openDetail = function (tile) {
    var filename = tile.getAttribute('data-filename');
    var source   = tile.getAttribute('data-source');
    var tagsCsv  = tile.getAttribute('data-tags') || '';
    var hidden   = tile.getAttribute('data-hidden') === '1';
    current = {
      filename: filename, source: source,
      tags: tagsCsv === '' ? [] : tagsCsv.split(','),
      hidden: hidden, tile: tile,
    };
    document.getElementById('pd-img').src = 'assets/img/' + filename + '?v=' + Date.now();
    document.getElementById('pd-fn').textContent = filename;
    var srcEl = document.getElementById('pd-src');
    var srcCls = source === 'seed' ? 'seed' : (source === 'gallery_live' ? 'live' : 'slot');
    var srcLbl = source === 'seed' ? 'Built-in (can\'t delete)' : (source === 'gallery_live' ? 'Live upload' : 'Slot upload');
    srcEl.className = 'pd-src ' + srcCls;
    srcEl.textContent = srcLbl;

    // Render tag chips
    var chipsEl = document.getElementById('pd-tag-chips');
    chipsEl.innerHTML = '';
    (window.KNK_TAGS || []).forEach(function (t) {
      var c = document.createElement('button');
      c.type = 'button'; c.className = 'chip';
      if (current.tags.indexOf(t) !== -1) c.classList.add('active');
      c.textContent = t;
      c.addEventListener('click', function () {
        c.classList.toggle('active');
        if (c.classList.contains('active')) {
          if (current.tags.indexOf(t) === -1) current.tags.push(t);
        } else {
          current.tags = current.tags.filter(function (x) { return x !== t; });
        }
      });
      chipsEl.appendChild(c);
    });

    // Hide button label
    document.getElementById('pd-toggle-hidden').textContent = current.hidden ? 'Unhide' : 'Hide';

    // Delete button visibility
    document.getElementById('pd-delete').style.display = (source === 'seed') ? 'none' : '';

    setStatus('', '');
    openModal('m-detail');
  };

  function setStatus(msg, kind) {
    var el = document.getElementById('pd-status');
    el.textContent = msg || '';
    el.className = 'pd-status' + (kind ? ' ' + kind : '');
  }

  document.getElementById('pd-save').addEventListener('click', async function () {
    if (!current) return;
    setStatus('Saving…', '');
    try {
      var res = await postForm({ action: 'library_set_tags', filename: current.filename, tags: current.tags });
      if (!res.ok) throw new Error(res.error || 'Save failed');
      // Update tile data-tags so chip filter works without reload
      current.tile.setAttribute('data-tags', (res.tags || []).join(','));
      setStatus('Saved.', 'ok');
      setTimeout(function () { closeModal('m-detail'); location.reload(); }, 600);
    } catch (e) { setStatus(e.message || String(e), 'err'); }
  });

  document.getElementById('pd-toggle-hidden').addEventListener('click', async function () {
    if (!current) return;
    var newHidden = !current.hidden;
    setStatus(newHidden ? 'Hiding…' : 'Unhiding…', '');
    try {
      var res = await postForm({ action: 'library_set_hidden', filename: current.filename, hidden: newHidden ? '1' : '0' });
      if (!res.ok) throw new Error(res.error || 'Failed');
      setStatus(newHidden ? 'Hidden.' : 'Visible.', 'ok');
      setTimeout(function () { closeModal('m-detail'); location.reload(); }, 600);
    } catch (e) { setStatus(e.message || String(e), 'err'); }
  });

  document.getElementById('pd-delete').addEventListener('click', async function () {
    if (!current) return;
    if (!confirm('Delete this photo? It\'ll be removed from the site permanently.')) return;
    setStatus('Deleting…', '');
    try {
      var res = await postForm({ action: 'library_delete', filename: current.filename });
      if (!res.ok) throw new Error(res.error || 'Delete failed');
      setStatus('Deleted.', 'ok');
      setTimeout(function () { closeModal('m-detail'); location.reload(); }, 500);
    } catch (e) { setStatus(e.message || String(e), 'err'); }
  });
})();

/* ============================================================
   Gallery-wall drop zone — bulk upload
   ============================================================ */
(function () {
  var drop    = document.getElementById('drop');
  var input   = document.getElementById('gallery-fileinput');
  var statusE = document.getElementById('gallery-status');
  if (!drop || !input) return;

  ['dragenter','dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); drop.classList.add('drag'); });
  });
  ['dragleave','drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); drop.classList.remove('drag'); });
  });
  drop.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files) handleFiles(e.dataTransfer.files);
  });
  input.addEventListener('change', function (e) { handleFiles(e.target.files); });

  async function handleFiles(files) {
    for (var i = 0; i < files.length; i++) {
      var f = files[i];
      var row = addRow(f.name);
      try {
        var blob = await prepareImage(f);
        await uploadBlob(blob, f.name, row);
        markDone(row);
      } catch (err) {
        markError(row, err.message || String(err));
      }
    }
    setTimeout(function () { location.reload(); }, 900);
  }

  function uploadBlob(blob, originalName, row) {
    return new Promise(function (resolve, reject) {
      var fd = new FormData();
      fd.append('action', 'gallery_upload');
      fd.append('photo', blob, (originalName || 'photo').replace(/\.[^.]+$/, '') + '.jpg');
      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'photos.php', true);
      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable) setProgress(row, (e.loaded / e.total) * 100);
      };
      xhr.onload = function () {
        try {
          var res = JSON.parse(xhr.responseText);
          if (res.ok) resolve(res); else reject(new Error(res.error || 'Upload failed'));
        } catch (e) { reject(new Error('Bad server response')); }
      };
      xhr.onerror = function () { reject(new Error('Network error')); };
      xhr.send(fd);
    });
  }

  function addRow(name) {
    var row = document.createElement('div');
    row.className = 'row';
    row.innerHTML = '<span class="fn"></span>' +
                    '<div class="bar-outer"><div class="bar-inner"></div></div>' +
                    '<span class="msg">Queued…</span>';
    row.querySelector('.fn').textContent = name;
    statusE.appendChild(row);
    return row;
  }
  function setProgress(row, pct) {
    row.querySelector('.bar-inner').style.width = Math.max(0, Math.min(100, pct)) + '%';
    row.querySelector('.msg').textContent = 'Uploading ' + Math.round(pct) + '%';
  }
  function markDone(row)     { row.classList.add('done'); row.querySelector('.bar-inner').style.width = '100%'; row.querySelector('.msg').textContent = 'Done'; }
  function markError(row, e) { row.classList.add('err');  row.querySelector('.msg').textContent = 'Error: ' + e; }
})();
</script>
</body>
</html>

<?php
/* =========================================================
   KnK Inn — Photo manager (V2 two-tab hub)
   --------------------------------------------------------
   Tab 1 — Homepage slots:
       Replace any of the 22 managed photos that drive
       index.php + drinks.php.  Backed by the photo_slots
       table; uploads land in assets/img/slots/.

   Tab 2 — Gallery wall:
       The original bulk-uploader that adds photos to
       assets/img/gallery-live/ (shown on gallery.php).

   Role-gated: super_admin + owner only.
   Target PHP 7.4 (Matbao shared hosting).
   ========================================================= */

require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/photo_slots_store.php";

$me = knk_require_role(["super_admin", "owner"]);

/* ----------------------------------------------------------
 * Gallery-wall constants (unchanged from the original uploader)
 * ---------------------------------------------------------- */
if (!defined("PHOTOS_DIR"))      define("PHOTOS_DIR",    __DIR__ . "/assets/img/gallery-live");
if (!defined("PHOTOS_URL"))      define("PHOTOS_URL",    "assets/img/gallery-live");
if (!defined("GALLERY_MAX_UPLOAD")) define("GALLERY_MAX_UPLOAD", 15 * 1024 * 1024);
$GALLERY_ALLOWED_MIMES = ["image/jpeg","image/jpg","image/png","image/webp","image/heic","image/heif"];

if (!is_dir(PHOTOS_DIR)) @mkdir(PHOTOS_DIR, 0755, true);
if (!is_dir(KNK_SLOTS_DIR)) @mkdir(KNK_SLOTS_DIR, 0755, true);

/* ----------------------------------------------------------
 * Dispatch POST / GET actions.
 *   ?action=slot_replace   — AJAX, returns JSON
 *   ?action=slot_reset     — AJAX, returns JSON
 *   ?action=gallery_upload — AJAX, returns JSON
 *    action=gallery_delete — form POST, redirects
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

if ($action === "gallery_delete") {
    $f = basename((string)($_POST["file"] ?? ""));
    if (preg_match('/^[a-zA-Z0-9._-]+\.(jpe?g|png|webp)$/i', $f)) {
        $path = PHOTOS_DIR . "/" . $f;
        if (is_file($path)) @unlink($path);
    }
    header("Location: photos.php?tab=gallery");
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
    echo json_encode(["ok" => true, "name" => $name, "url" => PHOTOS_URL . "/" . $name]);
    exit;
}

/* ----------------------------------------------------------
 * Default: render the page.
 * ---------------------------------------------------------- */
$tab = (string)($_GET["tab"] ?? "slots");
if ($tab !== "slots" && $tab !== "gallery") $tab = "slots";

$slots    = knk_slots_load();
$sections = knk_photo_sections();

$gallery_files = [];
if (is_dir(PHOTOS_DIR)) {
    foreach (glob(PHOTOS_DIR . "/*.{jpg,jpeg,png,webp}", GLOB_BRACE) as $f) {
        $gallery_files[] = ["name" => basename($f), "mtime" => filemtime($f)];
    }
    usort($gallery_files, function ($a, $b) { return $b["mtime"] <=> $a["mtime"]; });
}
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

    /* Gallery-wall tab (same look as old uploader, cleaned up) */
    .drop {
      border: 2px dashed rgba(201,170,113,0.4); border-radius: 6px; padding: 2.4rem 1.4rem;
      text-align: center; background: rgba(24,12,3,0.4); cursor: pointer;
      transition: all 0.3s var(--ease-out);
    }
    .drop:hover, .drop.drag { border-color: var(--gold); background: rgba(201,170,113,0.06); }
    .drop p { color: var(--cream-dim); margin: 0.4rem 0; font-size: 0.95rem; }
    .drop strong { color: var(--gold); display: block; font-size: 1.1rem; margin-bottom: 0.4rem; }
    .drop input { display: none; }
    .drop .hint { font-size: 0.78rem; color: var(--cream-faint); margin-top: 0.8rem; }

    #gallery-status { margin-top: 1rem; }
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

    .gallery-wall h2 { margin: 2.4rem 0 1rem; font-size: 1rem; letter-spacing: 0.2em; text-transform: uppercase; color: var(--gold); }
    .gallery-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.6rem;
    }
    .tile {
      position: relative; aspect-ratio: 1 / 1; border-radius: 4px; overflow: hidden;
      background: rgba(255,255,255,0.04);
    }
    .tile img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .tile button.del {
      position: absolute; top: 6px; right: 6px; width: 28px; height: 28px; border-radius: 50%;
      border: none; background: rgba(0,0,0,0.7); color: #fff; font-size: 1rem; cursor: pointer;
      display: flex; align-items: center; justify-content: center; font-family: inherit;
      transition: background 0.2s;
    }
    .tile button.del:hover { background: #c04a3a; }
    .empty { color: var(--cream-faint); text-align: center; padding: 2rem; font-size: 0.9rem; }
    .count { color: var(--cream-dim); font-size: 0.82rem; margin-bottom: 0.8rem; }
  </style>
</head>
<body>
<?php knk_render_admin_nav($me); ?>
<div class="wrap">

  <header class="bar">
    <span class="eyebrow">KnK Inn</span>
    <h1 class="display-md">Photo <em>manager</em></h1>
    <p style="color: var(--cream-dim); font-size: 0.9rem; max-width: 60ch; margin: 0.4rem 0 0;">
      Swap the photos that visitors see on the homepage and the drinks page, or add extra shots to the gallery wall.
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
                <label class="btn" for="<?= htmlspecialchars($slot_id) ?>-file">Replace</label>
                <input type="file" accept="image/*"
                       id="<?= htmlspecialchars($slot_id) ?>-file"
                       class="slot-file">
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
      <p class="hint">Each photo is auto-resized &amp; rotated, then shows up on the Gallery page straight away.</p>
      <input type="file" id="gallery-fileinput" accept="image/*" multiple>
    </label>

    <div id="gallery-status"></div>

    <section class="gallery-wall">
      <h2>Your uploads</h2>
      <p class="count"><?= count($gallery_files) ?> photo<?= count($gallery_files) === 1 ? '' : 's' ?> in the live gallery</p>

      <?php if (empty($gallery_files)): ?>
        <div class="empty">No photos yet — upload one above.</div>
      <?php else: ?>
        <div class="gallery-grid">
          <?php foreach ($gallery_files as $f): $u = PHOTOS_URL . "/" . rawurlencode($f["name"]); ?>
            <div class="tile">
              <img src="<?= htmlspecialchars($u) ?>" alt="" loading="lazy">
              <form method="post" onsubmit="return confirm('Delete this photo?');">
                <input type="hidden" name="action" value="gallery_delete">
                <input type="hidden" name="file"   value="<?= htmlspecialchars($f["name"]) ?>">
                <button class="del" type="submit" title="Delete">×</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

  </div>

</div>

<script>
/* ============================================================
   Tab switching (client-side, also preserves querystring)
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
   Shared: client-side image prep (resize + re-encode to JPEG)
   ============================================================ */
var MAX_EDGE = 2000;
var JPEG_Q   = 0.86;

async function prepareImage(file) {
  var bitmap;
  try {
    bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
  } catch (_) {
    bitmap = await loadViaImage(file);
  }
  var w = bitmap.width, h = bitmap.height;
  var tw = w, th = h;
  var long = Math.max(w, h);
  if (long > MAX_EDGE) {
    var s = MAX_EDGE / long;
    tw = Math.round(w * s);
    th = Math.round(h * s);
  }
  var canvas = document.createElement('canvas');
  canvas.width = tw; canvas.height = th;
  var ctx = canvas.getContext('2d');
  ctx.drawImage(bitmap, 0, 0, tw, th);
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
   Slots tab — per-card replace + reset
   ============================================================ */
(function () {
  document.querySelectorAll('.slot-card').forEach(function (card) {
    var section  = card.getAttribute('data-section');
    var slotIdx  = card.getAttribute('data-slot-index');
    var fileIn   = card.querySelector('.slot-file');
    var statusEl = card.querySelector('.status');
    var resetBtn = card.querySelector('.slot-reset');

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
          // Hide the "Custom" pill and the Reset button on next load; easiest is reload.
          setTimeout(function () { location.reload(); }, 700);
        } catch (err) {
          setErr(card, err.message || String(err));
        }
      });
    }
  });

  function uploadSlot(blob, originalName, section, slotIdx) {
    return new Promise(function (resolve, reject) {
      var fd = new FormData();
      fd.append('action', 'slot_replace');
      fd.append('section', section);
      fd.append('slot_index', slotIdx);
      fd.append('photo', blob, (originalName || 'photo').replace(/\.[^.]+$/, '') + '.jpg');
      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'photos.php', true);
      xhr.onload = function () {
        try { resolve(JSON.parse(xhr.responseText)); }
        catch (e) { reject(new Error('Bad server response')); }
      };
      xhr.onerror = function () { reject(new Error('Network error')); };
      xhr.send(fd);
    });
  }

  function resetSlot(section, slotIdx) {
    return new Promise(function (resolve, reject) {
      var fd = new FormData();
      fd.append('action', 'slot_reset');
      fd.append('section', section);
      fd.append('slot_index', slotIdx);
      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'photos.php', true);
      xhr.onload = function () {
        try { resolve(JSON.parse(xhr.responseText)); }
        catch (e) { reject(new Error('Bad server response')); }
      };
      xhr.onerror = function () { reject(new Error('Network error')); };
      xhr.send(fd);
    });
  }

  function refreshCardImage(card, url) {
    var thumb = card.querySelector('.thumb');
    if (!thumb) return;
    thumb.innerHTML = '<img src="' + url + '?v=' + Date.now() + '" alt="" loading="lazy">';
  }
  function setBusy(card, msg) {
    card.classList.remove('ok', 'err'); card.classList.add('busy');
    card.querySelector('.status').textContent = msg;
  }
  function setOk(card, msg) {
    card.classList.remove('busy', 'err'); card.classList.add('ok');
    card.querySelector('.status').textContent = msg;
  }
  function setErr(card, msg) {
    card.classList.remove('busy', 'ok'); card.classList.add('err');
    card.querySelector('.status').textContent = 'Error: ' + msg;
  }
})();

/* ============================================================
   Gallery-wall tab — bulk upload
   ============================================================ */
(function () {
  var drop    = document.getElementById('drop');
  var input   = document.getElementById('gallery-fileinput');
  var statusE = document.getElementById('gallery-status');
  if (!drop || !input) return;

  ['dragenter','dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) {
      e.preventDefault(); e.stopPropagation(); drop.classList.add('drag');
    });
  });
  ['dragleave','drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) {
      e.preventDefault(); e.stopPropagation(); drop.classList.remove('drag');
    });
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
          if (res.ok) resolve(res);
          else reject(new Error(res.error || 'Upload failed'));
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
  function markDone(row)  { row.classList.add('done'); row.querySelector('.bar-inner').style.width = '100%'; row.querySelector('.msg').textContent = 'Done'; }
  function markError(row, e) { row.classList.add('err'); row.querySelector('.msg').textContent = 'Error: ' + e; }
})();
</script>
</body>
</html>

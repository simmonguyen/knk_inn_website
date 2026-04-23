<?php
/* =========================================================
   KnK Inn — Photo uploader for Simmo
   Drop this at https://knkinn.com/photos.php
   Password-protected; uploads land in assets/img/gallery-live/
   and appear on gallery.php automatically.
   ========================================================= */

session_start();

/* ---- Config ---- */
const PHOTOS_PASSWORD = 'Knk@070475';                 // Simmo's password (same as FTP — his choice)
const PHOTOS_DIR      = __DIR__ . '/assets/img/gallery-live';
const PHOTOS_URL      = 'assets/img/gallery-live';    // relative from site root
const MAX_UPLOAD      = 15 * 1024 * 1024;             // 15 MB cap per file (post-resize it'll be much smaller)
const ALLOWED_MIMES   = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

/* Make sure the upload dir exists */
if (!is_dir(PHOTOS_DIR)) {
  @mkdir(PHOTOS_DIR, 0755, true);
}

function is_logged_in(): bool {
  return !empty($_SESSION['photos_ok']);
}

/* ---------- Handle logout ---------- */
if (($_POST['action'] ?? '') === 'logout') {
  $_SESSION = [];
  session_destroy();
  header('Location: photos.php');
  exit;
}

/* ---------- Handle login ---------- */
$login_error = '';
if (($_POST['action'] ?? '') === 'login') {
  $pw = $_POST['password'] ?? '';
  if (hash_equals(PHOTOS_PASSWORD, $pw)) {
    session_regenerate_id(true);
    $_SESSION['photos_ok'] = true;
    header('Location: photos.php');
    exit;
  } else {
    $login_error = 'Wrong password, mate. Try again.';
  }
}

/* Everything below requires login */
if (!is_logged_in()) {
  render_login($login_error);
  exit;
}

/* ---------- Handle delete (POST only, same-origin via session) ---------- */
if (($_POST['action'] ?? '') === 'delete') {
  $f = basename($_POST['file'] ?? '');
  // only allow filenames that match our generated pattern
  if (preg_match('/^[a-zA-Z0-9._-]+\.(jpe?g|png|webp)$/i', $f)) {
    $path = PHOTOS_DIR . '/' . $f;
    if (is_file($path)) {
      @unlink($path);
    }
  }
  header('Location: photos.php');
  exit;
}

/* ---------- Handle upload (AJAX, returns JSON) ---------- */
if (($_POST['action'] ?? '') === 'upload' || ($_GET['action'] ?? '') === 'upload') {
  header('Content-Type: application/json; charset=utf-8');

  if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Upload failed (' . ($_FILES['photo']['error'] ?? 'no file') . ')']);
    exit;
  }

  $tmp  = $_FILES['photo']['tmp_name'];
  $size = $_FILES['photo']['size'];

  if ($size > MAX_UPLOAD) {
    echo json_encode(['ok' => false, 'error' => 'File too big (>15 MB).']);
    exit;
  }

  $mime = function_exists('mime_content_type') ? mime_content_type($tmp) : ($_FILES['photo']['type'] ?? '');
  if (!in_array(strtolower($mime), ALLOWED_MIMES, true)) {
    echo json_encode(['ok' => false, 'error' => 'Not an image: ' . $mime]);
    exit;
  }

  /* Name it with date + random suffix */
  $stamp = date('Ymd-His');
  $rand  = substr(bin2hex(random_bytes(3)), 0, 6);
  $ext   = ($mime === 'image/png')  ? 'png'
         : ($mime === 'image/webp') ? 'webp'
         : 'jpg';
  $name  = $stamp . '-' . $rand . '.' . $ext;
  $dest  = PHOTOS_DIR . '/' . $name;

  if (!move_uploaded_file($tmp, $dest)) {
    echo json_encode(['ok' => false, 'error' => 'Could not save file.']);
    exit;
  }

  /* Server-side EXIF auto-rotate fallback (if GD + exif available and it's a JPEG) */
  if ($ext === 'jpg' && function_exists('exif_read_data') && function_exists('imagecreatefromjpeg')) {
    try {
      $exif = @exif_read_data($dest);
      if (!empty($exif['Orientation']) && $exif['Orientation'] > 1) {
        $img = @imagecreatefromjpeg($dest);
        if ($img) {
          $angle = 0;
          switch ($exif['Orientation']) {
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

  echo json_encode([
    'ok'   => true,
    'name' => $name,
    'url'  => PHOTOS_URL . '/' . $name,
  ]);
  exit;
}

/* ---------- Default: render the manager page ---------- */
render_manager();

/* ============================================================
   Helpers
   ============================================================ */

function render_login(string $error = ''): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>KnK Inn — Photos</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Caveat:wght@700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/styles.css?v=12">
  <style>
    body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 2rem; }
    .lock-card {
      background: rgba(24,12,3,0.6); border: 1px solid rgba(201,170,113,0.22);
      padding: 2.4rem 2rem; border-radius: 6px; width: 100%; max-width: 380px;
      text-align: center; backdrop-filter: blur(8px);
    }
    .lock-card h1 { margin-bottom: 0.6rem; }
    .lock-card p { color: var(--cream-dim); font-size: 0.9rem; margin-bottom: 1.6rem; }
    .lock-card input[type=password] {
      width: 100%; padding: 0.85rem 1rem; margin-bottom: 1rem;
      background: rgba(255,255,255,0.04); border: 1px solid rgba(201,170,113,0.3);
      color: var(--cream); font-size: 1rem; font-family: inherit; border-radius: 4px;
    }
    .lock-card input[type=password]:focus { outline: none; border-color: var(--gold); }
    .lock-card button {
      width: 100%; padding: 0.85rem; background: var(--gold); color: var(--brown-deep);
      border: none; font-weight: 700; letter-spacing: 0.15em; text-transform: uppercase;
      font-size: 0.8rem; cursor: pointer; border-radius: 4px; font-family: inherit;
    }
    .lock-card button:hover { background: var(--gold-light); }
    .err { color: #ff9a8a; font-size: 0.85rem; margin-bottom: 1rem; }
  </style>
</head>
<body>
  <form class="lock-card" method="post" autocomplete="off">
    <span class="eyebrow">Staff only</span>
    <h1 class="display-md">KnK <em>Photos</em></h1>
    <p>Enter password to upload photos to the gallery.</p>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <input type="hidden" name="action" value="login">
    <input type="password" name="password" placeholder="Password" autofocus required>
    <button type="submit">Unlock</button>
  </form>
</body>
</html>
<?php }

function render_manager(): void {
  $files = [];
  if (is_dir(PHOTOS_DIR)) {
    foreach (glob(PHOTOS_DIR . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) as $f) {
      $files[] = ['name' => basename($f), 'mtime' => filemtime($f)];
    }
    usort($files, function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });
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
    body { padding: 2rem 1rem 4rem; }
    .wrap { max-width: 900px; margin: 0 auto; }
    header.bar {
      display: flex; align-items: center; justify-content: space-between;
      gap: 1rem; flex-wrap: wrap; margin-bottom: 2rem;
    }
    header.bar .title { flex: 1; min-width: 220px; }
    header.bar .logout {
      color: var(--cream-dim); font-size: 0.75rem; letter-spacing: 0.18em;
      text-transform: uppercase; text-decoration: none; padding: 0.5rem 1rem;
      border: 1px solid rgba(201,170,113,0.3); border-radius: 3px; background: transparent;
      cursor: pointer; font-family: inherit;
    }
    header.bar .logout:hover { border-color: var(--gold); color: var(--gold); }

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

    #status { margin-top: 1rem; }
    .row {
      display: flex; align-items: center; gap: 0.8rem; padding: 0.7rem 0.9rem;
      margin-bottom: 0.5rem; border-radius: 4px; background: rgba(255,255,255,0.03);
      font-size: 0.9rem;
    }
    .row .bar-outer {
      flex: 1; height: 6px; background: rgba(201,170,113,0.15); border-radius: 3px; overflow: hidden;
    }
    .row .bar-inner { height: 100%; background: var(--gold); width: 0%; transition: width 0.2s; }
    .row.done .bar-inner { background: #7fd08a; }
    .row.err  { color: #ff9a8a; }
    .row .fn { flex: 0 1 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    section.gallery { margin-top: 3rem; }
    section.gallery h2 { margin-bottom: 1rem; font-size: 1rem; letter-spacing: 0.2em; text-transform: uppercase; color: var(--gold); }
    .grid {
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
<div class="wrap">

  <header class="bar">
    <div class="title">
      <span class="eyebrow">KnK Inn</span>
      <h1 class="display-md">Photo <em>manager</em></h1>
    </div>
    <form method="post" style="margin:0;">
      <input type="hidden" name="action" value="logout">
      <button class="logout" type="submit">Log out</button>
    </form>
  </header>

  <label class="drop" id="drop">
    <strong>Tap to add photos</strong>
    <p>Or drag &amp; drop JPG / PNG / iPhone photos here</p>
    <p class="hint">Each photo is auto-resized &amp; rotated. They appear on the gallery page straight away.</p>
    <input type="file" id="fileinput" accept="image/*" multiple>
  </label>

  <div id="status"></div>

  <section class="gallery">
    <h2>Your uploads</h2>
    <p class="count"><?= count($files) ?> photo<?= count($files) === 1 ? '' : 's' ?> in the live gallery</p>

    <?php if (empty($files)): ?>
      <div class="empty">No photos yet — upload one above.</div>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($files as $f): $u = PHOTOS_URL . '/' . rawurlencode($f['name']); ?>
          <div class="tile">
            <img src="<?= htmlspecialchars($u) ?>" alt="" loading="lazy">
            <form method="post" onsubmit="return confirm('Delete this photo?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="file"   value="<?= htmlspecialchars($f['name']) ?>">
              <button class="del" type="submit" title="Delete">×</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<script>
/* =========================================================
   Client-side: HEIC/EXIF-aware resize, then XHR upload
   ========================================================= */
(function () {
  const drop = document.getElementById('drop');
  const input = document.getElementById('fileinput');
  const statusEl = document.getElementById('status');
  const MAX_EDGE = 2000;   // px — long edge cap
  const Q        = 0.86;   // JPEG quality

  ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => {
    e.preventDefault(); e.stopPropagation(); drop.classList.add('drag');
  }));
  ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => {
    e.preventDefault(); e.stopPropagation(); drop.classList.remove('drag');
  }));
  drop.addEventListener('drop', e => {
    if (e.dataTransfer && e.dataTransfer.files) handleFiles(e.dataTransfer.files);
  });
  input.addEventListener('change', e => handleFiles(e.target.files));

  async function handleFiles(files) {
    for (const f of files) {
      const row = addRow(f.name);
      try {
        const blob = await prepareImage(f, row);
        await uploadBlob(blob, f.name, row);
        markDone(row);
      } catch (err) {
        markError(row, err.message || String(err));
      }
    }
    // After all done, reload so the grid refreshes
    setTimeout(() => location.reload(), 900);
  }

  async function prepareImage(file, row) {
    setMsg(row, 'Processing…');
    // Try createImageBitmap with EXIF rotation (handles HEIC on iOS Safari)
    let bitmap;
    try {
      bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch (_) {
      // Fallback: <img> + URL.createObjectURL (no EXIF auto-rotate — server will fix)
      bitmap = await loadViaImage(file);
    }
    const { width: w, height: h } = bitmap;
    let tw = w, th = h;
    const long = Math.max(w, h);
    if (long > MAX_EDGE) {
      const s = MAX_EDGE / long;
      tw = Math.round(w * s); th = Math.round(h * s);
    }
    const canvas = document.createElement('canvas');
    canvas.width = tw; canvas.height = th;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(bitmap, 0, 0, tw, th);
    if (bitmap.close) bitmap.close();

    return await new Promise((resolve, reject) => {
      canvas.toBlob(b => b ? resolve(b) : reject(new Error('Encode failed')), 'image/jpeg', Q);
    });
  }

  function loadViaImage(file) {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => {
        // drawImage() accepts an HTMLImageElement directly; mimic the ImageBitmap fields we use.
        img.width_ = img.naturalWidth;
        img.height_ = img.naturalHeight;
        Object.defineProperty(img, 'width',  { value: img.naturalWidth,  configurable: true });
        Object.defineProperty(img, 'height', { value: img.naturalHeight, configurable: true });
        img.close = () => URL.revokeObjectURL(url);
        resolve(img);
      };
      img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Could not read image')); };
      img.src = url;
    });
  }

  function uploadBlob(blob, originalName, row) {
    return new Promise((resolve, reject) => {
      const fd = new FormData();
      fd.append('action', 'upload');
      fd.append('photo', blob, (originalName || 'photo').replace(/\.[^.]+$/, '') + '.jpg');
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'photos.php', true);
      xhr.upload.onprogress = e => {
        if (e.lengthComputable) setProgress(row, (e.loaded / e.total) * 100);
      };
      xhr.onload = () => {
        try {
          const res = JSON.parse(xhr.responseText);
          if (res.ok) resolve(res);
          else reject(new Error(res.error || 'Upload failed'));
        } catch (e) { reject(new Error('Bad server response')); }
      };
      xhr.onerror = () => reject(new Error('Network error'));
      xhr.send(fd);
    });
  }

  function addRow(name) {
    const row = document.createElement('div');
    row.className = 'row';
    row.innerHTML = '<span class="fn"></span>' +
                    '<div class="bar-outer"><div class="bar-inner"></div></div>' +
                    '<span class="msg">Queued…</span>';
    row.querySelector('.fn').textContent = name;
    statusEl.appendChild(row);
    return row;
  }
  function setProgress(row, pct) {
    row.querySelector('.bar-inner').style.width = Math.max(0, Math.min(100, pct)) + '%';
    row.querySelector('.msg').textContent = 'Uploading ' + Math.round(pct) + '%';
  }
  function setMsg(row, msg) { row.querySelector('.msg').textContent = msg; }
  function markDone(row)     { row.classList.add('done'); row.querySelector('.bar-inner').style.width = '100%'; row.querySelector('.msg').textContent = 'Done'; }
  function markError(row, e) { row.classList.add('err');  row.querySelector('.msg').textContent = 'Error: ' + e; }
})();
</script>
</body>
</html>
<?php }

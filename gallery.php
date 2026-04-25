<?php
/*
 * Public gallery page.
 *
 * Single source of truth = the photo_library table managed via /photos.php.
 * That means:
 *   - Order on this page == sort_order set by Ben dragging tiles in admin
 *   - Filter chips      == knk_photo_tags() whitelist used in admin
 *   - Hidden photos      are excluded
 *   - "Latest" chip      shows only photos uploaded via the gallery (source = gallery_live)
 *
 * If the DB / migration 011 isn't available we fall back to a direct disk
 * scan so the page still renders something rather than blank.
 */

require_once __DIR__ . '/includes/photo_library_store.php';

// Refresh the library from disk on every page load — cheap, idempotent.
// Means new files Ben drops via FTP show up without an admin visit.
@knk_photo_library_scan();

$lib_photos = knk_photo_library_list(['include_hidden' => false]);

// Disk fallback if DB returned nothing (pre-migration or DB blip).
if (empty($lib_photos)) {
    $lib_photos = [];
    $img_root = __DIR__ . '/assets/img';
    $sub_dirs = ['', 'gallery-live'];
    foreach ($sub_dirs as $sub) {
        $dir = $sub === '' ? $img_root : $img_root . '/' . $sub;
        if (!is_dir($dir)) continue;
        foreach (glob($dir . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) as $f) {
            $rel = $sub === '' ? basename($f) : $sub . '/' . basename($f);
            $auto = knk_photo_auto_tags($rel, $sub === 'gallery-live' ? 'gallery_live' : 'seed');
            $lib_photos[] = [
                'filename' => $rel,
                'source'   => $sub === 'gallery-live' ? 'gallery_live' : 'seed',
                'tags'     => $auto,
                'hidden'   => 0,
                'url'      => 'assets/img/' . $rel,
            ];
        }
    }
}

$total_photos = count($lib_photos);
$has_live     = false;
foreach ($lib_photos as $p) {
    if (($p['source'] ?? '') === 'gallery_live') { $has_live = true; break; }
}

/* The chip filter logic in main.js does a lowercase match against tokens
   in data-cat (split on spaces). So data-filter and data-cat tokens are
   stored in lowercase here, while the visible chip label stays cased. */
function gal_slug(string $tag): string {
    return strtolower(preg_replace('/[^a-z0-9]+/i', '', $tag));
}

$tag_whitelist = knk_photo_tags();

// i18n keys for chips. Existing keys we keep using: rooms, rooftop, exterior,
// people. New ones added in i18n.js: lounge, darts, sports, other.
$chip_i18n = [
    'rooms'    => 'gallery.filter.rooms',
    'rooftop'  => 'gallery.filter.rooftop',
    'lounge'   => 'gallery.filter.lounge',
    'darts'    => 'gallery.filter.darts',
    'exterior' => 'gallery.filter.exterior',
    'sports'   => 'gallery.filter.sports',
    'people'   => 'gallery.filter.people',
    'other'    => 'gallery.filter.other',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Gallery — KnK Inn</title>
<meta name="description" content="Photographs from KnK Inn — rooms, rooftop, bar, and street life on De Tham, Saigon.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@400;500;600;700&family=Caveat:wght@700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/styles.css?v=13">
</head>
<body>

<!-- NAV -->
<nav id="nav">
  <div class="nav-inner">
    <a href="index.php" class="nav-logo">KnK Inn</a>
    <ul class="nav-links">
      <li><a href="index.php" data-i18n="nav.home">Home</a></li>
      <li><a href="rooms.php" data-i18n="nav.rooms">Rooms</a></li>
      <li><a href="drinks.php" data-i18n="nav.drinks">Drinks</a></li>
      <li><a href="gallery.php" class="active" data-i18n="nav.gallery">Gallery</a></li>
      <li><a href="index.php#sports" data-i18n="nav.sports">Sports</a></li>
      <li><a href="index.php#contact" data-i18n="nav.contact">Contact</a></li>
    </ul>
    <div class="nav-right">
      <div data-lang-switch></div>
      <a href="index.php#contact" class="nav-cta" data-i18n="nav.book">Book</a>
    </div>
    <button class="hamburger" aria-label="Menu" aria-expanded="false">
      <span></span><span></span><span></span>
    </button>
  </div>
</nav>

<div class="mobile-menu">
  <a href="index.php" data-i18n="nav.home">Home</a>
  <a href="rooms.php" data-i18n="nav.rooms">Rooms</a>
  <a href="drinks.php" data-i18n="nav.drinks">Drinks</a>
  <a href="gallery.php" data-i18n="nav.gallery">Gallery</a>
  <a href="index.php#sports" data-i18n="nav.sports">Sports</a>
  <a href="index.php#contact" data-i18n="nav.contact">Contact</a>
</div>

<!-- PAGE HEADER -->
<header class="page-header">
  <div class="container">
    <span class="eyebrow" data-i18n="gallery.eyebrow">Inside KnK</span>
    <h1 class="display-lg" data-i18n="gallery.pageTitle">The <em>Gallery.</em></h1>
    <p data-i18n="gallery.pageSub">A walk through KnK Inn — the rooms, the rooftop, the bar, the street.</p>
  </div>
</header>

<section class="section" id="gallery" style="padding-top:2rem;">
  <div class="container">
    <div class="gallery-head">
      <span class="gallery-count"><span id="gallery-count"><?= (int)$total_photos ?></span> <span data-i18n="gallery.photosLabel">photos</span></span>
    </div>

    <div class="gallery-filter filter-bar">
      <button class="gallery-chip chip active" data-filter="all" data-i18n="gallery.filter.all">All</button>
      <?php if ($has_live): ?>
        <button class="gallery-chip chip" data-filter="new" data-i18n="gallery.filter.latest">Latest</button>
      <?php endif; ?>
      <?php foreach ($tag_whitelist as $tag):
        $slug = gal_slug($tag);
        $i18n = $chip_i18n[$slug] ?? '';
      ?>
        <button class="gallery-chip chip" data-filter="<?= htmlspecialchars($slug) ?>"<?php if ($i18n): ?> data-i18n="<?= htmlspecialchars($i18n) ?>"<?php endif; ?>><?= htmlspecialchars($tag) ?></button>
      <?php endforeach; ?>
    </div>

    <div class="masonry" id="masonry">
      <?php foreach ($lib_photos as $p):
        $src       = 'assets/img/' . $p['filename'];
        $tag_slugs = [];
        foreach (($p['tags'] ?? []) as $t) {
            $s = gal_slug((string)$t);
            if ($s !== '') $tag_slugs[] = $s;
        }
        // gallery_live photos always carry the "new" token so the
        // Latest chip picks them up, regardless of their tags.
        if (($p['source'] ?? '') === 'gallery_live') $tag_slugs[] = 'new';
        $cat_attr = implode(' ', array_unique($tag_slugs));
      ?>
        <div class="masonry-item" data-cat="<?= htmlspecialchars($cat_attr) ?>" data-lb data-lb-src="<?= htmlspecialchars($src) ?>"><img src="<?= htmlspecialchars($src) ?>" alt="" loading="lazy"></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- FOOTER -->
<footer>
  <div class="footer-inner">
    <div class="footer-brand">
      <h3>KnK Inn</h3>
      <p data-i18n="footer.tagline">Aussie pub style · Saigon heart.<br>96 Đề Thám, Cầu Ông Lãnh, Hồ Chí Minh 70000, Vietnam.</p>
    </div>
    <div class="footer-col">
      <h4 data-i18n="footer.explore">Explore</h4>
      <a href="index.php" data-i18n="nav.home">Home</a>
      <a href="rooms.php" data-i18n="nav.rooms">Rooms</a>
      <a href="drinks.php" data-i18n="nav.drinks">Drinks</a>
      <a href="gallery.php" data-i18n="nav.gallery">Gallery</a>
    </div>
    <div class="footer-col">
      <h4 data-i18n="footer.connect">Connect</h4>
      <a href="tel:+84903933850">0903 933 850</a>
      <a href="https://maps.app.goo.gl/536hWM3Kgq6KUPfg9" target="_blank" rel="noopener">Google Maps</a>
    </div>
  </div>
  <div class="footer-bottom">
    <span>© <span data-year>2026</span> KnK Inn. <span data-i18n="footer.rights">All rights reserved.</span></span>
    <span data-i18n="footer.madeWith">Built with coffee in Saigon.</span>
  </div>
</footer>

<!-- LIGHTBOX -->
<div id="lightbox">
  <button class="lb-close" aria-label="Close">×</button>
  <button class="lb-nav" id="lbPrev" aria-label="Previous">‹</button>
  <div class="lb-img-wrap"><img src="" alt=""></div>
  <button class="lb-nav" id="lbNext" aria-label="Next">›</button>
  <div class="lb-counter"></div>
</div>

<script src="assets/js/i18n.js?v=13"></script>
<script src="assets/js/main.js?v=13"></script>
<script>
  // Update visible count on filter
  (function() {
    const count = document.getElementById('gallery-count');
    const items = document.querySelectorAll('.masonry-item');
    document.querySelectorAll('.gallery-chip').forEach(chip => {
      chip.addEventListener('click', () => {
        setTimeout(() => {
          const visible = Array.from(items).filter(i => getComputedStyle(i).display !== 'none').length;
          if (count) count.textContent = visible;
        }, 20);
      });
    });
  })();
</script>
</body>
</html>

<?php
/*
 * Rooms page — driven by photo_slots so Simmo can swap any image
 * from the Photo Manager without touching this file.
 *
 *   rooms_types  #1..#3 — the three big room-type cards
 *   rooms_common #1..#6 — the six common-space tiles
 *
 * Falls back to the original hardcoded filenames if the slot rows
 * aren't in the DB yet (e.g. before migration 013 has been run).
 */
require_once __DIR__ . '/includes/photo_slots_store.php';
require_once __DIR__ . '/includes/room_rates_store.php';
$slots = knk_slots_load();

/* Live "From XXX VND / night" copy from the rate engine. Falls back
 * to the previous hardcoded copy if the rooms registry isn't seeded. */
$rate_nowindow = knk_room_type_lowest_default('standard-nowindow'); if ($rate_nowindow <= 0) $rate_nowindow = 600000;
$rate_balcony  = knk_room_type_lowest_default('standard-balcony');  if ($rate_balcony  <= 0) $rate_balcony  = 700000;
$rate_vip      = knk_room_type_lowest_default('vip');               if ($rate_vip      <= 0) $rate_vip      = 900000;
$fmt_vnd = function (int $vnd): string { return number_format($vnd, 0, '.', ',') . ' ₫'; };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Rooms — KnK Inn</title>
<meta name="description" content="Boutique rooms at KnK Inn — quiet, clean, comfortable accommodation in the heart of District 1, Ho Chi Minh City.">
<?php
  /* Use the first room-types tile as the OG/Twitter image — that's
   * the "Standard No Window" hero on the rooms grid. */
  $og_img = "https://knkinn.com/" . htmlspecialchars(knk_photo_src($slots, 'rooms_types', 1, 'rm_00.jpg'), ENT_QUOTES, "UTF-8");
?>
<meta property="og:title"        content="Rooms — KnK Inn, Saigon">
<meta property="og:description"  content="Boutique rooms in District 1: standard, balcony, and VIP with private bathtub. Aussie pub-style hotel + bar.">
<meta property="og:image"        content="<?= $og_img ?>">
<meta property="og:url"          content="https://knkinn.com/rooms.php">
<meta property="og:type"         content="website">
<meta property="og:site_name"    content="KnK Inn">
<meta property="og:locale"       content="en_US">
<meta name="twitter:card"        content="summary_large_image">
<meta name="twitter:title"       content="Rooms — KnK Inn, Saigon">
<meta name="twitter:description" content="Boutique rooms in District 1 — standard, balcony, or VIP with private bathtub.">
<meta name="twitter:image"       content="<?= $og_img ?>">
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
      <li><a href="rooms.php" class="active" data-i18n="nav.rooms">Rooms</a></li>
      <li><a href="drinks.php" data-i18n="nav.drinks">Drinks</a></li>
      <li><a href="gallery.php" data-i18n="nav.gallery">Gallery</a></li>
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
    <span class="eyebrow" data-i18n="rooms.eyebrow">Accommodation</span>
    <h1 class="display-lg" data-i18n="rooms.pageTitle">Our <em>Rooms.</em></h1>
    <p data-i18n="rooms.pageSub">Three room types across four floors. Quiet, cool, comfortable — the basics done properly.</p>
  </div>
</header>

<section class="section" style="padding-top:2rem;">
  <div class="container">
    <p class="rooms-intro" data-i18n="rooms.intro">Every KnK Inn room has blackout curtains, strong AC, a rain shower, in-room safe, fast Wi-Fi and a proper mattress. Choose between Standard rooms (with balcony) and VIP rooms (with private bathtub). Ask about long-stay rates.</p>

    <div class="rooms-features">
      <div class="room-feat reveal">
        <span class="room-feat-icon">❄️</span>
        <div>
          <h4 data-i18n="rooms.feat1.title">Strong AC</h4>
          <p data-i18n="rooms.feat1.desc">Silent, split-system units in every room.</p>
        </div>
      </div>
      <div class="room-feat reveal reveal-delay-1">
        <span class="room-feat-icon">🛁</span>
        <div>
          <h4 data-i18n="rooms.feat2.title">Rain Showers</h4>
          <p data-i18n="rooms.feat2.desc">Hot water, excellent pressure, Vietnamese-made soaps.</p>
        </div>
      </div>
      <div class="room-feat reveal reveal-delay-2">
        <span class="room-feat-icon">📶</span>
        <div>
          <h4 data-i18n="rooms.feat3.title">Fast Wi-Fi</h4>
          <p data-i18n="rooms.feat3.desc">Fibre across the whole building, including the rooftop.</p>
        </div>
      </div>
      <div class="room-feat reveal reveal-delay-3">
        <span class="room-feat-icon">🔇</span>
        <div>
          <h4 data-i18n="rooms.feat4.title">Quiet</h4>
          <p data-i18n="rooms.feat4.desc">Double glazing on the street side — you'd never know De Tham was below.</p>
        </div>
      </div>
    </div>

    <!-- Three room types. Each links to a per-type page with photos, calendar & booking flow. -->
    <div class="rooms-grid rooms-grid-booking" style="margin-top:4rem;">

      <a class="room-card room-card-link" href="rooms/standard-nowindow.php">
        <img src="<?= htmlspecialchars(knk_photo_src($slots, 'rooms_types', 1, 'rm_00.jpg')) ?>" alt="<?= htmlspecialchars(knk_photo_alt($slots, 'rooms_types', 1, 'Standard room, no window')) ?>">
        <div class="room-card-overlay">
          <span class="room-card-floor">From <?= $fmt_vnd($rate_nowindow) ?> / night</span>
          <span class="room-card-name">Standard · No Window</span>
          <span class="room-card-cta">View &amp; Book →</span>
        </div>
      </a>

      <a class="room-card room-card-link" href="rooms/standard-balcony.php">
        <img src="<?= htmlspecialchars(knk_photo_src($slots, 'rooms_types', 2, 'rm_02.jpg')) ?>" alt="<?= htmlspecialchars(knk_photo_alt($slots, 'rooms_types', 2, 'Standard room with balcony')) ?>">
        <div class="room-card-overlay">
          <span class="room-card-floor">From <?= $fmt_vnd($rate_balcony) ?> / night</span>
          <span class="room-card-name">Standard · Balcony</span>
          <span class="room-card-cta">View &amp; Book →</span>
        </div>
      </a>

      <a class="room-card room-card-link" href="rooms/vip.php">
        <img src="<?= htmlspecialchars(knk_photo_src($slots, 'rooms_types', 3, 'rm_04.jpg')) ?>" alt="<?= htmlspecialchars(knk_photo_alt($slots, 'rooms_types', 3, 'VIP room with private bathtub')) ?>">
        <div class="room-card-overlay">
          <span class="room-card-floor">From <?= $fmt_vnd($rate_vip) ?> / night</span>
          <span class="room-card-name">VIP · Private Bathtub</span>
          <span class="room-card-cta">View &amp; Book →</span>
        </div>
      </a>
    </div>

    <!-- COMMON SPACES -->
    <div class="section-head" style="text-align:center;margin-top:5rem;margin-bottom:2rem;">
      <span class="eyebrow" data-i18n="rooms.commonEyebrow">Inside KnK Inn</span>
      <h2 class="display-lg" data-i18n="rooms.commonTitle">Common <em>spaces.</em></h2>
      <p data-i18n="rooms.commonSub">The spots you'll pass through — from street level up to the Sky Garden on the roof.</p>
    </div>
    <div class="rooms-grid">
      <?php
      // Common-space tiles. Defaults mirror the original hardcoded filenames.
      // Tiles 2 (Ground Bar), 4 (5th Floor Bar) and 6 (Rooftop) open a
      // multi-photo lightbox from /assets/img/knk-260428/<slug>/. The
      // others use the existing single-image lightbox via data-lb.
      require_once __DIR__ . "/includes/photo_galleries.php";
      $common = [
          1 => ['default' => 'ex_01.jpg', 'name' => 'Street',        'alt' => 'Street',        'gallery' => null],
          2 => ['default' => 'ex_10.jpg', 'name' => 'Ground Bar',    'alt' => 'Ground Bar',    'gallery' => 'sport-pub'],
          3 => ['default' => 'nw_57.jpg', 'name' => 'Elevator',      'alt' => 'Elevator',      'gallery' => null],
          4 => ['default' => 'nw_11.jpg', 'name' => '5th Floor Bar', 'alt' => '5th Floor Bar', 'gallery' => 'wine-bar-floor-5'],
          5 => ['default' => 'rm_15.jpg', 'name' => 'Darts Room',    'alt' => 'Darts Room',    'gallery' => null],
          6 => ['default' => 'ex_08.jpg', 'name' => 'Rooftop',       'alt' => 'Rooftop',       'gallery' => 'rooftop'],
      ];
      foreach ($common as $idx => $c):
          $src = knk_photo_src($slots, 'rooms_common', $idx, $c['default']);
          $alt = knk_photo_alt($slots, 'rooms_common', $idx, $c['alt']);
          $gallery_photos = $c['gallery'] ? knk_gallery_photos($c['gallery']) : [];
      ?>
        <?php if (!empty($gallery_photos)): ?>
          <button type="button" class="room-card"
                  data-knk-gallery="<?= htmlspecialchars(json_encode($gallery_photos), ENT_QUOTES, "UTF-8") ?>"
                  style="padding:0;border:0;background:transparent;cursor:zoom-in;display:block;">
            <img src="<?= htmlspecialchars($src) ?>" alt="<?= htmlspecialchars($alt) ?>">
            <div class="room-card-overlay"><span class="room-card-name"><?= htmlspecialchars($c['name']) ?></span></div>
          </button>
        <?php else: ?>
          <div class="room-card" data-lb data-lb-src="<?= htmlspecialchars($src) ?>">
            <img src="<?= htmlspecialchars($src) ?>" alt="<?= htmlspecialchars($alt) ?>">
            <div class="room-card-overlay"><span class="room-card-name"><?= htmlspecialchars($c['name']) ?></span></div>
          </div>
        <?php endif; ?>
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
<script src="assets/js/main.js?v=15"></script>
<?php
  // Multi-photo lightbox markup for the gallery tiles (Ground Bar
  // → sport-pub, 5th Floor Bar → wine-bar-floor-5, Rooftop →
  // rooftop). Single-image tiles still use the existing #lightbox
  // above. Safe to call once per page.
  if (function_exists('knk_render_lightbox_markup')) {
      knk_render_lightbox_markup();
  }
?>
</body>
</html>

<?php
/*
 * VIP — Private Bathtub room subpage.
 *
 * Hero banner + in-room gallery are now driven by photo_slots
 * (section "room_vip") so Simmo can swap any of them from the
 * Photo Manager without touching this file. Defaults match the
 * filenames previously hardcoded in vip.html.
 *
 * Slot 1 = hero banner.  Slots 2..6 = gallery tiles.
 */
require_once __DIR__ . '/../includes/photo_slots_store.php';
require_once __DIR__ . '/../includes/room_rates_store.php';
$slots = knk_slots_load();
$live_price_vnd = knk_room_type_lowest_default('vip');
if ($live_price_vnd <= 0) $live_price_vnd = 900000;

$rp = function (string $section, int $idx, string $default) use ($slots): string {
    return '../' . knk_photo_src($slots, $section, $idx, $default);
};
$ra = function (string $section, int $idx, string $default) use ($slots): string {
    return knk_photo_alt($slots, $section, $idx, $default);
};

// Gallery defaults — must match knk_slot_defaults() room_vip#2..#6
$gallery_defaults = [
    2 => 'rm_04.jpg',
    3 => 'rm_11.jpg',
    4 => 'rm_16.jpg',
    5 => 'rm_23.jpg',
    6 => 'nw_39.jpg',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>VIP — Private Bathtub — KnK Inn</title>
<meta name="description" content="Book the VIP — Private Bathtub at KnK Inn — private bathtub, strong AC, rain shower, fast Wi-Fi. 96 De Tham, District 1, Saigon.">
<?php $og_img = "https://knkinn.com/" . ltrim($rp('room_vip', 1, 'rm_04.jpg'), './'); ?>
<meta property="og:title"        content="VIP with Private Bathtub — KnK Inn, Saigon">
<meta property="og:description"  content="VIP room at KnK Inn with private bathtub. Strong AC, rain shower, fast Wi-Fi. District 1.">
<meta property="og:image"        content="<?= htmlspecialchars($og_img, ENT_QUOTES, "UTF-8") ?>">
<meta property="og:url"          content="https://knkinn.com/rooms/vip.php">
<meta property="og:type"         content="website">
<meta property="og:site_name"    content="KnK Inn">
<meta property="og:locale"       content="en_US">
<meta name="twitter:card"        content="summary_large_image">
<meta name="twitter:title"       content="VIP with Private Bathtub — KnK Inn">
<meta name="twitter:description" content="Private bathtub · strong AC · rain shower · fast Wi-Fi. District 1, Saigon.">
<meta name="twitter:image"       content="<?= htmlspecialchars($og_img, ENT_QUOTES, "UTF-8") ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@400;500;600;700&family=Caveat:wght@700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/styles.css?v=13">
</head>
<body>

<!-- NAV -->
<nav id="nav">
  <div class="nav-inner">
    <a href="../index.php" class="nav-logo">KnK Inn</a>
    <ul class="nav-links">
      <li><a href="../index.php" data-i18n="nav.home">Home</a></li>
      <li><a href="../rooms.php" class="active" data-i18n="nav.rooms">Rooms</a></li>
      <li><a href="../drinks.php" data-i18n="nav.drinks">Drinks</a></li>
      <li><a href="../gallery.php" data-i18n="nav.gallery">Gallery</a></li>
      <li><a href="../index.php#sports" data-i18n="nav.sports">Sports</a></li>
      <li><a href="../index.php#contact" data-i18n="nav.contact">Contact</a></li>
    </ul>
    <div class="nav-right">
      <div data-lang-switch></div>
      <a href="#booking-widget" class="nav-cta">Book this room</a>
    </div>
    <button class="hamburger" aria-label="Menu" aria-expanded="false">
      <span></span><span></span><span></span>
    </button>
  </div>
</nav>

<div class="mobile-menu">
  <a href="../index.php" data-i18n="nav.home">Home</a>
  <a href="../rooms.php" data-i18n="nav.rooms">Rooms</a>
  <a href="../drinks.php" data-i18n="nav.drinks">Drinks</a>
  <a href="../gallery.php" data-i18n="nav.gallery">Gallery</a>
  <a href="../index.php#sports" data-i18n="nav.sports">Sports</a>
  <a href="../index.php#contact" data-i18n="nav.contact">Contact</a>
</div>

<!-- HERO IMAGE BANNER -->
<header class="room-hero-img" style="--room-hero-img: url('<?= htmlspecialchars($rp('room_vip', 1, 'rm_04.jpg')) ?>');"></header>

<!-- TOP: description LEFT · booking RIGHT (stacks on narrow) -->
<section class="section" style="padding-top:3rem;padding-bottom:2rem;">
  <div class="container">
    <div class="room-top-grid">

      <div class="room-top-desc">
        <span class="eyebrow">Accommodation · Floors 2, 3 and 4</span>
        <h1 class="display-lg">VIP · Private <em>bathtub.</em></h1>
        <p class="room-hero-lede">Our flagship rooms — a deep private bathtub, a little more elbow room than the Standards, and premium toiletries for a proper treat. Available on floors 2, 3 and 4; the floor 4 VIP is closest to the Sky Garden and is our most popular. Let us know your preferred floor at booking.</p>
        <div class="room-hero-meta">
          <span><strong>1 Queen bed</strong></span>
          <span><strong>Up to 2 guests</strong></span>
          <span><strong>~20–22 m²</strong></span>
          <span><strong>From 900,000 ₫ / night</strong></span>
        </div>
      </div>

      <div class="room-top-book">
        <div class="room-top-book-head">
          <span class="eyebrow">Book direct</span>
          <h2 class="display-md">Pick your <em>dates.</em></h2>
          <p class="room-top-book-sub">
            Crossed-out days are already booked. Simmo confirms within 24 hours by email — no payment taken online.
          </p>
        </div>

        <div id="booking-widget"
             data-room-id="vip"
             data-room-name="VIP — Private Bathtub"
             data-room-type="vip"
             data-price="<?= (int)$live_price_vnd ?>">
        </div>
      </div>

    </div>
  </div>
</section>

<!-- GALLERY + FEATURES -->
<section class="section" style="padding-top:2rem;">
  <div class="container">

    <div class="section-head" style="text-align:left;">
      <span class="eyebrow">Inside the room</span>
      <h2 class="display-md">A proper <em>retreat</em> from the street.</h2>
    </div>

    <div class="room-gallery">
<?php
    /* New photo set — Rooms 3, 5, 7 (the F2/F3/F4 VIP-with-tub
     * units). Each tile opens its own room-specific lightbox.
     * Falls back to the existing slot-driven thumbnails. */
    require_once __DIR__ . "/../includes/photo_galleries.php";
    $room_groups = [
        ['slug' => 'room-3', 'label' => 'Room 3 — Floor 2'],
        ['slug' => 'room-5', 'label' => 'Room 5 — Floor 3'],
        ['slug' => 'room-7', 'label' => 'Room 7 — Floor 4'],
    ];
    $rendered_new = false;
    foreach ($room_groups as $rg) {
        $photos = knk_gallery_photos($rg['slug']);
        if (empty($photos)) continue;
        $rendered_new = true;
        // Site-absolute paths ("/assets/img/...") work as-is.
        $thumb = $photos[0];
        ?>
        <button type="button" class="room-card"
                data-knk-gallery="<?= htmlspecialchars(json_encode($photos), ENT_QUOTES, "UTF-8") ?>"
                style="padding:0;border:0;background:transparent;display:block;cursor:zoom-in;position:relative;">
          <img src="<?= htmlspecialchars($thumb) ?>" alt="<?= htmlspecialchars($rg['label']) ?>" loading="lazy">
          <span style="position:absolute;left:0;right:0;bottom:0;padding:0.5rem 0.7rem;
                       background:linear-gradient(0deg, rgba(0,0,0,0.65), rgba(0,0,0,0));
                       color:#f5e9d1;font-weight:700;font-size:0.85rem;text-align:left;">
            <?= htmlspecialchars($rg['label']) ?>
            <span style="opacity:0.7;font-weight:400;"> · <?= count($photos) ?> photos</span>
          </span>
        </button>
        <?php
    }
    if (!$rendered_new) {
        foreach ($gallery_defaults as $idx => $default):
            $src = $rp('room_vip', $idx, $default);
            $alt = $ra('room_vip', $idx, 'VIP — Private Bathtub photo ' . ($idx - 1));
?>
      <div class="room-card" data-lb data-lb-src="<?= htmlspecialchars($src) ?>">
        <img src="<?= htmlspecialchars($src) ?>" alt="<?= htmlspecialchars($alt) ?>" loading="lazy">
      </div>
<?php   endforeach;
    }
?>
    </div>

    <div class="section-head" style="text-align:left;margin-top:4rem;">
      <span class="eyebrow">What's included</span>
      <h2 class="display-md">The <em>basics</em>, done properly.</h2>
    </div>

    <div class="room-features">
            <div class="room-features-item">
        <span class="ico">🛁</span>
        <div>
          <h5>Private bathtub</h5>
          <p>Deep soak tub plus a separate rain shower — lava-grade hot water.</p>
        </div>
      </div>
      <div class="room-features-item">
        <span class="ico">🧴</span>
        <div>
          <h5>Premium toiletries</h5>
          <p>Vietnamese-made shampoo, conditioner, body wash — smells like holiday.</p>
        </div>
      </div>
      <div class="room-features-item">
        <span class="ico">🧖</span>
        <div>
          <h5>Bathrobes & slippers</h5>
          <p>Because you've earned it.</p>
        </div>
      </div>
      <div class="room-features-item">
        <span class="ico">🌿</span>
        <div>
          <h5>Rooftop-adjacent</h5>
          <p>Floor 4 VIP is one flight below the Sky Garden — nightcap, then bed.</p>
        </div>
      </div>
      <div class="room-features-item">
        <span class="ico">❄️</span>
        <div>
          <h5>Strong split AC</h5>
          <p>Silent overnight, cools fast after a sweltering day outside.</p>
        </div>
      </div>
      <div class="room-features-item">
        <span class="ico">📶</span>
        <div>
          <h5>Fibre Wi-Fi</h5>
          <p>Strong all the way up the building, rooftop included.</p>
        </div>
      </div>
    </div>

    <p style="margin-top:3rem;text-align:center;">
      <a href="../rooms.php" class="btn-ghost">← Back to all rooms</a>
    </p>

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
      <a href="../index.php" data-i18n="nav.home">Home</a>
      <a href="../rooms.php" data-i18n="nav.rooms">Rooms</a>
      <a href="../drinks.php" data-i18n="nav.drinks">Drinks</a>
      <a href="../gallery.php" data-i18n="nav.gallery">Gallery</a>
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

<script src="../assets/js/i18n.js?v=13"></script>
<script src="../assets/js/main.js?v=13"></script>
<script src="../assets/js/booking.js"></script>
<?php if (function_exists('knk_render_lightbox_markup')) knk_render_lightbox_markup(); ?>
</body>
</html>

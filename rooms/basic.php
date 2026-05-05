<?php
/*
 * Basic — Queen Room (Room 9, Ground floor) subpage.
 *
 * Hero banner + in-room gallery are driven by photo_slots
 * (section "room_basic") so Simmo can swap any of them from
 * the Photo Manager without touching this file. Defaults fall
 * back to nw_*.jpg placeholders shared with the no-window page
 * until Room 9 photos are uploaded to the photo library.
 *
 * Slot 1 = hero banner.  Slots 2..4 = gallery tiles.
 */
require_once __DIR__ . '/../includes/photo_slots_store.php';
require_once __DIR__ . '/../includes/room_rates_store.php';
$slots = knk_slots_load();
$live_price_vnd = knk_room_type_lowest_default('basic');
if ($live_price_vnd <= 0) $live_price_vnd = 800000;

// We're one folder deep, so all asset URLs need a "../" prefix.
$rp = function (string $section, int $idx, string $default) use ($slots): string {
    return '../' . knk_photo_src($slots, $section, $idx, $default);
};
$ra = function (string $section, int $idx, string $default) use ($slots): string {
    return knk_photo_alt($slots, $section, $idx, $default);
};

// Gallery defaults — placeholders until Room 9 photos uploaded.
$gallery_defaults = [
    2 => 'rm_00.jpg',
    3 => 'nw_01.jpg',
    4 => 'nw_41.jpg',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Basic — KnK Inn</title>
<meta name="description" content="Book a Basic room at KnK Inn — ground-floor Queen bed, glass shower, overhead skylight, strong AC, fast Wi-Fi. 96 De Tham, District 1, Saigon.">
<?php $og_img = "https://knkinn.com/" . ltrim($rp('room_basic', 1, 'rm_00.jpg'), './'); ?>
<meta property="og:title"        content="Basic — KnK Inn, Saigon">
<meta property="og:description"  content="Ground-floor Queen room with skylight, strong AC, glass shower, fast Wi-Fi. District 1.">
<meta property="og:image"        content="<?= htmlspecialchars($og_img, ENT_QUOTES, "UTF-8") ?>">
<meta property="og:url"          content="https://knkinn.com/rooms/basic.php">
<meta property="og:type"         content="website">
<meta property="og:site_name"    content="KnK Inn">
<meta property="og:locale"       content="en_US">
<meta name="twitter:card"        content="summary_large_image">
<meta name="twitter:title"       content="Basic — KnK Inn">
<meta name="twitter:description" content="Ground-floor Queen room — skylight, strong AC, glass shower, fast Wi-Fi. District 1, Saigon.">
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
<header class="room-hero-img" style="--room-hero-img: url('<?= htmlspecialchars($rp('room_basic', 1, 'rm_00.jpg')) ?>');"></header>

<!-- TOP: description LEFT · booking RIGHT (stacks on narrow) -->
<section class="section" style="padding-top:3rem;padding-bottom:2rem;">
  <div class="container">
    <div class="room-top-grid">

      <div class="room-top-desc">
        <span class="eyebrow">Accommodation · Ground floor</span>
        <h1 class="display-lg"><em>Basic.</em></h1>
        <p class="room-hero-lede">Our smallest, most affordable room — ground floor, Queen bed, glass shower. An overhead skylight in place of a street-facing window keeps the room blackout-quiet for sleep-ins, with morning light filtering through when you want it.</p>
        <div class="room-hero-meta">
          <span><strong>1 Queen bed</strong></span>
          <span><strong>Up to 2 guests</strong></span>
          <span><strong>~27 m²</strong></span>
          <span><strong>From 800,000 ₫ / night</strong></span>
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
             data-room-id="basic"
             data-room-name="Basic"
             data-room-type="basic"
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
    /* Photo gallery — falls back to slot-driven thumbnails until
     * the room-9 gallery folder is populated under the photo
     * library. Same pattern as the no-window page. */
    require_once __DIR__ . "/../includes/photo_galleries.php";
    $room9_photos = knk_gallery_photos('room-9');
    if (!empty($room9_photos)) {
        $abs_json = htmlspecialchars(json_encode($room9_photos), ENT_QUOTES, "UTF-8");
        foreach ($room9_photos as $i => $src) {
?>
      <button type="button" class="room-card"
              data-knk-gallery="<?= $abs_json ?>"
              style="padding:0;border:0;background:transparent;display:block;cursor:zoom-in;">
        <img src="<?= htmlspecialchars($src) ?>" alt="Room 9 photo <?= $i + 1 ?>" loading="lazy">
      </button>
<?php
        }
    } else {
        foreach ($gallery_defaults as $idx => $default):
            $src = $rp('room_basic', $idx, $default);
            $alt = $ra('room_basic', $idx, 'Basic photo ' . ($idx - 1));
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
        <span class="ico">☀️</span>
        <div>
          <h5>Overhead skylight</h5>
          <p>Soft natural light from above without the street noise that comes with a window.</p>
        </div>
      </div>
      <div class="room-features-item">
        <span class="ico">❄️</span>
        <div>
          <h5>Strong split AC</h5>
          <p>Silent overnight, cools fast in Saigon's shoulder seasons.</p>
        </div>
      </div>
      <div class="room-features-item">
        <span class="ico">🚿</span>
        <div>
          <h5>Glass shower</h5>
          <p>Hot water, Vietnamese-made soaps, plenty of pressure.</p>
        </div>
      </div>
      <div class="room-features-item">
        <span class="ico">🔐</span>
        <div>
          <h5>In-room safe</h5>
          <p>Locks your passport, laptop and cash while you're out.</p>
        </div>
      </div>
      <div class="room-features-item">
        <span class="ico">📶</span>
        <div>
          <h5>Fibre Wi-Fi</h5>
          <p>Fast enough for video calls and game streams.</p>
        </div>
      </div>
      <div class="room-features-item">
        <span class="ico">👕</span>
        <div>
          <h5>Daily housekeeping</h5>
          <p>Fresh linens on long stays, plus laundry on request.</p>
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

<?php
/*
 * Standard — Balcony room subpage.
 *
 * Hero banner + in-room gallery are now driven by photo_slots
 * (section "room_balcony") so Simmo can swap any of them from
 * the Photo Manager without touching this file. Defaults match
 * the filenames previously hardcoded in standard-balcony.html.
 *
 * Slot 1 = hero banner.  Slots 2..11 = gallery tiles.
 */
require_once __DIR__ . '/../includes/photo_slots_store.php';
require_once __DIR__ . '/../includes/room_rates_store.php';
$slots = knk_slots_load();
/* Live nightly rate from the rate engine (migration 026). Falls
 * back to the previous hardcoded number if the rooms table hasn't
 * been seeded yet — defensive against deploy-before-migrate. */
$live_price_vnd = knk_room_type_lowest_default('standard-balcony');
if ($live_price_vnd <= 0) $live_price_vnd = 1100000;

$rp = function (string $section, int $idx, string $default) use ($slots): string {
    return '../' . knk_photo_src($slots, $section, $idx, $default);
};
$ra = function (string $section, int $idx, string $default) use ($slots): string {
    return knk_photo_alt($slots, $section, $idx, $default);
};

// Gallery defaults — must match knk_slot_defaults() room_balcony#2..#11
$gallery_defaults = [
    2  => 'rm_02.jpg',
    3  => 'rm_00.jpg',
    4  => 'rm_09.jpg',
    5  => 'rm_14.jpg',
    6  => 'rm_18.jpg',
    7  => 'rm_03.jpg',
    8  => 'rm_13.jpg',
    9  => 'rm_17.jpg',
    10 => 'rm_20.jpg',
    11 => 'rm_24.jpg',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Superior — KnK Inn</title>
<meta name="description" content="Book a Superior room at KnK Inn — private balcony, strong AC, rain shower, fast Wi-Fi. 96 De Tham, District 1, Saigon.">
<?php
  /* OG / Twitter cards — driven by the same hero photo the page uses,
   * so what shows up on Facebook / WhatsApp / Tripadvisor previews
   * matches what guests see when they tap through. */
  $og_img = "https://knkinn.com/" . ltrim($rp('room_balcony', 1, 'rm_02.jpg'), './');
?>
<meta property="og:title"        content="Superior — KnK Inn, Saigon">
<meta property="og:description"  content="Superior room with private balcony at KnK Inn — quiet, clean, fast Wi-Fi. Heart of District 1's backpacker scene.">
<meta property="og:image"        content="<?= htmlspecialchars($og_img, ENT_QUOTES, "UTF-8") ?>">
<meta property="og:url"          content="https://knkinn.com/rooms/standard-balcony.php">
<meta property="og:type"         content="website">
<meta property="og:site_name"    content="KnK Inn">
<meta property="og:locale"       content="en_US">
<meta name="twitter:card"        content="summary_large_image">
<meta name="twitter:title"       content="Superior — KnK Inn">
<meta name="twitter:description" content="Superior with balcony · strong AC · rain shower · fast Wi-Fi. District 1, Saigon.">
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
<header class="room-hero-img" style="--room-hero-img: url('<?= htmlspecialchars($rp('room_balcony', 1, 'rm_02.jpg')) ?>');"></header>

<!-- TOP: description LEFT · booking RIGHT (stacks on narrow) -->
<section class="section" style="padding-top:3rem;padding-bottom:2rem;">
  <div class="container">
    <div class="room-top-grid">

      <div class="room-top-desc">
        <span class="eyebrow">Accommodation · Floors 2, 3 and 4</span>
        <h1 class="display-lg"><em>Superior.</em></h1>
        <p class="room-hero-lede">A bright Queen room with a private balcony over De Tham — step out for a morning coffee or an evening beer while the street wakes up below. Available on floors 2, 3 and 4; the higher the floor, the quieter the night and the better the view. Let us know your preferred floor at booking.</p>
        <div class="room-hero-meta">
          <span><strong>1 Queen bed</strong></span>
          <span><strong>Up to 2 guests</strong></span>
          <span><strong>~25 m²</strong></span>
          <span><strong>From 1,100,000 ₫ / night</strong></span>
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
             data-room-id="standard-balcony"
             data-room-name="Superior"
             data-room-type="standard"
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
    /* New photo set — Rooms 4, 6, 8 (the F2/F3/F4 standard-balcony
     * units). Each one's tile opens its own room-specific lightbox.
     * Falls back to the existing slot-driven thumbnails if the new
     * folders are missing. */
    require_once __DIR__ . "/../includes/photo_galleries.php";
    $room_groups = [
        ['slug' => 'room-4', 'label' => 'Room 4 — Floor 2'],
        ['slug' => 'room-6', 'label' => 'Room 6 — Floor 3'],
        ['slug' => 'room-8', 'label' => 'Room 8 — Floor 4'],
    ];
    $rendered_new = false;
    foreach ($room_groups as $rg) {
        $photos = knk_gallery_photos($rg['slug']);
        if (empty($photos)) continue;
        $rendered_new = true;
        // Photo paths are already site-absolute ("/assets/img/...")
        // so they work from /rooms/<slug>.php without any "../" dance.
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
    /* Fallback to the original slot-driven gallery if no new photos
     * are present — keeps old deploys working before the photo
     * folders ship. */
    if (!$rendered_new) {
        foreach ($gallery_defaults as $idx => $default):
            $src = $rp('room_balcony', $idx, $default);
            $alt = $ra('room_balcony', $idx, 'Standard — Balcony photo ' . ($idx - 1));
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
        <span class="ico">🌇</span>
        <div>
          <h5>Private balcony</h5>
          <p>Your own slice of District 1 — potted plants, low table, morning sun.</p>
        </div>
      </div>
      <div class="room-features-item">
        <span class="ico">📈</span>
        <div>
          <h5>Choose your floor</h5>
          <p>Floor 2 is closest to the bar; floor 4 is closest to the rooftop. Pick your altitude.</p>
        </div>
      </div>
      <div class="room-features-item">
        <span class="ico">❄️</span>
        <div>
          <h5>Strong split AC</h5>
          <p>Cools fast when you come back from the midday heat, silent overnight.</p>
        </div>
      </div>
      <div class="room-features-item">
        <span class="ico">🚿</span>
        <div>
          <h5>Rain shower</h5>
          <p>Hot water, good pressure, full-size toiletries.</p>
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
          <p>Fast enough for video calls and streams — strong across all floors.</p>
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

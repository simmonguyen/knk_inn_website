<?php
/*
 * Standard — No Window room subpage.
 *
 * Hero banner + in-room gallery are now driven by photo_slots
 * (section "room_nowindow") so Simmo can swap any of them from
 * the Photo Manager without touching this file. Defaults match
 * the filenames previously hardcoded in standard-nowindow.html.
 *
 * Slot 1 = hero banner.  Slots 2..4 = gallery tiles.
 */
require_once __DIR__ . '/../includes/photo_slots_store.php';
$slots = knk_slots_load();

// We're one folder deep, so all asset URLs need a "../" prefix.
$rp = function (string $section, int $idx, string $default) use ($slots): string {
    return '../' . knk_photo_src($slots, $section, $idx, $default);
};
$ra = function (string $section, int $idx, string $default) use ($slots): string {
    return knk_photo_alt($slots, $section, $idx, $default);
};

// Gallery defaults — must match knk_slot_defaults() room_nowindow#2..#4
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
<title>Standard — No Window — KnK Inn</title>
<meta name="description" content="Book the Standard — No Window at KnK Inn — no window, strong AC, rain shower, fast Wi-Fi. 96 De Tham, District 1, Saigon.">
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
<header class="room-hero-img" style="--room-hero-img: url('<?= htmlspecialchars($rp('room_nowindow', 1, 'rm_00.jpg')) ?>');"></header>

<!-- TOP: description LEFT · booking RIGHT (stacks on narrow) -->
<section class="section" style="padding-top:3rem;padding-bottom:2rem;">
  <div class="container">
    <div class="room-top-grid">

      <div class="room-top-desc">
        <span class="eyebrow">Accommodation · Ground floor</span>
        <h1 class="display-lg">Standard · <em>No window.</em></h1>
        <p class="room-hero-lede">The ground-floor Standard — steps from reception and a solid choice when you want a cool, dark, quiet room to crash in. No window, which keeps it pitch-black for night shifts and serious sleep-ins.</p>
        <div class="room-hero-meta">
          <span><strong>1 Queen bed</strong></span>
          <span><strong>Up to 2 guests</strong></span>
          <span><strong>~14 m²</strong></span>
          <span><strong>From 600,000 ₫ / night</strong></span>
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
             data-room-id="standard-nowindow"
             data-room-name="Standard — No Window"
             data-room-type="standard"
             data-price="600000">
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
<?php foreach ($gallery_defaults as $idx => $default):
    $src = $rp('room_nowindow', $idx, $default);
    $alt = $ra('room_nowindow', $idx, 'Standard — No Window photo ' . ($idx - 1));
?>
      <div class="room-card" data-lb data-lb-src="<?= htmlspecialchars($src) ?>">
        <img src="<?= htmlspecialchars($src) ?>" alt="<?= htmlspecialchars($alt) ?>" loading="lazy">
      </div>
<?php endforeach; ?>
    </div>

    <div class="section-head" style="text-align:left;margin-top:4rem;">
      <span class="eyebrow">What's included</span>
      <h2 class="display-md">The <em>basics</em>, done properly.</h2>
    </div>

    <div class="room-features">
            <div class="room-features-item">
        <span class="ico">🔇</span>
        <div>
          <h5>Pitch-black & silent</h5>
          <p>No window means zero street noise and total blackout. Best sleep on the block.</p>
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
          <h5>Rain shower</h5>
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
</body>
</html>

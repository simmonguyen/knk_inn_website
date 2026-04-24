<?php
/*
 * KnK Inn — drinks page.
 *
 * Hero photos are managed slots (edit in /photos.php).
 * The drink list itself is rendered from the `menu_drinks` table
 * (edit in /menu.php) — same source of truth as /order.php.
 */

require_once __DIR__ . "/includes/photo_slots_store.php";
require_once __DIR__ . "/includes/menu_store.php";

$slots = knk_slots_load();
$menuGroups = knk_menu_grouped(true);   // visible-only

function d_src(array $slots, string $section, int $i, string $default): string {
    return htmlspecialchars(knk_photo_src($slots, $section, $i, $default));
}

// VND display helper — falls back to "free" if zero, otherwise "120,000đ"
function d_price(int $v): string {
    return number_format($v, 0, ".", ",") . "đ";
}

// i18n-key helper for category headings: uses the seeded convention
// `drinks.cat.<slug>` so existing translations keep working for the
// categories we had at Phase 1.
function d_cat_key(string $slug): string { return "drinks.cat." . $slug; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Drinks — KnK Inn</title>
<meta name="description" content="Beer, wine, spirits and cocktails at KnK Inn — proper drinks list, fair prices, District 1 Saigon.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@400;500;600;700&family=Caveat:wght@700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/styles.css?v=12">
</head>
<body>

<!-- NAV -->
<nav id="nav">
  <div class="nav-inner">
    <a href="index.php" class="nav-logo">KnK Inn</a>
    <ul class="nav-links">
      <li><a href="index.php" data-i18n="nav.home">Home</a></li>
      <li><a href="rooms.html" data-i18n="nav.rooms">Rooms</a></li>
      <li><a href="drinks.php" class="active" data-i18n="nav.drinks">Drinks</a></li>
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
  <a href="rooms.html" data-i18n="nav.rooms">Rooms</a>
  <a href="drinks.php" data-i18n="nav.drinks">Drinks</a>
  <a href="gallery.php" data-i18n="nav.gallery">Gallery</a>
  <a href="index.php#sports" data-i18n="nav.sports">Sports</a>
  <a href="index.php#contact" data-i18n="nav.contact">Contact</a>
</div>

<!-- PAGE HEADER -->
<header class="page-header">
  <div class="container">
    <span class="eyebrow" data-i18n="drinks.eyebrow">Behind The Bar</span>
    <h1 class="display-lg" data-i18n="drinks.pageTitle">The <em>Drinks List.</em></h1>
    <p data-i18n="drinks.pageSub">Cold taps, a proper wine list, and a spirits shelf we're genuinely proud of. Prices in VND — not including VAT.</p>
  </div>
</header>

<section class="section" style="padding-top:2rem;">
  <div class="container">
    <div class="drinks-wrap">
      <div class="drinks-img-stack reveal">
        <img src="<?= d_src($slots, 'drinks', 1, 'nw_69.jpg') ?>" alt="Bar">
        <img src="<?= d_src($slots, 'drinks', 2, 'nw_56.jpg') ?>" alt="Bar detail">
      </div>
      <div class="drinks-categories reveal">

        <?php foreach ($menuGroups as $group): ?>
          <div class="drink-cat">
            <h3 class="drink-cat-title" data-i18n="<?= htmlspecialchars(d_cat_key($group["slug"])) ?>"><?= htmlspecialchars($group["title"]) ?></h3>
            <?php foreach ($group["items"] as $drink): ?>
              <div class="drink-item">
                <span class="drink-name"<?= $drink["i18n_key"] ? ' data-i18n="' . htmlspecialchars($drink["i18n_key"]) . '"' : "" ?>><?= htmlspecialchars($drink["name"]) ?></span>
                <span class="drink-price"><?= htmlspecialchars(d_price((int)$drink["price_vnd"])) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>

      </div>
    </div>

    <p class="drinks-note reveal" style="text-align:center;margin-top:4rem;color:var(--cream-faint);font-size:0.78rem;letter-spacing:0.15em;text-transform:uppercase;">
      <span data-i18n="drinks.note">Prices do not include VAT · House buckets &amp; group packages available on request</span>
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
      <a href="index.php" data-i18n="nav.home">Home</a>
      <a href="rooms.html" data-i18n="nav.rooms">Rooms</a>
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

<script src="assets/js/i18n.js?v=12"></script>
<script src="assets/js/main.js?v=12"></script>
</body>
</html>

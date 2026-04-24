<?php
/*
 * KnK Inn — drinks page.
 *
 * The two hero photos at the top are managed slots — Simmo can
 * swap them via /photos.php without touching any code.
 */

require_once __DIR__ . "/includes/photo_slots_store.php";

$slots = knk_slots_load();

function d_src(array $slots, string $section, int $i, string $default): string {
    return htmlspecialchars(knk_photo_src($slots, $section, $i, $default));
}
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

        <div class="drink-cat">
          <h3 class="drink-cat-title" data-i18n="drinks.cat.beer">Beer</h3>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.beer.saigonGreen">Saigon Green</span><span class="drink-price">45,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.beer.bialsa">Bia Saigon Special</span><span class="drink-price">55,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.beer.tiger">Tiger</span><span class="drink-price">60,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.beer.heineken">Heineken</span><span class="drink-price">65,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.beer.corona">Corona</span><span class="drink-price">95,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.beer.guinness">Guinness (can)</span><span class="drink-price">130,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.beer.pasteurTap">Pasteur Street (tap)</span><span class="drink-price">120,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.beer.houseTap">House Tap Pint</span><span class="drink-price">110,000đ</span></div>
        </div>

        <div class="drink-cat">
          <h3 class="drink-cat-title" data-i18n="drinks.cat.wine">Wine</h3>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.wine.dalatRed">Dalat Red (glass)</span><span class="drink-price">120,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.wine.dalatBottle">Dalat Red (bottle)</span><span class="drink-price">550,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.wine.shirazGlass">Australian Shiraz (glass)</span><span class="drink-price">160,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.wine.shirazBottle">Australian Shiraz (bottle)</span><span class="drink-price">790,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.wine.sauvGlass">NZ Sauvignon Blanc (glass)</span><span class="drink-price">160,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.wine.sauvBottle">NZ Sauvignon Blanc (bottle)</span><span class="drink-price">790,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.wine.proseccoGlass">Prosecco (glass)</span><span class="drink-price">140,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.wine.proseccoBottle">Prosecco (bottle)</span><span class="drink-price">680,000đ</span></div>
        </div>

        <div class="drink-cat">
          <h3 class="drink-cat-title" data-i18n="drinks.cat.bourbon">Bourbon &amp; American Whiskey</h3>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.bourbon.jimBeam">Jim Beam White</span><span class="drink-price">95,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.bourbon.jackBlack">Jack Daniel's Black</span><span class="drink-price">130,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.bourbon.jackHoney">Jack Daniel's Honey</span><span class="drink-price">140,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.bourbon.makers">Maker's Mark</span><span class="drink-price">165,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.bourbon.buffalo">Buffalo Trace</span><span class="drink-price">180,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.bourbon.woodford">Woodford Reserve</span><span class="drink-price">220,000đ</span></div>
        </div>

        <div class="drink-cat">
          <h3 class="drink-cat-title" data-i18n="drinks.cat.scotch">Scotch &amp; Irish</h3>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.scotch.johnnieRed">Johnnie Walker Red</span><span class="drink-price">110,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.scotch.johnnieBlack">Johnnie Walker Black</span><span class="drink-price">160,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.scotch.chivas12">Chivas Regal 12</span><span class="drink-price">160,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.scotch.glenfiddich">Glenfiddich 12</span><span class="drink-price">210,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.scotch.glenlivet">Glenlivet 12</span><span class="drink-price">220,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.scotch.macallan">Macallan Double Cask 12</span><span class="drink-price">320,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.scotch.jameson">Jameson Irish</span><span class="drink-price">140,000đ</span></div>
        </div>

        <div class="drink-cat">
          <h3 class="drink-cat-title" data-i18n="drinks.cat.gin">Gin</h3>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.gin.gordons">Gordon's</span><span class="drink-price">95,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.gin.beefeater">Beefeater</span><span class="drink-price">115,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.gin.bombay">Bombay Sapphire</span><span class="drink-price">140,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.gin.tanqueray">Tanqueray</span><span class="drink-price">145,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.gin.hendricks">Hendrick's</span><span class="drink-price">180,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.gin.monkey47">Monkey 47</span><span class="drink-price">260,000đ</span></div>
        </div>

        <div class="drink-cat">
          <h3 class="drink-cat-title" data-i18n="drinks.cat.rum">Rum &amp; Cane</h3>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.rum.bacardi">Bacardi Superior</span><span class="drink-price">95,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.rum.havana3">Havana Club 3</span><span class="drink-price">120,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.rum.havana7">Havana Club 7</span><span class="drink-price">160,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.rum.captain">Captain Morgan Spiced</span><span class="drink-price">130,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.rum.kraken">Kraken Black Spiced</span><span class="drink-price">170,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.rum.sonTinh">Son Tinh Cane</span><span class="drink-price">110,000đ</span></div>
        </div>

        <div class="drink-cat">
          <h3 class="drink-cat-title" data-i18n="drinks.cat.premium">Premium &amp; Aperitifs</h3>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.premium.patronSilver">Patrón Silver</span><span class="drink-price">220,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.premium.patronAnejo">Patrón Añejo</span><span class="drink-price">280,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.premium.cognac">Hennessy VS</span><span class="drink-price">240,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.premium.aperol">Aperol</span><span class="drink-price">110,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.premium.campari">Campari</span><span class="drink-price">120,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.premium.vermouth">Martini Vermouth</span><span class="drink-price">100,000đ</span></div>
        </div>

        <div class="drink-cat">
          <h3 class="drink-cat-title" data-i18n="drinks.cat.cocktails">House Cocktails</h3>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.cocktails.saigonSpritz">Saigon Spritz</span><span class="drink-price">150,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.cocktails.lemongrassGT">Lemongrass G&amp;T</span><span class="drink-price">160,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.cocktails.espMartini">Espresso Martini</span><span class="drink-price">170,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.cocktails.oldFashioned">Bourbon Old Fashioned</span><span class="drink-price">180,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.cocktails.mojito">Mojito (mint grown upstairs)</span><span class="drink-price">160,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.cocktails.negroni">Negroni</span><span class="drink-price">180,000đ</span></div>
        </div>

        <div class="drink-cat">
          <h3 class="drink-cat-title" data-i18n="drinks.cat.coffee">Coffee &amp; Soft</h3>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.coffee.caPhe">Cà Phê Sữa Đá</span><span class="drink-price">45,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.coffee.espresso">Espresso</span><span class="drink-price">45,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.coffee.flatWhite">Flat White</span><span class="drink-price">60,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.coffee.latte">Latte</span><span class="drink-price">60,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.coffee.softDrinks">Soft Drinks</span><span class="drink-price">35,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.coffee.juice">Fresh Juice</span><span class="drink-price">60,000đ</span></div>
          <div class="drink-item"><span class="drink-name" data-i18n="drinks.coffee.sparkling">Sparkling Water</span><span class="drink-price">50,000đ</span></div>
        </div>

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

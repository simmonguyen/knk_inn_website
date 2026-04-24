-- ============================================================
-- 003 — menu_drinks table + seed (Stock Market Phase 1)
--
-- The customer ordering page (order.php) and the public drinks
-- page (drinks.php) historically read a hardcoded HTML menu. This
-- migration moves the menu into a real table so super_admin and
-- owner can edit drinks, prices, and categories through /menu.php
-- without touching code.
--
-- Seed data mirrors the current drinks.php contents so the site
-- behaves identically the moment this migration runs. Simmo can
-- then edit to match the printed menu via the admin UI (add the
-- extra cocktails, bump prices, etc.).
--
-- Notes:
--   - item_code is the stable reference that shows up in
--     order_items.item_code. Keeping the same values as the
--     existing data-i18n keys means old orders remain linkable.
--   - No FK from order_items → menu_drinks: Simmo must be able
--     to delete a drink that was ordered in the past without
--     DB errors, and order_items already snapshots name + price.
--   - Phase 2 market-stock columns are deliberately NOT added
--     here — they land in a later migration when the stock
--     market itself ships.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- ---------- menu_drinks ----------
CREATE TABLE IF NOT EXISTS menu_drinks (
    id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    item_code      VARCHAR(80)      NOT NULL,   -- stable id, appears in order_items.item_code
    i18n_key       VARCHAR(120)     NULL,       -- e.g. 'drinks.beer.tiger' for the public drinks page
    category       VARCHAR(60)      NOT NULL,   -- display name, e.g. 'Beer'
    category_slug  VARCHAR(40)      NOT NULL,   -- grouping key, e.g. 'beer'
    category_sort  SMALLINT UNSIGNED NOT NULL DEFAULT 100,  -- order of the category on the menu
    sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 100,  -- order within the category
    name           VARCHAR(120)     NOT NULL,
    ingredients    VARCHAR(255)     NULL,       -- free text, used for cocktails later
    price_vnd      BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    is_visible     TINYINT(1)       NOT NULL DEFAULT 1,     -- 0 = hidden from order/drinks pages
    updated_by     INT UNSIGNED     NULL,
    updated_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_menu_item_code (item_code),
    KEY idx_menu_category (category_slug, sort_order),
    KEY idx_menu_visible (is_visible),
    CONSTRAINT fk_menu_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- seed from current drinks.php ----------
-- INSERT IGNORE: re-running this migration won't duplicate rows,
-- and won't clobber any Simmo-made edits to prices or names.

-- Beer -----------------------------------------------------------
INSERT IGNORE INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, price_vnd) VALUES
 ('beer.saigonGreen',  'drinks.beer.saigonGreen',  'Beer', 'beer', 10,  10, 'Saigon Green',          45000),
 ('beer.bialsa',       'drinks.beer.bialsa',       'Beer', 'beer', 10,  20, 'Bia Saigon Special',    55000),
 ('beer.tiger',        'drinks.beer.tiger',        'Beer', 'beer', 10,  30, 'Tiger',                 60000),
 ('beer.heineken',     'drinks.beer.heineken',     'Beer', 'beer', 10,  40, 'Heineken',              65000),
 ('beer.corona',       'drinks.beer.corona',       'Beer', 'beer', 10,  50, 'Corona',                95000),
 ('beer.guinness',     'drinks.beer.guinness',     'Beer', 'beer', 10,  60, 'Guinness (can)',       130000),
 ('beer.pasteurTap',   'drinks.beer.pasteurTap',   'Beer', 'beer', 10,  70, 'Pasteur Street (tap)', 120000),
 ('beer.houseTap',     'drinks.beer.houseTap',     'Beer', 'beer', 10,  80, 'House Tap Pint',       110000);

-- Wine -----------------------------------------------------------
INSERT IGNORE INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, price_vnd) VALUES
 ('wine.dalatRed',       'drinks.wine.dalatRed',       'Wine', 'wine', 20,  10, 'Dalat Red (glass)',            120000),
 ('wine.dalatBottle',    'drinks.wine.dalatBottle',    'Wine', 'wine', 20,  20, 'Dalat Red (bottle)',           550000),
 ('wine.shirazGlass',    'drinks.wine.shirazGlass',    'Wine', 'wine', 20,  30, 'Australian Shiraz (glass)',    160000),
 ('wine.shirazBottle',   'drinks.wine.shirazBottle',   'Wine', 'wine', 20,  40, 'Australian Shiraz (bottle)',   790000),
 ('wine.sauvGlass',      'drinks.wine.sauvGlass',      'Wine', 'wine', 20,  50, 'NZ Sauvignon Blanc (glass)',   160000),
 ('wine.sauvBottle',     'drinks.wine.sauvBottle',     'Wine', 'wine', 20,  60, 'NZ Sauvignon Blanc (bottle)',  790000),
 ('wine.proseccoGlass',  'drinks.wine.proseccoGlass',  'Wine', 'wine', 20,  70, 'Prosecco (glass)',             140000),
 ('wine.proseccoBottle', 'drinks.wine.proseccoBottle', 'Wine', 'wine', 20,  80, 'Prosecco (bottle)',            680000);

-- Bourbon & American Whiskey ------------------------------------
INSERT IGNORE INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, price_vnd) VALUES
 ('bourbon.jimBeam',    'drinks.bourbon.jimBeam',   'Bourbon & American Whiskey', 'bourbon', 30,  10, 'Jim Beam White',       95000),
 ('bourbon.jackBlack',  'drinks.bourbon.jackBlack', 'Bourbon & American Whiskey', 'bourbon', 30,  20, 'Jack Daniel''s Black', 130000),
 ('bourbon.jackHoney',  'drinks.bourbon.jackHoney', 'Bourbon & American Whiskey', 'bourbon', 30,  30, 'Jack Daniel''s Honey', 140000),
 ('bourbon.makers',     'drinks.bourbon.makers',    'Bourbon & American Whiskey', 'bourbon', 30,  40, 'Maker''s Mark',        165000),
 ('bourbon.buffalo',    'drinks.bourbon.buffalo',   'Bourbon & American Whiskey', 'bourbon', 30,  50, 'Buffalo Trace',        180000),
 ('bourbon.woodford',   'drinks.bourbon.woodford',  'Bourbon & American Whiskey', 'bourbon', 30,  60, 'Woodford Reserve',     220000);

-- Scotch & Irish -------------------------------------------------
INSERT IGNORE INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, price_vnd) VALUES
 ('scotch.johnnieRed',   'drinks.scotch.johnnieRed',   'Scotch & Irish', 'scotch', 40,  10, 'Johnnie Walker Red',      110000),
 ('scotch.johnnieBlack', 'drinks.scotch.johnnieBlack', 'Scotch & Irish', 'scotch', 40,  20, 'Johnnie Walker Black',    160000),
 ('scotch.chivas12',     'drinks.scotch.chivas12',     'Scotch & Irish', 'scotch', 40,  30, 'Chivas Regal 12',         160000),
 ('scotch.glenfiddich',  'drinks.scotch.glenfiddich',  'Scotch & Irish', 'scotch', 40,  40, 'Glenfiddich 12',          210000),
 ('scotch.glenlivet',    'drinks.scotch.glenlivet',    'Scotch & Irish', 'scotch', 40,  50, 'Glenlivet 12',            220000),
 ('scotch.macallan',     'drinks.scotch.macallan',     'Scotch & Irish', 'scotch', 40,  60, 'Macallan Double Cask 12', 320000),
 ('scotch.jameson',      'drinks.scotch.jameson',      'Scotch & Irish', 'scotch', 40,  70, 'Jameson Irish',           140000);

-- Gin ------------------------------------------------------------
INSERT IGNORE INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, price_vnd) VALUES
 ('gin.gordons',   'drinks.gin.gordons',   'Gin', 'gin', 50,  10, 'Gordon''s',        95000),
 ('gin.beefeater', 'drinks.gin.beefeater', 'Gin', 'gin', 50,  20, 'Beefeater',       115000),
 ('gin.bombay',    'drinks.gin.bombay',    'Gin', 'gin', 50,  30, 'Bombay Sapphire', 140000),
 ('gin.tanqueray', 'drinks.gin.tanqueray', 'Gin', 'gin', 50,  40, 'Tanqueray',       145000),
 ('gin.hendricks', 'drinks.gin.hendricks', 'Gin', 'gin', 50,  50, 'Hendrick''s',     180000),
 ('gin.monkey47',  'drinks.gin.monkey47',  'Gin', 'gin', 50,  60, 'Monkey 47',       260000);

-- Rum & Cane -----------------------------------------------------
INSERT IGNORE INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, price_vnd) VALUES
 ('rum.bacardi',   'drinks.rum.bacardi',   'Rum & Cane', 'rum', 60,  10, 'Bacardi Superior',       95000),
 ('rum.havana3',   'drinks.rum.havana3',   'Rum & Cane', 'rum', 60,  20, 'Havana Club 3',         120000),
 ('rum.havana7',   'drinks.rum.havana7',   'Rum & Cane', 'rum', 60,  30, 'Havana Club 7',         160000),
 ('rum.captain',   'drinks.rum.captain',   'Rum & Cane', 'rum', 60,  40, 'Captain Morgan Spiced', 130000),
 ('rum.kraken',    'drinks.rum.kraken',    'Rum & Cane', 'rum', 60,  50, 'Kraken Black Spiced',   170000),
 ('rum.sonTinh',   'drinks.rum.sonTinh',   'Rum & Cane', 'rum', 60,  60, 'Son Tinh Cane',         110000);

-- Premium & Aperitifs -------------------------------------------
INSERT IGNORE INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, price_vnd) VALUES
 ('premium.patronSilver', 'drinks.premium.patronSilver', 'Premium & Aperitifs', 'premium', 70,  10, 'Patrón Silver',    220000),
 ('premium.patronAnejo',  'drinks.premium.patronAnejo',  'Premium & Aperitifs', 'premium', 70,  20, 'Patrón Añejo',     280000),
 ('premium.cognac',       'drinks.premium.cognac',       'Premium & Aperitifs', 'premium', 70,  30, 'Hennessy VS',      240000),
 ('premium.aperol',       'drinks.premium.aperol',       'Premium & Aperitifs', 'premium', 70,  40, 'Aperol',           110000),
 ('premium.campari',      'drinks.premium.campari',      'Premium & Aperitifs', 'premium', 70,  50, 'Campari',          120000),
 ('premium.vermouth',     'drinks.premium.vermouth',     'Premium & Aperitifs', 'premium', 70,  60, 'Martini Vermouth', 100000);

-- House Cocktails ----------------------------------------------
INSERT IGNORE INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, price_vnd) VALUES
 ('cocktails.saigonSpritz', 'drinks.cocktails.saigonSpritz', 'House Cocktails', 'cocktails', 80,  10, 'Saigon Spritz',              150000),
 ('cocktails.lemongrassGT', 'drinks.cocktails.lemongrassGT', 'House Cocktails', 'cocktails', 80,  20, 'Lemongrass G&T',             160000),
 ('cocktails.espMartini',   'drinks.cocktails.espMartini',   'House Cocktails', 'cocktails', 80,  30, 'Espresso Martini',           170000),
 ('cocktails.oldFashioned', 'drinks.cocktails.oldFashioned', 'House Cocktails', 'cocktails', 80,  40, 'Bourbon Old Fashioned',      180000),
 ('cocktails.mojito',       'drinks.cocktails.mojito',       'House Cocktails', 'cocktails', 80,  50, 'Mojito (mint grown upstairs)', 160000),
 ('cocktails.negroni',      'drinks.cocktails.negroni',      'House Cocktails', 'cocktails', 80,  60, 'Negroni',                    180000);

-- Coffee & Soft -------------------------------------------------
INSERT IGNORE INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, price_vnd) VALUES
 ('coffee.caPhe',      'drinks.coffee.caPhe',      'Coffee & Soft', 'coffee', 90,  10, 'Cà Phê Sữa Đá',    45000),
 ('coffee.espresso',   'drinks.coffee.espresso',   'Coffee & Soft', 'coffee', 90,  20, 'Espresso',          45000),
 ('coffee.flatWhite',  'drinks.coffee.flatWhite',  'Coffee & Soft', 'coffee', 90,  30, 'Flat White',        60000),
 ('coffee.latte',      'drinks.coffee.latte',      'Coffee & Soft', 'coffee', 90,  40, 'Latte',             60000),
 ('coffee.softDrinks', 'drinks.coffee.softDrinks', 'Coffee & Soft', 'coffee', 90,  50, 'Soft Drinks',       35000),
 ('coffee.juice',      'drinks.coffee.juice',      'Coffee & Soft', 'coffee', 90,  60, 'Fresh Juice',       60000),
 ('coffee.sparkling',  'drinks.coffee.sparkling',  'Coffee & Soft', 'coffee', 90,  70, 'Sparkling Water',   50000);

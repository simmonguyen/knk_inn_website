-- ============================================================
-- 005 — Real menu reseed (replaces Phase 1's drinks.php-derived seed)
--
-- Phase 1 (migration 003) seeded menu_drinks from the OLD hardcoded
-- drinks.php — an aspirational craft-bar list (Patron, Macallan,
-- Hendrick's, Espresso Martinis, etc.) that was never the real KnK
-- Inn menu. This migration wipes that seed and replaces it with
-- what's actually on Simmo's printed menu (Saigon-pub style).
--
-- Cleanups applied during the rewrite:
--   - Liquer    → Liqueur          (spelling)
--   - Jimbean   → Jim Beam         (spelling)
--   - Maker'mark→ Maker's Mark     (spelling)
--   - Slipper Nipple → Slippery Nipple  (spelling)
--   - Rhum      → Rum              (used 'Rum' as category)
--   - Rogu Gin  → Roku Gin         (spelling — Roku is a Japanese gin,
--                                   moved from "Aperitifs" → Gin)
--   - Cheeky cocktail names kept verbatim per Ben.
--   - Blank "(Juice/Squash) 75k" line dropped (print error per Ben).
--   - Stray "Vodka 120k" in the Popular Cocktail column dropped —
--     it was a column-layout artifact (real Vodka entries are
--     Absolut and Eristoff in the spirits section).
--   - Soft-drink "Fresh Coconut" 40k labeled "Coconut Water (canned)"
--     to disambiguate from the 50k juice-bar Fresh Coconut.
--
-- Category restructure (11 customer-facing categories):
--    1. Cocktails              (Popular + Special merged)
--    2. Beer
--    3. Wine
--    4. Bourbon & Whisky       (Bourbon + Premium 12yr + Regular 7yr
--                               + Japanese whisky)
--    5. Gin
--    6. Rum
--    7. Vodka & Tequila        (Tequila moved out of Bourbon,
--                               labeled "House Tequila" pending
--                               Simmo confirming the brand)
--    8. Liqueurs & Aperitifs
--    9. Coffee & Tea
--   10. Smoothies & Juice
--   11. Soft Drinks
--
-- Safe to wipe: no foreign key constraints reference menu_drinks
-- (order_items snapshots name+price, market_pinned/market_events
-- only carry item_code as plain VARCHAR with no FK).
--
-- Pinned slots from market_pinned: currently both NULL (Simmo
-- hasn't picked yet — site is in "warming up" state), so no
-- dangling references after the wipe.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- Wipe the old Phase-1 seed. Idempotent: schema_migrations stops
-- this migration from re-running, so any later /menu.php edits
-- Simmo makes will not be touched.
DELETE FROM menu_drinks;

-- Reset auto_increment so the new seed gets clean IDs from 1.
ALTER TABLE menu_drinks AUTO_INCREMENT = 1;

-- ---------- 1. Cocktails (26) ----------
-- Popular + Special merged. Names verbatim. Ingredients column
-- gets the small note printed under each cocktail.
INSERT INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, ingredients, price_vnd) VALUES
 ('cocktail.longIsland',        'drinks.cocktail.longIsland',        'Cocktails', 'cocktail', 10, 10, 'Long Island Iced Tea',  'Rum, Gin, Triple Sec, Tequila, Vodka',     180000),
 ('cocktail.b52',               'drinks.cocktail.b52',               'Cocktails', 'cocktail', 10, 20, 'B52',                   'Bailey''s, Grand Marnier, Kahlua',          120000),
 ('cocktail.margarita',         'drinks.cocktail.margarita',         'Cocktails', 'cocktail', 10, 30, 'Margarita',             'Tequila, Cointreau, Lemon juice',           150000),
 ('cocktail.pinaColada',        'drinks.cocktail.pinaColada',        'Cocktails', 'cocktail', 10, 40, 'Pina Colada',           'Rum, Coconut, Fruit juice, Cream',          140000),
 ('cocktail.grasshopper',       'drinks.cocktail.grasshopper',       'Cocktails', 'cocktail', 10, 50, 'Grasshopper',           'Creme de Menthe, Milk',                     120000),
 ('cocktail.maitai',            'drinks.cocktail.maitai',            'Cocktails', 'cocktail', 10, 60, 'Maitai',                'Rum, Orange, Fruit juice',                  130000),
 ('cocktail.tequilaSunrise',    'drinks.cocktail.tequilaSunrise',    'Cocktails', 'cocktail', 10, 70, 'Tequila Sunrise',       'Tequila, Orange juice',                     130000),
 ('cocktail.slipperyNipple',    'drinks.cocktail.slipperyNipple',    'Cocktails', 'cocktail', 10, 80, 'Slippery Nipple',       'Sambuca, Bailey''s',                        130000),
 ('cocktail.whiskySour',        'drinks.cocktail.whiskySour',        'Cocktails', 'cocktail', 10, 90, 'Whisky Sour',           'Whisky, Lemon',                             120000),
 ('cocktail.kamikaze',          'drinks.cocktail.kamikaze',          'Cocktails', 'cocktail', 10, 100,'Kamikaze',              'Vodka, Lemon, Triple Sec',                  140000),
 ('cocktail.screwdriver',       'drinks.cocktail.screwdriver',       'Cocktails', 'cocktail', 10, 110,'Screwdriver',           'Vodka, Orange juice',                       120000),
 ('cocktail.blueLagoon',        'drinks.cocktail.blueLagoon',        'Cocktails', 'cocktail', 10, 120,'Blue Lagoon',           'Vodka, Blue Curacao, Tonic',                130000),
 ('cocktail.stranger',          'drinks.cocktail.stranger',          'Cocktails', 'cocktail', 10, 130,'Stranger',              'Cacao, Bailey''s',                          130000),
 ('cocktail.cockSuckingCowboy', 'drinks.cocktail.cockSuckingCowboy', 'Cocktails', 'cocktail', 10, 140,'Cock Sucking Cowboy',   'Bailey''s, Butterscotch',                   140000),
 ('cocktail.quickFuck',         'drinks.cocktail.quickFuck',         'Cocktails', 'cocktail', 10, 150,'Quick Fuck',            'Bailey''s, Midori, Vodka',                  120000),
 ('cocktail.blueHawaii',        'drinks.cocktail.blueHawaii',        'Cocktails', 'cocktail', 10, 160,'Blue Hawaii',           'Rum, Vodka, Blue Curacao, Pineapple',       160000),
 ('cocktail.blackRussian',      'drinks.cocktail.blackRussian',      'Cocktails', 'cocktail', 10, 170,'Black Russian',         'Vodka, Kahlua',                             130000),
 ('cocktail.passionDaiquiri',   'drinks.cocktail.passionDaiquiri',   'Cocktails', 'cocktail', 10, 180,'Passion Daiquiri',      'Rum, Sirop, Lemon juice',                   140000),
 ('cocktail.iLoveYou',          'drinks.cocktail.iLoveYou',          'Cocktails', 'cocktail', 10, 190,'I Love You',            'Bailey''s, Kahlua, Cream',                  130000),
 ('cocktail.greenfield',        'drinks.cocktail.greenfield',        'Cocktails', 'cocktail', 10, 200,'Greenfield',            'Vodka, Midori',                             140000),
 ('cocktail.terminator',        'drinks.cocktail.terminator',        'Cocktails', 'cocktail', 10, 210,'Terminator',            'Vodka, Tequila, Spices',                    130000),
 ('cocktail.blackWestern',      'drinks.cocktail.blackWestern',      'Cocktails', 'cocktail', 10, 220,'Black Western',         'Rum, Vodka, Coke',                          140000),
 ('cocktail.takeMeHome',        'drinks.cocktail.takeMeHome',        'Cocktails', 'cocktail', 10, 230,'Take Me Home',          'Bailey''s, Kahlua',                         130000),
 ('cocktail.godFather',         'drinks.cocktail.godFather',         'Cocktails', 'cocktail', 10, 240,'God Father',            'Whisky, Amaretto',                          130000),
 ('cocktail.topGun',            'drinks.cocktail.topGun',            'Cocktails', 'cocktail', 10, 250,'Top Gun',               'Whisky, Drambuie',                          130000),
 ('cocktail.blackSambuca',      'drinks.cocktail.blackSambuca',      'Cocktails', 'cocktail', 10, 260,'Black Sambuca',         'Sambuca',                                   150000);

-- ---------- 2. Beer (5) ----------
INSERT INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, price_vnd) VALUES
 ('beer.heineken',      'drinks.beer.heineken',      'Beer', 'beer', 20, 10, 'Heineken',         60000),
 ('beer.tigerDraught',  'drinks.beer.tigerDraught',  'Beer', 'beer', 20, 20, 'Tiger Draught',    60000),
 ('beer.tigerCrystal',  'drinks.beer.tigerCrystal',  'Beer', 'beer', 20, 30, 'Tiger Crystal',    60000),
 ('beer.corona',        'drinks.beer.corona',        'Beer', 'beer', 20, 40, 'Corona',          100000),
 ('beer.saigonSpecial', 'drinks.beer.saigonSpecial', 'Beer', 'beer', 20, 50, 'Saigon Special',   55000);

-- ---------- 3. Wine (2) ----------
INSERT INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, price_vnd) VALUES
 ('wine.chileBottle',  'drinks.wine.chileBottle',  'Wine', 'wine', 30, 10, 'Chilean Wine (bottle)',    600000),
 ('wine.aussieBottle', 'drinks.wine.aussieBottle', 'Wine', 'wine', 30, 20, 'Australian Wine (bottle)', 600000);

-- ---------- 4. Bourbon & Whisky (12) ----------
-- Bourbon first (Aussie pub muscle memory), then Scotch/Irish/Canadian
-- 7yr & 12yr range, then Japanese (Suntory/Toki/Chita).
INSERT INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, ingredients, price_vnd) VALUES
 ('bourbon.jimBeam',       'drinks.bourbon.jimBeam',       'Bourbon & Whisky', 'whisky', 40, 10,  'Jim Beam',              NULL,                              110000),
 ('bourbon.jackDaniel',    'drinks.bourbon.jackDaniel',    'Bourbon & Whisky', 'whisky', 40, 20,  'Jack Daniel''s',        NULL,                              120000),
 ('bourbon.makersMark',    'drinks.bourbon.makersMark',    'Bourbon & Whisky', 'whisky', 40, 30,  'Maker''s Mark',         NULL,                              110000),
 ('whisky.canadianClub',   'drinks.whisky.canadianClub',   'Bourbon & Whisky', 'whisky', 40, 40,  'Canadian Club',         '12 years',                        120000),
 ('whisky.johnnieBlack',   'drinks.whisky.johnnieBlack',   'Bourbon & Whisky', 'whisky', 40, 50,  'Johnnie Walker Black',  '12 years',                        120000),
 ('whisky.famousGrouse',   'drinks.whisky.famousGrouse',   'Bourbon & Whisky', 'whisky', 40, 60,  'Famous Grouse',         '7 years',                         110000),
 ('whisky.wildTurkey',     'drinks.whisky.wildTurkey',     'Bourbon & Whisky', 'whisky', 40, 70,  'Wild Turkey',           '7 years',                         110000),
 ('whisky.johnnieRed',     'drinks.whisky.johnnieRed',     'Bourbon & Whisky', 'whisky', 40, 80,  'Johnnie Walker Red',    '7 years',                         110000),
 ('whisky.jameson',        'drinks.whisky.jameson',        'Bourbon & Whisky', 'whisky', 40, 90,  'Jameson',               'Irish, 7 years',                  110000),
 ('whisky.toki',           'drinks.whisky.toki',           'Bourbon & Whisky', 'whisky', 40, 100, 'Toki',                  'Japanese',                        120000),
 ('whisky.chita',          'drinks.whisky.chita',          'Bourbon & Whisky', 'whisky', 40, 110, 'Chita',                 'Japanese',                        190000),
 ('whisky.suntory',        'drinks.whisky.suntory',        'Bourbon & Whisky', 'whisky', 40, 120, 'Suntory Old Whisky',    'Japanese',                        220000);

-- ---------- 5. Gin (3) ----------
-- Roku moved out of "Aperitifs" — it's a Japanese gin.
INSERT INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, ingredients, price_vnd) VALUES
 ('gin.gordons', 'drinks.gin.gordons', 'Gin', 'gin', 50, 10, 'Gordon''s', NULL,         110000),
 ('gin.bombay',  'drinks.gin.bombay',  'Gin', 'gin', 50, 20, 'Bombay',    NULL,         120000),
 ('gin.roku',    'drinks.gin.roku',    'Gin', 'gin', 50, 30, 'Roku',      'Japanese',   120000);

-- ---------- 6. Rum (3) ----------
INSERT INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, ingredients, price_vnd) VALUES
 ('rum.lightBacardi',  'drinks.rum.lightBacardi',  'Rum', 'rum', 60, 10, 'Light Bacardi',           NULL,             110000),
 ('rum.captainMorgan', 'drinks.rum.captainMorgan', 'Rum', 'rum', 60, 20, 'Captain Morgan',          NULL,             110000),
 ('rum.aussieBundy',   'drinks.rum.aussieBundy',   'Rum', 'rum', 60, 30, 'Aussie Bundy',            'Regular',        110000);

-- ---------- 7. Vodka & Tequila (3) ----------
-- Tequila moved out of Bourbon section.
INSERT INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, ingredients, price_vnd) VALUES
 ('vodka.absolut',  'drinks.vodka.absolut',  'Vodka & Tequila', 'vodkaTequila', 70, 10, 'Absolut Vodka', NULL, 120000),
 ('vodka.eristoff', 'drinks.vodka.eristoff', 'Vodka & Tequila', 'vodkaTequila', 70, 20, 'Eristoff',      NULL, 110000),
 ('tequila.house',  'drinks.tequila.house',  'Vodka & Tequila', 'vodkaTequila', 70, 30, 'House Tequila', NULL, 110000);

-- ---------- 8. Liqueurs & Aperitifs (8) ----------
INSERT INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, ingredients, price_vnd) VALUES
 ('liqueur.cointreau',     'drinks.liqueur.cointreau',     'Liqueurs & Aperitifs', 'liqueur', 80, 10, 'Cointreau',           NULL,                  110000),
 ('liqueur.deKuyper',      'drinks.liqueur.deKuyper',      'Liqueurs & Aperitifs', 'liqueur', 80, 20, 'De Kuyper Triple Sec',NULL,                  110000),
 ('liqueur.baileys',       'drinks.liqueur.baileys',       'Liqueurs & Aperitifs', 'liqueur', 80, 30, 'Bailey''s Irish Cream',NULL,                 110000),
 ('liqueur.kahlua',        'drinks.liqueur.kahlua',        'Liqueurs & Aperitifs', 'liqueur', 80, 40, 'Kahlua',              NULL,                  110000),
 ('liqueur.malibu',        'drinks.liqueur.malibu',        'Liqueurs & Aperitifs', 'liqueur', 80, 50, 'Malibu',              NULL,                  110000),
 ('liqueur.sambuca',       'drinks.liqueur.sambuca',       'Liqueurs & Aperitifs', 'liqueur', 80, 60, 'Sambuca',             NULL,                  110000),
 ('aperitif.ricard',       'drinks.aperitif.ricard',       'Liqueurs & Aperitifs', 'liqueur', 80, 70, 'Ricard',              'French aniseed',      120000),
 ('aperitif.jagermeister', 'drinks.aperitif.jagermeister', 'Liqueurs & Aperitifs', 'liqueur', 80, 80, 'Jagermeister',        'German herbal',       120000);

-- ---------- 9. Coffee & Tea (6) ----------
INSERT INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, ingredients, price_vnd) VALUES
 ('coffee.lipton',      'drinks.coffee.lipton',      'Coffee & Tea', 'coffee', 90, 10, 'Lipton Tea',                NULL,                              50000),
 ('coffee.hot',         'drinks.coffee.hot',         'Coffee & Tea', 'coffee', 90, 20, 'Hot Coffee',                NULL,                              40000),
 ('coffee.milk',        'drinks.coffee.milk',        'Coffee & Tea', 'coffee', 90, 30, 'Milk Coffee',               NULL,                              50000),
 ('coffee.black',       'drinks.coffee.black',       'Coffee & Tea', 'coffee', 90, 40, 'Black Coffee',              NULL,                              45000),
 ('coffee.cheeseCream', 'drinks.coffee.cheeseCream', 'Coffee & Tea', 'coffee', 90, 50, 'Coffee with Cheese Cream',  NULL,                              60000),
 ('coffee.silver',      'drinks.coffee.silver',      'Coffee & Tea', 'coffee', 90, 60, 'Silver Coffee',             'Milk coffee with extra milk',     50000);

-- ---------- 10. Smoothies & Juice (11) ----------
INSERT INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, price_vnd) VALUES
 ('smoothie.strawberry', 'drinks.smoothie.strawberry', 'Smoothies & Juice', 'smoothiejuice', 95, 10,  'Strawberry Smoothie', 70000),
 ('smoothie.banana',     'drinks.smoothie.banana',     'Smoothies & Juice', 'smoothiejuice', 95, 20,  'Banana Smoothie',     70000),
 ('smoothie.soursop',    'drinks.smoothie.soursop',    'Smoothies & Juice', 'smoothiejuice', 95, 30,  'Soursop Smoothie',    70000),
 ('smoothie.sapodilla',  'drinks.smoothie.sapodilla',  'Smoothies & Juice', 'smoothiejuice', 95, 40,  'Sapodilla Smoothie',  70000),
 ('smoothie.mango',      'drinks.smoothie.mango',      'Smoothies & Juice', 'smoothiejuice', 95, 50,  'Mango Smoothie',      70000),
 ('smoothie.avocado',    'drinks.smoothie.avocado',    'Smoothies & Juice', 'smoothiejuice', 95, 60,  'Avocado Smoothie',    75000),
 ('juice.watermelon',    'drinks.juice.watermelon',    'Smoothies & Juice', 'smoothiejuice', 95, 70,  'Watermelon Juice',    75000),
 ('juice.apple',         'drinks.juice.apple',         'Smoothies & Juice', 'smoothiejuice', 95, 80,  'Apple Juice',         75000),
 ('juice.pineapple',     'drinks.juice.pineapple',     'Smoothies & Juice', 'smoothiejuice', 95, 90,  'Pineapple Juice',     75000),
 ('juice.orange',        'drinks.juice.orange',        'Smoothies & Juice', 'smoothiejuice', 95, 100, 'Orange Juice',        75000),
 ('juice.coconut',       'drinks.juice.coconut',       'Smoothies & Juice', 'smoothiejuice', 95, 110, 'Fresh Coconut',       50000);

-- ---------- 11. Soft Drinks (9) ----------
INSERT INTO menu_drinks (item_code, i18n_key, category, category_slug, category_sort, sort_order, name, ingredients, price_vnd) VALUES
 ('soft.coke',         'drinks.soft.coke',         'Soft Drinks', 'soft', 100, 10, 'Coke',                  NULL,        40000),
 ('soft.cokeDiet',     'drinks.soft.cokeDiet',     'Soft Drinks', 'soft', 100, 20, 'Coke Diet',             NULL,        50000),
 ('soft.tonic',        'drinks.soft.tonic',        'Soft Drinks', 'soft', 100, 30, 'Tonic',                 NULL,        40000),
 ('soft.soda',         'drinks.soft.soda',         'Soft Drinks', 'soft', 100, 40, 'Soda',                  NULL,        40000),
 ('soft.sevenUp',      'drinks.soft.sevenUp',      'Soft Drinks', 'soft', 100, 50, '7Up',                   NULL,        40000),
 ('soft.redBull',      'drinks.soft.redBull',      'Soft Drinks', 'soft', 100, 60, 'Red Bull',              NULL,        40000),
 ('soft.water',        'drinks.soft.water',        'Soft Drinks', 'soft', 100, 70, 'Water',                 NULL,        30000),
 ('soft.coconutWater', 'drinks.soft.coconutWater', 'Soft Drinks', 'soft', 100, 80, 'Coconut Water (canned)',NULL,        40000),
 ('soft.gingerAle',    'drinks.soft.gingerAle',    'Soft Drinks', 'soft', 100, 90, 'Ginger Ale',            NULL,        60000);

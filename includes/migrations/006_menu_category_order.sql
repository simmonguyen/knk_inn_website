-- ============================================================
-- 006 — Reorder menu categories by pub-style popularity
--
-- Migration 005 originally seeded with Cocktails first. Ben (and
-- Simmo) want pub-style ordering since KnK Inn is a pub, not a
-- cocktail bar. A later commit changed 005's sort values, but
-- 005 had already been applied — schema_migrations skips it.
--
-- This migration just UPDATEs category_sort for the rows already
-- in menu_drinks. Idempotent — if 005 happened to run with the
-- new values it's a no-op.
--
-- Final order:
--    1. Beer                  (sort  10)
--    2. Wine                  (sort  20)
--    3. Cocktails             (sort  30)
--    4. Bourbon & Whisky      (sort  40)
--    5. Vodka & Tequila       (sort  50)
--    6. Gin                   (sort  60)
--    7. Rum                   (sort  70)
--    8. Liqueurs & Aperitifs  (sort  80)
--    9. Soft Drinks           (sort  90)
--   10. Smoothies & Juice     (sort 100)
--   11. Coffee & Tea          (sort 110)
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

UPDATE menu_drinks SET category_sort =  10 WHERE category_slug = 'beer';
UPDATE menu_drinks SET category_sort =  20 WHERE category_slug = 'wine';
UPDATE menu_drinks SET category_sort =  30 WHERE category_slug = 'cocktail';
UPDATE menu_drinks SET category_sort =  40 WHERE category_slug = 'whisky';
UPDATE menu_drinks SET category_sort =  50 WHERE category_slug = 'vodkaTequila';
UPDATE menu_drinks SET category_sort =  60 WHERE category_slug = 'gin';
UPDATE menu_drinks SET category_sort =  70 WHERE category_slug = 'rum';
UPDATE menu_drinks SET category_sort =  80 WHERE category_slug = 'liqueur';
UPDATE menu_drinks SET category_sort =  90 WHERE category_slug = 'soft';
UPDATE menu_drinks SET category_sort = 100 WHERE category_slug = 'smoothiejuice';
UPDATE menu_drinks SET category_sort = 110 WHERE category_slug = 'coffee';

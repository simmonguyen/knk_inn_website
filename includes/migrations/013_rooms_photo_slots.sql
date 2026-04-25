-- ============================================================
-- 013 — Rooms page photo slots
--
-- The Rooms page (rooms.php — converted from rooms.html in this
-- same change) has nine photos: three big room-type cards near
-- the top, and six common-space tiles further down.
--
-- Adds them as managed photo_slots so Simmo can swap them from
-- the Photo Manager (Homepage photos tab) the same way he does
-- with the index/drinks slots.
--
--   rooms_types  (3) — Standard·NoWindow / Standard·Balcony / VIP
--   rooms_common (6) — Street / Ground Bar / Elevator /
--                      5th Floor Bar / Darts Room / Rooftop
--
-- Defaults match the filenames previously hardcoded in rooms.html.
-- Idempotent — re-running is a no-op.
-- ============================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO photo_slots (section, slot_index, label, filename) VALUES
    ('rooms_types',  1, 'Standard · No Window',     'rm_00.jpg'),
    ('rooms_types',  2, 'Standard · Balcony',       'rm_02.jpg'),
    ('rooms_types',  3, 'VIP · Private Bathtub',    'rm_04.jpg'),
    ('rooms_common', 1, 'Street',                   'ex_01.jpg'),
    ('rooms_common', 2, 'Ground Bar',               'ex_10.jpg'),
    ('rooms_common', 3, 'Elevator',                 'nw_57.jpg'),
    ('rooms_common', 4, '5th Floor Bar',            'nw_11.jpg'),
    ('rooms_common', 5, 'Darts Room',               'rm_15.jpg'),
    ('rooms_common', 6, 'Rooftop',                  'ex_08.jpg');

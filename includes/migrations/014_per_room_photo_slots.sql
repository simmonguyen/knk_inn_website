-- ============================================================
-- 014 — Per-room subpage photo slots
--
-- Each room subpage (rooms/standard-nowindow.php, …balcony.php,
-- …vip.php — converted from .html in this same change) has a
-- hero banner image plus an in-room gallery. Adding all of those
-- as managed photo_slots so Simmo can swap them from the Photo
-- Manager (Homepage photos tab) the same way he does the slots
-- on index/drinks/rooms.
--
--   room_nowindow ( 4) — hero + 3 gallery tiles
--   room_balcony  (11) — hero + 10 gallery tiles
--   room_vip      ( 6) — hero + 5 gallery tiles
--
-- Slot 1 in every section is the hero banner. Slots 2..N are the
-- gallery tiles, in left-to-right / top-to-bottom display order.
--
-- Defaults match the filenames previously hardcoded in each .html.
-- Idempotent — re-running is a no-op.
-- ============================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO photo_slots (section, slot_index, label, filename) VALUES
    ('room_nowindow',  1, 'Hero banner', 'rm_00.jpg'),
    ('room_nowindow',  2, 'Gallery 1',   'rm_00.jpg'),
    ('room_nowindow',  3, 'Gallery 2',   'nw_01.jpg'),
    ('room_nowindow',  4, 'Gallery 3',   'nw_41.jpg'),

    ('room_balcony',   1, 'Hero banner', 'rm_02.jpg'),
    ('room_balcony',   2, 'Gallery 1',   'rm_02.jpg'),
    ('room_balcony',   3, 'Gallery 2',   'rm_00.jpg'),
    ('room_balcony',   4, 'Gallery 3',   'rm_09.jpg'),
    ('room_balcony',   5, 'Gallery 4',   'rm_14.jpg'),
    ('room_balcony',   6, 'Gallery 5',   'rm_18.jpg'),
    ('room_balcony',   7, 'Gallery 6',   'rm_03.jpg'),
    ('room_balcony',   8, 'Gallery 7',   'rm_13.jpg'),
    ('room_balcony',   9, 'Gallery 8',   'rm_17.jpg'),
    ('room_balcony',  10, 'Gallery 9',   'rm_20.jpg'),
    ('room_balcony',  11, 'Gallery 10',  'rm_24.jpg'),

    ('room_vip',       1, 'Hero banner', 'rm_04.jpg'),
    ('room_vip',       2, 'Gallery 1',   'rm_04.jpg'),
    ('room_vip',       3, 'Gallery 2',   'rm_11.jpg'),
    ('room_vip',       4, 'Gallery 3',   'rm_16.jpg'),
    ('room_vip',       5, 'Gallery 4',   'rm_23.jpg'),
    ('room_vip',       6, 'Gallery 5',   'nw_39.jpg');

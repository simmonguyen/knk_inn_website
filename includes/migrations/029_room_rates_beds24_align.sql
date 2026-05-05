-- ============================================================
-- 029 — Align room rates registry with Beds24 reality
--
-- The 026 migration seeded 7 rooms with placeholder rates +
-- legacy display names ("VIP with tub", "Standard with balcony").
-- Since then:
--
--   - KnK Inn's hotel licence came through (2026-04-23) and the
--     Beds24 channel manager went live for Booking.com / Airbnb.
--   - The canonical room categories are now Basic / Standard /
--     Superior / Premium (Beds24 vocab — Simmo, all OTAs, and
--     knkinn.com all use those names).
--   - A 4th category was added (Basic, Room 9 on Floor 1).
--   - Standard inventory was bumped 1 -> 2 (Rooms 1 and 2 on F1).
--   - Direct-booking rack rates were set: 800K Basic, 850K Standard,
--     1100K Superior, 1300K Premium.
--
-- This migration:
--
--   1. Adds the two missing rooms (basic-9, standard-nowindow-2).
--   2. Renames every existing room's display_name to Beds24
--      vocabulary, with the actual room number + floor surfaced
--      so /room-rates.php tabs read like "Premium — Room 5 (F3)".
--   3. Bumps default_vnd_per_night to current rack rates so direct
--      bookings on knkinn.com don't undercut the OTAs.
--   4. Re-orders sort_order so the admin tabs group by category in
--      the canonical order: Basic -> Standard -> Superior -> Premium.
--
-- Slug values are left alone (would break api/room_rates_export.php
-- consumers + any saved bookmark URLs). Slugs are internal — the
-- admin reads display_name.
--
-- Idempotent — INSERT IGNORE for new rows, UPDATE-by-slug for
-- the rest.
-- ============================================================

SET NAMES utf8mb4;

-- ---------- Add new rooms ----------
INSERT IGNORE INTO rooms
    (slug, room_type, display_name, floor, sort_order, default_vnd_per_night) VALUES
    ('basic-9',              'basic',             'Basic — Room 9 (F1)',     1,  5,  800000),
    ('standard-nowindow-2',  'standard-nowindow', 'Standard — Room 2 (F1)',  1, 11,  850000);

-- ---------- Update existing rooms to Beds24 names + room numbers ----------
-- standard-nowindow-1: Room 1 on F1
UPDATE rooms
   SET display_name          = 'Standard — Room 1 (F1)',
       sort_order             = 10,
       default_vnd_per_night = 850000
 WHERE slug = 'standard-nowindow-1';

-- standard-balcony-2/3/4 -> Superior. The numeric slug suffix
-- mirrors the FLOOR, not the room number, in the original 026
-- seed. Mapping (from /rooms/standard-balcony.php):
--   F2 -> Room 4, F3 -> Room 6, F4 -> Room 8.
UPDATE rooms
   SET display_name          = 'Superior — Room 4 (F2)',
       sort_order             = 20,
       default_vnd_per_night = 1100000
 WHERE slug = 'standard-balcony-2';

UPDATE rooms
   SET display_name          = 'Superior — Room 6 (F3)',
       sort_order             = 21,
       default_vnd_per_night = 1100000
 WHERE slug = 'standard-balcony-3';

UPDATE rooms
   SET display_name          = 'Superior — Room 8 (F4)',
       sort_order             = 22,
       default_vnd_per_night = 1100000
 WHERE slug = 'standard-balcony-4';

-- vip-2/3/4 -> Premium. Slug suffix = floor (per 026 convention).
-- Mapping (from /rooms/vip.php):
--   F2 -> Room 3, F3 -> Room 5, F4 -> Room 7.
UPDATE rooms
   SET display_name          = 'Premium — Room 3 (F2)',
       sort_order             = 30,
       default_vnd_per_night = 1300000
 WHERE slug = 'vip-2';

UPDATE rooms
   SET display_name          = 'Premium — Room 5 (F3)',
       sort_order             = 31,
       default_vnd_per_night = 1300000
 WHERE slug = 'vip-3';

UPDATE rooms
   SET display_name          = 'Premium — Room 7 (F4)',
       sort_order             = 32,
       default_vnd_per_night = 1300000
 WHERE slug = 'vip-4';

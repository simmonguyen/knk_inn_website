-- ============================================================
-- 007 — One-off cleanup of test orders + guests
--
-- Ben confirmed all guests and orders to date are test data
-- created while smoke-testing the V2 build (orders flow, stock
-- market warming-up state, etc.). Wipe them so production
-- starts from a clean slate.
--
-- What this deletes:
--   - All rows in `orders` (and `order_items` via FK CASCADE)
--   - All rows in `guests`
--
-- What this leaves alone:
--   - `bookings`         — Ben may have real bookings from the
--                          new hotel licence. Wiping guests will
--                          null out bookings.guest_id (the FK is
--                          ON DELETE SET NULL); bookings keep
--                          their snapshot guest_name/email/phone.
--   - `users`            — staff accounts, never test data
--   - `audit_log`        — append-only, kept for the record
--   - `market_events`    — append-only price log; old entries
--                          reference pre-reseed item_codes that
--                          no longer exist in menu_drinks. The
--                          engine ignores them. Ask if you want
--                          these cleared too (separate migration).
--   - `market_pinned`    — slots stay; their item_code values may
--                          point at deleted item_codes, in which
--                          case Simmo just re-pins on the admin
--                          page when he re-enables the market.
--
-- Idempotent: re-running deletes no rows (already empty).
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- order_items go automatically (FK ON DELETE CASCADE).
DELETE FROM orders;

-- bookings.guest_id is ON DELETE SET NULL, so this is safe.
DELETE FROM guests;

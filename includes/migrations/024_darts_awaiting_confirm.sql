-- ============================================================
-- 024 — darts_games.awaiting_confirm
--
-- Adds a "freeze the cursor" flag set after the 3rd dart of an
-- x01 (501/301) turn. With this on, current_slot_no stays on the
-- thrower until they tap "Confirm round" or "Undo", instead of
-- the server auto-advancing the moment dart 3 lands. Lets a
-- player fix a fat-finger on dart 3 before the next player's up.
--
-- Other game types (cricket / killer / aroundclock / halveit)
-- aren't affected — the tap-by-tap rendering on those is already
-- stable enough that auto-advance is fine.
--
-- Idempotent — safe to re-run.
-- ============================================================

ALTER TABLE darts_games
    ADD COLUMN IF NOT EXISTS awaiting_confirm TINYINT(1) NOT NULL DEFAULT 0
    AFTER current_turn_no;

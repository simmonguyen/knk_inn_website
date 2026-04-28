-- ============================================================
-- 027 — Darts scoring mode
--
-- Lets the host pick who's allowed to record throws when a game
-- starts. Three modes:
--
--   self_only   — strict, default. Only the current thrower can
--                 record their own throws on their own phone.
--                 (This was the only behaviour before today.)
--
--   any_player  — any player in the game can record the current
--                 thrower's throw. Covers "everyone scores their
--                 opponent" and "we're trusting each other" play
--                 styles. Useful when one player's phone has
--                 died but they still want to play.
--
--   host_only   — only the host (slot 1) can record throws for
--                 anyone. Single-device mode — one phone runs
--                 the whole scoreboard, no need for everyone to
--                 sign in.
--
-- Stored on the game row, set at creation time, immutable for
-- the life of the game (changing it mid-match would surprise
-- whoever's currently scoring).
--
-- Idempotent — safe to re-run.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE darts_games
    ADD COLUMN IF NOT EXISTS scoring_mode VARCHAR(20) NOT NULL DEFAULT 'self_only'
    AFTER status;

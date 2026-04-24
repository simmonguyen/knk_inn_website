-- ============================================================
-- 002 — Fix photo_slots seed typo.
--
-- Migration 001 seeded four_things slot #3 with 're_15.jpg',
-- but the actual file on disk is 'rm_15.jpg'. That would have
-- rendered a broken image on the homepage the first time Phase 2
-- went live, before any admin upload covered the gap.
--
-- Only updates the row if it's still pointing at the broken default
-- — if Simmo or Ben already replaced that photo via the admin UI,
-- we leave their choice alone.
-- ============================================================

UPDATE photo_slots
   SET filename = 'rm_15.jpg',
       updated_at = CURRENT_TIMESTAMP
 WHERE section    = 'four_things'
   AND slot_index = 3
   AND filename   = 're_15.jpg';

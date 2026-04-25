-- ============================================================
-- 012 — photo_library sort_order
--
-- Adds an explicit sort_order column to photo_library so the
-- Gallery wall can show photos in a defined order (and let Ben
-- drag them around to change it).
--
-- The column is backfilled with a stable initial ranking based
-- on the existing implicit order (source, then filename), so on
-- first run after this migration the gallery looks the same as
-- before. After that, the UI rewrites these values when Ben
-- reorders.
--
-- Idempotent — re-running is a no-op.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE photo_library
    ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER hidden,
    ADD KEY IF NOT EXISTS idx_photo_library_sort (sort_order);

-- Backfill: any row still at the default (0) gets a stable
-- initial rank. Once rows have sort_order > 0 the WHERE clause
-- skips them on subsequent runs.
UPDATE photo_library pl
JOIN (
    SELECT id, ROW_NUMBER() OVER (
        ORDER BY FIELD(source,'seed','gallery_live','slot_upload'), filename
    ) AS rn
      FROM photo_library
) ranked ON ranked.id = pl.id
   SET pl.sort_order = ranked.rn
 WHERE pl.sort_order = 0;

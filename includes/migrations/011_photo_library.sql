-- ============================================================
-- 011 — Photo library + tags + extra Find Us slot
--
-- Two changes here:
--
-- 1.  photo_library: a master index of every photo available on
--     the site (the seeded ex_/nw_/rm_ shots in /assets/img/, plus
--     anything Simmo uploads to /assets/img/gallery-live/, plus
--     slot uploads under /assets/img/slots/).  The Photo manager's
--     Gallery wall renders this table — you can filter by tag, and
--     the slot Replace dialog uses it as the "Pick from gallery"
--     source.
--
--     Tags are stored as a JSON-array string in `tags` (e.g.
--     '["rooms","exterior"]').  The whitelist of allowed tags lives
--     in includes/photo_library_store.php (knk_photo_tags()).
--
--     The table is auto-populated on first load by a scan of the
--     /assets/img/ directory — see knk_photo_library_scan().
--
-- 2.  An extra Find Us slot (slot 2 = the photo on the contact
--     card, currently hardcoded as nw_33.jpg).  The banner image
--     stays on slot 1.
--
-- Idempotent — re-running is a no-op.
-- ============================================================

SET NAMES utf8mb4;

-- ---------- photo_library ----------
CREATE TABLE IF NOT EXISTS photo_library (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    -- Path under /assets/img/, e.g. 'nw_33.jpg' or
    -- 'gallery-live/20260425-aa11.jpg' or 'slots/find_us-2-...'
    filename        VARCHAR(255)     NOT NULL,
    -- Where the file came from. Used to colour-code the gallery and
    -- to allow deletion of uploads (but never the seeded files).
    source          ENUM('seed','gallery_live','slot_upload') NOT NULL DEFAULT 'seed',
    -- JSON array of tag strings — see knk_photo_tags() for the
    -- whitelist. Empty array '[]' = untagged.
    tags            TEXT             NULL,
    -- Soft-hide so a photo doesn't show in the picker. The file
    -- stays on disk; toggling unhides it again.
    hidden          TINYINT(1)       NOT NULL DEFAULT 0,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_photo_library_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- find_us slot 2 ----------
-- The contact-card photo (nw_33.jpg) was previously hardcoded in
-- index.php. Adding it as a slot lets Simmo swap it from the admin.
INSERT IGNORE INTO photo_slots (section, slot_index, label, filename) VALUES
    ('find_us', 2, 'Contact photo', 'nw_33.jpg');

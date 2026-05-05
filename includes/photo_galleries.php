<?php
/*
 * KnK Inn — folder-backed photo galleries.
 *
 * Each "gallery" is just a subfolder under /assets/img/knk-260428/.
 * Drop photos in that folder, the lightbox picks them up. No DB,
 * no tagging, no admin step.
 *
 * Used by:
 *   - index.php   — tap rm_12 (Up Above section) opens the rooftop set
 *   - rooms.php   — tap "Ground Bar" tile opens the Sports Pub set
 *   - rooms.php   — tap "5th Floor Bar" tile opens the Wine Bar set
 *   - /rooms/<slug>.php — per-room subpages can opt in too
 *
 * Bulk imports were resized to ≤1600px on the long edge at JPEG 82,
 * so a typical gallery loads in well under a megabyte total.
 */

declare(strict_types=1);

if (!defined("KNK_GAL_BASE_DIR")) {
    define("KNK_GAL_BASE_DIR", __DIR__ . "/../assets/img/knk-260428");
}
if (!defined("KNK_GAL_BASE_URL")) {
    define("KNK_GAL_BASE_URL", "/assets/img/knk-260428");
}

/**
 * Returns an array of web-relative URLs for every photo in the
 * folder, sorted alphabetically (so the resize script's NN-numbered
 * filenames render in order). Empty array if the folder doesn't
 * exist or is empty.
 *
 * Folder slugs match the resize-script output:
 *   rooftop, sport-pub, wine-bar-floor-5, room-1 … room-9
 */
function knk_gallery_photos(string $slug): array {
    $slug = trim($slug);
    // Defensive: only allow [a-z0-9-]+. No "../" sneaking through.
    if ($slug === "" || !preg_match('/^[a-z0-9-]+$/', $slug)) return [];

    $dir = KNK_GAL_BASE_DIR . "/" . $slug;
    if (!is_dir($dir)) return [];

    $files = [];
    foreach (scandir($dir) as $f) {
        if ($f === "." || $f === "..") continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, ["jpg", "jpeg", "png", "webp"], true)) continue;
        $files[] = $f;
    }
    sort($files, SORT_NATURAL);

    $out = [];
    foreach ($files as $f) {
        $out[] = KNK_GAL_BASE_URL . "/" . $slug . "/" . rawurlencode($f);
    }
    return $out;
}

/**
 * Render the shared lightbox overlay markup once per page. Multiple
 * galleries on the same page reuse it — they just attach different
 * data-knk-gallery JSON arrays to their trigger elements.
 *
 * Call this once near the closing </body>. Safe to call zero times
 * on pages with no galleries.
 */
function knk_render_lightbox_markup(): void {
    static $rendered = false;
    if ($rendered) return;
    $rendered = true;
    ?>
    <div class="knk-lightbox" id="knkLightbox" aria-hidden="true" role="dialog">
      <button class="knk-lb-x"     id="knkLbX"    aria-label="Close gallery">×</button>
      <button class="knk-lb-prev"  id="knkLbPrev" aria-label="Previous photo">‹</button>
      <button class="knk-lb-next"  id="knkLbNext" aria-label="Next photo">›</button>
      <div    class="knk-lb-stage" id="knkLbStage">
        <img id="knkLbImg" alt="">
      </div>
      <div    class="knk-lb-counter" id="knkLbCounter">1 / 1</div>
    </div>
    <style>
      .knk-lightbox {
        position: fixed; inset: 0;
        background: rgba(8,5,3,0.95);
        display: none;
        align-items: center; justify-content: center;
        z-index: 9999;
        -webkit-tap-highlight-color: transparent;
        user-select: none;
      }
      .knk-lightbox.is-open { display: flex; }
      .knk-lb-stage {
        max-width: 92vw; max-height: 86vh;
        display: flex; align-items: center; justify-content: center;
      }
      .knk-lb-stage img {
        max-width: 100%; max-height: 86vh;
        object-fit: contain;
        border-radius: 4px;
        box-shadow: 0 8px 40px rgba(0,0,0,0.6);
      }
      .knk-lightbox button {
        position: absolute;
        background: rgba(201,170,113,0.18);
        border: 1px solid rgba(201,170,113,0.45);
        color: #f5e9d1;
        cursor: pointer;
        font-family: "Archivo Black", system-ui, sans-serif;
        line-height: 1;
        border-radius: 50%;
      }
      .knk-lb-x {
        top: 1rem; right: 1rem;
        width: 44px; height: 44px;
        font-size: 1.4rem;
      }
      .knk-lb-prev, .knk-lb-next {
        top: 50%; transform: translateY(-50%);
        width: 52px; height: 52px;
        font-size: 1.6rem;
      }
      .knk-lb-prev { left:  1rem; }
      .knk-lb-next { right: 1rem; }
      .knk-lightbox button:hover { background: rgba(201,170,113,0.32); }
      .knk-lb-counter {
        position: absolute;
        bottom: 1rem; left: 50%;
        transform: translateX(-50%);
        color: rgba(245,233,209,0.75);
        font-size: 0.85rem;
        letter-spacing: 0.06em;
        background: rgba(0,0,0,0.4);
        padding: 0.3rem 0.7rem;
        border-radius: 999px;
      }
      /* Click-to-open targets get a soft hover hint. */
      [data-knk-gallery] { cursor: zoom-in; }
    </style>
    <script>
    (function () {
      var box   = document.getElementById("knkLightbox");
      var imgEl = document.getElementById("knkLbImg");
      var cnt   = document.getElementById("knkLbCounter");
      var prevB = document.getElementById("knkLbPrev");
      var nextB = document.getElementById("knkLbNext");
      var xB    = document.getElementById("knkLbX");
      if (!box || !imgEl) return;

      var current = [];
      var idx = 0;

      function show(i) {
        if (current.length === 0) return;
        idx = (i + current.length) % current.length;
        imgEl.src = current[idx];
        cnt.textContent = (idx + 1) + " / " + current.length;
      }
      function open(arr) {
        if (!Array.isArray(arr) || arr.length === 0) return;
        current = arr;
        box.classList.add("is-open");
        box.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
        show(0);
      }
      function close() {
        box.classList.remove("is-open");
        box.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
        // Drop the heavy image from memory so flicking through
        // multiple galleries doesn't accumulate.
        imgEl.src = "";
      }

      // Wire any existing trigger element (data-knk-gallery contains a
      // JSON array of photo URLs).
      function wireTriggers(root) {
        var els = (root || document).querySelectorAll("[data-knk-gallery]");
        for (var i = 0; i < els.length; i++) {
          var el = els[i];
          if (el._knkWired) continue;
          el._knkWired = true;
          el.addEventListener("click", function (ev) {
            ev.preventDefault();
            try {
              var arr = JSON.parse(this.getAttribute("data-knk-gallery") || "[]");
              open(arr);
            } catch (e) {}
          });
        }
      }
      wireTriggers(document);
      // Expose for late-rendered nodes.
      window.knkLightboxRewire = wireTriggers;

      prevB.addEventListener("click", function (e) { e.stopPropagation(); show(idx - 1); });
      nextB.addEventListener("click", function (e) { e.stopPropagation(); show(idx + 1); });
      xB.addEventListener("click", close);

      // Background tap closes; keep clicks on the image itself open.
      box.addEventListener("click", function (e) {
        if (e.target === box || e.target === document.getElementById("knkLbStage")) close();
      });

      // Keyboard nav.
      document.addEventListener("keydown", function (e) {
        if (!box.classList.contains("is-open")) return;
        if (e.key === "Escape")     close();
        else if (e.key === "ArrowLeft")  show(idx - 1);
        else if (e.key === "ArrowRight") show(idx + 1);
      });

      // Swipe nav (touch).
      var startX = null;
      box.addEventListener("touchstart", function (e) {
        if (e.touches.length === 1) startX = e.touches[0].clientX;
      }, { passive: true });
      box.addEventListener("touchend", function (e) {
        if (startX === null) return;
        var endX = (e.changedTouches[0] || {}).clientX || startX;
        var dx = endX - startX;
        if (Math.abs(dx) > 50) show(idx + (dx < 0 ? 1 : -1));
        startX = null;
      });
    })();
    </script>
    <?php
}

/**
 * Nested-gallery awareness for the Photo Manager.
 *
 * Several photo-slot sections aren't actually the source of truth on
 * the public site — folder-backed nested galleries either override the
 * slot photos (fallback pattern: per-room subpages) or sit "behind"
 * them (cover pattern: rooms_common slots #2/#4/#6 open a multi-photo
 * lightbox on click). Without surfacing this, the slot tiles in the
 * admin UI look misleading: Simmo updates a slot, doesn't see the
 * change live, and assumes the upload broke.
 *
 * Returns metadata for a given (section, slot_index), or null if that
 * slot has no nested gallery wired up. Shape:
 *   [
 *     "kind"     => "cover" | "fallback",
 *     "slugs"    => [folder slugs under /assets/img/knk-260428/],
 *     "totals"   => [slug => photo count],
 *     "friendly" => [slug => friendly display name],
 *   ]
 *
 * "friendly" maps a slug to a name Simmo recognises ("Room 9",
 * "Sport Pub") so the Photo Manager UI can talk in plain English
 * without leaking server paths into the admin.
 *
 * "cover"    — slot's photo IS the cover; clicking it on the public
 *              site opens an N-photo gallery from the folder.
 * "fallback" — slot photos only render when the folder is empty.
 *              Currently populated folders take precedence.
 *
 * If $slot_index is 0, returns section-wide metadata (used for the
 * banner on per-room sections that applies to all slots).
 */
function knk_slot_nested_gallery(string $section, int $slot_index = 0): ?array {
    // Slug → friendly name. Used by the admin so Simmo sees "Room 9"
    // instead of "/assets/img/knk-260428/room-9/".
    static $friendly_names = [
        "sport-pub"        => "Sport Pub",
        "wine-bar-floor-5" => "Level 5 Bar",
        "rooftop"          => "Rooftop",
        "room-1"           => "Room 1",
        "room-2"           => "Room 2",
        "room-3"           => "Room 3",
        "room-4"           => "Room 4",
        "room-5"           => "Room 5",
        "room-6"           => "Room 6",
        "room-7"           => "Room 7",
        "room-8"           => "Room 8",
        "room-9"           => "Room 9",
    ];
    $build = function (string $kind, array $slugs) use ($friendly_names): array {
        $totals = [];
        $friendly = [];
        foreach ($slugs as $s) {
            $totals[$s]   = count(knk_gallery_photos($s));
            $friendly[$s] = $friendly_names[$s] ?? $s;
        }
        return ["kind" => $kind, "slugs" => $slugs, "totals" => $totals, "friendly" => $friendly];
    };

    // rooms_common: only slots #2 / #4 / #6 are gallery covers.
    if ($section === "rooms_common") {
        $covers = [2 => "sport-pub", 4 => "wine-bar-floor-5", 6 => "rooftop"];
        if ($slot_index === 0) {
            return $build("cover", array_values($covers));
        }
        if (!isset($covers[$slot_index])) return null;
        return $build("cover", [$covers[$slot_index]]);
    }

    // Per-room sections: every slot is a fallback for the folder set.
    // The mapping mirrors the consumers in /rooms/*.php.
    static $section_to_slugs = [
        "room_basic"    => ["room-9"],
        "room_nowindow" => ["room-1", "room-2"],
        "room_balcony"  => ["room-4", "room-6", "room-8"],
        "room_vip"      => ["room-3", "room-5", "room-7"],
    ];
    if (isset($section_to_slugs[$section])) {
        return $build("fallback", $section_to_slugs[$section]);
    }

    return null;
}

/**
 * Convenience: render a single trigger element wrapping arbitrary
 * markup, so a caller can do
 *   <?php echo knk_gallery_trigger('rooftop', '<img src="…">'); ?>
 * and get a clickable thumbnail. The wrapper is a <button> styled as
 * a transparent block, so no <a href="#"> URLs leak into history.
 */
function knk_gallery_trigger(string $slug, string $inner_html, array $attrs = []): string {
    $photos = knk_gallery_photos($slug);
    if (empty($photos)) return $inner_html; // No photos? Render plain.
    $json = htmlspecialchars(json_encode($photos), ENT_QUOTES, "UTF-8");

    $attr_html = "";
    foreach ($attrs as $k => $v) {
        $attr_html .= " " . htmlspecialchars($k, ENT_QUOTES, "UTF-8")
                   . '="' . htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8") . '"';
    }

    return '<button type="button" class="knk-gal-trigger"'
         . ' data-knk-gallery="' . $json . '"'
         . $attr_html . '>'
         . $inner_html
         . '</button>';
}

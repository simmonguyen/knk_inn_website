<?php
/*
 * KnK Inn — avatar store (profile Phase 2).
 *
 * Tiny pipeline for guest avatar photos:
 *
 *   1) browser uploads a multipart file via /api/avatar_upload.php
 *   2) knk_avatar_save_upload() validates the mime + dimensions,
 *      square-crops to 256x256 via GD, writes a JPEG into
 *      /uploads/avatars/, cleans up the previous avatar (if any)
 *      and updates guests.avatar_path with the new relative path.
 *   3) callers render the avatar via knk_avatar_url_for($email).
 *
 * Storage layout:
 *   web:  /uploads/avatars/<gid>-<rand>.jpg
 *   fs:   /<doc-root>/uploads/avatars/<gid>-<rand>.jpg
 *
 * The directory is auto-created on first use with 0775 perms. The
 * filename includes a random token so browsers can cache aggressively
 * and we still bust the cache on every re-upload.
 *
 * GD is required; if it's not loaded the upload fails gracefully
 * with a user-facing error (no PHP fatal). Matbao PHP 7.4 ships
 * with GD by default — but better to defend than 500 the page.
 *
 * Limits:
 *   - max upload: 6 MB (Matbao default upload_max_filesize is 8 MB)
 *   - accepted: image/jpeg, image/png, image/webp
 *   - output:   JPEG q=85, 256x256, sRGB, no metadata
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/guests_store.php";

/* ---------- paths ---------- */

if (!defined("KNK_AVATAR_DIR")) {
    /** Filesystem path to the avatars folder. */
    define("KNK_AVATAR_DIR", __DIR__ . "/../uploads/avatars");
}
if (!defined("KNK_AVATAR_URL_BASE")) {
    /** Public URL prefix for avatars. */
    define("KNK_AVATAR_URL_BASE", "/uploads/avatars");
}
if (!defined("KNK_AVATAR_MAX_BYTES")) {
    define("KNK_AVATAR_MAX_BYTES", 6 * 1024 * 1024);
}
if (!defined("KNK_AVATAR_OUT_PX")) {
    define("KNK_AVATAR_OUT_PX", 256);
}

/* =========================================================
   READ — URL helper
   ========================================================= */

/**
 * Web-relative URL for the avatar of $email. Returns "" if no
 * avatar is set, in which case the caller should fall back to the
 * gold-circle initial.
 *
 * If the caller already has the guests row in hand (common case
 * inside profile.php / bar.php), it can pass it as $guest_row to
 * avoid the DB hit.
 */
function knk_avatar_url_for(string $email, ?array $guest_row = null): string {
    $email = strtolower(trim($email));
    if ($email === "") return "";
    if ($guest_row === null) {
        $guest_row = knk_guest_find_by_email($email);
    }
    if (!$guest_row) return "";
    $p = (string)($guest_row["avatar_path"] ?? "");
    if ($p === "") return "";
    // avatar_path is stored as a web-relative path like
    // "/uploads/avatars/12-abc123.jpg" — return as-is. We trust the
    // value because only knk_avatar_save_upload() writes it.
    return $p;
}

/* =========================================================
   WRITE — upload + resize + save
   ========================================================= */

/**
 * Save a guest's uploaded avatar. Returns null on success, or a
 * short user-facing error string on failure.
 *
 * $file is one element of $_FILES, e.g. $_FILES["avatar"].
 * Expected keys: tmp_name, type, size, error.
 *
 * Validation:
 *   - $_FILES error code must be UPLOAD_ERR_OK
 *   - size must be 1..KNK_AVATAR_MAX_BYTES
 *   - mime must be image/jpeg | image/png | image/webp
 *   - GD must be available for the mime
 *
 * Side effects:
 *   - upserts the guests row for $email
 *   - writes a new JPEG into /uploads/avatars/
 *   - deletes the old avatar file (best-effort)
 *   - updates guests.avatar_path
 */
function knk_avatar_save_upload(string $email, array $file): ?string {
    $email = strtolower(trim($email));
    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "Sign in first.";
    }
    $err = (int)($file["error"] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return "That photo's too big — keep it under 6MB.";
        }
        if ($err === UPLOAD_ERR_NO_FILE) {
            return "Pick a photo first.";
        }
        return "Upload failed — try again.";
    }
    $size = (int)($file["size"] ?? 0);
    if ($size <= 0)                          return "That photo looks empty.";
    if ($size > KNK_AVATAR_MAX_BYTES)        return "That photo's too big — keep it under 6MB.";

    $tmp = (string)($file["tmp_name"] ?? "");
    if ($tmp === "" || !is_uploaded_file($tmp)) {
        return "Upload failed — try again.";
    }

    // Sniff the mime ourselves rather than trusting $file["type"].
    $info = @getimagesize($tmp);
    if (!$info || empty($info[2])) {
        return "That doesn't look like a photo we can use.";
    }
    $type = (int)$info[2]; // IMAGETYPE_JPEG / PNG / WEBP / etc.

    if (!function_exists("imagecreatetruecolor")) {
        return "Photo upload isn't available right now — sorry.";
    }

    // Decode the source image based on detected type.
    $src = null;
    if      ($type === IMAGETYPE_JPEG && function_exists("imagecreatefromjpeg")) {
        $src = @imagecreatefromjpeg($tmp);
    } elseif ($type === IMAGETYPE_PNG  && function_exists("imagecreatefrompng")) {
        $src = @imagecreatefrompng($tmp);
    } elseif ($type === IMAGETYPE_WEBP && function_exists("imagecreatefromwebp")) {
        $src = @imagecreatefromwebp($tmp);
    } else {
        return "Use a JPEG, PNG, or WebP photo.";
    }
    if (!$src) {
        return "We couldn't read that photo. Try a different one.";
    }

    $sw = imagesx($src);
    $sh = imagesy($src);
    if ($sw < 1 || $sh < 1) {
        imagedestroy($src);
        return "That photo's dimensions look wrong.";
    }

    // Square-crop from the centre, then scale to KNK_AVATAR_OUT_PX.
    $side = min($sw, $sh);
    $sx   = (int)(($sw - $side) / 2);
    $sy   = (int)(($sh - $side) / 2);

    $out = KNK_AVATAR_OUT_PX;
    $dst = imagecreatetruecolor($out, $out);
    if (!$dst) {
        imagedestroy($src);
        return "Photo processing failed — try again.";
    }
    // Fill with a neutral background so transparent PNGs don't go black.
    $bg = imagecolorallocate($dst, 244, 237, 224); // matches --cream
    imagefilledrectangle($dst, 0, 0, $out, $out, $bg);

    // High-quality bicubic resample.
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $out, $out, $side, $side);
    imagedestroy($src);

    // Make sure the target dir exists.
    if (!is_dir(KNK_AVATAR_DIR)) {
        @mkdir(KNK_AVATAR_DIR, 0775, true);
    }
    if (!is_dir(KNK_AVATAR_DIR) || !is_writable(KNK_AVATAR_DIR)) {
        imagedestroy($dst);
        return "Server can't store photos right now — ask staff.";
    }

    // Upsert the guests row so we have an id to embed in the filename.
    $gid = knk_guest_upsert($email);
    if (!$gid) {
        imagedestroy($dst);
        return "Couldn't find your profile to attach the photo.";
    }

    // Random suffix doubles as a cache-buster.
    try {
        $rand = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $rand = substr(hash("sha256", uniqid("", true) . microtime(true)), 0, 16);
    }
    $base     = $gid . "-" . $rand . ".jpg";
    $abs_path = KNK_AVATAR_DIR . "/" . $base;
    $web_path = KNK_AVATAR_URL_BASE . "/" . $base;

    if (!@imagejpeg($dst, $abs_path, 85)) {
        imagedestroy($dst);
        return "Couldn't save the photo — try again.";
    }
    imagedestroy($dst);

    // Best-effort: drop the old avatar file so we don't leak orphans.
    try {
        $stmt = knk_db()->prepare("SELECT avatar_path FROM guests WHERE id = ?");
        $stmt->execute([$gid]);
        $old = (string)($stmt->fetchColumn() ?: "");
        if ($old !== "") {
            knk_avatar_remove_file_for_path($old);
        }
        // Update guests.avatar_path.
        knk_db()->prepare("UPDATE guests SET avatar_path = ? WHERE id = ?")
                ->execute([$web_path, $gid]);
    } catch (Throwable $e) {
        error_log("knk_avatar_save_upload (db): " . $e->getMessage());
        // The new file is on disk and processed; even if the DB update
        // failed we don't want to leave the user with a broken state,
        // so try a second straight UPDATE without the SELECT prelude.
        try {
            knk_db()->prepare("UPDATE guests SET avatar_path = ? WHERE id = ?")
                    ->execute([$web_path, $gid]);
        } catch (Throwable $e2) {
            return "Saved the photo but couldn't link it. Try again.";
        }
    }
    return null;
}

/**
 * Wipe the avatar for $email — delete the file (best-effort) and
 * NULL out the column. Used by the "remove photo" UI control.
 */
function knk_avatar_clear(string $email): bool {
    $email = strtolower(trim($email));
    if ($email === "") return false;
    try {
        $row = knk_guest_find_by_email($email);
        if (!$row) return true;
        $old = (string)($row["avatar_path"] ?? "");
        if ($old !== "") {
            knk_avatar_remove_file_for_path($old);
        }
        knk_db()->prepare("UPDATE guests SET avatar_path = NULL WHERE id = ?")
                ->execute([(int)$row["id"]]);
        return true;
    } catch (Throwable $e) {
        error_log("knk_avatar_clear: " . $e->getMessage());
        return false;
    }
}

/**
 * Internal — delete the file at the given web-relative path,
 * confined to KNK_AVATAR_URL_BASE so a corrupt avatar_path can't
 * trick us into rm-ing arbitrary files.
 */
function knk_avatar_remove_file_for_path(string $web_path): void {
    $web_path = (string)$web_path;
    $base     = KNK_AVATAR_URL_BASE . "/";
    if (strpos($web_path, $base) !== 0) return;
    $rel = substr($web_path, strlen($base));
    // No path traversal.
    if ($rel === "" || strpos($rel, "..") !== false || strpos($rel, "/") !== false) return;
    $abs = KNK_AVATAR_DIR . "/" . $rel;
    if (is_file($abs)) {
        @unlink($abs);
    }
}

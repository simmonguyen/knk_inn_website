<?php
/*
 * KnK Inn — settings store (V2 Phase 7).
 *
 * Thin read/write layer over the `settings` table. Values are stored
 * as strings; helpers coerce them to bool / int on the way out so
 * callers don't have to do the "1"/"0" → true/false dance themselves.
 *
 * Known keys (seeded in 001_initial_schema.sql):
 *   marketing_reminders_enabled        — '0' | '1'   (default '1')
 *   marketing_reminder_days_before     — '7'         (integer string)
 *   owner_order_notifications_enabled  — '0' | '1'   (default '1')
 *   owner_notification_email           — email or '' (blank → fallback
 *                                        to owner user's email)
 *   schema_version                     — '1'
 *   sent_marketing_reminders           — JSON blob {"<fixture-key>":
 *                                        "<iso timestamp>", ...}
 *                                        written by cron/reminders.php
 *
 * All reads are memoised per-request so a page can call knk_setting()
 * repeatedly without hammering the DB. Writes invalidate the cache
 * so the next read picks up the change in the same request.
 */

declare(strict_types=1);

require_once __DIR__ . "/db.php";

/**
 * Internal: load-or-return the per-request cache of all settings.
 * Kept as a static inside this function so both knk_setting() and
 * knk_setting_cache_reset() share the same backing array.
 */
function &knk_settings_cache(): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $stmt = knk_db()->query("SELECT `key`, value FROM settings");
        foreach ($stmt as $row) {
            $cache[(string)$row["key"]] = $row["value"];
        }
    }
    return $cache;
}

/** Force the next knk_setting() call to re-query the DB. */
function knk_setting_cache_reset(): void {
    $cache = &knk_settings_cache();
    // Re-query into the same reference so everyone sees the new data.
    $fresh = [];
    $stmt = knk_db()->query("SELECT `key`, value FROM settings");
    foreach ($stmt as $row) {
        $fresh[(string)$row["key"]] = $row["value"];
    }
    $cache = $fresh;
}

/**
 * Get a setting value as-is (string or null if missing / NULL in DB).
 */
function knk_setting(string $key, ?string $default = null): ?string {
    $cache = knk_settings_cache();
    if (!array_key_exists($key, $cache)) return $default;
    $v = $cache[$key];
    return $v === null ? $default : $v;
}

/** Bool-coerced. Treats '1','true','yes','on' as true, everything else false. */
function knk_setting_bool(string $key, bool $default = false): bool {
    $v = knk_setting($key);
    if ($v === null) return $default;
    $v = strtolower(trim($v));
    return in_array($v, ["1", "true", "yes", "on"], true);
}

/** Integer-coerced. Falls back to $default if missing / non-numeric. */
function knk_setting_int(string $key, int $default = 0): int {
    $v = knk_setting($key);
    if ($v === null || !is_numeric($v)) return $default;
    return (int)$v;
}

/**
 * Write a setting. Upserts by key. Passing null clears the value.
 * Records the staff user for audit if supplied.
 *
 * Invalidates the cache so same-request reads see the new value.
 */
function knk_setting_set(string $key, ?string $value, ?int $updated_by = null): void {
    $sql = "INSERT INTO settings (`key`, value, updated_by)
            VALUES (:k, :v, :u)
            ON DUPLICATE KEY UPDATE value = VALUES(value),
                                    updated_by = VALUES(updated_by),
                                    updated_at = CURRENT_TIMESTAMP";
    $stmt = knk_db()->prepare($sql);
    $stmt->execute([
        ":k" => $key,
        ":v" => $value,
        ":u" => $updated_by,
    ]);
    knk_setting_cache_reset();
}

/* ---------- sent-reminder tracking ----------
 *
 * Stored under the `sent_marketing_reminders` settings key as a JSON blob:
 *   { "<fixture-key>": "<ISO-timestamp-sent-at>", ... }
 *
 * Fixture key combines kickoff date + sport + a short hash of the title,
 * so the same match can't trigger an email twice even if the list is
 * shuffled.
 */

/** Return the full map of sent reminders (fixture-key => iso timestamp). */
function knk_reminders_get_sent(): array {
    $raw = knk_setting("sent_marketing_reminders", "");
    if ($raw === null || $raw === "") return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    return $decoded;
}

/** Has this fixture already been emailed? */
function knk_reminders_already_sent(string $fixture_key): bool {
    $map = knk_reminders_get_sent();
    return isset($map[$fixture_key]);
}

/**
 * Mark this fixture as emailed. Also prunes entries older than 90 days
 * so the JSON blob doesn't grow forever.
 */
function knk_reminders_mark_sent(string $fixture_key): void {
    $map = knk_reminders_get_sent();
    $map[$fixture_key] = date("c");

    $cutoff_ts = time() - (90 * 24 * 60 * 60);
    foreach ($map as $k => $sent_at) {
        $ts = strtotime((string)$sent_at);
        if ($ts === false || $ts < $cutoff_ts) unset($map[$k]);
    }

    knk_setting_set("sent_marketing_reminders", json_encode($map));
}

/** Stable key for a fixture, for de-dupe. "YYYY-MM-DD|sport|hash". */
function knk_reminders_fixture_key(array $fx): string {
    $sport = (string)($fx["sport"] ?? "");
    $title = (string)($fx["title"] ?? "");
    $iso   = (string)($fx["kickoff"] ?? "");
    $date  = $iso !== "" ? substr($iso, 0, 10) : "nodate";
    $hash  = substr(sha1($title), 0, 10);
    $sport_slug = strtolower((string)preg_replace("/[^a-z0-9]+/i", "-", $sport));
    return $date . "|" . $sport_slug . "|" . $hash;
}

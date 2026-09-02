<?php
/**
 * Legacy Meta Migration
 *
 * The plugin historically stored event metadata under the `_icob_` prefix.
 * As of 1.0.0 all data lives under the canonical `_awecal_` prefix and the
 * dual-prefix read/query compatibility was removed. This migration runs
 * once (guarded by the `awesome_calendar_events_meta_migration_version`
 * option, so DB restores and file-level updates are also covered) and
 * renames every `_icob_` row to its `_awecal_` equivalent.
 *
 * Values are normalized with the same rules the meta box applies on save
 * (see Awesome_Calendar_Events_Meta_Migration::normalize_legacy_value()),
 * and rows are only created when the canonical key is not already present
 * (canonical data always wins). Migrated `_icob_` rows are deleted.
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Calendar_Events_Meta_Migration {

    const MIGRATION_OPTION = 'awesome_calendar_events_meta_migration_version';
    const BATCH_SIZE = 500;

    /**
     * Run the migration when the stored marker option does not match the
     * current plugin version.
     *
     * @return int Number of meta rows renamed (0 when already migrated).
     */
    public static function maybe_migrate() {
        global $wpdb;

        if (get_option(self::MIGRATION_OPTION) === AWESOME_CALENDAR_EVENTS_VERSION) {
            // The marker only proves the migration ran for this version once.
            // Legacy rows can reappear afterwards (DB restores, legacy data
            // written by other tools), so do a cheap indexed existence check
            // and self-heal when any `_icob_` rows are still around.
            $remaining = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 1",
                $wpdb->esc_like(AWECAL_LEGACY_META_PREFIX) . '%'
            ));
            if ($remaining === null) {
                return 0;
            }
        }
        $renamed = self::run();
        update_option(self::MIGRATION_OPTION, AWESOME_CALENDAR_EVENTS_VERSION);
        return $renamed;
    }

    /**
     * Rename legacy `_icob_` post meta to the canonical `_awecal_` prefix.
     *
     * Processes the rows in batches; rows are deleted as they are handled
     * so the loop always keeps scanning from the top.
     *
     * @return int Number of meta rows renamed.
     */
    public static function run() {
        global $wpdb;

        $renamed = 0;

        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_id, post_id, meta_key, meta_value
                 FROM {$wpdb->postmeta}
                 WHERE meta_key LIKE %s
                 ORDER BY meta_id
                 LIMIT %d",
                $wpdb->esc_like(AWECAL_LEGACY_META_PREFIX) . '%',
                self::BATCH_SIZE
            ), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                if (self::migrate_row($row)) {
                    $renamed++;
                }
            }
        }

        return $renamed;
    }

    /**
     * Migrate a single postmeta row.
     *
     * @param array $row Raw postmeta row (meta_id, post_id, meta_key, meta_value).
     * @return bool True when a value was copied to a canonical key.
     */
    private static function migrate_row($row) {
        $post_id = (int) $row['post_id'];
        $suffix = substr($row['meta_key'], strlen(AWECAL_LEGACY_META_PREFIX));
        $wpdb = $GLOBALS['wpdb'];

        if ($suffix === '') {
            $wpdb->delete($wpdb->postmeta, ['meta_id' => (int) $row['meta_id']]);
            return false;
        }

        $canonical_key = AWECAL_META_PREFIX . $suffix;

        // Canonical data always wins; the legacy row is removed either way.
        $copied = false;
        if (!metadata_exists('post', $post_id, $canonical_key)) {
            $value = self::normalize_legacy_value($suffix, maybe_unserialize($row['meta_value']));
            update_post_meta($post_id, $canonical_key, $value);
            $copied = true;
        }

        $wpdb->delete($wpdb->postmeta, ['meta_id' => (int) $row['meta_id']]);
        return $copied;
    }

    /**
     * Normalize a legacy value with the same rules the meta box applies
     * on save. Unknown suffixes are copied verbatim.
     *
     * @param string $suffix Meta key suffix (without either prefix).
     * @param mixed  $value  Raw (unserialized) legacy value.
     * @return mixed
     */
    public static function normalize_legacy_value($suffix, $value) {
        switch ($suffix) {
            case 'event_date':
            case 'event_recurrence_end_date':
                $ts = strtotime(trim((string) $value));
                return $ts ? gmdate('Y-m-d', $ts) : '';
            case 'event_date_enabled':
                return empty($value) ? 0 : 1;
            case 'event_recurrence_type':
                return in_array((string) $value, ['none', 'daily', 'weekly', 'monthly', 'yearly'], true)
                    ? (string) $value
                    : 'none';
            case 'event_recurrence_interval':
                return max(1, intval($value));
            case 'event_recurrence_weekdays':
                if (is_array($value)) {
                    $ints = array_values(array_intersect(array_map('intval', $value), range(0, 6)));
                    sort($ints);
                    return '[' . implode(',', $ints) . ']';
                }
                $parsed = Awesome_Calendar_Events_Event_Meta::parse_weekday_string((string) $value);
                return '[' . implode(',', $parsed) . ']';
            case 'event_recurrence_end_type':
                return in_array((string) $value, ['none', 'date', 'count'], true)
                    ? (string) $value
                    : 'none';
            case 'event_recurrence_count':
                return intval($value);
            case 'event_start_time':
                $start_time = trim((string) $value);
                return preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $start_time) ? $start_time : '';
            case 'event_custom_time_label':
            case 'event_location':
                return sanitize_text_field((string) $value);
            case 'announcement':
                // Legacy storage was a boolean flag; '0' (or empty) means off.
                $text = sanitize_text_field((string) $value);
                return $text === '0' ? '' : $text;
            case 'announcement_expiration':
                return Awesome_Calendar_Events_Event_Meta::normalize_datetime_input($value);
            case 'event_duration_hours':
                $hours = floatval($value);
                return $hours < 0 ? 0 : $hours;
            case 'event_duration_minutes':
                return max(0, intval($value));
            default:
                return $value;
        }
    }
}

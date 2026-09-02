<?php
/**
 * Post Meta Helper
 *
 * All event metadata uses the canonical `_awecal_` prefix. Sites upgrading
 * from the historical `_icob_` prefix are migrated once on install by
 * Awesome_Calendar_Events_Meta_Migration (see class-meta-migration.php);
 * no runtime legacy-prefix fallback exists anymore.
 *
 * Canonical prefixes:
 *  - AWECAL_META_PREFIX ('_awecal_')       — the only prefix written and read
 *  - AWECAL_LEGACY_META_PREFIX ('_icob_')  — historical prefix, used only by
 *                                            the one-time migration
 */

if (!defined('ABSPATH')) { exit; }

if (!defined('AWECAL_META_PREFIX')) {
    define('AWECAL_META_PREFIX', '_awecal_');
}

if (!defined('AWECAL_LEGACY_META_PREFIX')) {
    define('AWECAL_LEGACY_META_PREFIX', '_icob_');
}

/**
 * Map a legacy (or bare) event meta key to its canonical `_awecal_` key.
 *
 * Keys that already use the `_awecal_` prefix are returned unchanged.
 * Keys using the legacy `_icob_` prefix are translated; anything else is
 * assumed to be a bare suffix (e.g. 'event_date') and gets the new prefix.
 */
function awecal_meta_key($key) {
    $key = (string) $key;
    if (strpos($key, AWECAL_META_PREFIX) === 0) {
        return $key;
    }
    if (strpos($key, AWECAL_LEGACY_META_PREFIX) === 0) {
        return AWECAL_META_PREFIX . substr($key, strlen(AWECAL_LEGACY_META_PREFIX));
    }
    return AWECAL_META_PREFIX . ltrim($key, '_');
}

/**
 * Drop-in wrapper for get_post_meta() for event meta.
 *
 * Reads the requested key as-is from post meta. All event keys use the
 * canonical `_awecal_` prefix; legacy `_icob_` data no longer exists after
 * the one-time migration.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Meta key (e.g. '_awecal_event_date').
 * @param bool   $single  Whether to return a single value. Default true.
 * @return mixed
 */
function awecal_get_post_meta($post_id, $key, $single = true) {
    return get_post_meta($post_id, (string) $key, $single);
}

/**
 * Meta query clause matching posts with the event-date flag enabled.
 *
 * @return array
 */
function awecal_event_date_enabled_meta_query() {
    return [
        'key'     => AWECAL_META_PREFIX . 'event_date_enabled',
        'value'   => '1',
        'compare' => '=',
    ];
}

/**
 * Meta query clause matching posts that have a non-empty event date.
 *
 * @return array
 */
function awecal_event_date_present_meta_query() {
    return [
        'key'     => AWECAL_META_PREFIX . 'event_date',
        'compare' => '!=',
        'value'   => '',
    ];
}

/**
 * Meta query clause filtering posts by event date range.
 *
 * Event dates are stored as Y-m-d strings (site timezone), which compare
 * correctly lexicographically, so a CHAR BETWEEN is used instead of a
 * DATE comparison.
 *
 * @param string $from Inclusive lower bound (Y-m-d). Empty string = unbounded.
 * @param string $to   Inclusive upper bound (Y-m-d). Empty string = unbounded.
 * @return array
 */
function awecal_event_date_range_meta_query($from = '', $to = '') {
    $from = (string) $from;
    $to = (string) $to;

    if ($from !== '' && $to !== '') {
        $value = [$from, $to];
        $compare = 'BETWEEN';
    } elseif ($from !== '') {
        $value = $from;
        $compare = '>=';
    } else {
        $value = $to;
        $compare = '<=';
    }

    return [
        'key'     => AWECAL_META_PREFIX . 'event_date',
        'value'   => $value,
        'compare' => $compare,
        'type'    => 'CHAR',
    ];
}

/**
 * Meta query clause matching posts flagged as announcements.
 *
 * Announcement meta is a non-empty text string. Legacy boolean storage
 * wrote '1'/'0', so '0' is excluded as well. Value comparisons only
 * match posts that actually carry the key; a missing key means the post
 * is not an announcement.
 *
 * @return array
 */
function awecal_event_announcement_enabled_meta_query() {
    return [
        'relation' => 'AND',
        [
            'key'     => AWECAL_META_PREFIX . 'announcement',
            'value'   => '',
            'compare' => '!=',
            'type'    => 'CHAR',
        ],
        [
            'key'     => AWECAL_META_PREFIX . 'announcement',
            'value'   => '0',
            'compare' => '!=',
            'type'    => 'CHAR',
        ],
    ];
}

/**
 * Meta query clause filtering posts by announcement expiration datetime.
 *
 * @param string $compare WP meta compare operator (e.g. '>=', '<=', 'BETWEEN').
 * @param string|array $value Value(s) to compare against.
 * @return array
 */
function awecal_event_announcement_expiration_meta_query($compare, $value) {
    return [
        'key'     => AWECAL_META_PREFIX . 'announcement_expiration',
        'value'   => $value,
        'compare' => $compare,
        'type'    => 'CHAR',
    ];
}

/**
 * Meta query clause excluding posts whose recurrence has already ended
 * on or before the given reference date.
 *
 * A post matches when the recurrence end date is absent (NOT EXISTS),
 * empty (the metabox stores '' when no end date is set), or is on/after
 * the reference date. The OR grouping is required because a plain value
 * comparison only matches posts that actually have the meta key, which
 * would wrongly exclude events without an end date.
 *
 * @param string $reference_date Y-m-d reference date.
 * @return array
 */
function awecal_event_recurrence_not_ended_meta_query($reference_date) {
    return [
        'relation' => 'OR',
        [
            'key'     => AWECAL_META_PREFIX . 'event_recurrence_end_date',
            'compare' => 'NOT EXISTS',
        ],
        [
            'key'     => AWECAL_META_PREFIX . 'event_recurrence_end_date',
            'value'   => '',
            'compare' => '=',
        ],
        [
            'key'     => AWECAL_META_PREFIX . 'event_recurrence_end_date',
            'value'   => $reference_date,
            'compare' => '>=',
            'type'    => 'CHAR',
        ],
    ];
}

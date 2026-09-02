<?php
/**
 * Post Meta Helper
 *
 * The plugin historically stored event metadata under the `_icob_` prefix.
 * New posts write under the `_awecal_` prefix while legacy `_icob_` data
 * remains readable for backward compatibility. The helpers in this file
 * implement that dual-prefix read strategy.
 *
 * Canonical prefixes:
 *  - AWECAL_META_PREFIX ('_awecal_')         — written by this plugin from now on
 *  - AWECAL_LEGACY_META_PREFIX ('_icob_')    — read-only fallback for existing posts
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
 * Prefix-aware drop-in replacement for get_post_meta() for event meta.
 *
 * This is the only place in the plugin that knows about the legacy
 * `_icob_` prefix. Callers always reference canonical `_awecal_` keys;
 * when the canonical key has no stored value the helper transparently
 * falls back to the legacy `_icob_` equivalent so pre-migration posts
 * keep working. Keys that use neither prefix are read as-is (custom
 * meta keys are untouched).
 *
 * @param int    $post_id Post ID.
 * @param string $key     Meta key (canonical prefix, e.g. '_awecal_event_date').
 * @param bool   $single  Whether to return a single value. Default true.
 * @return mixed
 */
function awecal_get_post_meta($post_id, $key, $single = true) {
    $key = (string) $key;
    if (strpos($key, AWECAL_META_PREFIX) === 0) {
        if (metadata_exists('post', $post_id, $key)) {
            return get_post_meta($post_id, $key, $single);
        }
        // Canonical key has no stored value: fall back to legacy data.
        $legacy_key = AWECAL_LEGACY_META_PREFIX . substr($key, strlen(AWECAL_META_PREFIX));
        return get_post_meta($post_id, $legacy_key, $single);
    }
    if (strpos($key, AWECAL_LEGACY_META_PREFIX) === 0) {
        // Legacy key requested directly: prefer migrated data if present.
        $new_key = awecal_meta_key($key);
        if (metadata_exists('post', $post_id, $new_key)) {
            return get_post_meta($post_id, $new_key, $single);
        }
        return get_post_meta($post_id, $key, $single);
    }
    return get_post_meta($post_id, $key, $single);
}

/**
 * Meta query clause matching posts with the event-date flag enabled,
 * regardless of which prefix their meta was stored under.
 *
 * @return array
 */
function awecal_event_date_enabled_meta_query() {
    return [
        'relation' => 'OR',
        [
            'key'     => awecal_meta_key('_icob_event_date_enabled'),
            'value'   => '1',
            'compare' => '=',
        ],
        [
            'key'     => '_icob_event_date_enabled',
            'value'   => '1',
            'compare' => '=',
        ],
    ];
}

/**
 * Meta query clause matching posts that have a non-empty event date,
 * regardless of which prefix their meta was stored under.
 *
 * @return array
 */
function awecal_event_date_present_meta_query() {
    return [
        'relation' => 'OR',
        [
            'key'     => awecal_meta_key('_icob_event_date'),
            'compare' => '!=',
            'value'   => '',
        ],
        [
            'key'     => '_icob_event_date',
            'compare' => '!=',
            'value'   => '',
        ],
    ];
}

/**
 * Meta query clause filtering posts by event date range.
 *
 * Event dates are stored as Y-m-d strings (site timezone), which compare
 * correctly lexicographically, so a CHAR BETWEEN is used instead of a
 * DATE comparison. Matches both canonical and legacy keys.
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

    $canonical_key = awecal_meta_key('_icob_event_date');
    $legacy_key = AWECAL_LEGACY_META_PREFIX . substr($canonical_key, strlen(AWECAL_META_PREFIX));

    return [
        'relation' => 'OR',
        [
            'key'     => $canonical_key,
            'value'   => $value,
            'compare' => $compare,
            'type'    => 'CHAR',
        ],
        [
            'key'     => $legacy_key,
            'value'   => $value,
            'compare' => $compare,
            'type'    => 'CHAR',
        ],
    ];
}

/**
 * Meta query clause matching posts flagged as announcements,
 * regardless of which prefix their meta was stored under.
 *
 * Announcement meta is a non-empty text string. Legacy boolean storage
 * wrote '1'/'0', so '0' is excluded as well. Value comparisons only
 * match posts that actually carry the key; a missing key means the post
 * is not an announcement.
 *
 * @return array
 */
function awecal_event_announcement_enabled_meta_query() {
    $non_empty_clause = static function ($key) {
        return [
            'relation' => 'AND',
            [
                'key'     => $key,
                'value'   => '',
                'compare' => '!=',
                'type'    => 'CHAR',
            ],
            [
                'key'     => $key,
                'value'   => '0',
                'compare' => '!=',
                'type'    => 'CHAR',
            ],
        ];
    };

    $canonical_key = awecal_meta_key('_icob_announcement');
    $legacy_key = AWECAL_LEGACY_META_PREFIX . substr($canonical_key, strlen(AWECAL_META_PREFIX));

    return [
        'relation' => 'OR',
        $non_empty_clause($canonical_key),
        $non_empty_clause($legacy_key),
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
    $canonical_key = awecal_meta_key('_icob_announcement_expiration');
    $legacy_key = AWECAL_LEGACY_META_PREFIX . substr($canonical_key, strlen(AWECAL_META_PREFIX));

    return [
        'relation' => 'OR',
        [
            'key'     => $canonical_key,
            'value'   => $value,
            'compare' => $compare,
            'type'    => 'CHAR',
        ],
        [
            'key'     => $legacy_key,
            'value'   => $value,
            'compare' => $compare,
            'type'    => 'CHAR',
        ],
    ];
}

/**
 * Meta query clause excluding posts whose recurrence has already ended
 * on or before the given reference date.
 *
 * A post matches when, for BOTH meta keys (canonical and legacy), the
 * recurrence end date is absent (NOT EXISTS), empty (the metabox stores
 * '' when no end date is set), or is on/after the reference date. The
 * per-key OR grouping is required because a plain value comparison only
 * matches posts that actually have the meta key, which would wrongly
 * exclude events without an end date.
 *
 * @param string $reference_date Y-m-d reference date.
 * @return array
 */
function awecal_event_recurrence_not_ended_meta_query($reference_date) {
    $canonical_key = awecal_meta_key('_icob_event_recurrence_end_date');
    $legacy_key = AWECAL_LEGACY_META_PREFIX . substr($canonical_key, strlen(AWECAL_META_PREFIX));

    $group_for = function($key) use ($reference_date) {
        return [
            'relation' => 'OR',
            [
                'key'     => $key,
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => $key,
                'value'   => '',
                'compare' => '=',
            ],
            [
                'key'     => $key,
                'value'   => $reference_date,
                'compare' => '>=',
                'type'    => 'CHAR',
            ],
        ];
    };

    return [
        'relation' => 'AND',
        $group_for($canonical_key),
        $group_for($legacy_key),
    ];
}

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

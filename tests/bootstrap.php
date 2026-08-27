<?php
/**
 * PHPUnit bootstrap for awesome-calendar-events tests.
 *
 * Loads the plugin includes with a minimal in-memory WordPress shim so tests
 * can run without a database or a full WordPress install. The plugin code
 * itself lives in wp-content/plugins/awesome-calendar-events/ and is loaded
 * read-only; nothing under the plugin directory is modified by the tests.
 */

error_reporting(E_ALL);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('AWESOME_CALENDAR_EVENTS_VERSION')) {
    define('AWESOME_CALENDAR_EVENTS_VERSION', '1.0.0-test');
}

if (!defined('AWESOME_CALENDAR_EVENTS_PLUGIN_DIR')) {
    define('AWESOME_CALENDAR_EVENTS_PLUGIN_DIR', dirname(__DIR__) . '/wp-content/plugins/awesome-calendar-events/');
}

$GLOBALS['__awecal_meta'] = [];       // [post_id][meta_key] => mixed
$GLOBALS['__awecal_registered_meta'] = []; // [meta_key] => args
$GLOBALS['__awecal_posts'] = [];      // [post_id] => [title, content, excerpt]

/* -------------------------------------------------------------------------
 * Test helpers
 * ---------------------------------------------------------------------- */

function awecal_test_create_post($id, $title = 'Test Event', $content = '') {
    $GLOBALS['__awecal_posts'][$id] = [
        'title' => $title,
        'content' => $content,
        'excerpt' => '',
    ];
    return (object) [
        'ID' => $id,
        'post_title' => $title,
        'post_content' => $content,
        'post_excerpt' => '',
    ];
}

function awecal_test_reset_meta() {
    $GLOBALS['__awecal_meta'] = [];
    $GLOBALS['__awecal_registered_meta'] = [];
    $GLOBALS['__awecal_posts'] = [];
}

/* -------------------------------------------------------------------------
 * Meta API stubs (in-memory store, single-value semantics)
 * ---------------------------------------------------------------------- */

function get_post_meta($post_id, $key, $single = false) {
    if ($single) {
        return $GLOBALS['__awecal_meta'][$post_id][$key] ?? '';
    }
    return isset($GLOBALS['__awecal_meta'][$post_id][$key]) ? [$GLOBALS['__awecal_meta'][$post_id][$key]] : [];
}

function update_post_meta($post_id, $key, $value) {
    $GLOBALS['__awecal_meta'][$post_id][$key] = $value;
    return true;
}

function add_post_meta($post_id, $key, $value) {
    return update_post_meta($post_id, $key, $value);
}

function delete_post_meta($post_id, $key) {
    unset($GLOBALS['__awecal_meta'][$post_id][$key]);
    return true;
}

function metadata_exists($type, $post_id, $key) {
    return isset($GLOBALS['__awecal_meta'][$post_id][$key]);
}

function register_post_meta($post_type, $meta_key, $args) {
    $GLOBALS['__awecal_registered_meta'][$meta_key] = $args;
    return true;
}

/* -------------------------------------------------------------------------
 * Hook / capability stubs
 * ---------------------------------------------------------------------- */

function add_action($hook, $callback = null, $priority = 10, $args = 1) { return true; }
function add_filter($hook, $callback = null, $priority = 10, $args = 1) { return true; }
function apply_filters($hook, $value) { return $value; }
function register_activation_hook($file, $callback) { return true; }
function register_deactivation_hook($file, $callback) { return true; }
function current_user_can($cap, ...$args) { return true; }

/* -------------------------------------------------------------------------
 * Sanitization / formatting stubs
 * ---------------------------------------------------------------------- */

function sanitize_text_field($str) {
    return trim(strip_tags((string) $str));
}

function wp_unslash($value) { return $value; }
function wp_verify_nonce($nonce, $action = -1) { return 1; }
function absint($value) { return abs((int) $value); }

/* -------------------------------------------------------------------------
 * Options / locale / time stubs
 * ---------------------------------------------------------------------- */

function get_option($name, $default = false) {
    $options = [
        'date_format' => 'F j, Y',
        'start_of_week' => 1,
        'timezone_string' => '',
        'gmt_offset' => 0,
    ];
    return $options[$name] ?? $default;
}

function current_time($type) {
    if ($type === 'timestamp') {
        return time();
    }
    return date($type === 'mysql' ? 'Y-m-d H:i:s' : $type);
}

function date_i18n($format, $timestamp = null) {
    return date($format, $timestamp ?: time());
}

function __($text, $domain = 'default') { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_html_e($text) { echo esc_html($text); }
function esc_attr_e($text) { echo esc_attr($text); }

/* -------------------------------------------------------------------------
 * Misc WP API stubs
 * ---------------------------------------------------------------------- */

function home_url($path = '') { return 'https://example.com' . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_strip_all_tags($text) { return trim(strip_tags((string) $text)); }
function esc_url_raw($url) { return $url; }
function get_the_title($post = null) {
    $id = is_object($post) ? $post->ID : (int) $post;
    if (isset($GLOBALS['__awecal_posts'][$id]['title'])) {
        return $GLOBALS['__awecal_posts'][$id]['title'];
    }
    if (is_object($post) && isset($post->post_title)) {
        return $post->post_title;
    }
    return '';
}
function get_permalink($post = null) {
    $id = is_object($post) ? $post->ID : (int) $post;
    return 'https://example.com/?p=' . $id;
}
function get_post($post = null) {
    $id = is_object($post) ? $post->ID : (int) $post;
    if (!isset($GLOBALS['__awecal_posts'][$id])) {
        return null;
    }
    $data = $GLOBALS['__awecal_posts'][$id];
    return (object) [
        'ID' => $id,
        'post_title' => $data['title'],
        'post_content' => $data['content'],
        'post_excerpt' => $data['excerpt'],
    ];
}

/* -------------------------------------------------------------------------
 * Load plugin includes (read-only)
 * ---------------------------------------------------------------------- */

$plugin_includes = [
    'includes/class-meta-helper.php',
    'includes/class-event-meta.php',
    'includes/class-ics-generator.php',
];

foreach ($plugin_includes as $relative) {
    require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . $relative;
}

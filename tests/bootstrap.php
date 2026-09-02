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
$GLOBALS['__awecal_options'] = [];    // option overrides
$GLOBALS['__awecal_transients'] = []; // transient store
$GLOBALS['__awecal_query_posts'] = []; // posts returned by the WP_Query stub
$GLOBALS['__awecal_last_query_args'] = null; // args captured by the WP_Query stub

date_default_timezone_set('UTC');

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

/* -------------------------------------------------------------------------
 * Test helpers
 * ---------------------------------------------------------------------- */

function awecal_test_create_post($id, $title = 'Test Event', $content = '', $post_date_gmt = '2026-08-20 14:05:00') {
    $GLOBALS['__awecal_posts'][$id] = [
        'title' => $title,
        'content' => $content,
        'excerpt' => '',
        'post_date_gmt' => $post_date_gmt,
        'thumbnail_url' => null,
        'thumbnail_id' => 0,
        'terms' => [],
    ];
    return (object) [
        'ID' => $id,
        'post_title' => $title,
        'post_content' => $content,
        'post_excerpt' => '',
        'post_date_gmt' => $post_date_gmt,
    ];
}

function awecal_test_reset_meta() {
    $GLOBALS['__awecal_meta'] = [];
    $GLOBALS['__awecal_registered_meta'] = [];
    $GLOBALS['__awecal_posts'] = [];
    $GLOBALS['__awecal_options'] = [];
    $GLOBALS['__awecal_transients'] = [];
    $GLOBALS['__awecal_query_posts'] = [];
    $GLOBALS['__awecal_last_query_args'] = null;
    $GLOBALS['__awecal_filters'] = [];
}

/* -------------------------------------------------------------------------
 * Error / REST / query stubs
 * ---------------------------------------------------------------------- */

class WP_Error {
    public $code;
    public $message;
    public $data;
    public function __construct($code = '', $message = '', $data = []) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error($thing) {
    return $thing instanceof WP_Error;
}

class WP_REST_Server {
    const READABLE = 'GET';
}

class AWECAL_Test_REST_Response {
    public $data;
    public $headers = [];
    public function __construct($data) { $this->data = $data; }
    public function header($name, $value) { $this->headers[$name] = $value; }
}

function rest_ensure_response($data) {
    return new AWECAL_Test_REST_Response($data);
}

function wp_hash($data, $scheme = 'auth') {
    return hash_hmac('md5', (string) $data, 'awecal-test-salt');
}

/**
 * In-memory WP_Query stub: captures the args (so tests can assert the
 * SQL-level meta_query / tax_query clauses) and returns the posts placed
 * in $GLOBALS['__awecal_query_posts'], honoring posts_per_page/paged.
 */
class WP_Query {
    public $posts = [];
    public $found_posts = 0;
    public $max_num_pages = 1;

    public function __construct($args) {
        $GLOBALS['__awecal_last_query_args'] = $args;
        $all = array_values($GLOBALS['__awecal_query_posts']);
        $per = isset($args['posts_per_page']) ? (int) $args['posts_per_page'] : 10;
        if ($per < 1) { $per = max(1, count($all)); }
        $page = max(1, (int) ($args['paged'] ?? 1));
        $this->posts = array_slice($all, ($page - 1) * $per, $per);
        if (!empty($args['no_found_rows'])) {
            $this->found_posts = count($this->posts);
            $this->max_num_pages = 1;
        } else {
            $this->found_posts = count($all);
            $this->max_num_pages = max(1, (int) ceil(count($all) / $per));
        }
    }
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
function add_filter($hook, $callback = null, $priority = 10, $args = 1) {
    $GLOBALS['__awecal_filters'][$hook][] = $callback;
    return true;
}
function apply_filters($hook, $value) {
    foreach ($GLOBALS['__awecal_filters'][$hook] ?? [] as $callback) {
        $value = call_user_func($callback, $value);
    }
    return $value;
}
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
function sanitize_title($str) {
    $str = strtolower(trim(strip_tags((string) $str)));
    return preg_replace('/[^a-z0-9]+/', '-', $str);
}

/* -------------------------------------------------------------------------
 * Options / locale / time stubs
 * ---------------------------------------------------------------------- */

function get_option($name, $default = false) {
    if (isset($GLOBALS['__awecal_options'][$name])) {
        return $GLOBALS['__awecal_options'][$name];
    }
    $options = [
        'date_format' => 'F j, Y',
        'start_of_week' => 1,
        'timezone_string' => '',
        'gmt_offset' => 0,
    ];
    return $options[$name] ?? $default;
}

function update_option($name, $value) {
    $GLOBALS['__awecal_options'][$name] = $value;
    return true;
}

function get_transient($key) {
    return $GLOBALS['__awecal_transients'][$key] ?? false;
}

function set_transient($key, $value, $expiration = 0) {
    $GLOBALS['__awecal_transients'][$key] = $value;
    return true;
}

function delete_transient($key) {
    unset($GLOBALS['__awecal_transients'][$key]);
    return true;
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
        'post_date_gmt' => $data['post_date_gmt'],
    ];
}

function get_the_excerpt($post = null) {
    $id = is_object($post) ? $post->ID : (int) $post;
    $excerpt = $GLOBALS['__awecal_posts'][$id]['excerpt'] ?? '';
    if ($excerpt !== '') {
        return $excerpt;
    }
    $content = $GLOBALS['__awecal_posts'][$id]['content'] ?? '';
    return wp_trim_words(wp_strip_all_tags($content));
}

function wp_trim_words($text, $num_words = 55, $more = '…') {
    $words = preg_split('/\s+/', trim((string) $text));
    if (count($words) <= $num_words) {
        return trim((string) $text);
    }
    return implode(' ', array_slice($words, 0, $num_words)) . $more;
}

function get_the_post_thumbnail_url($post = null, $size = 'post-thumbnail') {
    $id = is_object($post) ? $post->ID : (int) $post;
    return $GLOBALS['__awecal_posts'][$id]['thumbnail_url'] ?? false;
}

function get_post_thumbnail_id($post = null) {
    $id = is_object($post) ? $post->ID : (int) $post;
    return $GLOBALS['__awecal_posts'][$id]['thumbnail_id'] ?? 0;
}

function get_the_terms($post_id, $taxonomy) {
    $terms = $GLOBALS['__awecal_posts'][$post_id]['terms'][$taxonomy] ?? false;
    return $terms;
}

function mysql2date($format, $value, $translate = true) {
    $ts = strtotime((string) $value);
    return $ts ? date($format, $ts) : false;
}

function wp_json_encode($data, $flags = 0) {
    return json_encode($data, $flags);
}

function maybe_unserialize($value) {
    if (is_string($value)) {
        $unserialized = @unserialize($value);
        return $unserialized === false && $value !== 'b:0;' ? $value : $unserialized;
    }
    return $value;
}

/* -------------------------------------------------------------------------
 * Load plugin includes (read-only)
 * ---------------------------------------------------------------------- */

$plugin_includes = [
    'includes/class-meta-helper.php',
    'includes/class-event-meta.php',
    'includes/class-ics-generator.php',
    'includes/class-events-query-api.php',
    'includes/class-meta-migration.php',
];

foreach ($plugin_includes as $relative) {
    require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . $relative;
}

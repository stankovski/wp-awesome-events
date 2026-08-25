<?php
/**
 * Plugin Name: Awesome Events
 * Plugin URI: https://bothellmosque.org
 * Description: Manage event dates and recurring schedules, publish calendar feeds, display countdowns, and let visitors add events to their calendar.
 * Version: 1.0.0
 * Author: ICOB Team
 * Author URI: https://bothellmosque.org
 * Text Domain: awesome-events
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.6
 * Requires PHP: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('AWESOME_EVENTS_VERSION', '1.0.0');
define('AWESOME_EVENTS_PLUGIN_FILE', __FILE__);
define('AWESOME_EVENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AWESOME_EVENTS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main Awesome Events Plugin Class
 */
class Awesome_Events_Plugin {

    /**
     * Single instance of the plugin
     */
    private static $instance = null;

    /**
     * Get the single instance of the plugin
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'));
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_filter('block_categories_all', array($this, 'add_block_category'), 10, 2);

        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        $this->load_includes();
        $this->init_blocks();
    }

    /**
     * Load plugin includes
     */
    private function load_includes() {
        // Event meta (dates & recurrence)
        require_once AWESOME_EVENTS_PLUGIN_DIR . 'includes/class-event-meta.php';
        // Event REST API endpoints
        require_once AWESOME_EVENTS_PLUGIN_DIR . 'includes/class-event-rest-api.php';
        // Event date block
        require_once AWESOME_EVENTS_PLUGIN_DIR . 'includes/class-event-date-block.php';
        // Event countdown block
        require_once AWESOME_EVENTS_PLUGIN_DIR . 'includes/class-event-countdown-block.php';
        // Event shortcodes
        require_once AWESOME_EVENTS_PLUGIN_DIR . 'includes/class-event-shortcodes.php';
        // ICS generator utility
        require_once AWESOME_EVENTS_PLUGIN_DIR . 'includes/class-ics-generator.php';
        // Events ICS feed endpoint
        require_once AWESOME_EVENTS_PLUGIN_DIR . 'includes/class-events-ics.php';
        // Single event ICS endpoint
        require_once AWESOME_EVENTS_PLUGIN_DIR . 'includes/class-single-event-ics.php';
        // Add to Calendar button block variation
        require_once AWESOME_EVENTS_PLUGIN_DIR . 'includes/class-add-to-calendar-button.php';
    }

    /**
     * Initialize Gutenberg blocks / feature classes
     */
    private function init_blocks() {
        // Initialize event meta system
        new Awesome_Events_Event_Meta();
        // Initialize event REST API
        new Awesome_Events_Event_REST_API();
        // Initialize event date block
        new Awesome_Events_Event_Date_Block();
        // Initialize event countdown block
        new Awesome_Events_Event_Countdown_Block();
        // Initialize event shortcodes
        new Awesome_Events_Event_Shortcodes();
        // Initialize ICS events endpoint
        new Awesome_Events_Events_ICS();
        // Initialize single event ICS endpoint
        new Awesome_Events_Single_Event_ICS();
        // Initialize add to calendar button
        new Awesome_Events_Add_To_Calendar_Button();
    }

    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain('awesome-events', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Add custom block category.
     *
     * Registered as a fallback so event blocks are grouped correctly even if
     * another plugin hasn't already registered the 'icob' category slug.
     */
    public function add_block_category($categories, $post) {
        foreach ($categories as $category) {
            if (isset($category['slug']) && $category['slug'] === 'icob') {
                // Already registered (e.g. by the icob plugin); avoid duplicates.
                return $categories;
            }
        }
        array_unshift($categories, array(
            'slug'  => 'icob',
            'title' => __('ICOB Blocks', 'awesome-events'),
            'icon'  => 'slides',
        ));
        return $categories;
    }

    /**
     * Plugin activation
     */
    public function activate() {
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }
}

// Initialize the plugin
Awesome_Events_Plugin::get_instance();

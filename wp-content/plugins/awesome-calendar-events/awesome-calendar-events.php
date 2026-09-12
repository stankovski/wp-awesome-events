<?php
/**
 * Plugin Name: Awesome Calendar Events
 * Plugin URI: https://github.com/stankovski/wp-awesome-events
 * Description: Manage event dates and recurring schedules, publish calendar feeds, display countdowns, and let visitors add events to their calendar.
 * Version: 1.1.0
 * Author: stankovski
 * Author URI: https://goodsoftware.foundation/
 * Text Domain: awesome-calendar-events
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 8.0
 * License: MIT
 * License URI: https://github.com/stankovski/wp-awesome-events/blob/main/LICENSE
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('AWESOME_CALENDAR_EVENTS_VERSION', '1.0.0');
define('AWESOME_CALENDAR_EVENTS_PLUGIN_FILE', __FILE__);
define('AWESOME_CALENDAR_EVENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AWESOME_CALENDAR_EVENTS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main Awesome Calendar Events Plugin Class
 */
class Awesome_Calendar_Events_Plugin {

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
        // Register the shared custom block icon script before the block
        // classes register their editor scripts (which use it as a
        // dependency); init_blocks() runs at the default priority 10.
        add_action('init', array($this, 'register_block_icons'), 5);
        add_action('init', array($this, 'init'));
        add_filter('block_categories_all', array($this, 'add_block_category'), 10, 2);

        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Ensure rewrite rules (e.g. /events.ics) survive DB restores or
        // updates that bypass the activation hook.
        add_action('admin_init', array($this, 'maybe_flush_rewrite_rules'));

        // Rename legacy `_icob_` meta to the canonical `_awecal_` prefix.
        // Runs on activation and once per version via admin_init so DB
        // restores or file-level updates are also migrated.
        add_action('admin_init', array($this, 'maybe_migrate_legacy_meta'));
    }

    /**
     * Register the shared block icon script (custom SVG icons).
     *
     * Defines the `awesome-calendar-events-block-icons` handle used as a
     * dependency by editor scripts of blocks that register their icons
     * client-side (see assets/js/block-icons.js).
     */
    public function register_block_icons() {
        wp_register_script(
            'awesome-calendar-events-block-icons',
            AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/js/block-icons.js',
            array('wp-element'),
            AWESOME_CALENDAR_EVENTS_VERSION,
            true
        );
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
        // Meta helper (canonical `_awecal_` prefix with transparent legacy fallback)
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-meta-helper.php';
        // Event meta (dates & recurrence)
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-event-meta.php';
        // Event REST API endpoints
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-event-rest-api.php';
        // Public events query API (awecal/v1/events)
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-events-query-api.php';
        // Event list block (Query Loop variation backed by the events query API)
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-event-list-block.php';
        // Event date block
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-event-date-block.php';
        // Event countdown block
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-event-countdown-block.php';
        // Announcement container block
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-announcement-container-block.php';
        // Calendar block (grid) with date/event container blocks
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-calendar-block.php';
        // Event shortcodes
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-event-shortcodes.php';
        // ICS generator utility
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-ics-generator.php';
        // Events ICS feed endpoint
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-events-ics.php';
        // Single event ICS endpoint
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-single-event-ics.php';
        // Add to Calendar button block variation
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-add-to-calendar-button.php';
        // Block patterns
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-block-patterns.php';
        // Advanced Query Loop dynamic date placeholders
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/aql-dynamic-date.php';
        // One-time legacy `_icob_` meta migration
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-meta-migration.php';
    }

    /**
     * Initialize Gutenberg blocks / feature classes
     */
    private function init_blocks() {
        // Initialize event meta system
        new Awesome_Calendar_Events_Event_Meta();
        // Initialize event REST API
        new Awesome_Calendar_Events_Event_REST_API();
        // Initialize public events query API
        new Awesome_Calendar_Events_Events_Query_API();
        // Initialize event list block
        new Awesome_Calendar_Events_Event_List_Block();
        // Initialize event date block
        new Awesome_Calendar_Events_Event_Date_Block();
        // Initialize event countdown block
        new Awesome_Calendar_Events_Event_Countdown_Block();
        // Initialize announcement container block
        new Awesome_Calendar_Events_Announcement_Container_Block();
        // Initialize calendar block (grid) with date/event container blocks
        new Awesome_Calendar_Events_Calendar_Block();
        // Initialize event shortcodes
        new Awesome_Calendar_Events_Event_Shortcodes();
        // Initialize ICS events endpoint
        new Awesome_Calendar_Events_Events_ICS();
        // Initialize single event ICS endpoint
        new Awesome_Calendar_Events_Single_Event_ICS();
        // Initialize add to calendar button
        new Awesome_Calendar_Events_Add_To_Calendar_Button();
        // Initialize block patterns
        new Awesome_Calendar_Events_Block_Patterns();
    }

    /**
     * Add custom block category.
     *
     * Registered as a fallback so event blocks are grouped correctly even if
     * the 'awesome-calendar-events' category slug is already registered.
     */
    public function add_block_category($categories, $post) {
        foreach ($categories as $category) {
            if (isset($category['slug']) && $category['slug'] === 'awesome-calendar-events') {
                // Already registered; avoid duplicates.
                return $categories;
            }
        }
        array_unshift($categories, array(
            'slug'  => 'awesome-calendar-events',
            'title' => __('Awesome Calendar Events', 'awesome-calendar-events'),
            'icon'  => 'slides',
        ));
        return $categories;
    }

    /**
     * Plugin activation
     */
    public function activate() {
        $this->maybe_migrate_legacy_meta();
        flush_rewrite_rules();
    }

    /**
     * Flush rewrite rules once per plugin version so the /events.ics rule is
     * always present, even after DB restores or file-level updates that
     * bypass register_activation_hook().
     */
    public function maybe_flush_rewrite_rules() {
        $stored_version = get_option('awesome_calendar_events_rewrite_version');
        if ($stored_version !== AWESOME_CALENDAR_EVENTS_VERSION) {
            flush_rewrite_rules();
            update_option('awesome_calendar_events_rewrite_version', AWESOME_CALENDAR_EVENTS_VERSION);
        }
    }

    /**
     * Rename legacy `_icob_` prefixed post meta to the canonical `_awecal_`
     * prefix once per plugin version.
     */
    public function maybe_migrate_legacy_meta() {
        // This runs on activation and admin_init, both of which happen
        // after the `init` hook, so load the migration dependencies here.
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-meta-helper.php';
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-event-meta.php';
        require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-meta-migration.php';

        Awesome_Calendar_Events_Meta_Migration::maybe_migrate();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }
}

// Initialize the plugin
Awesome_Calendar_Events_Plugin::get_instance();

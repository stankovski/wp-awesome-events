<?php
/**
 * Event Shortcodes
 *
 * Provides shortcodes to output event metadata in paragraphs, lists, buttons, etc.
 *
 * Available Shortcodes:
 * - [event_date] - Outputs formatted event date
 * - [event_friendly_date] - Outputs friendly/relative date (Today, Tomorrow, This Monday, or weekday names)
 * - [event_time] - Outputs event start time
 * - [event_location] - Outputs event location
 * - [event_calendar_url] - Generates addcal.co URL for adding event to calendar
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Events_Event_Shortcodes {

    public function __construct() {
        // Register shortcodes immediately - don't wait for init
        $this->register_shortcodes();
        // Ensure shortcodes are processed in rendered blocks
        add_filter('render_block', [$this, 'process_shortcodes_in_blocks'], 10, 2);
    }

    public function register_shortcodes() {
        add_shortcode('event_date', [$this, 'event_date_shortcode']);
        add_shortcode('event_friendly_date', [$this, 'event_friendly_date_shortcode']);
        add_shortcode('event_time', [$this, 'event_time_shortcode']);
        add_shortcode('event_full_time', [$this, 'event_full_time_shortcode']);
        add_shortcode('event_location', [$this, 'event_location_shortcode']);
    }

    /**
     * Process shortcodes in rendered blocks
     * This ensures shortcodes work in paragraph blocks and other block content
     */
    public function process_shortcodes_in_blocks($block_content, $block) {
        // Only process if content contains our shortcodes
        if (is_string($block_content) && strpos($block_content, '[event_') !== false) {
            return do_shortcode($block_content);
        }
        return $block_content;
    }

    /**
     * Get post ID from shortcode attributes or context
     */
    private function get_post_id($atts) {
        $atts = shortcode_atts([
            'post_id' => 0,
        ], $atts);

        $post_id = intval($atts['post_id']);

        if (!$post_id) {
            $post_id = get_the_ID();
        }

        return $post_id;
    }

    /**
     * [event_date] shortcode
     * Outputs the event date in specified format
     *
     * Attributes:
     * - format: PHP date format (default: site date format)
     * - post_id: Specific post ID (default: current post)
     * - fallback: Text to show if no date available
     *
     * Example: [event_date format="F j, Y"]
     */
    public function event_date_shortcode($atts, $content = null) {
        $atts = shortcode_atts([
            'format' => '',
            'post_id' => 0,
            'fallback' => '',
        ], $atts);

        $post_id = $this->get_post_id($atts);
        if (!$post_id) {
            return $atts['fallback'];
        }

        if (!class_exists('Awesome_Events_Event_Meta')) {
            return $atts['fallback'];
        }

        $format = $atts['format'] ?: get_option('date_format');
        $display = Awesome_Events_Event_Meta::get_event_date_display($post_id, $format, false, false);

        if (!empty($display['date'])) {
            return esc_html($display['date']);
        }

        return $atts['fallback'];
    }

    /**
     * [event_friendly_date] shortcode
     * Outputs a friendly/relative date string based on the event date block logic
     * Shows weekday names for recurring events with next occurrence date
     *
     * Attributes:
     * - post_id: Specific post ID (default: current post)
     * - fallback: Text to show if no date available
     * - format: PHP date format for fallback non-relative display (default: site date format)
     *
     * Example: [event_friendly_date]
     */
    public function event_friendly_date_shortcode($atts, $content = null) {
        $atts = shortcode_atts([
            'post_id' => 0,
            'fallback' => '',
            'format' => '',
        ], $atts);

        $post_id = $this->get_post_id($atts);
        if (!$post_id) {
            return $atts['fallback'];
        }

        if (!class_exists('Awesome_Events_Event_Meta')) {
            return $atts['fallback'];
        }

        $format = $atts['format'] ?: get_option('date_format');

        // Check if this is a weekly recurring event
        $rec_type = get_post_meta($post_id, '_icob_event_recurrence_type', true);

        if ($rec_type === 'weekly') {
            // For weekly events, get singular weekday names and prepend "Every"
            $display = Awesome_Events_Event_Meta::get_event_date_display($post_id, $format, false, false);
            if (!empty($display['weekdays'])) {
                return esc_html('Every ' . $display['weekdays']);
            }
        }

        // For non-weekly events, get display without relative output to avoid "Today", "Tomorrow", etc.
        $display = Awesome_Events_Event_Meta::get_event_date_display($post_id, $format, false, false);

        // For non-weekly events, just show the date
        if (!empty($display['date'])) {
            return esc_html($display['date']);
        }

        // Fallback to weekdays only if no date available
        if (!empty($display['weekdays'])) {
            return esc_html($display['weekdays']);
        }

        return $atts['fallback'];
    }

    /**
     * [event_time] shortcode
     * Outputs the event start time or custom time label
     *
     * Attributes:
     * - format: PHP time format (default: g:i A)
     * - post_id: Specific post ID (default: current post)
     * - fallback: Text to show if no time available
     *
     * Example: [event_time format="H:i"]
     */
    public function event_time_shortcode($atts, $content = null) {
        $atts = shortcode_atts([
            'format' => 'g:i A',
            'post_id' => 0,
            'fallback' => '',
        ], $atts);

        $post_id = $this->get_post_id($atts);
        if (!$post_id) {
            return $atts['fallback'];
        }

        // Check for custom time label first
        $custom_label = get_post_meta($post_id, '_icob_event_custom_time_label', true);
        if (!empty($custom_label)) {
            return esc_html($custom_label);
        }

        $time_raw = get_post_meta($post_id, '_icob_event_start_time', true);
        if (!$time_raw) {
            return $atts['fallback'];
        }

        // Parse and format the time
        $ts = strtotime(date('Y-m-d') . ' ' . $time_raw);
        if (!$ts) {
            return $atts['fallback'];
        }

        return esc_html(date_i18n($atts['format'], $ts));
    }

    /**
     * [event_full_time] shortcode
     * Outputs the event time range (start time - end time) or custom time label
     *
     * Attributes:
     * - format: PHP time format (default: g:i A)
     * - separator: Separator between times (default: ' - ')
     * - post_id: Specific post ID (default: current post)
     * - fallback: Text to show if no time available
     *
     * Example: [event_full_time format="H:i" separator=" to "]
     */
    public function event_full_time_shortcode($atts, $content = null) {
        $atts = shortcode_atts([
            'format' => 'g:i A',
            'separator' => ' - ',
            'post_id' => 0,
            'fallback' => '',
        ], $atts);

        $post_id = $this->get_post_id($atts);
        if (!$post_id) {
            return $atts['fallback'];
        }

        // Check for custom time label first
        $custom_label = get_post_meta($post_id, '_icob_event_custom_time_label', true);
        if (!empty($custom_label)) {
            return esc_html($custom_label);
        }

        $start_time_raw = get_post_meta($post_id, '_icob_event_start_time', true);
        $end_time_raw = get_post_meta($post_id, '_icob_event_end_time', true);

        if (!$start_time_raw) {
            return $atts['fallback'];
        }

        // Parse and format the start time
        $start_ts = strtotime(date('Y-m-d') . ' ' . $start_time_raw);
        if (!$start_ts) {
            return $atts['fallback'];
        }

        $start_formatted = date_i18n($atts['format'], $start_ts);

        // If no end time, just return start time
        if (!$end_time_raw) {
            return esc_html($start_formatted);
        }

        // Parse and format the end time
        $end_ts = strtotime(date('Y-m-d') . ' ' . $end_time_raw);
        if (!$end_ts) {
            return esc_html($start_formatted);
        }

        $end_formatted = date_i18n($atts['format'], $end_ts);

        return esc_html($start_formatted . $atts['separator'] . $end_formatted);
    }

    /**
     * [event_location] shortcode
     * Outputs the event location
     *
     * Attributes:
     * - post_id: Specific post ID (default: current post)
     * - fallback: Text to show if no location available
     *
     * Example: [event_location]
     */
    public function event_location_shortcode($atts, $content = null) {
        $atts = shortcode_atts([
            'post_id' => 0,
            'fallback' => '',
        ], $atts);

        $post_id = $this->get_post_id($atts);
        if (!$post_id) {
            return $atts['fallback'];
        }

        $location = get_post_meta($post_id, '_icob_event_location', true);
        if (!$location) {
            return $atts['fallback'];
        }

        return esc_html($location);
    }
}

<?php
/**
 * Event Shortcodes
 *
 * Provides shortcodes to output event metadata in paragraphs, lists, buttons, etc.
 *
 * Available Shortcodes:
 * - [awecal_event_date] - Outputs formatted event date
 * - [awecal_event_friendly_date] - Outputs friendly/relative date (Today, Tomorrow, This Monday, or weekday names)
 * - [awecal_event_time] - Outputs event start time
 * - [awecal_event_location] - Outputs event location
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Calendar_Events_Event_Shortcodes {

    public function __construct() {
        // Register shortcodes immediately - don't wait for init
        $this->register_shortcodes();
        // Ensure shortcodes are processed in rendered blocks
        add_filter('render_block', [$this, 'process_shortcodes_in_blocks'], 10, 2);
    }

    public function register_shortcodes() {
        add_shortcode('awecal_event_date', [$this, 'event_date_shortcode']);
        add_shortcode('awecal_event_friendly_date', [$this, 'event_friendly_date_shortcode']);
        add_shortcode('awecal_event_time', [$this, 'event_time_shortcode']);
        add_shortcode('awecal_event_full_time', [$this, 'event_full_time_shortcode']);
        add_shortcode('awecal_event_location', [$this, 'event_location_shortcode']);
    }

    /**
     * Process shortcodes in rendered blocks
     * This ensures shortcodes work in paragraph blocks and other block content
     */
    public function process_shortcodes_in_blocks($block_content, $block) {
        // Only process if content contains our shortcodes
        if (is_string($block_content) && strpos($block_content, '[awecal_event_') !== false) {
            return wp_kses_post(do_shortcode($block_content));
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
     * [awecal_event_date] shortcode
     * Outputs the event date in specified format
     *
     * Attributes:
     * - format: PHP date format (default: site date format)
     * - post_id: Specific post ID (default: current post)
     * - fallback: Text to show if no date available
     *
     * Example: [awecal_event_date format="F j, Y"]
     */
    public function event_date_shortcode($atts, $content = null) {
        $atts = shortcode_atts([
            'format' => '',
            'post_id' => 0,
            'fallback' => '',
        ], $atts);

        $post_id = $this->get_post_id($atts);
        if (!$post_id) {
            return esc_html($atts['fallback']);
        }

        if (!class_exists('Awesome_Calendar_Events_Event_Meta')) {
            return esc_html($atts['fallback']);
        }

        $format = $atts['format'] ?: get_option('date_format');
        $display = Awesome_Calendar_Events_Event_Meta::get_event_date_display($post_id, $format, false, false);

        if (!empty($display['date'])) {
            return esc_html($display['date']);
        }

        return esc_html($atts['fallback']);
    }

    /**
     * [awecal_event_friendly_date] shortcode
     * Outputs a friendly/relative date string based on the event date block logic
     * Shows weekday names for recurring events with next occurrence date
     *
     * Attributes:
     * - post_id: Specific post ID (default: current post)
     * - fallback: Text to show if no date available
     * - format: PHP date format for fallback non-relative display (default: site date format)
     *
     * Example: [awecal_event_friendly_date]
     */
    public function event_friendly_date_shortcode($atts, $content = null) {
        $atts = shortcode_atts([
            'post_id' => 0,
            'fallback' => '',
            'format' => '',
        ], $atts);

        $post_id = $this->get_post_id($atts);
        if (!$post_id) {
            return esc_html($atts['fallback']);
        }

        if (!class_exists('Awesome_Calendar_Events_Event_Meta')) {
            return esc_html($atts['fallback']);
        }

        $format = $atts['format'] ?: get_option('date_format');

        // Check if this is a weekly recurring event
        $rec_type = awecal_get_post_meta($post_id, '_awecal_event_recurrence_type', true);

        if ($rec_type === 'weekly') {
            // For weekly events, get singular weekday names and prepend "Every"
            $display = Awesome_Calendar_Events_Event_Meta::get_event_date_display($post_id, $format, false, false);
            if (!empty($display['weekdays'])) {
                return esc_html('Every ' . $display['weekdays']);
            }
        }

        // For non-weekly events, get display without relative output to avoid "Today", "Tomorrow", etc.
        $display = Awesome_Calendar_Events_Event_Meta::get_event_date_display($post_id, $format, false, false);

        // For non-weekly events, just show the date
        if (!empty($display['date'])) {
            return esc_html($display['date']);
        }

        // Fallback to weekdays only if no date available
        if (!empty($display['weekdays'])) {
            return esc_html($display['weekdays']);
        }

        return esc_html($atts['fallback']);
    }

    /**
     * [awecal_event_time] shortcode
     * Outputs the event start time or custom time label
     *
     * Attributes:
     * - format: PHP time format (default: g:i A)
     * - post_id: Specific post ID (default: current post)
     * - fallback: Text to show if no time available
     *
     * Example: [awecal_event_time format="H:i"]
     */
    public function event_time_shortcode($atts, $content = null) {
        $atts = shortcode_atts([
            'format' => 'g:i A',
            'post_id' => 0,
            'fallback' => '',
        ], $atts);

        $post_id = $this->get_post_id($atts);
        if (!$post_id) {
            return esc_html($atts['fallback']);
        }

        // Check for custom time label first
        $custom_label = awecal_get_post_meta($post_id, '_awecal_event_custom_time_label', true);
        if (!empty($custom_label)) {
            return esc_html($custom_label);
        }

        $time_raw = awecal_get_post_meta($post_id, '_awecal_event_start_time', true);
        if (!$time_raw) {
            return esc_html($atts['fallback']);
        }

        // Parse and format the time
        $ts = strtotime(gmdate('Y-m-d') . ' ' . $time_raw);
        if (!$ts) {
            return esc_html($atts['fallback']);
        }

        return esc_html(date_i18n($atts['format'], $ts));
    }

    /**
     * [awecal_event_full_time] shortcode
     * Outputs the event time range (start time - end time) or custom time label
     *
     * Attributes:
     * - format: PHP time format (default: g:i A)
     * - separator: Separator between times (default: ' - ')
     * - post_id: Specific post ID (default: current post)
     * - fallback: Text to show if no time available
     *
     * Example: [awecal_event_full_time format="H:i" separator=" to "]
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
            return esc_html($atts['fallback']);
        }

        // Check for custom time label first
        $custom_label = awecal_get_post_meta($post_id, '_awecal_event_custom_time_label', true);
        if (!empty($custom_label)) {
            return esc_html($custom_label);
        }

        $start_time_raw = awecal_get_post_meta($post_id, '_awecal_event_start_time', true);
        $end_time_raw = awecal_get_post_meta($post_id, '_awecal_event_end_time', true);

        if (!$start_time_raw) {
            return esc_html($atts['fallback']);
        }

        // Parse and format the start time
        $start_ts = strtotime(gmdate('Y-m-d') . ' ' . $start_time_raw);
        if (!$start_ts) {
            return esc_html($atts['fallback']);
        }

        $start_formatted = date_i18n($atts['format'], $start_ts);

        // If no end time, just return start time
        if (!$end_time_raw) {
            return esc_html($start_formatted);
        }

        // Parse and format the end time
        $end_ts = strtotime(gmdate('Y-m-d') . ' ' . $end_time_raw);
        if (!$end_ts) {
            return esc_html($start_formatted);
        }

        $end_formatted = date_i18n($atts['format'], $end_ts);

        return esc_html($start_formatted . $atts['separator'] . $end_formatted);
    }

    /**
     * [awecal_event_location] shortcode
     * Outputs the event location
     *
     * Attributes:
     * - post_id: Specific post ID (default: current post)
     * - fallback: Text to show if no location available
     *
     * Example: [awecal_event_location]
     */
    public function event_location_shortcode($atts, $content = null) {
        $atts = shortcode_atts([
            'post_id' => 0,
            'fallback' => '',
        ], $atts);

        $post_id = $this->get_post_id($atts);
        if (!$post_id) {
            return esc_html($atts['fallback']);
        }

        $location = awecal_get_post_meta($post_id, '_awecal_event_location', true);
        if (!$location) {
            return esc_html($atts['fallback']);
        }

        return esc_html($location);
    }
}

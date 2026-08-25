<?php
/**
 * Single Event ICS Endpoint
 *
 * Provides endpoint for downloading individual event ICS files.
 * URL: /?ics=event&post_id=123
 *
 * Supports recurring events with proper RRULE generation.
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Events_Single_Event_ICS {
    public function __construct() {
        add_action('template_redirect', [$this, 'maybe_output']);
    }

    public function maybe_output() {
        // Check if this is a single event ICS request
        if (!isset($_GET['ics']) || $_GET['ics'] !== 'event') {
            return;
        }

        if (!isset($_GET['post_id']) || !is_numeric($_GET['post_id'])) {
            return;
        }

        $post_id = intval($_GET['post_id']);
        $post = get_post($post_id);

        if (!$post || $post->post_status !== 'publish') {
            return;
        }

        // Check if event is enabled
        $event_enabled = get_post_meta($post_id, '_icob_event_date_enabled', true);
        if (!$event_enabled) {
            return;
        }

        // Check if event has a date
        $event_date = get_post_meta($post_id, '_icob_event_date', true);
        if (!$event_date) {
            return;
        }

        $this->output_ics($post);
        exit;
    }

    private function output_ics($post) {
        // Generate ICS content using the shared generator that lives in this plugin.
        if (!class_exists('Awesome_Events_ICS_Generator')) {
            require_once AWESOME_EVENTS_PLUGIN_DIR . 'includes/class-ics-generator.php';
        }

        $generator = new Awesome_Events_ICS_Generator();
        $ics_content = $generator->generate_single_event_calendar($post);

        if (empty($ics_content)) {
            return;
        }

        // Set headers
        nocache_headers();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="event-' . $post->ID . '.ics"');
        header('Content-Length: ' . strlen($ics_content));

        echo $ics_content;
    }
}

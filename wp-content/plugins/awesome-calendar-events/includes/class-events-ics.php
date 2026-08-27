<?php
/**
 * Provide /events.ics endpoint emitting VCALENDAR of ICOB events.
 *
 * Strategy:
 *  - Hook into init to add rewrite rule for events.ics
 *  - Hook template_redirect to detect request and output ICS
 *  - Query all published posts having the event date enabled (prefix handled by the meta helper)
 *  - For each post build one or more VEVENTs.
 *      * Non-recurring: single VEVENT
 *      * Recurring: If recurrence is present we will create an RRULE instead of expanding fully (efficient)
 *        Additionally we include a DTEND when duration present; DTSTART built using date and optional time.
 *  - Weekly recurrence with custom weekdays -> use BYDAY rule
 *  - Daily/monthly/yearly -> FREQ with INTERVAL
 *  - End conditions: date => UNTIL (in UTC Z format end-of-day), count => COUNT
 *  - Weekday mapping: stored Monday=0..Sunday=6 -> iCal uses MO,TU,WE,TH,FR,SA,SU (Monday..Sunday)
 *
 * NOTE: We expose base DTSTART date only; no timezone shifting beyond site timezone. We export floating times, or if site timezone string available we append TZID param.
 *
 * NOTE: Uses the shared Awesome_Calendar_Events_ICS_Generator that lives in this plugin
 * (see class-ics-generator.php). The pledge ICS feature in icob has its own
 * independent inline generation logic and does not use this class.
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Calendar_Events_Events_ICS {
    public function __construct() {
        $this->add_rewrite();
        add_action('template_redirect', [$this, 'maybe_output']);
        add_filter('redirect_canonical', [$this, 'disable_canonical_redirect_for_ics'], 10, 2);
    }

    public function add_rewrite() {
        // Simple rewrite for /events.ics
        add_rewrite_rule('events\.ics$', 'index.php?icob_events_ics=1', 'top');
        add_rewrite_tag('%icob_events_ics%', '([0-1])');
    }

    public function maybe_output() {
        if (get_query_var('icob_events_ics') !== '1') { return; }
        $this->output_ics();
        exit;
    }

    public function disable_canonical_redirect_for_ics($redirect_url, $requested_url) {
        // Check if the requested URL ends with events.ics (without trailing slash)
        if (preg_match('/\/events\.ics$/', wp_parse_url($requested_url, PHP_URL_PATH))) {
            return false; // Disable canonical redirect
        }
        return $redirect_url;
    }

    private function output_ics() {
        // Gather events
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => [
                'relation' => 'AND',
                awecal_event_date_enabled_meta_query(),
                awecal_event_date_present_meta_query(),
            ]
        ];
        $posts = get_posts($args);

        // Use the shared ICS generator (lives in this plugin).
        if (!class_exists('Awesome_Calendar_Events_ICS_Generator')) {
            require_once AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'includes/class-ics-generator.php';
        }

        $generator = new Awesome_Calendar_Events_ICS_Generator();
        $ics_content = $generator->generate_calendar($posts);

        // Headers
        nocache_headers();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="events.ics"');

        // Output ICS content
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- RFC 5545 text/calendar output is escaped by the generator, not as HTML.
        echo $ics_content;
    }
}

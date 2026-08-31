<?php
/**
 * Add to Calendar Block
 *
 * Creates an Awesome Calendar Events block that wraps a configured core/button in a
 * core/buttons container and generates calendar links based on post event metadata.
 *
 * Usage:
 * 1. In the block editor, insert the "Add to Calendar" block from Awesome Calendar Events
 * 2. It automatically creates a Buttons container with the configured button
 * 3. The button will automatically use event metadata from the current post:
 *    - Event date and time (_awecal_event_date, _awecal_event_start_time)
 *    - Event duration (_awecal_event_duration_hours)
 *    - Event location (_awecal_event_location)
 *    - Post title (used as event name)
 * 4. On the frontend, clicking the button opens a modal with calendar options:
 *    - Google Calendar
 *    - Apple Calendar
 *    - Outlook Calendar
 *    - Yahoo Calendar
 *    - iCal/Other (downloads .ics file)
 *
 * The button only appears on posts with event metadata enabled.
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Calendar_Events_Add_To_Calendar_Button {
    public function __construct() {
        $this->register_block();
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
        // Editor CSS must be enqueued via enqueue_block_assets so it loads inside the iframed editor canvas (WP 6.3+).
        add_action('enqueue_block_assets', [$this, 'enqueue_editor_iframe_styles']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('init', [$this, 'register_meta']);
        add_action('wp_footer', [$this, 'output_event_data']);
    }

    public function register_meta() {
        // Ensure the meta we need is available via REST API
        // (Already registered in class-event-meta.php, but just confirming)
    }

    private function register_block() {
        // DEPRECATED: content saved under the legacy "icob/add-to-calendar" block
        // name (before the plugin was renamed to Awesome Calendar Events) keeps
        // working without any registration: it is a static block whose saved
        // markup is rendered as-is, and the frontend assets it needs are
        // enqueued globally on wp_enqueue_scripts. New content must use
        // "awesome-calendar-events/add-to-calendar". Plan removal in a future release.
        if (!function_exists('register_block_type')) {
            return;
        }

        $ver = defined('AWESOME_CALENDAR_EVENTS_VERSION') ? AWESOME_CALENDAR_EVENTS_VERSION : '1.0.0';
        wp_register_script(
            'awesome-calendar-events-add-to-calendar-button',
            AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/js/add-to-calendar-button.js',
            ['wp-blocks', 'wp-element', 'wp-i18n', 'wp-components', 'wp-data', 'wp-block-editor'],
            $ver,
            true
        );

        register_block_type('awesome-calendar-events/add-to-calendar', [
            'api_version' => 3,
            'editor_script' => 'awesome-calendar-events-add-to-calendar-button',
            'category' => 'awesome-calendar-events',
            'title' => __('Add to Calendar', 'awesome-calendar-events'),
            'description' => __('Button that opens a calendar selection dialog for the event', 'awesome-calendar-events'),
            'keywords' => ['event', 'calendar', 'ical'],
            'supports' => [
                'html' => false,
            ],
        ]);
    }

    /**
     * Output event data as hidden elements for JavaScript to access
     */
    public function output_event_data() {
        if (!is_singular()) {
            return;
        }

        global $post;
        if (!$post) {
            return;
        }

        $event_enabled = awecal_get_post_meta($post->ID, '_awecal_event_date_enabled', true);
        if (!$event_enabled) {
            return;
        }

        $event_date = awecal_get_post_meta($post->ID, '_awecal_event_date', true);
        $start_time = awecal_get_post_meta($post->ID, '_awecal_event_start_time', true);
        $duration = awecal_get_post_meta($post->ID, '_awecal_event_duration_hours', true);
        $location = awecal_get_post_meta($post->ID, '_awecal_event_location', true);
        $recurrence_type = awecal_get_post_meta($post->ID, '_awecal_event_recurrence_type', true) ?: 'none';

        if (!$event_date) {
            return;
        }

        // Output hidden data attributes for JavaScript
        echo '<div id="awecal-event-data" style="display:none;"
            data-post-id="' . esc_attr($post->ID) . '"
            data-event-date="' . esc_attr($event_date) . '"
            data-event-time="' . esc_attr($start_time ?: '00:00') . '"
            data-event-duration="' . esc_attr($duration ?: '1') . '"
            data-event-location="' . esc_attr($location) . '"
            data-recurrence-type="' . esc_attr($recurrence_type) . '"></div>';
    }

    public function enqueue_editor_assets() {
        wp_enqueue_script('awesome-calendar-events-add-to-calendar-button');
    }

    /**
     * Editor-only CSS that must be injected into the editor iframe.
     * enqueue_block_assets fires both in frontend and editor; gate to admin to keep frontend untouched.
     */
    public function enqueue_editor_iframe_styles() {
        if (!is_admin()) { return; }
        $ver = defined('AWESOME_CALENDAR_EVENTS_VERSION') ? AWESOME_CALENDAR_EVENTS_VERSION : '1.0.0';
        wp_enqueue_style(
            'awesome-calendar-events-add-to-calendar-button-editor',
            AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/css/add-to-calendar-button.css',
            [],
            $ver
        );
    }

    public function enqueue_frontend_assets() {
        if (!is_admin()) {
            $ver = defined('AWESOME_CALENDAR_EVENTS_VERSION') ? AWESOME_CALENDAR_EVENTS_VERSION : '1.0.0';

            wp_enqueue_script(
                'awesome-calendar-events-add-to-calendar-frontend',
                AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/js/add-to-calendar-frontend.js',
                [],
                $ver,
                true
            );

            wp_enqueue_style(
                'awesome-calendar-events-add-to-calendar-frontend',
                AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/css/add-to-calendar-button.css',
                [],
                $ver
            );

            // Pass plugin URL and calendar data to frontend
            wp_localize_script('awesome-calendar-events-add-to-calendar-frontend', 'awecalCalendarData', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('icob_calendar_nonce'),
                'pluginUrl' => AWESOME_CALENDAR_EVENTS_PLUGIN_URL
            ]);
        }
    }

    /**
     * Generate calendar URLs
     */
    public static function get_calendar_urls($post_id) {
        $title = get_the_title($post_id);
        $event_date = awecal_get_post_meta($post_id, '_awecal_event_date', true);
        $start_time = awecal_get_post_meta($post_id, '_awecal_event_start_time', true);
        $duration_hours = awecal_get_post_meta($post_id, '_awecal_event_duration_hours', true);
        $location = awecal_get_post_meta($post_id, '_awecal_event_location', true);
        $description = wp_strip_all_tags(get_the_excerpt($post_id));
        $url = get_permalink($post_id);

        if (!$event_date) {
            return null;
        }

        // Parse date and time
        $timezone = wp_timezone();
        $datetime_str = $event_date . ' ' . ($start_time ?: '00:00');

        try {
            $start = new DateTime($datetime_str, $timezone);
            $end = clone $start;

            if ($duration_hours) {
                $hours = floor($duration_hours);
                $minutes = ($duration_hours - $hours) * 60;
                $end->modify("+{$hours} hours +{$minutes} minutes");
            } else {
                $end->modify('+1 hour'); // Default 1 hour
            }
        } catch (Exception $e) {
            return null;
        }

        // Format for different calendar services
        $start_utc = clone $start;
        $start_utc->setTimezone(new DateTimeZone('UTC'));
        $end_utc = clone $end;
        $end_utc->setTimezone(new DateTimeZone('UTC'));

        $start_google = $start_utc->format('Ymd\THis\Z');
        $end_google = $end_utc->format('Ymd\THis\Z');
        $start_ical = $start_utc->format('Ymd\THis\Z');
        $end_ical = $end_utc->format('Ymd\THis\Z');
        $start_outlook = $start_utc->format('Y-m-d\TH:i:s\Z');
        $end_outlook = $end_utc->format('Y-m-d\TH:i:s\Z');

        return [
            'google' => self::generate_google_url($title, $start_google, $end_google, $description, $location, $url),
            'outlook' => self::generate_outlook_url($title, $start_outlook, $end_outlook, $description, $location, $url),
            'office365' => self::generate_office365_url($title, $start_outlook, $end_outlook, $description, $location, $url),
            'apple' => self::generate_ical_url($post_id),
            'ical' => self::generate_ical_url($post_id),
        ];
    }

    private static function generate_google_url($title, $start, $end, $description, $location, $url) {
        $params = [
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => $start . '/' . $end,
            'details' => $description . "\n\n" . $url,
            'location' => $location,
        ];
        return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
    }

    private static function generate_outlook_url($title, $start, $end, $description, $location, $url) {
        $params = [
            'path' => '/calendar/action/compose',
            'rru' => 'addevent',
            'subject' => $title,
            'startdt' => $start,
            'enddt' => $end,
            'body' => $description . "\n\n" . $url,
            'location' => $location,
        ];
        return 'https://outlook.live.com/calendar/0/deeplink/compose?' . http_build_query($params);
    }

    private static function generate_office365_url($title, $start, $end, $description, $location, $url) {
        $params = [
            'path' => '/calendar/action/compose',
            'rru' => 'addevent',
            'subject' => $title,
            'startdt' => $start,
            'enddt' => $end,
            'body' => $description . "\n\n" . $url,
            'location' => $location,
        ];
        return 'https://outlook.office.com/calendar/0/deeplink/compose?' . http_build_query($params);
    }

    private static function generate_ical_url($post_id) {
        // Use existing ICS endpoint if available
        return add_query_arg(['ics' => 'event', 'post_id' => $post_id], home_url('/'));
    }
}

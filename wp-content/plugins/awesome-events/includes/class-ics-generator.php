<?php
/**
 * ICS Generator Utility
 *
 * Shared utility for generating iCalendar (ICS) format event data.
 * Used by both the events feed endpoint and individual event downloads.
 *
 * Handles:
 * - VCALENDAR wrapper
 * - VTIMEZONE components for named timezones
 * - VEVENT generation with proper date/time formatting
 * - Recurrence rules (RRULE) for repeating events
 * - Duration and location support
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Events_ICS_Generator {
    private $timezone;
    private $timezone_string;
    private $use_named_timezone;

    public function __construct() {
        $this->setup_timezone();
    }

    /**
     * Setup timezone configuration based on WordPress settings
     */
    private function setup_timezone() {
        $this->timezone_string = get_option('timezone_string');
        $this->use_named_timezone = false;
        
        if ($this->timezone_string) {
            try {
                $this->timezone = new DateTimeZone($this->timezone_string);
                $this->use_named_timezone = true;
            } catch (Exception $e) {
                $this->timezone = null;
            }
        }
        
        if (!$this->timezone) {
            // Fallback to GMT offset
            $offset = get_option('gmt_offset');
            if ($offset !== false) {
                $hours = (int)$offset;
                $mins = abs($offset - $hours) * 60;
                $sign = $offset >= 0 ? '+' : '-';
                $offset_string = sprintf('%s%02d%02d', $sign, abs($hours), $mins);
                try {
                    $this->timezone = new DateTimeZone($offset_string);
                    $this->timezone_string = "UTC{$offset_string}";
                } catch (Exception $e) {
                    $this->timezone = new DateTimeZone('UTC');
                    $this->timezone_string = 'UTC';
                }
            } else {
                $this->timezone = new DateTimeZone('UTC');
                $this->timezone_string = 'UTC';
            }
        }
    }

    /**
     * Generate complete ICS calendar with multiple events
     *
     * @param array $posts Array of WP_Post objects with event metadata
     * @return string ICS calendar content
     */
    public function generate_calendar($posts) {
        $lines = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//ICOB//Events ' . (defined('AWESOME_EVENTS_VERSION') ? AWESOME_EVENTS_VERSION : '1.0') . '//EN';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';

        // Add VTIMEZONE for named timezones
        if ($this->use_named_timezone && $this->timezone_string !== 'UTC') {
            $this->add_vtimezone($lines);
        }

        foreach ($posts as $post) {
            $this->add_event_to_calendar($lines, $post);
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }

    /**
     * Generate ICS calendar for a single event
     *
     * @param int|WP_Post $post Post ID or WP_Post object
     * @return string ICS calendar content
     */
    public function generate_single_event_calendar($post) {
        if (is_numeric($post)) {
            $post = get_post($post);
        }
        
        if (!$post) {
            return '';
        }

        return $this->generate_calendar([$post]);
    }

    /**
     * Add a single event to the calendar lines array
     *
     * @param array &$lines Reference to lines array
     * @param WP_Post $post Post object with event metadata
     */
    private function add_event_to_calendar(&$lines, $post) {
        // Get event metadata
        $meta = Awesome_Events_Event_Meta::get_event_date_display($post->ID, 'Y-m-d');
        if (empty($meta['iso'])) {
            return; // Skip if no event date
        }

        $rec_type = get_post_meta($post->ID, '_icob_event_recurrence_type', true) ?: 'none';
        $interval = max(1, intval(get_post_meta($post->ID, '_icob_event_recurrence_interval', true) ?: 1));
        $end_type = get_post_meta($post->ID, '_icob_event_recurrence_end_type', true) ?: 'none';
        $end_date = get_post_meta($post->ID, '_icob_event_recurrence_end_date', true);
        $count = intval(get_post_meta($post->ID, '_icob_event_recurrence_count', true));
        $weekdays = Awesome_Events_Event_Meta::get_weekdays($post->ID);
        $start_time = get_post_meta($post->ID, '_icob_event_start_time', true);
        $duration_hours = floatval(get_post_meta($post->ID, '_icob_event_duration_hours', true));
        $location = get_post_meta($post->ID, '_icob_event_location', true);
        
        if ($duration_hours <= 0) {
            $legacy_minutes = intval(get_post_meta($post->ID, '_icob_event_duration_minutes', true));
            if ($legacy_minutes > 0) {
                $duration_hours = $legacy_minutes / 60.0;
            }
        }

        $dtstart_date = $meta['iso']; // Y-m-d format
        $dt_format_date = str_replace('-', '', $dtstart_date); // YYYYMMDD
        $has_time = (bool)$start_time;
        
        // Build DTSTART
        if ($has_time && preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $start_time)) {
            list($h, $m) = explode(':', $start_time);
            $event_datetime = new DateTime($dtstart_date . ' ' . sprintf('%02d:%02d:00', $h, $m), $this->timezone);
            $dtstart = $event_datetime->format('Ymd\THis');
        } else {
            $dtstart = $dt_format_date; // all-day event
        }

        // Build event properties
        $uid = $post->ID . '@' . parse_url(home_url(), PHP_URL_HOST);
        $summary = $this->escape_text(get_the_title($post));
        $desc = $this->escape_text(wp_strip_all_tags($post->post_excerpt ?: $post->post_content));
        $url = get_permalink($post);

        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:' . $uid;
        $lines[] = 'SUMMARY:' . $summary;
        if ($desc) {
            $lines[] = 'DESCRIPTION:' . $desc;
        }
        if (!empty($location)) {
            $lines[] = 'LOCATION:' . $this->escape_text($location);
        }
        $lines[] = 'URL;VALUE=URI:' . esc_url_raw($url);
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');

        // Add DTSTART
        if ($has_time) {
            if ($this->use_named_timezone && $this->timezone_string !== 'UTC') {
                $lines[] = 'DTSTART;TZID=' . $this->timezone_string . ':' . $dtstart;
            } else {
                $lines[] = 'DTSTART:' . $dtstart;
            }
            
            // Add DTEND if duration present
            if ($duration_hours > 0) {
                $event_datetime = new DateTime($dtstart_date . ' ' . sprintf('%02d:%02d:00', $h, $m), $this->timezone);
                $event_datetime->add(new DateInterval('PT' . round($duration_hours * 3600) . 'S'));
                $dtend = $event_datetime->format('Ymd\THis');
                
                if ($this->use_named_timezone && $this->timezone_string !== 'UTC') {
                    $lines[] = 'DTEND;TZID=' . $this->timezone_string . ':' . $dtend;
                } else {
                    $lines[] = 'DTEND:' . $dtend;
                }
            }
        } else {
            $lines[] = 'DTSTART;VALUE=DATE:' . $dtstart;
            
            // Add DTEND for all-day events with duration
            if ($duration_hours > 0) {
                $start_datetime = new DateTime($dtstart_date . ' 00:00:00', $this->timezone);
                $end_datetime = clone $start_datetime;
                $end_datetime->add(new DateInterval('PT' . round($duration_hours * 3600) . 'S'));
                $end_date_out = $end_datetime->format('Ymd');
                
                if ($end_date_out !== $dt_format_date) {
                    $lines[] = 'DTEND;VALUE=DATE:' . $end_date_out;
                }
            }
        }

        // Add recurrence rule
        if ($rec_type && $rec_type !== 'none') {
            $rrule = $this->build_rrule($rec_type, $interval, $weekdays, $end_type, $end_date, $count);
            if ($rrule) {
                $lines[] = 'RRULE:' . $rrule;
            }
        }

        $lines[] = 'END:VEVENT';
    }

    /**
     * Get the RRULE string (without the "RRULE:" prefix) for a given event post.
     *
     * Public helper so other plugins (e.g. masjid-app) can obtain the same
     * recurrence rule string used in the ICS feeds without duplicating the
     * recurrence-to-RRULE mapping logic.
     *
     * @param int|WP_Post $post Post ID or WP_Post object
     * @return string|null RRULE string (e.g. "FREQ=WEEKLY;BYDAY=FR") or null if not recurring
     */
    public function get_recurrence_rule($post) {
        $post_id = is_numeric($post) ? intval($post) : (isset($post->ID) ? $post->ID : 0);
        if (!$post_id) {
            return null;
        }

        $rec_type = get_post_meta($post_id, '_icob_event_recurrence_type', true) ?: 'none';
        if ($rec_type === 'none') {
            return null;
        }

        $interval = max(1, intval(get_post_meta($post_id, '_icob_event_recurrence_interval', true) ?: 1));
        $end_type = get_post_meta($post_id, '_icob_event_recurrence_end_type', true) ?: 'none';
        $end_date = get_post_meta($post_id, '_icob_event_recurrence_end_date', true);
        $count = intval(get_post_meta($post_id, '_icob_event_recurrence_count', true));
        $weekdays = Awesome_Events_Event_Meta::get_weekdays($post_id);

        $rrule = $this->build_rrule($rec_type, $interval, $weekdays, $end_type, $end_date, $count);

        return $rrule ?: null;
    }

    /**
     * Build RRULE string for recurrence
     *
     * @param string $rec_type Recurrence type (daily, weekly, monthly, yearly)
     * @param int $interval Recurrence interval
     * @param array $weekdays Array of weekday integers (0=Mon, 6=Sun)
     * @param string $end_type End type (none, date, count)
     * @param string $end_date End date (Y-m-d format)
     * @param int $count Occurrence count
     * @return string RRULE string or empty if no recurrence
     */
    private function build_rrule($rec_type, $interval, $weekdays, $end_type, $end_date, $count) {
        $rrule_parts = [];
        
        switch($rec_type) {
            case 'daily':
                $rrule_parts[] = 'FREQ=DAILY';
                break;
            case 'weekly':
                $rrule_parts[] = 'FREQ=WEEKLY';
                break;
            case 'monthly':
                $rrule_parts[] = 'FREQ=MONTHLY';
                break;
            case 'yearly':
                $rrule_parts[] = 'FREQ=YEARLY';
                break;
            default:
                return '';
        }
        
        if ($interval > 1) {
            $rrule_parts[] = 'INTERVAL=' . $interval;
        }
        
        // Add BYDAY for weekly recurrence with specific weekdays
        if ($rec_type === 'weekly' && !empty($weekdays)) {
            $map = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU']; // Monday=0..Sunday=6
            $bydays = [];
            foreach ($weekdays as $wd) {
                if (isset($map[$wd])) {
                    $bydays[] = $map[$wd];
                }
            }
            if ($bydays) {
                $rrule_parts[] = 'BYDAY=' . implode(',', $bydays);
            }
        }
        
        // Add end condition
        if ($end_type === 'count' && $count > 0) {
            $rrule_parts[] = 'COUNT=' . $count;
        } elseif ($end_type === 'date' && $end_date) {
            // Convert end date to UTC for UNTIL parameter
            $until_datetime = new DateTime($end_date . ' 23:59:59', $this->timezone);
            $until_datetime->setTimezone(new DateTimeZone('UTC'));
            $rrule_parts[] = 'UNTIL=' . $until_datetime->format('Ymd\THis\Z');
        }
        
        return implode(';', $rrule_parts);
    }

    /**
     * Add VTIMEZONE component to calendar
     *
     * @param array &$lines Reference to lines array
     */
    private function add_vtimezone(&$lines) {
        try {
            $lines[] = 'BEGIN:VTIMEZONE';
            $lines[] = 'TZID:' . $this->timezone_string;
            
            // Get current year for timezone calculations
            $current_year = (int)date('Y');
            
            // Generate STANDARD and DAYLIGHT components for current and next year
            for ($year = $current_year; $year <= $current_year + 1; $year++) {
                $transitions = $this->timezone->getTransitions(
                    mktime(0, 0, 0, 1, 1, $year),
                    mktime(0, 0, 0, 12, 31, $year)
                );
                
                foreach ($transitions as $i => $transition) {
                    if ($i === 0) continue; // Skip first transition (base state)
                    
                    $dt = new DateTime('@' . $transition['ts']);
                    $dt->setTimezone($this->timezone);
                    
                    $is_dst = $transition['isdst'];
                    $component_type = $is_dst ? 'DAYLIGHT' : 'STANDARD';
                    
                    $lines[] = 'BEGIN:' . $component_type;
                    $lines[] = 'DTSTART:' . $dt->format('Ymd\THis');
                    $lines[] = 'TZOFFSETFROM:' . $this->format_offset($transitions[$i-1]['offset']);
                    $lines[] = 'TZOFFSETTO:' . $this->format_offset($transition['offset']);
                    $lines[] = 'TZNAME:' . $transition['abbr'];
                    $lines[] = 'END:' . $component_type;
                }
            }
            
            $lines[] = 'END:VTIMEZONE';
        } catch (Exception $e) {
            // If timezone component generation fails, continue without it
            error_log('Awesome Events ICS: Failed to generate VTIMEZONE component: ' . $e->getMessage());
        }
    }

    /**
     * Format timezone offset for iCal (e.g., +0100, -0700)
     *
     * @param int $offset_seconds Offset in seconds
     * @return string Formatted offset
     */
    private function format_offset($offset_seconds) {
        $hours = intval($offset_seconds / 3600);
        $minutes = abs(($offset_seconds % 3600) / 60);
        $sign = $hours >= 0 ? '+' : '-';
        return sprintf('%s%02d%02d', $sign, abs($hours), $minutes);
    }

    /**
     * Escape text for ICS format
     *
     * @param string $text Text to escape
     * @return string Escaped text
     */
    private function escape_text($text) {
        // Convert newlines to literal \n
        $text = preg_replace('/[\r\n]+/', '\\n', $text);
        // Escape special characters
        $replacements = [
            ',' => '\\,',
            ';' => '\\;',
            '\\' => '\\\\'
        ];
        return strtr($text, $replacements);
    }

    /**
     * Get the timezone being used
     *
     * @return DateTimeZone
     */
    public function get_timezone() {
        return $this->timezone;
    }

    /**
     * Get the timezone string being used
     *
     * @return string
     */
    public function get_timezone_string() {
        return $this->timezone_string;
    }
}

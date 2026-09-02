<?php

use PHPUnit\Framework\TestCase;

class IcsGeneratorTest extends TestCase {
    protected function setUp(): void {
        awecal_test_reset_meta();
    }

    private function make_post($id, $title = 'Test Event', $content = 'Event description') {
        return awecal_test_create_post($id, $title, $content);
    }

    public function test_generate_calendar_wraps_events_in_vcalendar() {
        $post = $this->make_post(50);
        update_post_meta(50, '_awecal_event_date_enabled', 1);
        update_post_meta(50, '_awecal_event_date', '2030-06-15');
        update_post_meta(50, '_awecal_event_recurrence_type', 'none');

        $ics = (new Awesome_Calendar_Events_ICS_Generator())->generate_calendar([$post]);

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringEndsWith("\r\nEND:VCALENDAR", $ics);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('SUMMARY:Test Event', $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20300615', $ics);
    }

    public function test_generate_calendar_reads_canonical_meta() {
        $post = $this->make_post(51);
        update_post_meta(51, '_awecal_event_date_enabled', 1);
        update_post_meta(51, '_awecal_event_date', '2030-06-15');
        update_post_meta(51, '_awecal_event_recurrence_type', 'none');
        update_post_meta(51, '_awecal_event_start_time', '10:30');

        $ics = (new Awesome_Calendar_Events_ICS_Generator())->generate_calendar([$post]);

        $this->assertStringContainsString('DTSTART:20300615T103000', $ics);
    }

    public function test_skips_posts_without_event_date() {
        $post = $this->make_post(52);

        $ics = (new Awesome_Calendar_Events_ICS_Generator())->generate_calendar([$post]);

        $this->assertStringNotContainsString('BEGIN:VEVENT', $ics);
    }

    public function test_escapes_special_characters_in_location() {
        $post = $this->make_post(53);
        update_post_meta(53, '_awecal_event_date_enabled', 1);
        update_post_meta(53, '_awecal_event_date', '2030-06-15');
        update_post_meta(53, '_awecal_event_recurrence_type', 'none');
        update_post_meta(53, '_awecal_event_location', "Hall A, room 2; level \"B\"\nline two");

        $ics = (new Awesome_Calendar_Events_ICS_Generator())->generate_calendar([$post]);

        // Newlines are collapsed by preg_replace to '\n', then the backslash
        // itself gets escaped by strtr — producing a literal '\\n' sequence.
        $this->assertStringContainsString('LOCATION:Hall A\, room 2\; level "B"\\\\nline two', $ics);
    }

    public function test_recurrence_rule_for_weekly_with_weekdays() {
        $post_id = 54;
        update_post_meta($post_id, '_awecal_event_recurrence_type', 'weekly');
        update_post_meta($post_id, '_awecal_event_recurrence_weekdays', '[0,5]');

        $rule = (new Awesome_Calendar_Events_ICS_Generator())->get_recurrence_rule($post_id);

        $this->assertSame('FREQ=WEEKLY;BYDAY=MO,SA', $rule);
    }

    public function test_recurrence_rule_with_interval_and_count() {
        $post_id = 55;
        update_post_meta($post_id, '_awecal_event_recurrence_type', 'daily');
        update_post_meta($post_id, '_awecal_event_recurrence_interval', 2);
        update_post_meta($post_id, '_awecal_event_recurrence_end_type', 'count');
        update_post_meta($post_id, '_awecal_event_recurrence_count', 5);

        $rule = (new Awesome_Calendar_Events_ICS_Generator())->get_recurrence_rule($post_id);

        $this->assertSame('FREQ=DAILY;INTERVAL=2;COUNT=5', $rule);
    }

    public function test_recurrence_rule_reads_canonical_meta() {
        $post_id = 56;
        update_post_meta($post_id, '_awecal_event_recurrence_type', 'monthly');
        update_post_meta($post_id, '_awecal_event_recurrence_interval', 3);

        $rule = (new Awesome_Calendar_Events_ICS_Generator())->get_recurrence_rule($post_id);

        $this->assertSame('FREQ=MONTHLY;INTERVAL=3', $rule);
    }

    public function test_recurrence_rule_null_for_non_recurring() {
        $post_id = 57;
        update_post_meta($post_id, '_awecal_event_recurrence_type', 'none');

        $this->assertNull((new Awesome_Calendar_Events_ICS_Generator())->get_recurrence_rule($post_id));
    }

    public function test_all_day_event_with_duration_spanning_days() {
        $post = $this->make_post(58);
        update_post_meta(58, '_awecal_event_date_enabled', 1);
        update_post_meta(58, '_awecal_event_date', '2030-06-15');
        update_post_meta(58, '_awecal_event_recurrence_type', 'none');
        update_post_meta(58, '_awecal_event_duration_hours', 36);

        $ics = (new Awesome_Calendar_Events_ICS_Generator())->generate_calendar([$post]);

        $this->assertStringContainsString('DTSTART;VALUE=DATE:20300615', $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20300616', $ics);
    }
}

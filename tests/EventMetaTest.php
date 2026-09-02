<?php

use PHPUnit\Framework\TestCase;

class EventMetaTest extends TestCase {
    private Awesome_Calendar_Events_Event_Meta $meta;

    protected function setUp(): void {
        awecal_test_reset_meta();
        $this->meta = new Awesome_Calendar_Events_Event_Meta();
    }

    /* ------------------------------------------------------------------ *
     * Registration
     * ------------------------------------------------------------------ */

    public function test_register_meta_registers_canonical_keys() {
        $this->meta->register_meta();

        foreach (['_awecal_event_date', '_awecal_event_location', '_awecal_event_recurrence_type'] as $key) {
            $this->assertArrayHasKey($key, $GLOBALS['__awecal_registered_meta'], "$key should be registered");
        }

        // Legacy keys are no longer registered.
        foreach (['_icob_event_date', '_icob_event_location', '_icob_event_recurrence_type'] as $key) {
            $this->assertArrayNotHasKey($key, $GLOBALS['__awecal_registered_meta'], "$key should not be registered");
        }

        $date_args = $GLOBALS['__awecal_registered_meta']['_awecal_event_date'];
        $this->assertTrue($date_args['show_in_rest']);
        $this->assertTrue($date_args['single']);
    }

    /* ------------------------------------------------------------------ *
     * Writing (new posts use the _awecal_ prefix)
     * ------------------------------------------------------------------ */

    public function test_save_meta_writes_canonical_awecal_keys() {
        $post_id = 10;
        $_POST = [
            'icob_event_meta_nonce' => 'nonce',
            'icob_event_date_enabled' => '1',
            'icob_event_date' => '2030-06-15',
            'icob_event_recurrence_type' => 'weekly',
            'icob_event_recurrence_interval' => '1',
            'icob_event_recurrence_weekdays' => ['0', '5'],
            'icob_event_recurrence_end_type' => 'count',
            'icob_event_recurrence_count' => '8',
            'icob_event_start_time' => '10:30',
            'icob_event_duration_hours' => '1.5',
            'icob_event_location' => 'Main Hall',
        ];

        $this->meta->save_meta($post_id);

        $this->assertSame(1, get_post_meta($post_id, '_awecal_event_date_enabled', true));
        $this->assertSame('2030-06-15', get_post_meta($post_id, '_awecal_event_date', true));
        $this->assertSame('weekly', get_post_meta($post_id, '_awecal_event_recurrence_type', true));
        $this->assertSame('[0,5]', get_post_meta($post_id, '_awecal_event_recurrence_weekdays', true));
        $this->assertSame('10:30', get_post_meta($post_id, '_awecal_event_start_time', true));
        $this->assertSame(1.5, get_post_meta($post_id, '_awecal_event_duration_hours', true));
        $this->assertSame(90, get_post_meta($post_id, '_awecal_event_duration_minutes', true));
        $this->assertSame('Main Hall', get_post_meta($post_id, '_awecal_event_location', true));

        // Legacy keys are no longer written.
        $this->assertSame('', get_post_meta($post_id, '_icob_event_date', true));
        $this->assertSame('', get_post_meta($post_id, '_icob_event_location', true));
    }

    public function test_save_meta_without_nonce_does_not_write() {
        $_POST = ['icob_event_date_enabled' => '1', 'icob_event_date' => '2030-06-15'];

        $this->meta->save_meta(11);

        $this->assertSame('', get_post_meta(11, '_awecal_event_date', true));
    }

    public function test_save_meta_stores_recurrence_end_date_only_when_end_type_is_date() {
        $post_id = 12;
        $_POST = [
            'icob_event_meta_nonce' => 'nonce',
            'icob_event_date_enabled' => '1',
            'icob_event_date' => '2030-06-15',
            'icob_event_recurrence_end_type' => 'date',
            'icob_event_recurrence_end_date' => '2030-12-31',
        ];

        $this->meta->save_meta($post_id);

        $this->assertSame('2030-12-31', get_post_meta($post_id, '_awecal_event_recurrence_end_date', true));

        $_POST['icob_event_recurrence_end_type'] = 'none';
        $this->meta->save_meta($post_id);
        $this->assertSame('', get_post_meta($post_id, '_awecal_event_recurrence_end_date', true));
    }

    /* ------------------------------------------------------------------ *
     * Reading
     * ------------------------------------------------------------------ */

    public function test_next_occurrence_reads_canonical_meta() {
        $post_id = 21;
        update_post_meta($post_id, '_awecal_event_date_enabled', 1);
        update_post_meta($post_id, '_awecal_event_date', '2030-06-15');
        update_post_meta($post_id, '_awecal_event_recurrence_type', 'none');

        $this->assertSame('2030-06-15', Awesome_Calendar_Events_Event_Meta::get_next_occurrence($post_id, '2030-01-01 00:00:00'));
    }

    public function test_next_occurrence_returns_null_when_disabled() {
        $post_id = 23;
        update_post_meta($post_id, '_awecal_event_date_enabled', 0);
        update_post_meta($post_id, '_awecal_event_date', '2030-06-15');

        $this->assertNull(Awesome_Calendar_Events_Event_Meta::get_next_occurrence($post_id, '2030-01-01 00:00:00'));
    }

    public function test_next_occurrence_daily_recurrence() {
        $post_id = 24;
        update_post_meta($post_id, '_awecal_event_date_enabled', 1);
        update_post_meta($post_id, '_awecal_event_date', '2030-06-15');
        update_post_meta($post_id, '_awecal_event_recurrence_type', 'daily');
        update_post_meta($post_id, '_awecal_event_recurrence_interval', 2);

        $this->assertSame('2030-06-21', Awesome_Calendar_Events_Event_Meta::get_next_occurrence($post_id, '2030-06-20 00:00:00'));
    }

    public function test_next_occurrence_weekly_recurrence_with_weekdays() {
        $post_id = 25;
        update_post_meta($post_id, '_awecal_event_date_enabled', 1);
        update_post_meta($post_id, '_awecal_event_date', '2030-06-15'); // Saturday
        update_post_meta($post_id, '_awecal_event_recurrence_type', 'weekly');
        update_post_meta($post_id, '_awecal_event_recurrence_weekdays', '[0]'); // Mondays

        $this->assertSame('2030-06-17', Awesome_Calendar_Events_Event_Meta::get_next_occurrence($post_id, '2030-06-16 00:00:00'));
    }

    public function test_next_occurrence_respects_count_end_condition() {
        $post_id = 26;
        update_post_meta($post_id, '_awecal_event_date_enabled', 1);
        update_post_meta($post_id, '_awecal_event_date', '2030-06-15');
        update_post_meta($post_id, '_awecal_event_recurrence_type', 'daily');
        update_post_meta($post_id, '_awecal_event_recurrence_end_type', 'count');
        update_post_meta($post_id, '_awecal_event_recurrence_count', 3);

        // Occurrence #3 (2030-06-17) is the last one.
        $this->assertSame('2030-06-17', Awesome_Calendar_Events_Event_Meta::get_next_occurrence($post_id, '2030-06-17 00:00:00'));
        $this->assertNull(Awesome_Calendar_Events_Event_Meta::get_next_occurrence($post_id, '2030-06-18 00:00:00'));
    }

    public function test_get_event_date_display_reads_canonical_meta() {
        $post_id = 30;
        update_post_meta($post_id, '_awecal_event_date_enabled', 1);
        update_post_meta($post_id, '_awecal_event_date', '2030-06-15');
        update_post_meta($post_id, '_awecal_event_recurrence_type', 'none');
        update_post_meta($post_id, '_awecal_event_start_time', '10:30');
        update_post_meta($post_id, '_awecal_event_duration_hours', 2);
        update_post_meta($post_id, '_awecal_event_location', 'Main Hall');

        $display = Awesome_Calendar_Events_Event_Meta::get_event_date_display($post_id, 'Y-m-d');

        $this->assertTrue($display['has_value']);
        $this->assertSame('2030-06-15', $display['iso']);
        $this->assertSame('10:30', $display['start_time']);
        $this->assertSame(2.0, $display['duration_hours']);
        $this->assertSame(120, $display['duration_minutes']);
        $this->assertSame('Main Hall', $display['location']);
    }

    public function test_get_event_date_display_converts_legacy_minutes() {
        $post_id = 31;
        update_post_meta($post_id, '_awecal_event_date_enabled', 1);
        update_post_meta($post_id, '_awecal_event_date', '2030-06-15');
        update_post_meta($post_id, '_awecal_event_recurrence_type', 'none');
        update_post_meta($post_id, '_awecal_event_duration_minutes', 90);

        $display = Awesome_Calendar_Events_Event_Meta::get_event_date_display($post_id, 'Y-m-d');

        $this->assertSame(1.5, $display['duration_hours']);
        $this->assertSame(90, $display['duration_minutes']);
    }

    /* ------------------------------------------------------------------ *
     * Weekday parsing
     * ------------------------------------------------------------------ */

    public function test_parse_weekday_string() {
        $this->assertSame([0, 2, 5], Awesome_Calendar_Events_Event_Meta::parse_weekday_string('[0,2,5]'));
        $this->assertSame([], Awesome_Calendar_Events_Event_Meta::parse_weekday_string('[]'));
        $this->assertSame([], Awesome_Calendar_Events_Event_Meta::parse_weekday_string(''));
        $this->assertSame([], Awesome_Calendar_Events_Event_Meta::parse_weekday_string('not-a-list'));
        $this->assertSame([1], Awesome_Calendar_Events_Event_Meta::parse_weekday_string('[1,9,-3]'));
        $this->assertSame([1, 3], Awesome_Calendar_Events_Event_Meta::parse_weekday_string('[3,1,1]'));
    }

    public function test_get_weekdays_supports_array_storage() {
        $post_id = 40;
        update_post_meta($post_id, '_awecal_event_recurrence_weekdays', [2, 0]);
        $this->assertSame([0, 2], Awesome_Calendar_Events_Event_Meta::get_weekdays($post_id));

        update_post_meta($post_id, '_awecal_event_recurrence_weekdays', '[5,1]');
        $this->assertSame([1, 5], Awesome_Calendar_Events_Event_Meta::get_weekdays($post_id));
    }
}

<?php

use PHPUnit\Framework\TestCase;

class MetaHelperTest extends TestCase {
    protected function setUp(): void {
        awecal_test_reset_meta();
    }

    public function test_meta_key_maps_legacy_prefix_to_new_prefix() {
        $this->assertSame('_awecal_event_date', awecal_meta_key('_icob_event_date'));
    }

    public function test_meta_key_keeps_new_prefix() {
        $this->assertSame('_awecal_event_date', awecal_meta_key('_awecal_event_date'));
    }

    public function test_meta_key_adds_new_prefix_to_bare_suffix() {
        $this->assertSame('_awecal_event_date', awecal_meta_key('event_date'));
    }

    public function test_reads_canonical_key_directly() {
        update_post_meta(1, '_awecal_event_date', '2030-06-15');
        $this->assertSame('2030-06-15', awecal_get_post_meta(1, '_awecal_event_date', true));
    }

    public function test_missing_meta_returns_empty_string() {
        $this->assertSame('', awecal_get_post_meta(42, '_awecal_event_date', true));
    }

    public function test_keys_from_neither_prefix_are_read_untouched() {
        update_post_meta(1, 'venue', 'Main Hall');
        $this->assertSame('Main Hall', awecal_get_post_meta(1, 'venue', true));
        $this->assertSame('', awecal_get_post_meta(1, 'nonexistent', true));
    }

    public function test_enabled_meta_query_targets_canonical_key_only() {
        $clause = awecal_event_date_enabled_meta_query();
        $this->assertSame('_awecal_event_date_enabled', $clause['key']);
        $this->assertSame('1', $clause['value']);
        $this->assertSame('=', $clause['compare']);
    }

    public function test_event_date_present_meta_query_targets_canonical_key_only() {
        $clause = awecal_event_date_present_meta_query();
        $this->assertSame('_awecal_event_date', $clause['key']);
        $this->assertSame('!=', $clause['compare']);
        $this->assertSame('', $clause['value']);
    }

    public function test_date_range_meta_query_uses_between_for_full_range() {
        $clause = awecal_event_date_range_meta_query('2030-01-01', '2030-12-31');
        $this->assertSame('_awecal_event_date', $clause['key']);
        $this->assertSame(['2030-01-01', '2030-12-31'], $clause['value']);
        $this->assertSame('BETWEEN', $clause['compare']);
        $this->assertSame('CHAR', $clause['type']);
    }

    public function test_date_range_meta_query_from_only_uses_gte() {
        $clause = awecal_event_date_range_meta_query('2030-01-01');
        $this->assertSame('2030-01-01', $clause['value']);
        $this->assertSame('>=', $clause['compare']);
    }

    public function test_date_range_meta_query_to_only_uses_lte() {
        $clause = awecal_event_date_range_meta_query('', '2030-12-31');
        $this->assertSame('2030-12-31', $clause['value']);
        $this->assertSame('<=', $clause['compare']);
    }

    public function test_announcement_enabled_meta_query_targets_canonical_key_only() {
        $clause = awecal_event_announcement_enabled_meta_query();
        $this->assertSame('AND', $clause['relation']);
        $this->assertCount(2, array_filter($clause, 'is_array'));
        foreach (array_values(array_filter($clause, 'is_array')) as $part) {
            $this->assertSame('_awecal_announcement', $part['key']);
            $this->assertSame('!=', $part['compare']);
            $this->assertSame('CHAR', $part['type']);
        }
        $this->assertSame('', $clause[0]['value']);
        $this->assertSame('0', $clause[1]['value']);
    }

    public function test_announcement_expiration_meta_query_targets_canonical_key_only() {
        $clause = awecal_event_announcement_expiration_meta_query('>=', '2030-01-01 00:00:00');
        $this->assertSame('_awecal_announcement_expiration', $clause['key']);
        $this->assertSame('>=', $clause['compare']);
        $this->assertSame('2030-01-01 00:00:00', $clause['value']);
        $this->assertSame('CHAR', $clause['type']);
    }

    public function test_recurrence_not_ended_meta_query_treats_empty_end_date_as_open() {
        // The metabox stores '' (not an absent key) when no end date is set;
        // the clause must match those rows, not only missing keys.
        $clause = awecal_event_recurrence_not_ended_meta_query('2030-01-01');

        $this->assertSame('OR', $clause['relation']);

        $keys = array_column(array_filter($clause, 'is_array'), 'key');
        $this->assertContains('_awecal_event_recurrence_end_date', $keys);

        $compares = array_column($clause, 'compare');
        $this->assertContains('NOT EXISTS', $compares);
        $this->assertContains('=', $compares);
        $this->assertContains('>=', $compares);

        $values = array_column($clause, 'value');
        $this->assertContains('', $values);
        $this->assertContains('2030-01-01', $values);
    }
}

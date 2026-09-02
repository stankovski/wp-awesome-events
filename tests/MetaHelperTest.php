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

    public function test_reads_new_prefix_value_directly() {
        update_post_meta(1, '_awecal_event_date', '2030-06-15');
        $this->assertSame('2030-06-15', awecal_get_post_meta(1, '_icob_event_date', true));
        $this->assertSame('2030-06-15', awecal_get_post_meta(1, '_awecal_event_date', true));
    }

    public function test_falls_back_to_legacy_prefix_when_new_missing() {
        update_post_meta(1, '_icob_event_date', '2020-01-01');
        $this->assertSame('2020-01-01', awecal_get_post_meta(1, '_icob_event_date', true));
    }

    public function test_reading_canonical_key_falls_back_to_legacy_data() {
        update_post_meta(1, '_icob_event_date', '2020-01-01');
        $this->assertSame('2020-01-01', awecal_get_post_meta(1, '_awecal_event_date', true));
    }

    public function test_reading_canonical_key_with_missing_legacy_data_returns_empty() {
        $this->assertSame('', awecal_get_post_meta(1, '_awecal_event_date', true));
    }

    public function test_new_prefix_wins_over_legacy_prefix() {
        update_post_meta(1, '_icob_event_date', '2020-01-01');
        update_post_meta(1, '_awecal_event_date', '2030-06-15');
        $this->assertSame('2030-06-15', awecal_get_post_meta(1, '_icob_event_date', true));
    }

    public function test_empty_new_value_is_not_shadowed_by_legacy_value() {
        update_post_meta(1, '_icob_event_location', 'Old Hall');
        update_post_meta(1, '_awecal_event_location', '');
        $this->assertSame('', awecal_get_post_meta(1, '_icob_event_location', true));
    }

    public function test_missing_meta_returns_empty_string() {
        $this->assertSame('', awecal_get_post_meta(42, '_icob_event_date', true));
    }

    public function test_keys_from_neither_prefix_are_read_untouched() {
        update_post_meta(1, 'venue', 'Main Hall');
        $this->assertSame('Main Hall', awecal_get_post_meta(1, 'venue', true));
        $this->assertSame('', awecal_get_post_meta(1, 'nonexistent', true));
    }

    public function test_enabled_meta_query_matches_both_prefixes() {
        $clause = awecal_event_date_enabled_meta_query();
        $this->assertSame('OR', $clause['relation']);
        $keys = array_column(array_filter($clause, 'is_array'), 'key');
        $this->assertContains('_awecal_event_date_enabled', $keys);
        $this->assertContains('_icob_event_date_enabled', $keys);
    }

    public function test_event_date_present_meta_query_matches_both_prefixes() {
        $clause = awecal_event_date_present_meta_query();
        $this->assertSame('OR', $clause['relation']);
        $keys = array_column(array_filter($clause, 'is_array'), 'key');
        $this->assertContains('_awecal_event_date', $keys);
        $this->assertContains('_icob_event_date', $keys);
    }

    public function test_date_range_meta_query_uses_between_for_full_range() {
        $clause = awecal_event_date_range_meta_query('2030-01-01', '2030-12-31');
        $this->assertSame('OR', $clause['relation']);
        $inner = array_values(array_filter($clause, 'is_array'));
        $this->assertCount(2, $inner);
        foreach ($inner as $part) {
            $this->assertSame(['2030-01-01', '2030-12-31'], $part['value']);
            $this->assertSame('BETWEEN', $part['compare']);
        }
        $keys = array_column($inner, 'key');
        $this->assertContains('_awecal_event_date', $keys);
        $this->assertContains('_icob_event_date', $keys);
    }

    public function test_date_range_meta_query_from_only_uses_gte() {
        $clause = awecal_event_date_range_meta_query('2030-01-01');
        $inner = array_values(array_filter($clause, 'is_array'));
        foreach ($inner as $part) {
            $this->assertSame('2030-01-01', $part['value']);
            $this->assertSame('>=', $part['compare']);
        }
    }

    public function test_date_range_meta_query_to_only_uses_lte() {
        $clause = awecal_event_date_range_meta_query('', '2030-12-31');
        $inner = array_values(array_filter($clause, 'is_array'));
        foreach ($inner as $part) {
            $this->assertSame('2030-12-31', $part['value']);
            $this->assertSame('<=', $part['compare']);
        }
    }

    public function test_announcement_enabled_meta_query_matches_both_prefixes() {
        $clause = awecal_event_announcement_enabled_meta_query();
        $this->assertSame('OR', $clause['relation']);
        // Each prefix branch is a nested AND group of non-empty comparisons.
        $this->assertSame('AND', $clause[0]['relation']);
        $this->assertSame('AND', $clause[1]['relation']);
        // Collect keys recursively.
        $keys = [];
        array_walk_recursive($clause, function($value, $key) use (&$keys) {
            if ($key === 'key') {
                $keys[] = $value;
            }
        });
        $this->assertContains('_awecal_announcement', $keys);
        $this->assertContains('_icob_announcement', $keys);
        $this->assertCount(4, $keys);
        foreach ($keys as $key) {
            $this->assertSame('', $clause[$key === '_icob_announcement' ? 1 : 0][0]['value']);
            $this->assertSame('!=', $clause[$key === '_icob_announcement' ? 1 : 0][0]['compare']);
        }
    }

    public function test_announcement_expiration_meta_query_matches_both_prefixes() {
        $clause = awecal_event_announcement_expiration_meta_query('>=', '2030-01-01 00:00:00');
        $this->assertSame('OR', $clause['relation']);
        $inner = array_values(array_filter($clause, 'is_array'));
        $this->assertCount(2, $inner);
        $keys = array_column($inner, 'key');
        $this->assertContains('_awecal_announcement_expiration', $keys);
        $this->assertContains('_icob_announcement_expiration', $keys);
        foreach ($inner as $part) {
            $this->assertSame('>=', $part['compare']);
            $this->assertSame('2030-01-01 00:00:00', $part['value']);
        }
    }

    public function test_recurrence_not_ended_meta_query_treats_empty_end_date_as_open() {
        // The metabox stores '' (not an absent key) when no end date is set;
        // the clause must match those rows, not only missing keys.
        $clause = awecal_event_recurrence_not_ended_meta_query('2030-01-01');

        $keys = [];
        array_walk_recursive($clause, function($value, $key) use (&$keys) {
            if ($key === 'key') {
                $keys[] = $value;
            }
        });
        $this->assertContains('_awecal_event_recurrence_end_date', $keys);
        $this->assertContains('_icob_event_recurrence_end_date', $keys);

        $compares = [];
        array_walk_recursive($clause, function($value, $key) use (&$compares) {
            if ($key === 'compare') {
                $compares[] = $value;
            }
        });
        $this->assertContains('NOT EXISTS', $compares);
        $this->assertContains('=', $compares);
        $this->assertContains('>=', $compares);

        $values = [];
        array_walk_recursive($clause, function($value, $key) use (&$values) {
            if ($key === 'value') {
                $values[] = $value;
            }
        });
        $this->assertContains('', $values);
        $this->assertContains('2030-01-01', $values);
    }
}

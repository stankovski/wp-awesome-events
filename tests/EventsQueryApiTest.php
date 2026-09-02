<?php

use PHPUnit\Framework\TestCase;

class EventsQueryApiTest extends TestCase {
    private Awesome_Calendar_Events_Events_Query_API $api;

    protected function setUp(): void {
        awecal_test_reset_meta();
        $this->api = new Awesome_Calendar_Events_Events_Query_API();
    }

    /* ------------------------------------------------------------------ *
     * Announcement meta registration & saving
     * ------------------------------------------------------------------ */

    public function test_announcement_meta_registered_for_canonical_prefix() {
        $meta = new Awesome_Calendar_Events_Event_Meta();
        $meta->register_meta();

        foreach (['_awecal_announcement', '_awecal_announcement_expiration'] as $key) {
            $this->assertArrayHasKey($key, $GLOBALS['__awecal_registered_meta'], "$key should be registered");
        }

        foreach (['_icob_announcement', '_icob_announcement_expiration'] as $key) {
            $this->assertArrayNotHasKey($key, $GLOBALS['__awecal_registered_meta'], "$key should not be registered");
        }

        $this->assertSame('string', $GLOBALS['__awecal_registered_meta']['_awecal_announcement']['type']);
        $this->assertSame('string', $GLOBALS['__awecal_registered_meta']['_awecal_announcement_expiration']['type']);
    }

    public function test_save_meta_writes_canonical_announcement_keys() {
        $post_id = 10;
        $_POST = [
            'icob_event_meta_nonce' => 'nonce',
            'icob_announcement' => '1',
            'icob_announcement_text' => 'Service moved to the main hall',
            'icob_announcement_expiration' => '2030-06-15T23:59',
        ];

        (new Awesome_Calendar_Events_Event_Meta())->save_meta($post_id);

        $this->assertSame('Service moved to the main hall', get_post_meta($post_id, '_awecal_announcement', true));
        $this->assertSame('2030-06-15 23:59:00', get_post_meta($post_id, '_awecal_announcement_expiration', true));
        $this->assertArrayNotHasKey('_icob_announcement', $GLOBALS['__awecal_meta'][$post_id]);
    }

    public function test_save_meta_stores_empty_string_when_checked_without_text() {
        $post_id = 12;
        $_POST = [
            'icob_event_meta_nonce' => 'nonce',
            'icob_announcement' => '1',
        ];

        (new Awesome_Calendar_Events_Event_Meta())->save_meta($post_id);

        $this->assertSame('', get_post_meta($post_id, '_awecal_announcement', true));
    }

    public function test_save_meta_clears_expiration_when_unchecked() {
        $post_id = 11;
        update_post_meta($post_id, '_awecal_announcement', 'Pool closed on Friday');
        update_post_meta($post_id, '_awecal_announcement_expiration', '2030-06-15 23:59:00');

        $_POST = [
            'icob_event_meta_nonce' => 'nonce',
            'icob_announcement_expiration' => '2030-06-15T23:59',
        ];

        (new Awesome_Calendar_Events_Event_Meta())->save_meta($post_id);

        $this->assertSame('', get_post_meta($post_id, '_awecal_announcement', true));
        $this->assertSame('', get_post_meta($post_id, '_awecal_announcement_expiration', true));
    }

    public function test_normalize_datetime_input_accepts_mysql_datetime() {
        $this->assertSame('2030-06-15 10:30:00', Awesome_Calendar_Events_Event_Meta::normalize_datetime_input('2030-06-15 10:30:00'));
        $this->assertSame('2030-06-15 00:00:00', Awesome_Calendar_Events_Event_Meta::normalize_datetime_input('2030-06-15'));
    }

    public function test_normalize_datetime_input_rejects_garbage() {
        $this->assertSame('', Awesome_Calendar_Events_Event_Meta::normalize_datetime_input('not-a-date'));
        $this->assertSame('', Awesome_Calendar_Events_Event_Meta::normalize_datetime_input(''));
    }

    /* ------------------------------------------------------------------ *
     * Param sanitization / validation
     * ------------------------------------------------------------------ */

    public function test_sanitize_slug_list_splits_and_normalizes() {
        $this->assertSame(
            ['music', 'outdoor'],
            $this->api->sanitize_slug_list(' Music, outdoor ,,music')
        );
    }

    public function test_sanitize_slug_list_caps_number_of_terms() {
        $input = implode(',', array_map(fn($i) => "tag-$i", range(1, 100)));
        $this->assertCount(Awesome_Calendar_Events_Events_Query_API::MAX_TERMS, $this->api->sanitize_slug_list($input));
    }

    public function test_validate_date_param() {
        $this->assertTrue($this->api->validate_date_param('2030-06-15'));
        $this->assertTrue($this->api->validate_date_param(''));
        $this->assertFalse($this->api->validate_date_param('15-06-2030'));
        $this->assertFalse($this->api->validate_date_param('2030-13-40'));
        $this->assertFalse($this->api->validate_date_param('2030-06-15; DROP TABLE wp_posts'));
    }

    /* ------------------------------------------------------------------ *
     * Query args building
     * ------------------------------------------------------------------ */

    private function make_request(array $params): object {
        return new class($params) {
            private array $params;
            public function __construct(array $params) { $this->params = $params; }
            public function get_param($name) { return $this->params[$name] ?? null; }
            public function get_params() { return $this->params; }
        };
    }

    public function test_build_query_args_base() {
        $args = $this->api->build_query_args($this->make_request([
            'per_page' => 50,
            'orderby' => 'event_date',
            'order' => 'desc',
        ]), 2);

        $this->assertSame('post', $args['post_type']);
        $this->assertSame('publish', $args['post_status']);
        $this->assertSame(50, $args['posts_per_page']);
        $this->assertSame(2, $args['paged']);
        $this->assertSame('DESC', $args['order']);
        $this->assertTrue($args['ignore_sticky_posts']);
        $this->assertFalse($args['update_post_term_cache']);
        $this->assertSame('AND', $args['meta_query']['relation']);
        $this->assertArrayNotHasKey('tax_query', $args);
        $this->assertArrayNotHasKey('s', $args);
    }

    public function test_build_query_args_includes_recurrence_end_date_clause() {
        $args = $this->api->build_query_args($this->make_request([]));
        $clauses = array_values(array_filter($args['meta_query'], 'is_array'));
        // [0] = enabled clause, [1] = recurrence-not-ended clause
        $this->assertCount(2, $clauses);
        $this->assertSame('OR', $clauses[1]['relation']);

        $keys = [];
        $compares = [];
        array_walk_recursive($clauses[1], function($v, $k) use (&$keys, &$compares) {
            if ($k === 'key') { $keys[] = $v; }
            if ($k === 'compare') { $compares[] = $v; }
        });
        $this->assertContains('_awecal_event_recurrence_end_date', $keys);
        $this->assertNotContains('_icob_event_recurrence_end_date', $keys);
        $this->assertContains('NOT EXISTS', $compares);
        $this->assertContains('>=', $compares);
    }

    public function test_build_query_args_date_range_uses_helper() {
        $args = $this->api->build_query_args($this->make_request([
            'date_from' => '2030-01-01',
            'date_to' => '2030-12-31',
        ]));

        $range = $args['meta_query'][1];
        $this->assertSame('_awecal_event_date', $range['key']);
        $this->assertSame('BETWEEN', $range['compare']);
        $this->assertArrayNotHasKey('relation', $range);
    }

    public function test_build_query_args_no_date_filter_has_no_range_clause() {
        $args = $this->api->build_query_args($this->make_request([]));
        $clauses = array_values(array_filter($args['meta_query'], 'is_array'));
        // [0] = enabled clause, [1] = recurrence-not-ended clause; no date range.
        $this->assertCount(2, $clauses);
        $this->assertSame('AND', $args['meta_query']['relation']);
        $compares = [];
        array_walk_recursive($args['meta_query'], function($v, $k) use (&$compares) {
            if ($k === 'compare') { $compares[] = $v; }
        });
        $this->assertNotContains('BETWEEN', $compares);
    }

    public function test_build_query_args_taxonomy_filters() {
        $args = $this->api->build_query_args($this->make_request([
            'categories' => ['music'],
            'tags' => ['jazz', 'outdoor'],
            'tag_operator' => 'all',
        ]));

        $this->assertSame('AND', $args['tax_query']['relation']);
        $clauses = array_values(array_filter($args['tax_query'], 'is_array'));
        $this->assertSame('category', $clauses[0]['taxonomy']);
        $this->assertSame(['music'], $clauses[0]['terms']);
        $this->assertSame('post_tag', $clauses[1]['taxonomy']);
        $this->assertSame(['jazz', 'outdoor'], $clauses[1]['terms']);
        $this->assertSame('AND', $clauses[1]['operator']);
    }

    public function test_build_query_args_tags_any_operator() {
        $args = $this->api->build_query_args($this->make_request([
            'tags' => ['jazz'],
        ]));
        $clauses = array_values(array_filter($args['tax_query'], 'is_array'));
        $this->assertSame('IN', $clauses[0]['operator']);
    }

    public function test_build_query_args_title_orderby_skips_meta_key() {
        $args = $this->api->build_query_args($this->make_request([
            'orderby' => 'title',
        ]));
        $this->assertSame('title', $args['orderby']);
        $this->assertArrayNotHasKey('meta_key', $args);
    }

    /* ------------------------------------------------------------------ *
     * Item preparation (minimal payload)
     * ------------------------------------------------------------------ */

    public function test_prepare_item_minimal() {
        $post = awecal_test_create_post(1, 'Summer Concert', '<p>An evening of jazz.</p>');
        update_post_meta(1, '_awecal_event_date_enabled', 1);
        update_post_meta(1, '_awecal_event_date', '2026-09-04');
        update_post_meta(1, '_awecal_event_start_time', '18:30');
        update_post_meta(1, '_awecal_event_duration_hours', 2);
        update_post_meta(1, '_awecal_event_location', 'City Park');

        $item = Awesome_Calendar_Events_Events_Query_API::prepare_item($post);

        $this->assertSame(1, $item['postId']);
        $this->assertSame('Summer Concert', $item['title']);
        $this->assertSame('An evening of jazz.', $item['snippet']);
        $this->assertSame('https://example.com/?p=1', $item['url']);
        $this->assertSame('2026-08-20T14:05:00+00:00', $item['publishedAt']);
        $this->assertFalse($item['announcement']);
        $this->assertNull($item['announcementEndDateTime']);
        $this->assertSame('2026-09-04', $item['event']['date']);
        $this->assertSame('18:30', $item['event']['startTime']);
        $this->assertSame(2.0, $item['event']['durationHours']);
        $this->assertSame('City Park', $item['event']['location']);
        // One-off event: no recurrence rule.
        $this->assertNull($item['event']['recurrenceRule']);

        // Details must not leak into the minimal payload.
        $this->assertArrayNotHasKey('fullBody', $item);
        $this->assertArrayNotHasKey('imageUrl', $item);
        $this->assertArrayNotHasKey('excerpt', $item);
    }

    public function test_prepare_item_recurring_event_exposes_rrule() {
        $post = awecal_test_create_post(2, 'Weekly Practice');
        update_post_meta(2, '_awecal_event_date_enabled', 1);
        update_post_meta(2, '_awecal_event_date', '2026-09-04');
        update_post_meta(2, '_awecal_event_recurrence_type', 'weekly');
        update_post_meta(2, '_awecal_event_recurrence_weekdays', '[4]');

        $item = Awesome_Calendar_Events_Events_Query_API::prepare_item($post);

        $this->assertSame('FREQ=WEEKLY;BYDAY=FR', $item['event']['recurrenceRule']);
    }

    public function test_prepare_item_reads_canonical_announcement_meta() {
        $post = awecal_test_create_post(3, 'Announcement');
        update_post_meta(3, '_awecal_announcement', 'Pool closed on Friday');
        update_post_meta(3, '_awecal_announcement_expiration', '2026-09-10 23:59:00');

        $item = Awesome_Calendar_Events_Events_Query_API::prepare_item($post);

        $this->assertTrue($item['announcement']);
        $this->assertSame('2026-09-10T23:59:00+00:00', $item['announcementEndDateTime']);
    }

    public function test_prepare_item_event_null_when_not_enabled() {
        $post = awecal_test_create_post(5, 'Plain Post');
        $item = Awesome_Calendar_Events_Events_Query_API::prepare_item($post);
        $this->assertNull($item['event']);
    }

    public function test_prepare_item_snippet_falls_back_to_trimmed_content() {
        $post = awecal_test_create_post(6, 'Long Post', str_repeat('word ', 100));
        $item = Awesome_Calendar_Events_Events_Query_API::prepare_item($post);
        $this->assertSame(1, preg_match('/…$/', $item['snippet']));
        $this->assertLessThanOrEqual(41, str_word_count($item['snippet']));
    }

    /* ------------------------------------------------------------------ *
     * Item preparation (details payload)
     * ------------------------------------------------------------------ */

    public function test_prepare_item_details() {
        $post = awecal_test_create_post(7, 'Detailed Post', '<p>Full body content.</p>');
        $GLOBALS['__awecal_posts'][7]['excerpt'] = 'Custom excerpt.';
        $GLOBALS['__awecal_posts'][7]['thumbnail_url'] = 'https://example.com/photo-1024x683.jpg';
        $GLOBALS['__awecal_posts'][7]['thumbnail_id'] = 99;
        update_post_meta(99, '_wp_attachment_image_alt', 'A photo');
        $GLOBALS['__awecal_posts'][7]['terms'] = [
            'category' => [(object) ['slug' => 'music']],
            'post_tag' => [(object) ['slug' => 'jazz'], (object) ['slug' => 'outdoor']],
        ];

        $item = Awesome_Calendar_Events_Events_Query_API::prepare_item($post, true);

        $this->assertSame('Custom excerpt.', $item['excerpt']);
        $this->assertSame('https://example.com/photo-1024x683.jpg', $item['imageUrl']);
        $this->assertSame('A photo', $item['imageAlt']);
        $this->assertSame('<p>Full body content.</p>', $item['fullBody']);
        $this->assertSame(['music'], $item['categories']);
        $this->assertSame(['jazz', 'outdoor'], $item['tags']);

        // Core minimal fields are still present.
        $this->assertSame(7, $item['postId']);
    }

    public function test_prepare_item_details_defaults_for_missing_terms_and_image() {
        $post = awecal_test_create_post(8, 'Bare Post');
        $item = Awesome_Calendar_Events_Events_Query_API::prepare_item($post, true);
        $this->assertFalse($item['imageUrl']);
        $this->assertSame([], $item['categories']);
        $this->assertSame([], $item['tags']);
    }

    /* ------------------------------------------------------------------ *
     * Cache invalidation
     * ------------------------------------------------------------------ */

    public function test_flush_cache_bumps_version() {
        $this->api->flush_cache();
        $this->api->flush_cache();
        $this->assertSame(3, get_option('awesome_calendar_events_api_cache_version'));
    }
}

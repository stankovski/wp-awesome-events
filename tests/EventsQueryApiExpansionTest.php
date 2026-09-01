<?php

use PHPUnit\Framework\TestCase;

class EventsQueryApiExpansionTest extends TestCase {
    private Awesome_Calendar_Events_Events_Query_API $api;

    protected function setUp(): void {
        awecal_test_reset_meta();
        $this->api = new Awesome_Calendar_Events_Events_Query_API();
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    private function make_request(array $params): object {
        return new class($params) {
            private array $params;
            public function __construct(array $params) { $this->params = $params; }
            public function get_param($name) { return $this->params[$name] ?? null; }
            public function get_params() { return $this->params; }
        };
    }

    private function make_one_off($id, $date) {
        $post = awecal_test_create_post($id, "One-off $id");
        update_post_meta($id, '_awecal_event_date_enabled', 1);
        update_post_meta($id, '_awecal_event_date', $date);
        return $post;
    }

    private function make_daily($id, $date, $interval = 1, array $extra = []) {
        $post = awecal_test_create_post($id, "Daily $id");
        update_post_meta($id, '_awecal_event_date_enabled', 1);
        update_post_meta($id, '_awecal_event_date', $date);
        update_post_meta($id, '_awecal_event_recurrence_type', 'daily');
        update_post_meta($id, '_awecal_event_recurrence_interval', $interval);
        foreach ($extra as $k => $v) {
            update_post_meta($id, "_awecal_$k", $v);
        }
        return $post;
    }

    private function make_weekly($id, $date, $weekdays, array $extra = []) {
        $post = awecal_test_create_post($id, "Weekly $id");
        update_post_meta($id, '_awecal_event_date_enabled', 1);
        update_post_meta($id, '_awecal_event_date', $date);
        update_post_meta($id, '_awecal_event_recurrence_type', 'weekly');
        update_post_meta($id, '_awecal_event_recurrence_weekdays', '[' . implode(',', $weekdays) . ']');
        foreach ($extra as $k => $v) {
            update_post_meta($id, "_awecal_$k", $v);
        }
        return $post;
    }

    private function occurrence_dates(array $items): array {
        return array_map(fn($i) => $i['occurrence']['date'], $items);
    }

    /* ------------------------------------------------------------------ *
     * Recurrence not-ended meta query (DB-level clause)
     * ------------------------------------------------------------------ */

    public function test_not_ended_meta_query_structure() {
        $clause = awecal_event_recurrence_not_ended_meta_query('2030-01-01');
        $this->assertSame('AND', $clause['relation']);

        $groups = array_values(array_filter($clause, 'is_array'));
        $this->assertCount(2, $groups); // canonical group + legacy group

        $keys = [];
        $compares = [];
        array_walk_recursive($clause, function($v, $k) use (&$keys, &$compares) {
            if ($k === 'key') { $keys[] = $v; }
            if ($k === 'compare') { $compares[] = $v; }
        });
        $this->assertContains('_awecal_event_recurrence_end_date', $keys);
        $this->assertContains('_icob_event_recurrence_end_date', $keys);
        // Each key gets a NOT EXISTS and a >= branch so events without an
        // end date are not excluded.
        $this->assertSame(2, count(array_keys($compares, 'NOT EXISTS')));
        $this->assertSame(2, count(array_keys($compares, '>=')));
    }

    public function test_expanded_query_uses_window_from_as_end_date_reference() {
        $args = $this->api->build_query_args($this->make_request([]), 1, true, '2030-03-01');

        // Expanded queries must NOT contain the original-date range clause.
        $flat = [];
        array_walk_recursive($args['meta_query'], function($v, $k) use (&$flat) {
            if ($k === 'compare' || $k === 'value') { $flat[] = $v; }
        });
        $this->assertNotContains('BETWEEN', $flat);

        // ... and must carry the not-ended clause bound to the window start.
        $encoded = wp_json_encode($args['meta_query']);
        $this->assertStringContainsString('2030-03-01', $encoded);
        $this->assertTrue($args['no_found_rows']);
    }

    public function test_collapsed_query_keeps_date_range_clause() {
        $args = $this->api->build_query_args($this->make_request([
            'date_from' => '2030-01-01',
            'date_to' => '2030-01-31',
        ]));
        $encoded = wp_json_encode($args['meta_query']);
        $this->assertStringContainsString('BETWEEN', $encoded);
        $this->assertStringContainsString('2030-01-01', $encoded);
    }

    /* ------------------------------------------------------------------ *
     * get_occurrences_between
     * ------------------------------------------------------------------ */

    public function test_occurrences_one_off_inside_window() {
        $this->make_one_off(1, '2030-01-05');
        $this->assertSame(
            ['2030-01-05'],
            Awesome_Calendar_Events_Event_Meta::get_occurrences_between(1, '2030-01-01', '2030-01-31')
        );
    }

    public function test_occurrences_one_off_outside_window() {
        $this->make_one_off(1, '2030-02-05');
        $this->assertSame(
            [],
            Awesome_Calendar_Events_Event_Meta::get_occurrences_between(1, '2030-01-01', '2030-01-31')
        );
    }

    public function test_occurrences_daily_interval() {
        $this->make_daily(1, '2030-01-01', 2);
        $this->assertSame(
            ['2030-01-01', '2030-01-03', '2030-01-05', '2030-01-07', '2030-01-09'],
            Awesome_Calendar_Events_Event_Meta::get_occurrences_between(1, '2030-01-01', '2030-01-10')
        );
    }

    public function test_occurrences_weekly_byday() {
        // 2030-01-04 is a Friday; weekdays [4] = Friday (Mon=0..Sun=6).
        $this->make_weekly(1, '2030-01-04', [4]);
        $this->assertSame(
            ['2030-01-04', '2030-01-11', '2030-01-18', '2030-01-25'],
            Awesome_Calendar_Events_Event_Meta::get_occurrences_between(1, '2030-01-01', '2030-01-31')
        );
    }

    public function test_occurrences_recurring_started_before_window() {
        // Daily every 7 days started 2020-01-01: occurrences land on
        // 2030-01-02 and 2030-01-09 within the window.
        $this->make_daily(1, '2020-01-01', 7);
        $this->assertSame(
            ['2030-01-02', '2030-01-09'],
            Awesome_Calendar_Events_Event_Meta::get_occurrences_between(1, '2030-01-01', '2030-01-10')
        );
    }

    public function test_occurrences_respects_count_end_condition() {
        $this->make_daily(1, '2030-01-01', 1, ['event_recurrence_end_type' => 'count', 'event_recurrence_count' => 3]);
        $this->assertSame(
            ['2030-01-01', '2030-01-02', '2030-01-03'],
            Awesome_Calendar_Events_Event_Meta::get_occurrences_between(1, '2030-01-01', '2030-01-31')
        );
    }

    public function test_occurrences_respects_end_date() {
        $this->make_daily(1, '2030-01-01', 1, ['event_recurrence_end_type' => 'date', 'event_recurrence_end_date' => '2030-01-03']);
        $this->assertSame(
            ['2030-01-01', '2030-01-02', '2030-01-03'],
            Awesome_Calendar_Events_Event_Meta::get_occurrences_between(1, '2030-01-01', '2030-01-31')
        );
        // Window entirely after the end date.
        $this->assertSame(
            [],
            Awesome_Calendar_Events_Event_Meta::get_occurrences_between(1, '2030-02-01', '2030-02-28')
        );
    }

    public function test_occurrences_materialize_from_skips_earlier_dates() {
        $this->make_daily(1, '2030-01-01');
        $this->assertSame(
            ['2030-01-05', '2030-01-06', '2030-01-07', '2030-01-08', '2030-01-09', '2030-01-10'],
            Awesome_Calendar_Events_Event_Meta::get_occurrences_between(1, '2030-01-01', '2030-01-10', 366, '2030-01-05')
        );
    }

    public function test_occurrences_limit_caps_materialization() {
        $this->make_daily(1, '2030-01-01');
        $this->assertSame(
            ['2030-01-01', '2030-01-02'],
            Awesome_Calendar_Events_Event_Meta::get_occurrences_between(1, '2030-01-01', '2030-12-31', 2)
        );
    }

    public function test_occurrences_disabled_event_returns_empty() {
        $post = $this->make_daily(1, '2030-01-01');
        update_post_meta(1, '_awecal_event_date_enabled', 0);
        $this->assertSame(
            [],
            Awesome_Calendar_Events_Event_Meta::get_occurrences_between(1, '2030-01-01', '2030-12-31')
        );
    }

    public function test_occurrences_invalid_window_returns_empty() {
        $this->make_daily(1, '2030-01-01');
        $this->assertSame(
            [],
            Awesome_Calendar_Events_Event_Meta::get_occurrences_between(1, '2030-12-31', '2030-01-01')
        );
    }

    /* ------------------------------------------------------------------ *
     * get_last_occurrence_date (count-based staleness)
     * ------------------------------------------------------------------ */

    public function test_last_occurrence_one_off_is_event_date() {
        $this->make_one_off(1, '2030-01-05');
        $this->assertSame('2030-01-05', Awesome_Calendar_Events_Event_Meta::get_last_occurrence_date(1));
    }

    public function test_last_occurrence_with_end_date() {
        $this->make_daily(1, '2030-01-01', 1, ['event_recurrence_end_type' => 'date', 'event_recurrence_end_date' => '2030-06-30']);
        $this->assertSame('2030-06-30', Awesome_Calendar_Events_Event_Meta::get_last_occurrence_date(1));
    }

    public function test_last_occurrence_with_count_daily() {
        // 3 daily occurrences starting 2030-01-01 → last = 2030-01-03.
        $this->make_daily(1, '2030-01-01', 1, ['event_recurrence_end_type' => 'count', 'event_recurrence_count' => 3]);
        $this->assertSame('2030-01-03', Awesome_Calendar_Events_Event_Meta::get_last_occurrence_date(1));
    }

    public function test_last_occurrence_with_count_weekly_byday() {
        // 3 Friday occurrences starting Fri 2030-01-04 → last = 2030-01-18.
        $this->make_weekly(1, '2030-01-04', [4], ['event_recurrence_end_type' => 'count', 'event_recurrence_count' => 3]);
        $this->assertSame('2030-01-18', Awesome_Calendar_Events_Event_Meta::get_last_occurrence_date(1));
    }

    public function test_last_occurrence_unlimited_returns_null() {
        $this->make_daily(1, '2030-01-01');
        $this->assertNull(Awesome_Calendar_Events_Event_Meta::get_last_occurrence_date(1));
    }

    public function test_last_occurrence_count_zero_is_unlimited() {
        $this->make_daily(1, '2030-01-01', 1, ['event_recurrence_end_type' => 'count', 'event_recurrence_count' => 0]);
        $this->assertNull(Awesome_Calendar_Events_Event_Meta::get_last_occurrence_date(1));
    }

    public function test_last_occurrence_disabled_returns_null() {
        $this->make_daily(1, '2030-01-01', 1, ['event_recurrence_end_type' => 'count', 'event_recurrence_count' => 3]);
        update_post_meta(1, '_awecal_event_date_enabled', 0);
        $this->assertNull(Awesome_Calendar_Events_Event_Meta::get_last_occurrence_date(1));
    }

    public function test_get_next_occurrence_still_works_after_refactor() {
        // Daily every 3 days starting 2 days before "from" → next = from + 1.
        $this->make_daily(1, '2030-06-15', 3);
        $this->assertSame('2030-06-18', Awesome_Calendar_Events_Event_Meta::get_next_occurrence(1, '2030-06-17 00:00:00'));
        // First occurrence (original date) counts when in the future.
        $this->assertSame('2030-06-15', Awesome_Calendar_Events_Event_Meta::get_next_occurrence(1, '2030-06-10 00:00:00'));
    }

    /* ------------------------------------------------------------------ *
     * Pagination tokens
     * ------------------------------------------------------------------ */

    public function test_page_token_roundtrip() {
        $token = Awesome_Calendar_Events_Events_Query_API::create_page_token(['p' => 3, 'd' => '2030-01-18', 't' => 7]);
        $parsed = Awesome_Calendar_Events_Events_Query_API::parse_page_token($token);
        $this->assertSame(['p' => 3, 'd' => '2030-01-18', 't' => 7], $parsed);
    }

    public function test_page_token_collapsed_has_null_date() {
        $token = Awesome_Calendar_Events_Events_Query_API::create_page_token(['p' => 2]);
        $parsed = Awesome_Calendar_Events_Events_Query_API::parse_page_token($token);
        $this->assertSame(2, $parsed['p']);
        $this->assertNull($parsed['d']);
        $this->assertSame(0, $parsed['t']);
    }

    public function test_tampered_token_is_rejected() {
        $token = Awesome_Calendar_Events_Events_Query_API::create_page_token(['p' => 2, 'd' => '2030-01-18']);
        $tampered = $token . 'x'; // corrupt signature
        $this->assertTrue(is_wp_error(Awesome_Calendar_Events_Events_Query_API::parse_page_token($tampered)));
        $this->assertTrue(is_wp_error(Awesome_Calendar_Events_Events_Query_API::parse_page_token('garbage')));
        $this->assertTrue(is_wp_error(Awesome_Calendar_Events_Events_Query_API::parse_page_token('')));
        $this->assertSame(400, Awesome_Calendar_Events_Events_Query_API::parse_page_token('garbage')->data['status']);
    }

    /* ------------------------------------------------------------------ *
     * Collapsed mode end-to-end (through WP_Query stub)
     * ------------------------------------------------------------------ */

    public function test_collapsed_excludes_count_exhausted_events() {
        $one_off = $this->make_one_off(1, '2030-01-05');
        $active = $this->make_daily(2, '2030-01-01');
        // Count exhausted long ago → stale.
        $stale = $this->make_daily(3, '2020-01-01', 1, ['event_recurrence_end_type' => 'count', 'event_recurrence_count' => 2]);
        $GLOBALS['__awecal_query_posts'] = [$one_off, $active, $stale];

        $response = $this->api->get_events($this->make_request([
            'per_page' => 20,
            'date_from' => '2030-01-01',
            'date_to' => '2030-01-31',
        ]));

        $ids = array_map(fn($i) => $i['postId'], $response->data);
        $this->assertSame([1, 2], $ids);
        $this->assertSame('3', $response->headers['X-WP-Total']);
        $this->assertArrayNotHasKey('X-WP-NextPageToken', $response->headers);
    }

    public function test_collapsed_pagination_via_token() {
        $posts = [
            $this->make_one_off(1, '2030-01-05'),
            $this->make_one_off(2, '2030-01-06'),
        ];
        $GLOBALS['__awecal_query_posts'] = $posts;

        $response = $this->api->get_events($this->make_request(['per_page' => 1]));
        $this->assertCount(1, $response->data);
        $this->assertSame(1, $response->data[0]['postId']);
        $this->assertSame('2', $response->headers['X-WP-TotalPages']);
        $this->assertArrayHasKey('X-WP-NextPageToken', $response->headers);

        // Follow the token → second page.
        $token = $response->headers['X-WP-NextPageToken'];
        $response2 = $this->api->get_events($this->make_request([
            'per_page' => 1,
            'page_token' => $token,
        ]));
        $this->assertCount(1, $response2->data);
        $this->assertSame(2, $response2->data[0]['postId']);
        $this->assertArrayNotHasKey('X-WP-NextPageToken', $response2->headers);
        // Token page numbers must reach the query (paged=2).
        $this->assertSame(2, $GLOBALS['__awecal_last_query_args']['paged']);
    }

    /* ------------------------------------------------------------------ *
     * Expanded mode end-to-end
     * ------------------------------------------------------------------ */

    public function test_expanded_merges_one_offs_and_recurring_sorted() {
        // Fridays in Jan 2030: 4, 11, 18, 25.
        $recurring = $this->make_weekly(1, '2030-01-04', [4]);
        $one_off = $this->make_one_off(2, '2030-01-05');
        $GLOBALS['__awecal_query_posts'] = [$one_off, $recurring];

        $response = $this->api->get_events($this->make_request([
            'expand_recurring' => 1,
            'per_page' => 3,
            'date_from' => '2030-01-01',
            'date_to' => '2030-01-31',
        ]));

        $this->assertSame(
            ['2030-01-04', '2030-01-05', '2030-01-11'],
            $this->occurrence_dates($response->data)
        );
        $this->assertSame('5', $response->headers['X-WP-Total']); // 4 Fridays + 1 one-off
        $this->assertSame('2', $response->headers['X-WP-TotalPages']);
        $this->assertArrayHasKey('X-WP-NextPageToken', $response->headers);

        // Occurrence payload details.
        $this->assertFalse($response->data[1]['occurrence']['isRecurring']);
        $this->assertTrue($response->data[0]['occurrence']['isRecurring']);
        $this->assertSame('FREQ=WEEKLY;BYDAY=FR', $response->data[0]['event']['recurrenceRule']);
    }

    public function test_expanded_occurrence_start_end_datetimes() {
        $post = $this->make_weekly(1, '2030-01-04', [4]);
        update_post_meta(1, '_awecal_event_start_time', '18:30');
        update_post_meta(1, '_awecal_event_duration_hours', 2);
        $GLOBALS['__awecal_query_posts'] = [$post];

        $response = $this->api->get_events($this->make_request([
            'expand_recurring' => 1,
            'per_page' => 5,
            'date_from' => '2030-01-01',
            'date_to' => '2030-01-31',
        ]));

        $first = $response->data[0]['occurrence'];
        $this->assertSame('2030-01-04T18:30:00+00:00', $first['start']);
        $this->assertSame('2030-01-04T20:30:00+00:00', $first['end']);
    }

    public function test_expanded_occurrence_without_time_has_null_start_end() {
        $post = $this->make_one_off(1, '2030-01-05');
        $GLOBALS['__awecal_query_posts'] = [$post];

        $response = $this->api->get_events($this->make_request([
            'expand_recurring' => 1,
            'date_from' => '2030-01-01',
            'date_to' => '2030-01-31',
        ]));

        $this->assertNull($response->data[0]['occurrence']['start']);
        $this->assertNull($response->data[0]['occurrence']['end']);
    }

    public function test_expanded_follows_cursor_token() {
        // Fridays in Jan 2030: 4, 11, 18, 25.
        $recurring = $this->make_weekly(1, '2030-01-04', [4]);
        $one_off = $this->make_one_off(2, '2030-01-05');
        $GLOBALS['__awecal_query_posts'] = [$one_off, $recurring];

        $response = $this->api->get_events($this->make_request([
            'expand_recurring' => 1,
            'per_page' => 3,
            'date_from' => '2030-01-01',
            'date_to' => '2030-01-31',
        ]));
        $token = $response->headers['X-WP-NextPageToken'];

        $response2 = $this->api->get_events($this->make_request([
            'expand_recurring' => 1,
            'per_page' => 3,
            'date_from' => '2030-01-01',
            'date_to' => '2030-01-31',
            'page_token' => $token,
        ]));

        // Remaining occurrences: 18, 25 (pre-cursor occurrences skipped).
        $this->assertSame(
            ['2030-01-18', '2030-01-25'],
            $this->occurrence_dates($response2->data)
        );
        // Total stays exact thanks to the running total embedded in the token.
        $this->assertSame('5', $response2->headers['X-WP-Total']);
        $this->assertArrayNotHasKey('X-WP-NextPageToken', $response2->headers);
    }

    public function test_expanded_excludes_stale_and_ended_events() {
        // Count-limited (3 occurrences) but not exhausted → included.
        $active = $this->make_daily(1, '2030-01-01', 1, ['event_recurrence_end_type' => 'count', 'event_recurrence_count' => 3]);
        // Count exhausted years ago.
        $stale = $this->make_daily(2, '2020-01-01', 1, ['event_recurrence_end_type' => 'count', 'event_recurrence_count' => 2]);
        // End date before the window.
        $ended = $this->make_daily(3, '2028-01-01', 1, ['event_recurrence_end_type' => 'date', 'event_recurrence_end_date' => '2029-12-31']);
        // One-off before the window is not "stale", just has no occurrences.
        $old_one_off = $this->make_one_off(4, '2029-06-01');
        $GLOBALS['__awecal_query_posts'] = [$active, $stale, $ended, $old_one_off];

        $response = $this->api->get_events($this->make_request([
            'expand_recurring' => 1,
            'per_page' => 100,
            'date_from' => '2030-01-01',
            'date_to' => '2030-01-31',
        ]));

        $this->assertSame(
            ['2030-01-01', '2030-01-02', '2030-01-03'],
            $this->occurrence_dates($response->data)
        );
        $this->assertSame('3', $response->headers['X-WP-Total']);
    }

    public function test_expanded_default_window_when_no_dates() {
        $upcoming = date('Y-m-d', strtotime('+10 days'));
        $post = $this->make_daily(1, $upcoming);
        $GLOBALS['__awecal_query_posts'] = [$post];

        $response = $this->api->get_events($this->make_request([
            'expand_recurring' => 1,
            'per_page' => 100,
        ]));

        // Window = [today, today+90]; the event recurs 11 times from today+10
        // (start + 10 more daily occurrences within 90 days of today).
        $dates = $this->occurrence_dates($response->data);
        $this->assertSame($upcoming, $dates[0]);
        $this->assertCount(81, $dates); // today+10 .. today+90 inclusive
        // The not-ended SQL clause must be bound to "today".
        $today = date('Y-m-d');
        $this->assertStringContainsString($today, wp_json_encode($GLOBALS['__awecal_last_query_args']['meta_query']));
    }

    public function test_expanded_invalid_window_rejected() {
        $result = $this->api->get_events($this->make_request([
            'expand_recurring' => 1,
            'date_from' => '2030-12-31',
            'date_to' => '2030-01-01',
        ]));
        $this->assertTrue(is_wp_error($result));
        $this->assertSame(400, $result->data['status']);
    }

    public function test_expanded_invalid_token_rejected() {
        $result = $this->api->get_events($this->make_request([
            'expand_recurring' => 1,
            'page_token' => 'forged.token',
        ]));
        $this->assertTrue(is_wp_error($result));
        $this->assertSame('rest_invalid_param', $result->code);
    }

    public function test_expanded_order_desc() {
        $recurring = $this->make_weekly(1, '2030-01-04', [4]);
        $GLOBALS['__awecal_query_posts'] = [$recurring];

        $response = $this->api->get_events($this->make_request([
            'expand_recurring' => 1,
            'order' => 'desc',
            'date_from' => '2030-01-01',
            'date_to' => '2030-01-31',
        ]));

        $this->assertSame(
            ['2030-01-25', '2030-01-18', '2030-01-11', '2030-01-04'],
            $this->occurrence_dates($response->data)
        );
    }

    /* ------------------------------------------------------------------ *
     * Response cache (awesome_calendar_events_api_cache_ttl)
     * ------------------------------------------------------------------ */

    public function test_response_is_cached_by_default() {
        $post = $this->make_one_off(1, '2030-01-05');
        $GLOBALS['__awecal_query_posts'] = [$post];
        $request = $this->make_request(['date_from' => '2030-01-01', 'date_to' => '2030-01-31']);

        $first = $this->api->get_events($request);
        $this->assertCount(1, $first->data);

        // Empty the data source: the second identical request must be served
        // from the cache, not re-queried.
        $GLOBALS['__awecal_query_posts'] = [];
        $second = $this->api->get_events($request);
        $this->assertCount(1, $second->data);
        $this->assertSame($first->data[0]['postId'], $second->data[0]['postId']);
    }

    public function test_different_params_are_cached_separately() {
        $post = $this->make_one_off(1, '2030-01-05');
        $GLOBALS['__awecal_query_posts'] = [$post];

        $this->api->get_events($this->make_request(['date_from' => '2030-01-01', 'date_to' => '2030-01-31']));
        $this->api->get_events($this->make_request(['date_from' => '2030-02-01', 'date_to' => '2030-02-28']));
        // The (default-enabled) cache stores one entry per param set.
        $this->assertCount(2, $GLOBALS['__awecal_transients']);
        $this->assertCount(2, array_unique(array_keys($GLOBALS['__awecal_transients'])));
    }

    public function test_cache_disabled_with_zero_ttl() {
        add_filter('awesome_calendar_events_api_cache_ttl', fn() => 0);
        $post = $this->make_one_off(1, '2030-01-05');
        $GLOBALS['__awecal_query_posts'] = [$post];

        $this->api->get_events($this->make_request(['date_from' => '2030-01-01', 'date_to' => '2030-01-31']));
        // TTL 0 → nothing written to the cache.
        $this->assertSame([], $GLOBALS['__awecal_transients']);
    }

    public function test_flush_cache_invalidates_cached_response() {
        $post = $this->make_one_off(1, '2030-01-05');
        $GLOBALS['__awecal_query_posts'] = [$post];
        $request = $this->make_request(['date_from' => '2030-01-01', 'date_to' => '2030-01-31']);

        $this->api->get_events($request);

        // Bump the cache version: the same request must hit the (now empty)
        // data source again under a new cache key.
        $GLOBALS['__awecal_query_posts'] = [];
        $this->api->flush_cache();
        $second = $this->api->get_events($request);
        $this->assertSame([], $second->data);
    }

    /* ------------------------------------------------------------------ *
     * include_details in expanded mode
     * ------------------------------------------------------------------ */

    public function test_expanded_details_per_occurrence() {
        $post = $this->make_one_off(1, '2030-01-05');
        $GLOBALS['__awecal_posts'][1]['thumbnail_url'] = 'https://example.com/photo.jpg';
        $GLOBALS['__awecal_query_posts'] = [$post];

        $response = $this->api->get_events($this->make_request([
            'expand_recurring' => 1,
            'include_details' => 1,
            'date_from' => '2030-01-01',
            'date_to' => '2030-01-31',
        ]));

        $this->assertSame('https://example.com/photo.jpg', $response->data[0]['imageUrl']);
        $this->assertArrayHasKey('fullBody', $response->data[0]);
        $this->assertArrayHasKey('occurrence', $response->data[0]);
    }
}

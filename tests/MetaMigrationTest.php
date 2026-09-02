<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('wpdb')) {
    /**
     * Minimal in-memory wpdb stub covering the queries used by the
     * legacy meta migration: postmeta LIKE scan + row delete.
     */
    class wpdb {
        public $postmeta = 'wp_postmeta';
        public array $rows = [];

        public function esc_like($text) {
            return addcslashes((string) $text, '_%\\');
        }

        public function prepare($query, ...$args) {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }
            return vsprintf(str_replace(['%s', '%d'], ["'" . '%s' . "'", '%d'], $query), array_map('strval', $args));
        }

    public function get_results($query, $output = null) {
        return $this->match_like($query);
    }

    public function get_var($query) {
        $matched = $this->match_like($query);
        return $matched ? $matched[0]['meta_key'] : null;
    }

    private function match_like($query) {
        if (!preg_match("/LIKE '([^']*)'/", $query, $m)) {
            return [];
        }
            // Unescape the esc_like() output (\% -> literal percent, \_ ->
            // literal underscore); remaining unescaped % are LIKE wildcards.
            $like = str_replace('\\%', '@@LIT@@', $m[1]);
            $like = str_replace('\\_', '_', $like);
            $like = str_replace('%', '@@WILD@@', $like);
            $pattern = '/^' . str_replace(['@@WILD@@', '@@LIT@@'], ['.*', '%'], preg_quote($like, '/')) . '$/';
            $matched = array_values(array_filter($this->rows, function($row) use ($pattern) {
                return preg_match($pattern, $row['meta_key']) === 1;
            }));
            // Honor the LIMIT clause appended by the migration.
            if (preg_match('/LIMIT (\d+)/', $query, $lim)) {
                $matched = array_slice($matched, 0, (int) $lim[1]);
            }
            return $matched;
        }

        public function delete($table, $where) {
            $before = count($this->rows);
            $this->rows = array_values(array_filter($this->rows, function($row) use ($where) {
                foreach ($where as $k => $v) {
                    if ((int) $row[$k] !== (int) $v) {
                        return true;
                    }
                }
                return false;
            }));
            return $before - count($this->rows);
        }
    }
}

class MetaMigrationTest extends TestCase {
    private wpdb $wpdb;

    protected function setUp(): void {
        awecal_test_reset_meta();
        $this->wpdb = new wpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    private function add_row($meta_id, $post_id, $key, $value) {
        $this->wpdb->rows[] = [
            'meta_id' => $meta_id,
            'post_id' => $post_id,
            'meta_key' => $key,
            'meta_value' => is_string($value) ? $value : serialize($value),
        ];
    }

    public function test_renames_legacy_keys_to_canonical_prefix() {
        $this->add_row(1, 10, '_icob_event_date', '2030-06-15');
        $this->add_row(2, 10, '_icob_event_date_enabled', '1');
        $this->add_row(3, 11, '_icob_event_location', 'Main Hall');

        $renamed = Awesome_Calendar_Events_Meta_Migration::run();

        $this->assertSame(3, $renamed);
        $this->assertSame('2030-06-15', get_post_meta(10, '_awecal_event_date', true));
        $this->assertSame(1, get_post_meta(10, '_awecal_event_date_enabled', true));
        $this->assertSame('Main Hall', get_post_meta(11, '_awecal_event_location', true));
        $this->assertCount(0, $this->wpdb->rows);
    }

    public function test_values_are_normalized_like_on_save() {
        $this->add_row(1, 10, '_icob_event_date', 'June 15, 2030');
        $this->add_row(2, 10, '_icob_event_recurrence_type', 'fortnightly');
        $this->add_row(3, 10, '_icob_event_recurrence_weekdays', [2, 0]);
        $this->add_row(4, 10, '_icob_event_recurrence_interval', '0');
        $this->add_row(5, 10, '_icob_event_start_time', '25:99');
        $this->add_row(6, 10, '_icob_announcement_expiration', '2030-06-15T23:59');
        $this->add_row(7, 10, '_icob_announcement', '0');

        Awesome_Calendar_Events_Meta_Migration::run();

        $this->assertSame('2030-06-15', get_post_meta(10, '_awecal_event_date', true));
        $this->assertSame('none', get_post_meta(10, '_awecal_event_recurrence_type', true));
        // Array storage normalizes to the canonical bracketed string.
        $this->assertSame('[0,2]', get_post_meta(10, '_awecal_event_recurrence_weekdays', true));
        $this->assertSame(1, get_post_meta(10, '_awecal_event_recurrence_interval', true));
        $this->assertSame('', get_post_meta(10, '_awecal_event_start_time', true));
        $this->assertSame('2030-06-15 23:59:00', get_post_meta(10, '_awecal_announcement_expiration', true));
        $this->assertSame('', get_post_meta(10, '_awecal_announcement', true));
    }

    public function test_canonical_key_wins_and_legacy_row_is_removed() {
        update_post_meta(10, '_awecal_event_date', '2030-01-01');
        $this->add_row(1, 10, '_icob_event_date', '2020-01-01');

        $renamed = Awesome_Calendar_Events_Meta_Migration::run();

        $this->assertSame(0, $renamed);
        $this->assertSame('2030-01-01', get_post_meta(10, '_awecal_event_date', true));
        $this->assertCount(0, $this->wpdb->rows);
    }

    public function test_batches_keep_scanning_until_table_is_empty() {
        for ($i = 1; $i <= 1200; $i++) {
            $this->add_row($i, 100 + $i, '_icob_event_date', '2030-06-15');
        }

        $renamed = Awesome_Calendar_Events_Meta_Migration::run();

        $this->assertSame(1200, $renamed);
        $this->assertCount(0, $this->wpdb->rows);
        $this->assertSame('2030-06-15', get_post_meta(1300, '_awecal_event_date', true));
    }

    public function test_maybe_migrate_self_heals_when_legacy_rows_reappear() {
        $this->add_row(1, 10, '_icob_event_date', '2030-06-15');

        $this->assertSame(1, Awesome_Calendar_Events_Meta_Migration::maybe_migrate());
        $this->assertSame(AWESOME_CALENDAR_EVENTS_VERSION, get_option(Awesome_Calendar_Events_Meta_Migration::MIGRATION_OPTION));

        // Marker matches, no legacy rows: no-op.
        $this->assertSame(0, Awesome_Calendar_Events_Meta_Migration::maybe_migrate());

        // Legacy rows appearing after the marker was set (e.g. a DB restore
        // or late seeding) are still migrated despite the matching marker.
        $this->add_row(2, 11, '_icob_event_date', '2030-06-15');
        $this->assertSame(1, Awesome_Calendar_Events_Meta_Migration::maybe_migrate());
        $this->assertSame('2030-06-15', get_post_meta(11, '_awecal_event_date', true));
        $this->assertCount(0, $this->wpdb->rows);
    }

    public function test_unknown_suffixes_are_copied_verbatim() {
        $this->add_row(1, 10, '_icob_custom_extra', ['a' => 'b']);

        Awesome_Calendar_Events_Meta_Migration::run();

        $this->assertSame(['a' => 'b'], get_post_meta(10, '_awecal_custom_extra', true));
    }
}

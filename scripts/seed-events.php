<?php
/**
 * Seed 100 test event posts into the local WP install.
 * Run inside the wordpress container: php /scripts/seed-events.php
 */

require '/var/www/html/wp-load.php';

mt_srand(42);

$locations = ['Main Hall', 'Community Room', 'ICOB Center', 'Rooftop Terrace', 'Library Annex', 'Gymnasium', 'Overflow Parking Lot', 'Zoom (Online)'];
$categories = ['events', 'community', 'youth', 'fundraiser', 'workshop'];
$tag_pool = ['family', 'free', 'outdoor', 'annual', 'charity', 'lecture'];

$existing = get_posts(['post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 's' => 'Seed Event']);
foreach ($existing as $old_id) {
    wp_delete_post($old_id, true);
}

// Ensure terms exist
$cat_ids = [];
foreach ($categories as $slug) {
    $term = get_term_by('slug', $slug, 'category');
    if (!$term) {
        $res = wp_insert_term(ucfirst($slug), 'category', ['slug' => $slug]);
        $cat_ids[$slug] = is_wp_error($res) ? null : $res['term_id'];
    } else {
        $cat_ids[$slug] = $term->term_id;
    }
}
$tag_ids = [];
foreach ($tag_pool as $slug) {
    $term = get_term_by('slug', $slug, 'post_tag');
    if (!$term) {
        $res = wp_insert_term(ucfirst($slug), 'post_tag', ['slug' => $slug]);
        $tag_ids[$slug] = is_wp_error($res) ? null : $res['term_id'];
    } else {
        $tag_ids[$slug] = $term->term_id;
    }
}

$today = strtotime(current_time('Y-m-d') . ' 00:00:00');
$created = [];
$legacy_only = [];

for ($i = 1; $i <= 100; $i++) {
    $offset_days = mt_rand(-90, 180);
    $date = date('Y-m-d', $today + $offset_days * DAY_IN_SECONDS);
    $start_time = sprintf('%02d:%02d', mt_rand(8, 20), in_array($i % 4, [0, 1]) ? 0 : 30);
    $duration_hours = mt_rand(1, 6);
    $location = $locations[$i % count($locations)];

    // Recurrence profile
    $r = mt_rand(1, 100);
    if ($r <= 55) {
        $rec_type = 'none';
        $weekdays = [];
        $end_type = 'none';
        $end_date = '';
        $rec_count = 0;
        $interval = 1;
    } elseif ($r <= 75) {
        $rec_type = 'weekly';
        $weekdays = [$i % 7];
        $end_type = mt_rand(1, 3) === 1 ? 'date' : 'none';
        $end_date = $end_type === 'date' ? date('Y-m-d', strtotime($date . ' +' . mt_rand(30, 300) . ' days')) : '';
        $rec_count = 0;
        $interval = 1;
    } elseif ($r <= 88) {
        $rec_type = 'daily';
        $weekdays = [];
        $end_type = mt_rand(1, 3) === 1 ? 'count' : 'none';
        $rec_count = $end_type === 'count' ? mt_rand(5, 40) : 0;
        $end_date = '';
        $interval = 1;
    } else {
        $rec_type = 'monthly';
        $weekdays = [];
        $end_type = mt_rand(1, 2) === 1 ? 'date' : 'none';
        $end_date = $end_type === 'date' ? date('Y-m-d', strtotime($date . ' +' . mt_rand(60, 365) . ' days')) : '';
        $rec_count = 0;
        $interval = mt_rand(1, 3);
    }

    // Announcements: 25% of posts, mixed active/expired
    $announcement = '';
    $announcement_expiration = '';
    $is_legacy_announcement = false;
    if ($i % 4 === 0) {
        if ($i % 12 === 0) {
            // expired announcement
            $announcement = 'Expired notice for seed event ' . $i;
            $announcement_expiration = date('Y-m-d H:i:s', $today - mt_rand(1, 30) * DAY_IN_SECONDS);
        } else {
            $announcement = 'Active notice for seed event ' . $i;
            $announcement_expiration = date('Y-m-d H:i:s', $today + mt_rand(1, 60) * DAY_IN_SECONDS);
        }
    }

    $is_legacy = ($i > 95); // last 5 posts: legacy _icob_ keys only

    $post_id = wp_insert_post([
        'post_title' => 'Seed Event ' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
        'post_content' => 'Test content for seed event ' . $i . '. Location: ' . $location . '. Date: ' . $date . '.',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date_gmt' => gmdate('Y-m-d H:i:s', time() - mt_rand(0, 86400 * 30)),
    ], true);

    if (is_wp_error($post_id)) {
        echo "ERROR creating post $i: " . $post_id->get_error_message() . "\n";
        continue;
    }

    $pfx = $is_legacy ? '_icob_' : '_awecal_';

    update_post_meta($post_id, $pfx . 'event_date_enabled', '1');
    update_post_meta($post_id, $pfx . 'event_date', $date);
    update_post_meta($post_id, $pfx . 'event_start_time', $start_time);
    update_post_meta($post_id, $pfx . 'event_duration_hours', (string) $duration_hours);
    update_post_meta($post_id, $pfx . 'event_duration_minutes', (string) ($duration_hours * 60));
    update_post_meta($post_id, $pfx . 'event_location', $location);
    update_post_meta($post_id, $pfx . 'event_custom_time_label', '');
    update_post_meta($post_id, $pfx . 'event_recurrence_type', $rec_type);
    update_post_meta($post_id, $pfx . 'event_recurrence_interval', (string) $interval);
    update_post_meta($post_id, $pfx . 'event_recurrence_weekdays', json_encode($weekdays));
    update_post_meta($post_id, $pfx . 'event_recurrence_end_type', $end_type);
    update_post_meta($post_id, $pfx . 'event_recurrence_end_date', $end_date);
    update_post_meta($post_id, $pfx . 'event_recurrence_count', (string) $rec_count);

    if (!$is_legacy) {
        update_post_meta($post_id, '_awecal_announcement', $announcement);
        update_post_meta($post_id, '_awecal_announcement_expiration', $announcement_expiration);
    } else {
        $legacy_only[] = $post_id;
    }

    // Categories: always 'events' for 80%, others random
    $post_cats = [];
    if ($i <= 80) {
        $post_cats[] = (int) $cat_ids['events'];
    }
    $post_cats[] = (int) $cat_ids[$categories[$i % count($categories)]];
    wp_set_post_categories($post_id, array_unique($post_cats));

    $post_tags = [$tag_ids[$tag_pool[$i % count($tag_pool)]], $tag_ids[$tag_pool[($i + 2) % count($tag_pool)]]];
    wp_set_object_terms($post_id, array_map('intval', array_unique($post_tags)), 'post_tag');

    $created[] = $post_id;
}

// Flush API response cache + rewrite rules
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_\\_transient\\_awecal\\_events\\_api%' OR option_name LIKE '\\_\\_transient\\_timeout\\_awecal\\_events\\_api%'");
global $wp_rewrite;
$wp_rewrite->flush_rules();

echo "Created " . count($created) . " posts (legacy-only: " . count($legacy_only) . ")\n";

// Summary
$future = 0; $past = 0; $ann = 0; $rec = 0;
foreach ($created as $pid) {
    $d = get_post_meta($pid, '_awecal_event_date', true) ?: get_post_meta($pid, '_icob_event_date', true);
    if ($d && $d >= current_time('Y-m-d')) { $future++; } else { $past++; }
    $a = get_post_meta($pid, '_awecal_announcement', true);
    if (is_string($a) && $a !== '' && $a !== '0') { $ann++; }
    $rt = get_post_meta($pid, '_awecal_event_recurrence_type', true) ?: get_post_meta($pid, '_icob_event_recurrence_type', true);
    if ($rt && $rt !== 'none') { $rec++; }
}
echo "Future events: $future, past: $past, announcements: $ann, recurring: $rec\n";

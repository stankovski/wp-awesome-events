<?php
/**
 * Event REST API Endpoints
 *
 * Provides custom REST API endpoints for event-related data.
 * Namespace kept as icob/v1 for backward compatibility with existing
 * block-editor consumers (e.g. the event countdown block).
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Calendar_Events_Event_REST_API {
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('icob/v1', '/event-posts', [
            'methods' => 'GET',
            'callback' => [$this, 'get_event_posts'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
            'args' => [
                'search' => [
                    'description' => 'Search term for post titles',
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'per_page' => [
                    'description' => 'Number of posts to return',
                    'type' => 'integer',
                    'default' => 100,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Computed event display values for a single post. Used by the block
        // editor previews so they mirror the server-rendered output (next
        // occurrence, weekday fallbacks, relative weeks) rather than raw meta.
        register_rest_route('icob/v1', '/event-display/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_event_display'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
            'args' => [
                'id' => [
                    'description' => 'Post ID',
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * Return the unified computed event display values for a post.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_event_display($request) {
        $post_id = absint($request['id']);
        if (!$post_id || !get_post($post_id)) {
            return new WP_Error('awecal_event_display_not_found', __('Post not found.', 'awesome-calendar-events'), ['status' => 404]);
        }

        if (!class_exists('Awesome_Calendar_Events_Event_Meta')) {
            return new WP_Error('awecal_event_display_unavailable', __('Event meta unavailable.', 'awesome-calendar-events'), ['status' => 500]);
        }

        // relative_week = true so the editor preview can offer relative output.
        $display = Awesome_Calendar_Events_Event_Meta::get_event_date_display($post_id, null, true, true);

        return rest_ensure_response([
            'date'           => (string) $display['date'],
            'iso'            => (string) $display['iso'],
            'weekdays'       => (string) $display['weekdays'],
            'relative'       => (string) $display['relative'],
            'start_time'     => (string) $display['start_time'],
            'location'       => (string) $display['location'],
            'has_value'      => (bool) $display['has_value'],
            // Raw saved meta so the editor can detect unsaved (dirty) edits
            // and prefer live client-side values over the saved computation.
            'raw_date'       => (string) awecal_get_post_meta($post_id, '_awecal_event_date', true),
            'raw_start_time' => (string) awecal_get_post_meta($post_id, '_awecal_event_start_time', true),
            'raw_location'   => (string) awecal_get_post_meta($post_id, '_awecal_event_location', true),
        ]);
    }

    public function get_event_posts($request) {
        $search = $request->get_param('search');
        $per_page = $request->get_param('per_page') ?: 100;

        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => min($per_page, 100),
            'orderby' => 'title',
            'order' => 'ASC',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta filtering on the event-date-enabled key is required to select posts that are events.
            'meta_query' => [
                awecal_event_date_enabled_meta_query(),
            ],
        ];

        if ($search) {
            $args['s'] = $search;
        }

        $query = new WP_Query($args);
        $posts = [];

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();

                // Get next occurrence for additional context
                $next_occurrence = null;
                if (class_exists('Awesome_Calendar_Events_Event_Meta')) {
                    $next_occurrence = Awesome_Calendar_Events_Event_Meta::get_next_occurrence($post_id);
                }

                $posts[] = [
                    'id' => $post_id,
                    'title' => get_the_title(),
                    'event_date' => awecal_get_post_meta($post_id, '_awecal_event_date', true),
                    'next_occurrence' => $next_occurrence,
                    'recurrence_type' => awecal_get_post_meta($post_id, '_awecal_event_recurrence_type', true),
                ];
            }
            wp_reset_postdata();
        }

        return rest_ensure_response($posts);
    }
}

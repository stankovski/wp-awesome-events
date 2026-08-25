<?php
/**
 * Event REST API Endpoints
 *
 * Provides custom REST API endpoints for event-related data.
 * Namespace kept as icob/v1 for backward compatibility with existing
 * block-editor consumers (e.g. the event countdown block).
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Events_Event_REST_API {
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
            'meta_query' => [
                [
                    'key' => '_icob_event_date_enabled',
                    'value' => '1',
                    'compare' => '='
                ]
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
                if (class_exists('Awesome_Events_Event_Meta')) {
                    $next_occurrence = Awesome_Events_Event_Meta::get_next_occurrence($post_id);
                }

                $posts[] = [
                    'id' => $post_id,
                    'title' => get_the_title(),
                    'event_date' => get_post_meta($post_id, '_icob_event_date', true),
                    'next_occurrence' => $next_occurrence,
                    'recurrence_type' => get_post_meta($post_id, '_icob_event_recurrence_type', true),
                ];
            }
            wp_reset_postdata();
        }

        return rest_ensure_response($posts);
    }
}

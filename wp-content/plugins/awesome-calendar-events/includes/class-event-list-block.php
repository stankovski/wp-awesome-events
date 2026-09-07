<?php
/**
 * Event List Block
 *
 * A core/query block variation ("Event List") that lists upcoming events.
 *
 * Built on the Query Loop block with no additional query customization:
 * users can only filter events by category or tags. The query is resolved
 * through the public events query API (Awesome_Calendar_Events_Events_Query_API)
 * in collapsed mode, so recurring events are never unwrapped into
 * occurrences; each recurring event appears once, while it is upcoming.
 *
 * When the block is inserted, the default post template contains the
 * Event Date block on top, followed by the Featured Image and Title.
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Calendar_Events_Event_List_Block {

    /**
     * Namespace attribute used by the core/query variation.
     */
    const NAMESPACE_SLUG = 'awesome-calendar-events/event-list';

    /**
     * Marker stored inside the query attribute so the query filters can
     * recognize this block's queries. It rides along with the editor's
     * REST request and is kept in the frontend WP_Query vars.
     */
    const QUERY_FLAG = 'awecalEventList';

    public function __construct() {
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
        add_filter('pre_render_block', [$this, 'maybe_filter_frontend_query'], 10, 2);
        add_filter('rest_post_query', [$this, 'maybe_filter_rest_query'], 10, 2);
        add_filter('the_posts', [$this, 'exclude_stale_events'], 10, 2);
    }

    /**
     * Enqueue the block variation script for the block editor.
     */
    public function enqueue_editor_assets() {
        $version = defined('AWESOME_CALENDAR_EVENTS_VERSION') ? AWESOME_CALENDAR_EVENTS_VERSION : '1.0.0';
        wp_enqueue_script(
            'awesome-calendar-events-event-list-block-editor',
            AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/js/event-list-block.js',
            ['wp-blocks', 'wp-element', 'wp-i18n'],
            $version,
            true
        );
    }

    /**
     * Register the query vars filter when an Event List block is about to
     * render on the frontend.
     *
     * @param mixed $pre_render   Whether the block should be rendered.
     * @param array $parsed_block The parsed block being rendered.
     * @return mixed
     */
    public function maybe_filter_frontend_query($pre_render, $parsed_block) {
        if (($parsed_block['attrs']['namespace'] ?? '') !== self::NAMESPACE_SLUG) {
            return $pre_render;
        }

        add_filter('query_loop_block_query_vars', [$this, 'filter_query_vars'], 10, 2);
        return $pre_render;
    }

    /**
     * Apply the upcoming events query to the frontend Query Loop.
     *
     * @param array    $default_query Query vars built by the Query Loop block.
     * @param WP_Block $block         The Query Loop block instance.
     * @return array
     */
    public function filter_query_vars($default_query, $block) {
        $block_query = $block->context['query'] ?? ($block->attributes['query'] ?? []);
        if (empty($block_query[self::QUERY_FLAG])) {
            return $default_query;
        }

        return array_merge($default_query, $this->build_event_query_args($block_query, $default_query));
    }

    /**
     * Apply the upcoming events query to the block editor preview
     * (REST collection request for posts).
     *
     * @param array           $args    Query args built for the REST request.
     * @param WP_REST_Request $request The REST request.
     * @return array
     */
    public function maybe_filter_rest_query($args, $request) {
        if (!$request->get_param(self::QUERY_FLAG)) {
            return $args;
        }

        $block_query = [
            'perPage' => (int) ($args['posts_per_page'] ?? 10),
            'order' => strtolower((string) ($request->get_param('order') ?? 'asc')),
            'taxQuery' => [
                'category' => $request->get_param('categories') ?: [],
                'post_tag' => $request->get_param('tags') ?: [],
            ],
        ];
        $default_query = ['paged' => (int) ($args['paged'] ?? 1)];

        return array_merge($args, $this->build_event_query_args($block_query, $default_query));
    }

    /**
     * Build the upcoming events query via the public events query API
     * (collapsed mode: recurring events are never unwrapped).
     *
     * @param array $block_query    Query attribute of the block.
     * @param array $default_query  Query vars built for the current page.
     * @return array
     */
    private function build_event_query_args($block_query, $default_query) {
        $request = new WP_REST_Request();
        $request->set_param('orderby', 'event_date');
        $order = strtolower((string) ($block_query['order'] ?? 'asc'));
        $request->set_param('order', $order === 'desc' ? 'desc' : 'asc');
        $request->set_param('per_page', max(1, (int) ($block_query['perPage'] ?? 10)));
        $request->set_param('upcoming', true);
        $request->set_param('categories', self::term_ids_to_slugs($block_query['taxQuery']['category'] ?? []));
        $request->set_param('tags', self::term_ids_to_slugs($block_query['taxQuery']['post_tag'] ?? []));

        $api = new Awesome_Calendar_Events_Events_Query_API();
        $page = max(1, (int) ($default_query['paged'] ?? 1));
        $args = $api->build_query_args($request, $page);

        // Mark the query so exclude_stale_events() can drop recurring
        // events whose occurrences are exhausted, mirroring the API's
        // in-memory staleness exclusion in collapsed mode.
        $args[self::QUERY_FLAG] = true;

        return $args;
    }

    /**
     * Drop recurring events whose occurrences are exhausted from query
     * results (both frontend render and editor preview), matching the
     * collapsed mode of the events query API.
     *
     * @param array    $posts Posts returned by the query.
     * @param WP_Query $query The query object.
     * @return array
     */
    public function exclude_stale_events($posts, $query) {
        if (empty($query->query_vars[self::QUERY_FLAG])) {
            return $posts;
        }

        $today = current_time('Y-m-d');
        return array_values(array_filter(
            $posts,
            function ($post) use ($today) {
                return !Awesome_Calendar_Events_Events_Query_API::is_stale_recurring((int) $post->ID, $today);
            }
        ));
    }

    /**
     * Convert taxonomy term IDs (as stored by the Query Loop taxonomy
     * filter) into term slugs for the events query API.
     *
     * @param mixed $term_ids
     * @return array
     */
    private static function term_ids_to_slugs($term_ids) {
        $slugs = [];
        foreach ((array) $term_ids as $term_id) {
            $term = get_term((int) $term_id);
            if ($term instanceof WP_Term && $term->slug !== '') {
                $slugs[] = $term->slug;
            }
        }
        return array_values(array_unique($slugs));
    }
}

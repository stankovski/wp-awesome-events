<?php
/**
 * Events Query API (public, read-only)
 *
 * GET /wp-json/awecal/v1/events
 *
 * Designed for 3rd party plugins to query posts that carry event data,
 * filtered by category, tags and datetime. Posts are matched on the
 * original event date stored in meta (`_awecal_event_date` / legacy
 * `_icob_event_date`):
 *
 *  - One-off events: the datetime filter applies to the event date.
 *  - Recurring events: the datetime filter applies to the ORIGINAL event
 *    date. Recurrences are never expanded server-side; consumers receive
 *    the RRULE string (`event.recurrenceRule`) so they can expand
 *    occurrences themselves if needed.
 *
 * Responses are minimal by default (postId, title, snippet, url,
 * publishedAt, announcement, announcementEndDateTime, event). Pass
 * `include_details=true` to also receive excerpt, imageUrl, imageAlt,
 * fullBody and term slugs.
 *
 * Security:
 *  - Only `publish` status posts are ever returned; no draft/private data
 *    can leak regardless of requester.
 *  - Every parameter is validated/sanitized via the REST args schema;
 *    queries are built exclusively through the awecal_* meta query helpers
 *    (which are aware of both meta prefixes) and WP_Query. No raw SQL.
 *  - Permission callback defaults to public read (parity with the public
 *    ICS feeds); sites can lock the route down via the
 *    `awesome_calendar_events_api_permission_callback` filter.
 *  - Only whitelisted fields are serialized; raw post meta is never dumped.
 *
 * Performance:
 *  - Date filtering is a single CHAR BETWEEN meta clause over Y-m-d
 *    strings — no PHP occurrence expansion.
 *  - per_page is hard-capped at 100; term caches are only primed when
 *    include_details is requested.
 *  - Optional response caching via the
 *    `awesome_calendar_events_api_cache_ttl` filter (seconds; 0 = off,
 *    the default). Cache is invalidated on save_post/deleted_post.
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Calendar_Events_Events_Query_API {

    /**
     * Maximum number of items per page.
     */
    const MAX_PER_PAGE = 100;

    /**
     * Maximum number of taxonomy slugs accepted per filter parameter.
     */
    const MAX_TERMS = 50;

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('save_post', [$this, 'flush_cache']);
        add_action('deleted_post', [$this, 'flush_cache']);
    }

    public function register_routes() {
        register_rest_route('awecal/v1', '/events', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_events'],
            'permission_callback' => apply_filters(
                'awesome_calendar_events_api_permission_callback',
                '__return_true'
            ),
            'args' => $this->get_args_schema(),
        ]);
    }

    /**
     * REST args schema. WP sanitizes then validates each param and
     * automatically rejects invalid requests with 400 rest_invalid_param.
     *
     * @return array
     */
    public function get_args_schema() {
        return [
            'categories' => [
                'description' => 'Comma-separated category slugs (OR logic).',
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_slug_list'],
            ],
            'tags' => [
                'description' => 'Comma-separated tag slugs (OR unless tag_operator=all).',
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_slug_list'],
            ],
            'tag_operator' => [
                'description' => 'Whether posts must match any or all tags.',
                'type' => 'string',
                'default' => 'any',
                'enum' => ['any', 'all'],
            ],
            'date_from' => [
                'description' => 'Inclusive lower bound (YYYY-MM-DD) applied to the original event date.',
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [$this, 'validate_date_param'],
            ],
            'date_to' => [
                'description' => 'Inclusive upper bound (YYYY-MM-DD) applied to the original event date.',
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [$this, 'validate_date_param'],
            ],
            'search' => [
                'description' => 'Search term.',
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'include_details' => [
                'description' => 'Include excerpt, imageUrl, imageAlt, fullBody and term slugs.',
                'type' => 'boolean',
                'default' => false,
            ],
            'per_page' => [
                'description' => 'Number of posts to return (1-100).',
                'type' => 'integer',
                'default' => 20,
                'minimum' => 1,
                'maximum' => self::MAX_PER_PAGE,
                'sanitize_callback' => 'absint',
            ],
            'page' => [
                'description' => 'Current page of the collection.',
                'type' => 'integer',
                'default' => 1,
                'minimum' => 1,
                'sanitize_callback' => 'absint',
            ],
            'orderby' => [
                'description' => 'Sort collection by event_date, post date or title.',
                'type' => 'string',
                'default' => 'event_date',
                'enum' => ['event_date', 'date', 'title'],
            ],
            'order' => [
                'description' => 'Order sort attribute ascending or descending.',
                'type' => 'string',
                'default' => 'asc',
                'enum' => ['asc', 'desc'],
            ],
        ];
    }

    public function sanitize_slug_list($val) {
        $parts = explode(',', (string) $val);
        $slugs = [];
        foreach ($parts as $part) {
            $slug = sanitize_title(trim($part));
            if ($slug !== '') {
                $slugs[] = $slug;
            }
            if (count($slugs) >= self::MAX_TERMS) {
                break;
            }
        }
        return array_values(array_unique($slugs));
    }

    public function validate_date_param($val) {
        $val = trim((string) $val);
        if ($val === '') {
            return true;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            return false;
        }
        return strtotime($val . ' 00:00:00') !== false;
    }

    /**
     * Handle GET /awecal/v1/events.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_events($request) {
        $params = $request->get_params();
        ksort($params);
        $ttl = (int) apply_filters('awesome_calendar_events_api_cache_ttl', 0);
        $cache_key = $ttl > 0
            ? $this->cache_key(wp_json_encode($params))
            : null;

        if ($cache_key) {
            $cached = get_transient($cache_key);
            if (is_array($cached) && isset($cached['items'])) {
                return $this->build_response($cached['items'], $cached['total'], $cached['pages']);
            }
        }

        $query = new WP_Query($this->build_query_args($request));

        $items = [];
        foreach ($query->posts as $post) {
            $items[] = self::prepare_item($post, (bool) $request->get_param('include_details'));
        }

        if ($cache_key) {
            set_transient($cache_key, [
                'items' => $items,
                'total' => (int) $query->found_posts,
                'pages' => (int) $query->max_num_pages,
            ], $ttl);
        }

        return $this->build_response($items, (int) $query->found_posts, (int) $query->max_num_pages);
    }

    private function build_response($items, $total, $pages) {
        $response = rest_ensure_response($items);
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) $pages);
        return $response;
    }

    private function cache_key($params_json) {
        $version = get_option('awesome_calendar_events_api_cache_version', 1);
        return 'awecal_events_api_v' . (int) $version . '_' . md5((string) $params_json);
    }

    /**
     * Invalidate the response cache by bumping its version.
     */
    public function flush_cache() {
        $version = (int) get_option('awesome_calendar_events_api_cache_version', 1);
        update_option('awesome_calendar_events_api_cache_version', $version + 1);
    }

    /**
     * Build the WP_Query args from validated REST params. All meta clauses
     * come from the awecal_* helpers (legacy + canonical prefix aware).
     *
     * @param WP_REST_Request $request
     * @return array
     */
    public function build_query_args($request) {
        $per_page = min(self::MAX_PER_PAGE, max(1, absint($request->get_param('per_page'))));
        $page = max(1, absint($request->get_param('page')));
        $orderby = $request->get_param('orderby') ?: 'event_date';
        $order = strtolower((string) $request->get_param('order')) === 'desc' ? 'DESC' : 'ASC';
        $include_details = (bool) $request->get_param('include_details');

        $meta_query = [
            'relation' => 'AND',
            awecal_event_date_enabled_meta_query(),
        ];

        $date_from = (string) $request->get_param('date_from');
        $date_to = (string) $request->get_param('date_to');
        if ($date_from !== '' || $date_to !== '') {
            $meta_query[] = awecal_event_date_range_meta_query($date_from, $date_to);
        }

        $tax_query = [];
        $categories = $request->get_param('categories');
        if (is_array($categories) && $categories) {
            $tax_query[] = [
                'taxonomy' => 'category',
                'field' => 'slug',
                'terms' => $categories,
                'operator' => 'IN',
            ];
        }
        $tags = $request->get_param('tags');
        if (is_array($tags) && $tags) {
            $tag_operator = $request->get_param('tag_operator') === 'all' ? 'AND' : 'IN';
            $tax_query[] = [
                'taxonomy' => 'post_tag',
                'field' => 'slug',
                'terms' => $tags,
                'operator' => $tag_operator,
            ];
        }

        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'order' => $order,
            'ignore_sticky_posts' => true,
            // Term data is only needed when serializing term slugs in details mode.
            'update_post_term_cache' => $include_details,
            'meta_query' => $meta_query,
        ];

        if ($orderby === 'event_date') {
            // Y-m-d strings sort correctly as meta_value. Sorting covers the
            // canonical key; posts that only carry legacy meta may sort last.
            $args['meta_key'] = awecal_meta_key('_icob_event_date');
            $args['orderby'] = 'meta_value';
            $args['meta_type'] = 'CHAR';
        } else {
            $args['orderby'] = $orderby;
        }

        if ($tax_query) {
            if (count($tax_query) > 1) {
                $tax_query['relation'] = 'AND';
            }
            $args['tax_query'] = $tax_query;
        }

        $search = $request->get_param('search');
        if ($search) {
            $args['s'] = $search;
        }

        return $args;
    }

    /**
     * Serialize a post into an API item.
     *
     * @param WP_Post|int $post
     * @param bool $include_details
     * @return array
     */
    public static function prepare_item($post, $include_details = false) {
        $post_id = is_object($post) && isset($post->ID) ? (int) $post->ID : (int) $post;

        $item = [
            'postId' => $post_id,
            'title' => get_the_title($post),
            'snippet' => self::get_snippet($post),
            'url' => get_permalink($post),
            'publishedAt' => self::get_published_at($post),
            'announcement' => (bool) awecal_get_post_meta($post_id, '_awecal_announcement', true),
            'announcementEndDateTime' => self::normalize_expiration(
                awecal_get_post_meta($post_id, '_awecal_announcement_expiration', true)
            ),
            'event' => self::get_event_data($post_id),
        ];

        if ($include_details) {
            $item['excerpt'] = get_the_excerpt($post);
            $item['imageUrl'] = get_the_post_thumbnail_url($post, 'large');
            $thumbnail_id = (int) get_post_thumbnail_id($post);
            $item['imageAlt'] = $thumbnail_id ? (string) get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true) : '';
            $item['fullBody'] = apply_filters('the_content', (string) ($post->post_content ?? ''));
            $item['categories'] = self::get_term_slugs($post_id, 'category');
            $item['tags'] = self::get_term_slugs($post_id, 'post_tag');
        }

        return $item;
    }

    /**
     * Event payload for a post; null when the event date feature is not
     * enabled for the post.
     *
     * @param int $post_id
     * @return array|null
     */
    private static function get_event_data($post_id) {
        if (!(bool) awecal_get_post_meta($post_id, '_awecal_event_date_enabled', true)) {
            return null;
        }

        $generator = new Awesome_Calendar_Events_ICS_Generator();

        return [
            'date' => (string) awecal_get_post_meta($post_id, '_awecal_event_date', true),
            'startTime' => (string) awecal_get_post_meta($post_id, '_awecal_event_start_time', true),
            'durationHours' => (float) awecal_get_post_meta($post_id, '_awecal_event_duration_hours', true),
            'location' => (string) awecal_get_post_meta($post_id, '_awecal_event_location', true),
            // Null for one-off events; consumers expand occurrences themselves.
            'recurrenceRule' => $generator->get_recurrence_rule($post_id),
        ];
    }

    /**
     * Short text snippet: the excerpt when present, otherwise the first
     * words of the stripped content.
     *
     * @param WP_Post $post
     * @return string
     */
    private static function get_snippet($post) {
        $excerpt = trim((string) ($post->post_excerpt ?? ''));
        if ($excerpt !== '') {
            return wp_strip_all_tags($excerpt);
        }
        return wp_trim_words(wp_strip_all_tags((string) ($post->post_content ?? '')), 40, '…');
    }

    /**
     * ISO 8601 publication timestamp (from post_date_gmt).
     *
     * @param WP_Post $post
     * @return string|null
     */
    private static function get_published_at($post) {
        $gmt = (string) ($post->post_date_gmt ?? '');
        if ($gmt === '' || $gmt === '0000-00-00 00:00:00') {
            $gmt = (string) ($post->post_date ?? '');
        }
        if ($gmt === '' || $gmt === '0000-00-00 00:00:00') {
            return null;
        }
        return mysql2date('c', $gmt);
    }

    /**
     * Normalize a stored announcement expiration (site-local datetime)
     * to ISO 8601 with the site UTC offset. Null when unset/unparseable.
     *
     * @param string $raw
     * @return string|null
     */
    private static function normalize_expiration($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }
        // Stored value is site-local wall time; shift it to a UTC instant.
        $offset = (int) ((float) get_option('gmt_offset', 0) * HOUR_IN_SECONDS);
        return gmdate('c', $ts - $offset);
    }

    /**
     * Term slugs for a post's taxonomy (empty array on error/none).
     *
     * @param int $post_id
     * @param string $taxonomy
     * @return string[]
     */
    private static function get_term_slugs($post_id, $taxonomy) {
        $terms = get_the_terms($post_id, $taxonomy);
        if (!is_array($terms)) {
            return [];
        }
        $slugs = [];
        foreach ($terms as $term) {
            if (is_object($term) && isset($term->slug)) {
                $slugs[] = $term->slug;
            }
        }
        return $slugs;
    }
}

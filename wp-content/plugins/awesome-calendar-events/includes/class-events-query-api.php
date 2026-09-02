<?php
/**
 * Events Query API (public, read-only)
 *
 * GET /wp-json/awecal/v1/events
 *
 * Designed for 3rd party plugins to query posts that carry event data,
 * filtered by category, tags and datetime. Posts are matched on the
 * original event date stored in meta (`_awecal_event_date` / legacy
 * `_icob_event_date`).
 *
 * Modes:
 *  - Collapsed (default, `expand_recurring=false`): recurring events are
 *    returned once; the datetime filter applies to the ORIGINAL event
 *    date for both one-off and recurring events. Consumers receive the
 *    RRULE string (`event.recurrenceRule`) and can expand occurrences
 *    themselves.
 *  - Expanded (`expand_recurring=true`): the datetime window applies to
 *    OCCURRENCES. Recurring events are expanded into one item per
 *    occurrence inside the window (bounded by per-event and scan caps),
 *    sorted by occurrence date. One-off events become single instances.
 *
 * Stale events are excluded in both modes via the recurrence end date
 * (SQL, `_awecal_event_recurrence_end_date` / legacy key) and the
 * recurrence occurrence count (in memory, via
 * Awesome_Calendar_Events_Event_Meta::get_last_occurrence_date()).
 *
 * Pagination uses opaque `page_token` cursors (X-WP-NextPageToken
 * response header) instead of page numbers. Collapsed tokens embed the
 * page number; expanded tokens additionally embed the occurrence date
 * where the next page starts (so expansion can skip earlier
 * occurrences) and the running occurrence total (so X-WP-Total stays
 * exact without re-expanding).
 *
 * Security:
 *  - Only `publish` status posts are ever returned.
 *  - Every parameter is validated/sanitized via the REST args schema;
 *    queries are built exclusively through the awecal_* meta query
 *    helpers (legacy + canonical prefix aware) and WP_Query. No raw SQL.
 *  - Permission callback defaults to public read (parity with the public
 *    ICS feeds); sites can lock the route down via the
 *    `awesome_calendar_events_api_permission_callback` filter.
 *  - Page tokens are HMAC-signed (wp_hash); invalid tokens are rejected
 *    with 400 instead of being trusted.
 *  - Only whitelisted fields are serialized; raw post meta is never dumped.
 *
 * Performance:
 *  - Collapsed mode: date filtering is a single CHAR BETWEEN meta clause
 *    over Y-m-d strings — no PHP occurrence expansion.
 *  - Expanded mode: PHP expansion is bounded by the per-event occurrence
 *    cap (`awesome_calendar_events_api_max_occurrences_per_event`, default
 *    366) and the post scan cap (`awesome_calendar_events_api_max_posts_scan`,
 *    default 1000); cursor tokens skip materializing pre-cursor occurrences.
 *  - Responses are cached when `awesome_calendar_events_api_cache_ttl`
 *    (seconds, default 300) is > 0; invalidation on save_post/deleted_post.
 *  - per_page is hard-capped at 100; term caches are only primed when
 *    include_details is requested.
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

    /**
     * Default response cache TTL in seconds (filterable).
     */
    const DEFAULT_CACHE_TTL = 300;

    /**
     * Default expansion window length in days when no date filter is given.
     */
    const DEFAULT_EXPANSION_WINDOW_DAYS = 90;

    /**
     * Default maximum occurrences materialized per event.
     */
    const DEFAULT_MAX_OCCURRENCES_PER_EVENT = 366;

    /**
     * Default maximum posts scanned per expanded request.
     */
    const DEFAULT_MAX_POSTS_SCAN = 1000;

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
                'description' => 'Inclusive lower bound (YYYY-MM-DD). Collapsed: applies to the original event date. Expanded: applies to occurrences.',
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [$this, 'validate_date_param'],
            ],
            'date_to' => [
                'description' => 'Inclusive upper bound (YYYY-MM-DD). Collapsed: applies to the original event date. Expanded: applies to occurrences.',
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [$this, 'validate_date_param'],
            ],
            'expand_recurring' => [
                'description' => 'Expand recurring events into occurrence instances inside the datetime window.',
                'type' => 'boolean',
                'default' => false,
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
            'page_token' => [
                'description' => 'Opaque pagination cursor from the X-WP-NextPageToken response header.',
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'per_page' => [
                'description' => 'Number of items to return (1-100).',
                'type' => 'integer',
                'default' => 20,
                'minimum' => 1,
                'maximum' => self::MAX_PER_PAGE,
                'sanitize_callback' => 'absint',
            ],
            'orderby' => [
                'description' => 'Sort collection by event_date, post date or title. Expanded mode always sorts by occurrence date; this is the secondary sort.',
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
     * @return WP_REST_Response|WP_Error
     */
    public function get_events($request) {
        $expand = (bool) $request->get_param('expand_recurring');
        $include_details = (bool) $request->get_param('include_details');
        $per_page = min(self::MAX_PER_PAGE, max(1, absint($request->get_param('per_page') ?: 20)));

        $date_from = (string) $request->get_param('date_from');
        $date_to = (string) $request->get_param('date_to');
        if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
            return new WP_Error(
                'rest_invalid_param',
                __('date_from must not be after date_to.', 'awesome-calendar-events'),
                ['status' => 400]
            );
        }

        $page = 1;
        $token = null;
        $token_param = (string) $request->get_param('page_token');
        if ($token_param !== '') {
            $token = self::parse_page_token($token_param);
            if (is_wp_error($token)) {
                return $token;
            }
            $page = $token['p'];
        }

        // Response cache (per param set; includes token pages).
        $ttl = (int) apply_filters('awesome_calendar_events_api_cache_ttl', self::DEFAULT_CACHE_TTL);
        $params = $request->get_params();
        ksort($params);
        $cache_key = $ttl > 0 ? $this->cache_key(wp_json_encode($params)) : null;
        if ($cache_key) {
            $cached = get_transient($cache_key);
            if (is_array($cached) && isset($cached['items'])) {
                return $this->build_response($cached);
            }
        }

        if ($expand) {
            $result = $this->get_expanded_result($request, $per_page, $include_details, $token, $page);
        } else {
            $result = $this->get_collapsed_result($request, $per_page, $include_details, $page);
        }

        if ($cache_key) {
            set_transient($cache_key, $result, $ttl);
        }

        return $this->build_response($result);
    }

    /**
     * Collapsed mode: SQL-filtered posts, one item per post.
     *
     * @return array {items, total, pages, next_token}
     */
    private function get_collapsed_result($request, $per_page, $include_details, $page) {
        $query = new WP_Query($this->build_query_args($request, $page));

        $today = current_time('Y-m-d');
        $items = [];
        foreach ($query->posts as $post) {
            // In-memory exclusion of count-exhausted recurring events
            // (`_awecal_event_recurrence_count`); end-date exclusion already
            // happened in SQL.
            if ($this->is_stale_recurring((int) $post->ID, $today)) {
                continue;
            }
            $items[] = self::prepare_item($post, $include_details);
        }

        $next_token = null;
        if ($page < (int) $query->max_num_pages) {
            $next_token = self::create_page_token(['p' => $page + 1]);
        }

        return [
            'items' => $items,
            'total' => (int) $query->found_posts,
            'pages' => (int) $query->max_num_pages,
            'next_token' => $next_token,
        ];
    }

    /**
     * Expanded mode: recurring events materialized into occurrence items.
     *
     * @return array {items, total, pages, next_token}
     */
    private function get_expanded_result($request, $per_page, $include_details, $token, $page) {
        $window = $this->get_expansion_window($request);
        $from = $window['from'];
        $to = $window['to'];

        // Cursor from token: occurrences before this date are counted for
        // end-condition bookkeeping (COUNT semantics) but not materialized.
        $cursor = null;
        if ($token && !empty($token['d'])) {
            $cursor = $token['d'];
            if ($cursor < $from) { $cursor = $from; }
            if ($cursor > $to) { $cursor = $to; }
        }

        $per_event_cap = (int) apply_filters(
            'awesome_calendar_events_api_max_occurrences_per_event',
            self::DEFAULT_MAX_OCCURRENCES_PER_EVENT
        );

        $query = new WP_Query($this->build_query_args($request, 1, true, $from));

        $items = [];
        foreach ($query->posts as $post) {
            $post_id = (int) $post->ID;
            if ($this->is_stale_recurring($post_id, $from)) {
                continue;
            }
            $dates = Awesome_Calendar_Events_Event_Meta::get_occurrences_between(
                $post_id,
                $from,
                $to,
                $per_event_cap,
                $cursor
            );
            foreach ($dates as $date) {
                $items[] = self::prepare_occurrence_item($post, $date, $include_details);
            }
        }

        $orderby = $request->get_param('orderby') ?: 'event_date';
        $direction = strtolower((string) $request->get_param('order')) === 'desc' ? -1 : 1;
        usort($items, function($a, $b) use ($orderby, $direction) {
            $cmp = strcmp($a['occurrence']['date'], $b['occurrence']['date']);
            if ($cmp !== 0) {
                return $cmp * $direction;
            }
            if ($orderby === 'title') {
                return strcmp((string) $a['title'], (string) $b['title']) * $direction;
            }
            if ($orderby === 'date') {
                return strcmp((string) $a['publishedAt'], (string) $b['publishedAt']) * $direction;
            }
            return 0;
        });

        // Total = occurrences already served on earlier pages (carried in the
        // token) + everything remaining in the window from the cursor.
        $served_before = $token ? (int) $token['t'] : 0;
        $total = $served_before + count($items);

        // With a cursor the merged list starts exactly at the first item of
        // the requested page; without one, slice by page number.
        $offset = ($cursor !== null) ? 0 : ($page - 1) * $per_page;
        $page_items = array_slice($items, $offset, $per_page);

        $next_token = null;
        if (count($items) > $offset + $per_page) {
            $next_token = self::create_page_token([
                'p' => $page + 1,
                'd' => $items[$offset + $per_page]['occurrence']['date'],
                // Occurrences served once the next page is reached: everything
                // served before this page + this page's items.
                't' => $served_before + count($page_items),
            ]);
        }

        return [
            'items' => $page_items,
            'total' => $total,
            'pages' => (int) ceil($total / $per_page),
            'next_token' => $next_token,
        ];
    }

    /**
     * Effective expansion window. Missing bounds default to
     * [today, today + DEFAULT_EXPANSION_WINDOW_DAYS], both filterable via
     * `awesome_calendar_events_api_expansion_default_window` (window length
     * in days).
     *
     * @return array {from, to} Y-m-d strings.
     */
    private function get_expansion_window($request) {
        $date_from = (string) $request->get_param('date_from');
        $date_to = (string) $request->get_param('date_to');
        $today = current_time('Y-m-d');

        $from = $date_from !== '' ? $date_from : $today;
        if ($date_to !== '') {
            $to = $date_to;
        } else {
            $days = max(1, (int) apply_filters(
                'awesome_calendar_events_api_expansion_default_window',
                self::DEFAULT_EXPANSION_WINDOW_DAYS
            ));
            $to = gmdate('Y-m-d', strtotime($from . ' +' . $days . ' days'));
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Whether a recurring event's occurrences are exhausted before the
     * reference date (via `_awecal_event_recurrence_count` / end date).
     * One-off events are never stale.
     *
     * @param int    $post_id
     * @param string $reference_date Y-m-d
     * @return bool
     */
    private function is_stale_recurring($post_id, $reference_date) {
        $type = awecal_get_post_meta($post_id, '_awecal_event_recurrence_type', true) ?: 'none';
        if ($type === 'none') {
            return false;
        }
        $last = Awesome_Calendar_Events_Event_Meta::get_last_occurrence_date($post_id);
        return $last !== null && $last < $reference_date;
    }

    private function build_response($result) {
        $response = rest_ensure_response($result['items']);
        $response->header('X-WP-Total', (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) $result['pages']);
        if (!empty($result['next_token'])) {
            $response->header('X-WP-NextPageToken', $result['next_token']);
        }
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
     * @param int             $page          Page number (from token in collapsed mode).
     * @param bool            $expanded      Expanded mode query (no date-range clause; end-date bound on window start).
     * @param string|null     $window_from   Y-m-d expansion window start (expanded mode only).
     * @return array
     */
    public function build_query_args($request, $page = 1, $expanded = false, $window_from = null) {
        $per_page = min(self::MAX_PER_PAGE, max(1, absint($request->get_param('per_page') ?: 20)));
        $orderby = $request->get_param('orderby') ?: 'event_date';
        $order = strtolower((string) $request->get_param('order')) === 'desc' ? 'DESC' : 'ASC';
        $include_details = (bool) $request->get_param('include_details');

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

        if ($expanded) {
            // The datetime window applies to occurrences (resolved in PHP),
            // so the SQL date-range clause is intentionally omitted. The
            // recurrence end-date bound still runs in SQL against the window
            // start, pre-filtering events that cannot have occurrences.
            $args = [
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => (int) apply_filters(
                    'awesome_calendar_events_api_max_posts_scan',
                    self::DEFAULT_MAX_POSTS_SCAN
                ),
                'paged' => 1,
                'orderby' => 'date',
                'order' => 'ASC',
                'ignore_sticky_posts' => true,
                'no_found_rows' => true,
                'update_post_term_cache' => $include_details,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta filtering on the event date/end-date keys is required to match events; results are bounded by the posts-scan cap.
                'meta_query' => [
                    'relation' => 'AND',
                    awecal_event_date_enabled_meta_query(),
                    awecal_event_recurrence_not_ended_meta_query($window_from ?: current_time('Y-m-d')),
                ],
            ];
        } else {
            $meta_query = [
                'relation' => 'AND',
                awecal_event_date_enabled_meta_query(),
            ];

            $date_from = (string) $request->get_param('date_from');
            $date_to = (string) $request->get_param('date_to');
            if ($date_from !== '' || $date_to !== '') {
                $meta_query[] = awecal_event_date_range_meta_query($date_from, $date_to);
            }

            // Exclude events whose recurrence ended before today.
            $meta_query[] = awecal_event_recurrence_not_ended_meta_query(current_time('Y-m-d'));

            $args = [
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => $per_page,
                'paged' => max(1, $page),
                'order' => $order,
                'ignore_sticky_posts' => true,
                // Term data is only needed when serializing term slugs in details mode.
                'update_post_term_cache' => $include_details,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta filtering on the event date keys is required to match events; date filtering uses a single indexed CHAR BETWEEN clause.
                'meta_query' => $meta_query,
            ];

            if ($orderby === 'event_date') {
                // Y-m-d strings sort correctly as meta_value. Sorting covers the
                // canonical key; posts that only carry legacy meta may sort last.
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Sorting by the canonical event date meta key is required for event_date ordering.
                $args['meta_key'] = awecal_meta_key('_icob_event_date');
                $args['orderby'] = 'meta_value';
                $args['meta_type'] = 'CHAR';
            } else {
                $args['orderby'] = $orderby;
            }
        }

        if ($tax_query) {
            if (count($tax_query) > 1) {
                $tax_query['relation'] = 'AND';
            }
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Category/tag filtering is a requested API feature and cannot be expressed without tax_query.
            $args['tax_query'] = $tax_query;
        }

        $search = $request->get_param('search');
        if ($search) {
            $args['s'] = $search;
        }

        return $args;
    }

    /* ------------------------------------------------------------------ *
     * Pagination tokens
     * ------------------------------------------------------------------ */

    /**
     * Create a signed, opaque pagination token.
     *
     * @param array $payload {p: page, d: occurrence date|null, t: served total}
     * @return string
     */
    public static function create_page_token(array $payload) {
        $json = wp_json_encode([
            'p' => max(1, (int) ($payload['p'] ?? 1)),
            'd' => isset($payload['d']) && $payload['d'] ? (string) $payload['d'] : null,
            't' => max(0, (int) ($payload['t'] ?? 0)),
        ]);
        $encoded = rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
        return $encoded . '.' . hash_hmac('sha256', $encoded, self::token_key());
    }

    /**
     * Parse and verify a pagination token. Returns the payload array or a
     * WP_Error (400) for malformed/tampered tokens.
     *
     * @param string $token
     * @return array|WP_Error
     */
    public static function parse_page_token($token) {
        $token = trim((string) $token);
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return self::invalid_token_error();
        }
        list($encoded, $signature) = $parts;
        if (!hash_equals(hash_hmac('sha256', $encoded, self::token_key()), $signature)) {
            return self::invalid_token_error();
        }
        $json = base64_decode(strtr($encoded, '-_', '+/'));
        $data = json_decode((string) $json, true);
        if (!is_array($data) || !isset($data['p'])) {
            return self::invalid_token_error();
        }
        return [
            'p' => max(1, (int) $data['p']),
            'd' => isset($data['d']) && $data['d'] ? (string) $data['d'] : null,
            't' => max(0, (int) ($data['t'] ?? 0)),
        ];
    }

    private static function invalid_token_error() {
        return new WP_Error(
            'rest_invalid_param',
            __('Invalid page token.', 'awesome-calendar-events'),
            ['status' => 400]
        );
    }

    private static function token_key() {
        return wp_hash('awesome_calendar_events_page_token');
    }

    /* ------------------------------------------------------------------ *
     * Item preparation
     * ------------------------------------------------------------------ */

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
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter intentionally applied so full body HTML matches front-end rendering.
            $item['fullBody'] = apply_filters('the_content', (string) ($post->post_content ?? ''));
            $item['categories'] = self::get_term_slugs($post_id, 'category');
            $item['tags'] = self::get_term_slugs($post_id, 'post_tag');
        }

        return $item;
    }

    /**
     * Serialize a single occurrence of a post: base item + occurrence block.
     *
     * @param WP_Post|int $post
     * @param string      $occurrence_date Y-m-d
     * @param bool        $include_details
     * @return array
     */
    public static function prepare_occurrence_item($post, $occurrence_date, $include_details = false) {
        $post_id = is_object($post) && isset($post->ID) ? (int) $post->ID : (int) $post;
        $item = self::prepare_item($post, $include_details);

        $start = null;
        $end = null;
        $time = trim((string) awecal_get_post_meta($post_id, '_awecal_event_start_time', true));
        if ($time !== '' && preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            // Stored time is site-local wall time; emit UTC instants.
            $offset = (int) ((float) get_option('gmt_offset', 0) * HOUR_IN_SECONDS);
            $start_ts = strtotime($occurrence_date . ' ' . $time) - $offset;
            if ($start_ts !== false) {
                $start = gmdate('c', $start_ts);
                $duration = (float) awecal_get_post_meta($post_id, '_awecal_event_duration_hours', true);
                if ($duration > 0) {
                    $end = gmdate('c', $start_ts + (int) round($duration * HOUR_IN_SECONDS));
                }
            }
        }

        $rec_type = awecal_get_post_meta($post_id, '_awecal_event_recurrence_type', true) ?: 'none';

        $item['occurrence'] = [
            'date' => (string) $occurrence_date,
            'start' => $start,
            'end' => $end,
            'isRecurring' => $rec_type !== 'none',
        ];

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

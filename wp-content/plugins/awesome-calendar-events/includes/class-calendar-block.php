<?php
/**
 * Calendar block
 *
 * Registers the `awesome-calendar-events/calendar` block (a month calendar
 * grid), plus its companion container blocks:
 *
 *  - `awesome-calendar-events/calendar-date`  – one date cell
 *  - `awesome-calendar-events/event-details`  – one event inside a cell
 *
 * The calendar is server-rendered for the initial month. Event data comes
 * from the public events query API (`awecal/v1/events`, expanded mode) and
 * the frontend script re-renders the grid for previous/next months using
 * the same endpoint.
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Calendar_Events_Calendar_Block {

	/**
	 * Context keys used to pass date/event payloads down the block tree.
	 */
	const CONTEXT_DATE  = 'awesome-calendar-events/date';
	const CONTEXT_EVENT = 'awesome-calendar-events/event';

	/**
	 * Maximum events fetched for a displayed month (the events query API
	 * caps a single request at 100 items; cursor pagination is used to
	 * reach this quota).
	 */
	const MAX_EVENTS_PER_MONTH = 100;

	/**
	 * Maximum pages fetched per calendar render (safety bound).
	 */
	const MAX_PAGES = 10;

	public function __construct() {
		$this->register_blocks();
	}

	/**
	 * Register the blocks and their assets.
	 */
	private function register_blocks() {
		$version = defined('AWESOME_CALENDAR_EVENTS_VERSION') ? AWESOME_CALENDAR_EVENTS_VERSION : '1.0.0';

		wp_register_script(
			'awesome-calendar-events-calendar-editor',
			AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/js/calendar-block.js',
			['awesome-calendar-events-block-icons', 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
			$version,
			true
		);

		wp_register_script(
			'awesome-calendar-events-calendar-date-editor',
			AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/js/calendar-date-block.js',
			['awesome-calendar-events-block-icons', 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
			$version,
			true
		);

		wp_register_script(
			'awesome-calendar-events-event-details-editor',
			AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/js/event-details-block.js',
			['awesome-calendar-events-block-icons', 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
			$version,
			true
		);

		// Shared frontend styles for all three blocks (also used by the editor).
		wp_register_style(
			'awesome-calendar-events-calendar-style',
			AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/css/calendar-block.css',
			[],
			$version
		);

		// Frontend script: previous/next month navigation.
		wp_register_script(
			'awesome-calendar-events-calendar-frontend',
			AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/js/calendar-frontend.js',
			[],
			$version,
			true
		);

		if (!function_exists('register_block_type')) { return; }

		register_block_type(
			AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'blocks/calendar',
			['render_callback' => [$this, 'render_calendar']]
		);

		register_block_type(
			AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'blocks/calendar-date',
			['render_callback' => [$this, 'render_calendar_date']]
		);

		register_block_type(
			AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'blocks/event-details',
			['render_callback' => [$this, 'render_event_details']]
		);
	}

	/* ------------------------------------------------------------------ *
	 * Calendar block
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the `awesome-calendar-events/calendar` block on the server.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Block inner content.
	 * @param WP_Block $block      Block instance.
	 * @return string
	 */
	public function render_calendar($attributes, $content = '', $block = null) {
		$attributes = is_array($attributes) ? $attributes : [];

		$start_of_week   = max(0, min(6, (int) ($attributes['startOfWeek'] ?? 0)));
		$max_events      = max(0, (int) ($attributes['maxEventsPerDay'] ?? 0));
		$show_navigation = !isset($attributes['showNavigation']) || (bool) $attributes['showNavigation'];
		$categories      = trim((string) ($attributes['categories'] ?? ''));
		$tags            = trim((string) ($attributes['tags'] ?? ''));

		// Displayed month: current month shifted by initialOffset months.
		$offset = (int) ($attributes['initialOffset'] ?? 0);
		try {
			$first = new DateTimeImmutable('first day of this month 00:00:00', wp_timezone());
			if ($offset !== 0) {
				$first = $first->modify(($offset > 0 ? '+' : '') . $offset . ' months');
			}
		} catch (Exception $e) {
			return '';
		}

		$days_in_month = (int) $first->format('t');
		$first_dow     = (int) $first->format('w');
		$last_dow      = (int) $first->modify(($days_in_month - 1) . ' days')->format('w');

		// Grid bounds include the leading/trailing cells of adjacent months.
		$grid_start = $first->modify('-' . (($first_dow - $start_of_week + 7) % 7) . ' days');
		$grid_end   = $first->modify(($days_in_month - 1) . ' days')
			->modify('+' . (($start_of_week + 6 - $last_dow + 7) % 7) . ' days');

		$today = current_time('Y-m-d');
		$year  = (int) $first->format('Y');
		$month = (int) $first->format('n');

		// Weekday headers start at the configured start of week.
		$weekdays = [];
		$month_names = [];
		if (isset($GLOBALS['wp_locale'])) {
			for ($i = 0; $i < 7; $i++) {
				$weekdays[] = $GLOBALS['wp_locale']->get_weekday(($start_of_week + $i) % 7);
			}
			for ($m = 1; $m <= 12; $m++) {
				$month_names[] = $GLOBALS['wp_locale']->get_month($m);
			}
		} else {
			$weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
			$month_names = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
		}

		// Config consumed by the frontend script for month navigation. The
		// display options mirror the container block attributes so the JS
		// re-render matches the server-rendered markup.
		$config = [
			'month'         => $first->format('Y-m'),
			'startOfWeek'   => $start_of_week,
			'today'         => $today,
			'restUrl'       => esc_url_raw(rest_url('awecal/v1/events')),
			'categories'    => $categories,
			'tags'          => $tags,
			'maxEvents'     => $max_events,
			'weekdayNames'  => $weekdays,
			'monthNames'    => $month_names,
			'timezone'      => wp_timezone()->getName(),
			'timeFormat'    => (string) get_option('time_format', 'g:i a'),
			'dateCell'      => [
				'showDateNumber' => true,
			],
			'eventItem'     => [
				'showTitle'    => true,
				'titleTag'     => 'h3',
				'linkTitle'    => true,
				'showTime'     => true,
				'showLocation' => false,
				'showSnippet'  => false,
			],
		];

		// Overlay the actual container block settings when they exist in the
		// saved content so the JS re-render matches what the user configured.
		$date_template_attrs = $this->find_template_attributes($block, 'awesome-calendar-events/calendar-date');
		if (isset($date_template_attrs['showDateNumber'])) {
			$config['dateCell']['showDateNumber'] = (bool) $date_template_attrs['showDateNumber'];
		}
		$details_attrs = $this->find_template_attributes($block, 'awesome-calendar-events/event-details', true);
		if (is_array($details_attrs)) {
			$allowed_tags = ['h2', 'h3', 'h4', 'h5', 'p', 'div'];
			$config['eventItem'] = [
				'showTitle'    => !isset($details_attrs['showTitle']) || (bool) $details_attrs['showTitle'],
				'titleTag'     => (isset($details_attrs['titleTag']) && in_array($details_attrs['titleTag'], $allowed_tags, true)) ? $details_attrs['titleTag'] : 'h3',
				'linkTitle'    => !isset($details_attrs['linkTitle']) || (bool) $details_attrs['linkTitle'],
				'showTime'     => !isset($details_attrs['showTime']) || (bool) $details_attrs['showTime'],
				'showLocation' => !empty($details_attrs['showLocation']),
				'showSnippet'  => !empty($details_attrs['showSnippet']),
			];
		}

		$events_by_date = $this->fetch_events(
			$grid_start->format('Y-m-d'),
			$grid_end->format('Y-m-d'),
			$first->format('Y-m-01'),
			$first->format('Y-m-t'),
			$categories,
			$tags
		);

		$cells = '';
		$date_template = $this->find_template_block($block, 'awesome-calendar-events/calendar-date');
		$parent_context = ($block && isset($block->context) && is_array($block->context)) ? $block->context : [];

		for ($day = $grid_start; $day <= $grid_end; $day = $day->modify('+1 day')) {
			$ymd    = $day->format('Y-m-d');
			$events = isset($events_by_date[$ymd]) ? $events_by_date[$ymd] : [];

			$classes = ['awecal-calendar-day'];
			if ($ymd === $today) { $classes[] = 'is-today'; }
			if ($day->format('Y-m') !== $first->format('Y-m')) { $classes[] = 'is-other-month'; }
			$classes[] = $events ? 'has-events' : 'is-empty';
			$class_attr = esc_attr(implode(' ', $classes));

			if ($date_template !== null) {
				$child = new WP_Block(
					$date_template,
					array_merge(
						$parent_context,
						[
							self::CONTEXT_DATE => [
								'date'           => $ymd,
								'isToday'        => ($ymd === $today),
								'isCurrentMonth' => ($day->format('Y-m') === $first->format('Y-m')),
								'events'         => $events,
								'maxEvents'      => $max_events,
								'eventItem'      => $config['eventItem'],
							],
						]
					)
				);
				$cells .= $child->render();
			} else {
				// No calendar-date container in the content: render a bare
				// cell with default markup so events still show (parity with
				// the JS re-render).
				$inner = '<div class="awecal-calendar-day-number">' . esc_html((int) $day->format('j')) . '</div>';
				$count = 0;
				foreach ($events as $event) {
					if ($max_events > 0 && $count >= $max_events) { break; }
					$inner .= $this->render_default_event_item($event, $config['eventItem']);
					$count++;
				}
				$cells .= sprintf('<div class="%1$s" data-date="%2$s">%3$s</div>', $class_attr, esc_attr($ymd), $inner);
			}
		}

		$wrapper_attributes = get_block_wrapper_attributes([
			'class' => 'awecal-calendar',
		]);

		$nav = '';
		if ($show_navigation) {
			$nav = sprintf(
				'<button type="button" class="awecal-calendar-nav awecal-calendar-nav-prev" data-awecal-nav="prev" aria-label="%1$s">&#8249;</button>' .
				'<button type="button" class="awecal-calendar-nav awecal-calendar-nav-next" data-awecal-nav="next" aria-label="%2$s">&#8250;</button>',
				esc_attr__('Previous month', 'awesome-calendar-events'),
				esc_attr__('Next month', 'awesome-calendar-events')
			);
		}

		$html  = '<div ' . $wrapper_attributes . ' data-awecal-config="' . esc_attr(wp_json_encode($config)) . '">';
		$html .= '<div class="awecal-calendar-header">';
		$html .= '<div class="awecal-calendar-month-label" data-awecal-label>' . esc_html($month_names[$month - 1] . ' ' . $year) . '</div>';
		$html .= $nav;
		$html .= '</div>';
		$html .= '<div class="awecal-calendar-grid" data-awecal-grid>';
		foreach ($weekdays as $weekday) {
			$html .= '<div class="awecal-calendar-weekday">' . esc_html($weekday) . '</div>';
		}
		$html .= $cells;
		$html .= '</div>';
		$html .= '</div>';

		if ($show_navigation) {
			wp_enqueue_script('awesome-calendar-events-calendar-frontend');
		}

		return $html;
	}

	/**
	 * Fetch occurrences via the public events query API (expanded mode),
	 * following the cursor pagination until the displayed month is covered
	 * or its quota (MAX_EVENTS_PER_MONTH) is reached. Results are keyed by
	 * occurrence date.
	 *
	 * @param string $from          Y-m-d grid window start.
	 * @param string $to            Y-m-d grid window end.
	 * @param string $month_start   Y-m-d first day of the displayed month.
	 * @param string $month_end     Y-m-d last day of the displayed month.
	 * @param string $categories    Comma-separated category slugs.
	 * @param string $tags          Comma-separated tag slugs.
	 * @return array<string, array>
	 */
	private function fetch_events($from, $to, $month_start, $month_end, $categories, $tags) {
		if (!class_exists('Awesome_Calendar_Events_Events_Query_API')) {
			return [];
		}

		$api = new Awesome_Calendar_Events_Events_Query_API();

		$items = [];
		$token = null;

		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$request = new WP_REST_Request();
			$request->set_param('expand_recurring', true);
			$request->set_param('date_from', $from);
			$request->set_param('date_to', $to);
			$request->set_param('per_page', Awesome_Calendar_Events_Events_Query_API::MAX_PER_PAGE);
			$request->set_param('include_details', false);
			if ($categories !== '') {
				$request->set_param('categories', $categories);
			}
			if ($tags !== '') {
				$request->set_param('tags', $tags);
			}
			if ($token !== null) {
				$request->set_param('page_token', $token);
			}

			$response = $api->get_events($request);
			if (is_wp_error($response) || !method_exists($response, 'get_data')) {
				break;
			}

			$page_items = $response->get_data();
			if (!is_array($page_items)) {
				break;
			}

			$in_month  = 0;
			$last_date = '';
			foreach ($page_items as $item) {
				$items[] = $item;
				$date    = (string) ($item['occurrence']['date'] ?? '');
				if ($date === '') {
					continue;
				}
				$last_date = $date;
				if ($date >= $month_start && $date <= $month_end) {
					$in_month++;
				}
			}

			$token = $this->get_next_page_token($response);

			// Stop when the API is exhausted, the displayed month reached its
			// quota, or the window extends past the month's end (trailing
			// adjacent-month cells are covered).
			if (!$token || $in_month >= self::MAX_EVENTS_PER_MONTH || ($last_date !== '' && $last_date > $month_end)) {
				break;
			}
		}

		$by_date = [];
		foreach ($items as $item) {
			if (!is_array($item) || empty($item['occurrence']['date'])) {
				continue;
			}
			$by_date[(string) $item['occurrence']['date']][] = $item;
		}

		return $by_date;
	}

	/**
	 * Find the parsed block of a given name among the block's direct
	 * inner blocks (the date cell container).
	 *
	 * @param WP_Block|null $block Block instance.
	 * @param string        $name  Block name.
	 * @return array|null Parsed block.
	 */
	private function find_template_block($block, $name) {
		foreach ($this->get_inner_parsed_blocks($block) as $parsed) {
			if (($parsed['blockName'] ?? '') === $name) {
				return $parsed;
			}
		}
		return null;
	}

	/**
	 * Find the attributes of the first block of a given name among the
	 * block's direct inner blocks; when $recursive is true, also searches
	 * the inner blocks of each child (the event details container lives
	 * inside the date cell container).
	 *
	 * @param WP_Block|null $block     Block instance.
	 * @param string        $name      Block name.
	 * @param bool          $recursive Whether to search nested inner blocks.
	 * @return array|null
	 */
	private function find_template_attributes($block, $name, $recursive = false) {
		$parsed_blocks = $this->get_inner_parsed_blocks($block);
		foreach ($parsed_blocks as $parsed) {
			if (($parsed['blockName'] ?? '') === $name) {
				return $parsed['attrs'] ?? [];
			}
			if ($recursive && !empty($parsed['innerBlocks'])) {
				foreach ($parsed['innerBlocks'] as $nested) {
					if (($nested['blockName'] ?? '') === $name) {
						return $nested['attrs'] ?? [];
					}
				}
			}
		}
		return null;
	}

	/**
	 * Parsed inner blocks of a block instance.
	 *
	 * @param WP_Block|null $block Block instance.
	 * @return array
	 */
	private function get_inner_parsed_blocks($block) {
		if (!$block || !isset($block->inner_blocks) || !($block->inner_blocks instanceof WP_Block_List)) {
			return [];
		}
		$parsed = [];
		foreach ($block->inner_blocks as $child) {
			if ($child && isset($child->parsed_block)) {
				$parsed[] = $child->parsed_block;
			}
		}
		return $parsed;
	}

	/* ------------------------------------------------------------------ *
	 * Calendar date container
	 * ------------------------------------------------------------------ */

	/**
	 * Renders one date cell. The cell payload (date, events, options) is
	 * provided through block context by the calendar block.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Block inner content.
	 * @param WP_Block $block      Block instance.
	 * @return string
	 */
	public function render_calendar_date($attributes, $content = '', $block = null) {
		$attributes = is_array($attributes) ? $attributes : [];
		$context    = ($block && isset($block->context) && is_array($block->context)) ? $block->context : [];
		$date       = is_array($context[self::CONTEXT_DATE] ?? null) ? $context[self::CONTEXT_DATE] : null;

		$ymd      = $date ? (string) $date['date'] : '';
		$events   = $date && isset($date['events']) && is_array($date['events']) ? $date['events'] : [];
		$max      = $date && isset($date['maxEvents']) ? (int) $date['maxEvents'] : 0;
		$is_today = $date && !empty($date['isToday']);
		$other    = $date && isset($date['isCurrentMonth']) && !$date['isCurrentMonth'];
		$event_config = $date && isset($date['eventItem']) && is_array($date['eventItem']) ? $date['eventItem'] : [
			'showTitle'    => true,
			'titleTag'     => 'h3',
			'linkTitle'    => true,
			'showTime'     => true,
			'showLocation' => false,
			'showSnippet'  => false,
		];

		$classes = ['awecal-calendar-day'];
		if ($is_today) { $classes[] = 'is-today'; }
		if ($other)    { $classes[] = 'is-other-month'; }
		$classes[] = $events ? 'has-events' : 'is-empty';

		$wrapper = get_block_wrapper_attributes([
			'class'    => implode(' ', $classes),
			'data-date' => $ymd,
		]);

		$inner = '';
		if (!isset($attributes['showDateNumber']) || $attributes['showDateNumber']) {
			$inner .= '<div class="awecal-calendar-day-number">' . esc_html((int) substr($ymd, 8, 2)) . '</div>';
		}

		$details_children = [];
		if ($block && isset($block->inner_blocks) && $block->inner_blocks instanceof WP_Block_List) {
			foreach ($block->inner_blocks as $child) {
				if ($child && ($child->name ?? '') === 'awesome-calendar-events/event-details') {
					$details_children[] = $child->parsed_block;
				}
			}
		}

		if ($details_children) {
			foreach ($details_children as $parsed_details) {
				$count = 0;
				foreach ($events as $event) {
					if ($max > 0 && $count >= $max) {
						break;
					}
					$event_block = new WP_Block(
						$parsed_details,
						array_merge($context, [self::CONTEXT_EVENT => $event])
					);
					$inner .= $event_block->render();
					$count++;
				}
			}
		} else {
			// No event-details container in the content: render events with
			// default markup (parity with the JS re-render).
			$count = 0;
			foreach ($events as $event) {
				if ($max > 0 && $count >= $max) {
					break;
				}
				$inner .= $this->render_default_event_item($event, $event_config);
				$count++;
			}
		}

		return sprintf('<div %1$s>%2$s</div>', $wrapper, $inner);
	}

	/* ------------------------------------------------------------------ *
	 * Event details container
	 * ------------------------------------------------------------------ */

	/**
	 * Renders a single event. The event payload is provided through block
	 * context by the calendar date container.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Block inner content.
	 * @param WP_Block $block      Block instance.
	 * @return string
	 */
	public function render_event_details($attributes, $content = '', $block = null) {
		$attributes = is_array($attributes) ? $attributes : [];
		$context    = ($block && isset($block->context) && is_array($block->context)) ? $block->context : [];
		$event      = is_array($context[self::CONTEXT_EVENT] ?? null) ? $context[self::CONTEXT_EVENT] : null;

		if (!$event) {
			return '';
		}

		$show_title    = !isset($attributes['showTitle']) || (bool) $attributes['showTitle'];
		$allowed_tags  = ['h2', 'h3', 'h4', 'h5', 'p', 'div'];
		$title_tag     = (isset($attributes['titleTag']) && in_array($attributes['titleTag'], $allowed_tags, true)) ? $attributes['titleTag'] : 'h3';
		$link_title    = !isset($attributes['linkTitle']) || (bool) $attributes['linkTitle'];
		$show_time     = !isset($attributes['showTime']) || (bool) $attributes['showTime'];
		$show_location = !empty($attributes['showLocation']);
		$show_snippet  = !empty($attributes['showSnippet']);

		$inner = '';

		if ($show_title) {
			$title = (string) ($event['title'] ?? '');
			if ($title !== '') {
				if ($link_title && !empty($event['url'])) {
					$inner .= sprintf(
						'<%1$s class="awecal-calendar-event-title"><a href="%2$s">%3$s</a></%1$s>',
						$title_tag,
						esc_url((string) $event['url']),
						esc_html($title)
					);
				} else {
					$inner .= sprintf(
						'<%1$s class="awecal-calendar-event-title">%2$s</%1$s>',
						$title_tag,
						esc_html($title)
					);
				}
			}
		}

		if ($show_time) {
			$time = $this->format_event_time($event);
			if ($time !== '') {
				$inner .= sprintf('<span class="awecal-calendar-event-time">%1$s</span>', esc_html($time));
			}
		}

		if ($show_location) {
			$location = trim((string) ($event['event']['location'] ?? ''));
			if ($location !== '') {
				$inner .= sprintf('<span class="awecal-calendar-event-location">%1$s</span>', esc_html($location));
			}
		}

		if ($show_snippet) {
			$snippet = trim((string) ($event['snippet'] ?? ''));
			if ($snippet !== '') {
				$inner .= sprintf('<span class="awecal-calendar-event-snippet">%1$s</span>', esc_html($snippet));
			}
		}

		$wrapper = get_block_wrapper_attributes(['class' => 'awecal-calendar-event']);

		return sprintf('<div %1$s>%2$s</div>', $wrapper, $inner);
	}

	/**
	 * Next-page cursor token from a REST response (case-insensitive).
	 *
	 * @param WP_REST_Response $response Response.
	 * @return string|null
	 */
	private function get_next_page_token($response) {
		if (!method_exists($response, 'get_headers')) {
			return null;
		}
		foreach ((array) $response->get_headers() as $name => $value) {
			if (strcasecmp((string) $name, 'X-WP-NextPageToken') === 0 && $value) {
				return (string) $value;
			}
		}
		return null;
	}

	/**
	 * Default event item markup, mirroring the frontend JS re-render. Used
	 * when no Event Details container exists in the saved content.
	 *
	 * @param array $event API item.
	 * @param array $cfg   eventItem display config.
	 * @return string
	 */
	private function render_default_event_item($event, $cfg) {
		$inner = '';

		$title = (string) ($event['title'] ?? '');
		if (!empty($cfg['showTitle']) && $title !== '') {
			$allowed_tags = ['h2', 'h3', 'h4', 'h5', 'p', 'div'];
			$tag = (isset($cfg['titleTag']) && in_array($cfg['titleTag'], $allowed_tags, true)) ? $cfg['titleTag'] : 'h3';
			if (!empty($cfg['linkTitle']) && !empty($event['url'])) {
				$inner .= sprintf(
					'<%1$s class="awecal-calendar-event-title"><a href="%2$s">%3$s</a></%1$s>',
					$tag,
					esc_url((string) $event['url']),
					esc_html($title)
				);
			} else {
				$inner .= sprintf('<%1$s class="awecal-calendar-event-title">%2$s</%1$s>', $tag, esc_html($title));
			}
		}

		if (!empty($cfg['showTime'])) {
			$time = $this->format_event_time($event);
			if ($time !== '') {
				$inner .= sprintf('<span class="awecal-calendar-event-time">%1$s</span>', esc_html($time));
			}
		}

		if (!empty($cfg['showLocation'])) {
			$location = trim((string) ($event['event']['location'] ?? ''));
			if ($location !== '') {
				$inner .= sprintf('<span class="awecal-calendar-event-location">%1$s</span>', esc_html($location));
			}
		}

		if (!empty($cfg['showSnippet'])) {
			$snippet = trim((string) ($event['snippet'] ?? ''));
			if ($snippet !== '') {
				$inner .= sprintf('<span class="awecal-calendar-event-snippet">%1$s</span>', esc_html($snippet));
			}
		}

		return '<div class="awecal-calendar-event">' . $inner . '</div>';
	}

	/**
	 * Formatted start time of an occurrence (site time format).
	 *
	 * @param array $event API item.
	 * @return string
	 */
	private function format_event_time($event) {
		$start = (string) ($event['occurrence']['start'] ?? '');
		if ($start === '') {
			return '';
		}
		$ts = strtotime($start);
		if ($ts === false) {
			return '';
		}
		return wp_date((string) get_option('time_format', 'g:i a'), $ts);
	}
}

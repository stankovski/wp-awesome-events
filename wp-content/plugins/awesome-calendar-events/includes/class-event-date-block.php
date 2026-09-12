<?php
/**
 * Server-side rendering of the Awesome Calendar Events event meta blocks:
 * `awesome-calendar-events/event-date`, `awesome-calendar-events/event-time`
 * and `awesome-calendar-events/event-location`.
 *
 * Also registers block bindings sources of the same names so the event
 * metadata can be bound to supported blocks (e.g. a core paragraph),
 * similar to how the Post Date block binds post data.
 *
 * @package awesome-calendar-events
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Calendar_Events_Event_Date_Block {

	/**
	 * Register the blocks and the block bindings sources.
	 */
	public function __construct() {
		$this->register_blocks();
		$this->register_bindings_sources();
	}

	/**
	 * Register the event meta blocks and their assets.
	 */
	private function register_blocks() {
		$version = defined('AWESOME_CALENDAR_EVENTS_VERSION') ? AWESOME_CALENDAR_EVENTS_VERSION : '1.0.0';

		wp_register_script(
			'awesome-calendar-events-event-meta-blocks-shared',
			AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/js/event-meta-blocks-shared.js',
			array('wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-date', 'wp-api-fetch'),
			$version,
			true
		);

		wp_register_script(
			'awesome-calendar-events-event-date-block-editor',
			AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/js/event-date-block.js',
			array('awesome-calendar-events-block-icons', 'awesome-calendar-events-event-meta-blocks-shared'),
			$version,
			true
		);

		wp_register_script(
			'awesome-calendar-events-event-time-block-editor',
			AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/js/event-time-block.js',
			array('awesome-calendar-events-block-icons', 'awesome-calendar-events-event-meta-blocks-shared'),
			$version,
			true
		);

		wp_register_script(
			'awesome-calendar-events-event-location-block-editor',
			AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/js/event-location-block.js',
			array('awesome-calendar-events-block-icons', 'awesome-calendar-events-event-meta-blocks-shared'),
			$version,
			true
		);

		wp_register_style(
			'awesome-calendar-events-event-date-block-style',
			AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/css/event-date-block.css',
			array(),
			$version
		);

		if (!function_exists('register_block_type')) { return; }

		register_block_type(
			AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'blocks/event-date',
			array(
				'render_callback' => array($this, 'render_event_date'),
			)
		);

		register_block_type(
			AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'blocks/event-time',
			array(
				'render_callback' => array($this, 'render_event_time'),
			)
		);

		register_block_type(
			AWESOME_CALENDAR_EVENTS_PLUGIN_DIR . 'blocks/event-location',
			array(
				'render_callback' => array($this, 'render_event_location'),
			)
		);

		// DEPRECATED: legacy "icob/event-date" block name, kept for backwards
		// compatibility with content created before the plugin was renamed to
		// Awesome Calendar Events. Registered server-side only (no editor
		// script) so existing posts keep rendering on the frontend, but the
		// block never appears in the block inserter. New content must use the
		// "awesome-calendar-events/event-*" blocks. Plan removal in a future
		// release.
		register_block_type(
			'icob/event-date',
			array(
				'api_version'     => 3,
				'style'           => 'awesome-calendar-events-event-date-block-style',
				'render_callback' => array($this, 'render_legacy'),
				'attributes'      => $this->get_legacy_attributes(),
			)
		);
	}

	/**
	 * Register the event meta block bindings sources.
	 */
	private function register_bindings_sources() {
		if (!function_exists('register_block_bindings_source')) { return; }

		register_block_bindings_source(
			'awesome-calendar-events/event-date',
			array(
				'label'              => __('Event Date', 'awesome-calendar-events'),
				'get_value_callback' => array($this, 'get_event_date_bindings_value'),
				'uses_context'       => array('postId'),
			)
		);

		register_block_bindings_source(
			'awesome-calendar-events/event-time',
			array(
				'label'              => __('Event Time', 'awesome-calendar-events'),
				'get_value_callback' => array($this, 'get_event_time_bindings_value'),
				'uses_context'       => array('postId'),
			)
		);

		register_block_bindings_source(
			'awesome-calendar-events/event-location',
			array(
				'label'              => __('Event Location', 'awesome-calendar-events'),
				'get_value_callback' => array($this, 'get_event_location_bindings_value'),
				'uses_context'       => array('postId'),
			)
		);
	}

	/**
	 * Legacy attribute definitions for the deprecated "icob/event-date" block.
	 */
	private function get_legacy_attributes() {
		return array(
			'format'                  => array('type' => 'string', 'default' => 'F j, Y'),
			'timeFormat'              => array('type' => 'string', 'default' => 'g:i A'),
			'dataType'                => array('type' => 'string', 'default' => 'date'),
			'fallbackText'            => array('type' => 'string', 'default' => ''),
			'showLabel'               => array('type' => 'boolean', 'default' => false),
			'labelText'               => array('type' => 'string', 'default' => __('Event Date:', 'awesome-calendar-events')),
			'showWeekdaysWhenMissing' => array('type' => 'boolean', 'default' => true),
			'wrapTag'                 => array('type' => 'string', 'default' => 'div'),
			'className'               => array('type' => 'string', 'default' => ''),
			'postId'                  => array('type' => 'integer', 'default' => 0),
			'locationMetaKey'         => array('type' => 'string', 'default' => '_awecal_event_location'),
			'relativeCurrentWeek'     => array('type' => 'boolean', 'default' => false),
		);
	}

	/**
	 * Resolve a post ID from block context, falling back to the current post.
	 *
	 * @param object|null $block Block instance.
	 * @return int
	 */
	private static function get_post_id($block) {
		if ($block && isset($block->context['postId'])) {
			return (int) $block->context['postId'];
		}

		return (int) get_the_ID();
	}

	/**
	 * Resolve the formatted event date for a post.
	 *
	 * Returns an array with:
	 *  - 'value'    => display string (relative string, formatted date or weekday fallback)
	 *  - 'datetime' => machine-readable Y-m-d date for the "time" element (may be empty)
	 *
	 * @param int    $post_id       Post ID.
	 * @param string $format        PHP date format.
	 * @param bool   $show_weekdays Whether to fall back to plural weekdays when no date exists.
	 * @param bool   $relative_week Whether to output relative forms (e.g. "This Monday").
	 * @return array
	 */
	private static function get_formatted_date($post_id, $format, $show_weekdays, $relative_week) {
		$result = array(
			'value'    => '',
			'datetime' => '',
		);

		if (!class_exists('Awesome_Calendar_Events_Event_Meta')) {
			return $result;
		}

		$display = Awesome_Calendar_Events_Event_Meta::get_event_date_display($post_id, $format, true, $relative_week);

		if ($relative_week && !empty($display['relative'])) {
			$result['value'] = $display['relative'];
		} elseif (!empty($display['date'])) {
			$result['value']    = $display['date'];
			$result['datetime'] = $display['iso'];
		} elseif ($show_weekdays && !empty($display['weekdays'])) {
			$result['value'] = $display['weekdays'];
		}

		return $result;
	}

	/**
	 * Resolve the formatted event start time for a post.
	 *
	 * Returns an array with:
	 *  - 'value'    => formatted time string
	 *  - 'datetime' => machine-readable H:i time for the "time" element (may be empty)
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $time_format PHP time format.
	 * @return array
	 */
	private static function get_formatted_time($post_id, $time_format) {
		$result = array(
			'value'    => '',
			'datetime' => '',
		);

		if (!class_exists('Awesome_Calendar_Events_Event_Meta')) {
			return $result;
		}

		$display    = Awesome_Calendar_Events_Event_Meta::get_event_date_display($post_id, null, true, false);
		$start_time = isset($display['start_time']) ? $display['start_time'] : '';
		if ('' === $start_time) {
			return $result;
		}

		// The stored time is site-local (as entered in the event meta box).
		// Parse it in the site timezone so wp_date() below renders the same
		// wall-clock time instead of shifting it by the UTC offset.
		try {
			$datetime = new DateTimeImmutable($start_time, wp_timezone());
		} catch (Exception $e) {
			return $result;
		}

		$result['datetime'] = $datetime->format('H:i');
		$result['value']    = wp_date($time_format, $datetime->getTimestamp());

		return $result;
	}

	/**
	 * Resolve the event location for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function get_location($post_id) {
		if (!class_exists('Awesome_Calendar_Events_Event_Meta')) {
			return '';
		}

		$display = Awesome_Calendar_Events_Event_Meta::get_event_date_display($post_id, null, true, false);
		return isset($display['location']) ? (string) $display['location'] : '';
	}

	/**
	 * Build the wrapper classes shared by the event meta blocks,
	 * mirroring the Post Date block.
	 *
	 * @param array  $attributes  Block attributes.
	 * @param string $block_class Base class for the block.
	 * @return array
	 */
	private static function get_wrapper_classes($attributes, $block_class) {
		$classes = array($block_class);

		if (isset($attributes['textAlign'])) {
			$classes[] = 'has-text-align-' . $attributes['textAlign'];
		}
		if (isset($attributes['style']['elements']['link']['color']['text'])) {
			$classes[] = 'has-link-color';
		}

		return $classes;
	}

	/**
	 * Wrap a resolved value in the block wrapper with an optional "time" element.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $block_class Base class for the block.
	 * @param string $value      Display value.
	 * @param string $datetime   Machine-readable datetime (empty to render a span).
	 * @return string
	 */
	private static function render_value($attributes, $block_class, $value, $datetime) {
		$wrapper_attributes = get_block_wrapper_attributes(
			array('class' => implode(' ', self::get_wrapper_classes($attributes, $block_class)))
		);

		$label      = isset($attributes['label']) ? $attributes['label'] : '';
		$label_html = '' !== $label ? '<span class="awecal-event-label">' . esc_html($label) . ' </span>' : '';

		if ('' !== $datetime) {
			$inner = $label_html . sprintf(
				'<time datetime="%1$s" class="awecal-event-value">%2$s</time>',
				esc_attr($datetime),
				esc_html($value)
			);
		} else {
			$inner = $label_html . sprintf('<span class="awecal-event-value">%1$s</span>', esc_html($value));
		}

		return sprintf('<div %1$s>%2$s</div>', $wrapper_attributes, $inner);
	}

	/**
	 * Renders the `awesome-calendar-events/event-date` block on the server.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Block default content.
	 * @param WP_Block $block      Block instance.
	 * @return string Returns the filtered event date for the current post wrapped inside "time" tags.
	 */
	public function render_event_date($attributes, $content = '', $block = null) {
		$post_id = self::get_post_id($block);
		if (!$post_id) {
			return '';
		}

		$format        = empty($attributes['format']) ? get_option('date_format') : $attributes['format'];
		$show_weekdays = !isset($attributes['showWeekdaysWhenMissing']) || $attributes['showWeekdaysWhenMissing'];
		$relative_week = !empty($attributes['relativeCurrentWeek']);

		$date = self::get_formatted_date($post_id, $format, $show_weekdays, $relative_week);

		$value = '' !== $date['value'] ? $date['value'] : (isset($attributes['fallbackText']) ? $attributes['fallbackText'] : '');
		if ('' === $value) {
			return '';
		}

		return self::render_value($attributes, 'awecal-event-date-block', $value, $date['datetime']);
	}

	/**
	 * Renders the `awesome-calendar-events/event-time` block on the server.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Block default content.
	 * @param WP_Block $block      Block instance.
	 * @return string Returns the filtered event start time for the current post wrapped inside "time" tags.
	 */
	public function render_event_time($attributes, $content = '', $block = null) {
		$post_id = self::get_post_id($block);
		if (!$post_id) {
			return '';
		}

		$time_format = empty($attributes['timeFormat']) ? get_option('time_format') : $attributes['timeFormat'];

		$time = self::get_formatted_time($post_id, $time_format);

		$value = '' !== $time['value'] ? $time['value'] : (isset($attributes['fallbackText']) ? $attributes['fallbackText'] : '');
		if ('' === $value) {
			return '';
		}

		return self::render_value($attributes, 'awecal-event-time-block', $value, $time['datetime']);
	}

	/**
	 * Renders the `awesome-calendar-events/event-location` block on the server.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Block default content.
	 * @param WP_Block $block      Block instance.
	 * @return string Returns the filtered event location for the current post.
	 */
	public function render_event_location($attributes, $content = '', $block = null) {
		$post_id = self::get_post_id($block);
		if (!$post_id) {
			return '';
		}

		$location = self::get_location($post_id);

		$value = '' !== $location ? $location : (isset($attributes['fallbackText']) ? $attributes['fallbackText'] : '');
		if ('' === $value) {
			return '';
		}

		return self::render_value($attributes, 'awecal-event-location-block', $value, '');
	}

	/**
	 * Return the event date for a bound block attribute.
	 *
	 * @param array  $source_args     Source arguments.
	 * @param object $block_instance  Block instance.
	 * @param string $attribute_name  Bound attribute name.
	 * @return string|null
	 */
	public function get_event_date_bindings_value($source_args, $block_instance, $attribute_name) {
		$post_id = self::get_post_id($block_instance);
		if (!$post_id) {
			return null;
		}

		$date = self::get_formatted_date($post_id, get_option('date_format'), true, false);
		return '' !== $date['value'] ? $date['value'] : null;
	}

	/**
	 * Return the event time for a bound block attribute.
	 *
	 * @param array  $source_args     Source arguments.
	 * @param object $block_instance  Block instance.
	 * @param string $attribute_name  Bound attribute name.
	 * @return string|null
	 */
	public function get_event_time_bindings_value($source_args, $block_instance, $attribute_name) {
		$post_id = self::get_post_id($block_instance);
		if (!$post_id) {
			return null;
		}

		$time = self::get_formatted_time($post_id, get_option('time_format'));
		return '' !== $time['value'] ? $time['value'] : null;
	}

	/**
	 * Return the event location for a bound block attribute.
	 *
	 * @param array  $source_args     Source arguments.
	 * @param object $block_instance  Block instance.
	 * @param string $attribute_name  Bound attribute name.
	 * @return string|null
	 */
	public function get_event_location_bindings_value($source_args, $block_instance, $attribute_name) {
		$post_id = self::get_post_id($block_instance);
		if (!$post_id) {
			return null;
		}

		$location = self::get_location($post_id);
		return '' !== $location ? $location : null;
	}

	/**
	 * Renders the deprecated "icob/event-date" block, preserving the
	 * pre-refactor output (dataType switch, labels and wrapper tag).
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Block default content.
	 * @param WP_Block $block      Block instance.
	 * @return string
	 */
	public function render_legacy($attributes, $content = '', $block = null) {
		// Resolve target post ID in order of preference:
		// 1. Block context postId (standard dynamic block context)
		// 2. Explicit attribute 'postId' (allows programmatic render_block() usage)
		// 3. Current global post (e.g. when directly used inside The Loop without proper context)
		$post_ID = 0;
		if ($block && isset($block->context['postId'])) {
			$post_ID = intval($block->context['postId']);
		}
		if (!$post_ID && isset($attributes['postId']) && $attributes['postId']) {
			$post_ID = intval($attributes['postId']);
		}
		if (!$post_ID) {
			$maybe = get_the_ID();
			if ($maybe) { $post_ID = intval($maybe); }
		}
		if (!$post_ID) { return ''; }

		$dataType      = isset($attributes['dataType']) ? $attributes['dataType'] : 'date';
		if (!in_array($dataType, array('date', 'time', 'location'), true)) { $dataType = 'date'; }
		$format        = isset($attributes['format']) && $attributes['format'] ? $attributes['format'] : 'F j, Y';
		$timeFormat    = isset($attributes['timeFormat']) && $attributes['timeFormat'] ? $attributes['timeFormat'] : 'g:i A';
		$fallbackText  = isset($attributes['fallbackText']) ? $attributes['fallbackText'] : '';
		$showLabel     = !empty($attributes['showLabel']);
		$labelTextAttr = isset($attributes['labelText']) ? $attributes['labelText'] : '';
		// Auto default label if user toggles showLabel but hasn't customized.
		if ($labelTextAttr === '' || in_array($labelTextAttr, array(__('Event Date:', 'awesome-calendar-events'), __('Event Time:', 'awesome-calendar-events'), __('Event Location:', 'awesome-calendar-events')), true)) {
			switch($dataType) {
				case 'time': $labelText = __('Event Time:', 'awesome-calendar-events'); break;
				case 'location': $labelText = __('Event Location:', 'awesome-calendar-events'); break;
				case 'date':
				default: $labelText = __('Event Date:', 'awesome-calendar-events');
			}
		} else {
			$labelText = $labelTextAttr;
		}
		$showWeekdays  = !isset($attributes['showWeekdaysWhenMissing']) || $attributes['showWeekdaysWhenMissing'];
		$relativeWeek  = !empty($attributes['relativeCurrentWeek']) && $dataType === 'date';
		$wrapTag       = isset($attributes['wrapTag']) && in_array(strtolower($attributes['wrapTag']), array('div', 'span', 'p'), true) ? strtolower($attributes['wrapTag']) : 'div';
		$locationKey   = isset($attributes['locationMetaKey']) && $attributes['locationMetaKey'] ? sanitize_key($attributes['locationMetaKey']) : '_awecal_event_location';

		// Use unified helper for date / weekday fallback logic.
		$output = '';
		if ($dataType === 'date') {
			$display = self::get_formatted_date($post_ID, $format, $showWeekdays, $relativeWeek);
			$output  = $display['value'];
		} elseif ($dataType === 'time') {
			$time = self::get_formatted_time($post_ID, $timeFormat);
			$output = $time['value'];
		} elseif ($dataType === 'location') {
			// Prefix-aware read via the meta helper (falls back to pre-migration data).
			$loc = awecal_get_post_meta($post_ID, $locationKey, true);
			if ($loc) { $output = esc_html($loc); }
		}

		if ($output === '' && $fallbackText === '') { return ''; }
		if ($output === '') { $output = esc_html($fallbackText); }

		$wrapper_attrs = get_block_wrapper_attributes(array('class' => 'awecal-event-date-block'));
		$inner  = '';
		if ($showLabel) {
			$inner .= '<span class="awecal-event-date-label">' . esc_html($labelText) . ' </span>';
		}
		$inner .= '<span class="awecal-event-date-value">' . $output . '</span>';

		// get_block_wrapper_attributes always assumes a div; if user chose span/p we'll adjust outer tag.
		if ($wrapTag !== 'div') {
			// Replace opening tag name while preserving attributes.
			$wrapper_attrs = preg_replace('/^<div /', '<' . $wrapTag . ' ', $wrapper_attrs);
			$wrapper_attrs = preg_replace('/<\/div>$/', '</' . $wrapTag . '>', $wrapper_attrs);
		}
		// Insert inner content before closing tag.
		$html = preg_replace('/>(\s*)<\/' . ($wrapTag === 'div' ? 'div' : $wrapTag) . '$/', '>' . $inner . '</' . $wrapTag . '>', $wrapper_attrs);
		// Fallback if regex failed (unlikely): construct manually.
		if (strpos($html, $inner) === false) {
			$html = '<' . $wrapTag . ' ' . substr($wrapper_attrs, strpos($wrapper_attrs, 'class=')) . '>' . $inner . '</' . $wrapTag . '>';
		}
		return $html;
	}
}

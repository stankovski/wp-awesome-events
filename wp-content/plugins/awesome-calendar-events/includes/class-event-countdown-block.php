<?php
/**
 * Event Countdown Block
 *
 * Displays a live JavaScript countdown timer to the next event occurrence.
 * Uses event metadata from Awesome_Calendar_Events_Event_Meta to determine target date/time.
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Calendar_Events_Event_Countdown_Block {
    public function __construct() {
        $this->register_block();
    }

    private function register_block() {
        // Register assets
        $ver = defined('AWESOME_CALENDAR_EVENTS_VERSION') ? AWESOME_CALENDAR_EVENTS_VERSION : '1.0.0';

        if (!wp_script_is('awesome-calendar-events-event-countdown-block-editor', 'registered')) {
            wp_register_script(
                'awesome-calendar-events-event-countdown-block-editor',
                AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/js/event-countdown-block.js',
                ['wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-core-data'],
                $ver,
                true
            );
        }

        if (!wp_script_is('awesome-calendar-events-event-countdown-frontend', 'registered')) {
            wp_register_script(
                'awesome-calendar-events-event-countdown-frontend',
                AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/js/event-countdown-frontend.js',
                [],
                $ver,
                true
            );
        }

        if (!wp_style_is('awesome-calendar-events-event-countdown-block-style', 'registered')) {
            wp_register_style(
                'awesome-calendar-events-event-countdown-block-style',
                AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/css/event-countdown-block.css',
                [],
                $ver
            );
        }

        if (!wp_style_is('awesome-calendar-events-event-countdown-block-editor-style', 'registered')) {
            wp_register_style(
                'awesome-calendar-events-event-countdown-block-editor-style',
                AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/css/event-countdown-block-editor.css',
                [],
                $ver
            );
        }

        if (!function_exists('register_block_type')) { return; }

        register_block_type('awesome-calendar-events/event-countdown', [
            'api_version' => 3,
            'editor_script' => 'awesome-calendar-events-event-countdown-block-editor',
            'script' => 'awesome-calendar-events-event-countdown-frontend',
            'style' => 'awesome-calendar-events-event-countdown-block-style',
            'editor_style' => 'awesome-calendar-events-event-countdown-block-editor-style',
            'render_callback' => [$this, 'render'],
            'attributes' => $this->get_attributes(),
            'supports' => [
                'html' => false,
                'anchor' => true,
                'align' => ['left', 'center', 'right', 'wide', 'full'],
                'className' => true,
                'color' => ['text' => true, 'background' => true],
                'typography' => ['fontSize' => true, 'lineHeight' => true, 'fontWeight' => true, 'fontFamily' => true],
                'spacing' => ['margin' => true, 'padding' => true]
            ],
            'category' => 'awesome-calendar-events',
            'title' => __('Event Countdown', 'awesome-calendar-events'),
            'description' => __('Displays a live countdown timer to the next event occurrence.', 'awesome-calendar-events'),
            'keywords' => ['event', 'countdown', 'timer', 'clock', 'calendar']
        ]);

        // DEPRECATED: legacy "icob/event-countdown" block name, kept for backwards
        // compatibility with content created before the plugin was renamed to
        // Awesome Calendar Events. Registered server-side only (no editor
        // script) so existing posts keep rendering on the frontend, but the
        // block never appears in the block inserter. New content must use
        // "awesome-calendar-events/event-countdown". Plan removal in a future release.
        register_block_type('icob/event-countdown', [
            'api_version' => 3,
            'script' => 'awesome-calendar-events-event-countdown-frontend',
            'style' => 'awesome-calendar-events-event-countdown-block-style',
            'render_callback' => [$this, 'render'],
            'attributes' => $this->get_attributes(),
        ]);
    }

    /**
     * Shared attribute definitions for the current and legacy block names.
     */
    private function get_attributes() {
        return [
            'postId' => ['type' => 'integer', 'default' => 0],
            'showLabel' => ['type' => 'boolean', 'default' => true],
            'labelText' => ['type' => 'string', 'default' => __('Countdown to Event:', 'awesome-calendar-events')],
            'showDays' => ['type' => 'boolean', 'default' => true],
            'showHours' => ['type' => 'boolean', 'default' => true],
            'showMinutes' => ['type' => 'boolean', 'default' => true],
            'showSeconds' => ['type' => 'boolean', 'default' => false],
            'separator' => ['type' => 'string', 'default' => ':'],
            'completedText' => ['type' => 'string', 'default' => __('Event has started!', 'awesome-calendar-events')],
            'daysLabel' => ['type' => 'string', 'default' => __('d', 'awesome-calendar-events')],
            'hoursLabel' => ['type' => 'string', 'default' => __('h', 'awesome-calendar-events')],
            'minutesLabel' => ['type' => 'string', 'default' => __('m', 'awesome-calendar-events')],
            'secondsLabel' => ['type' => 'string', 'default' => __('s', 'awesome-calendar-events')],
        ];
    }

    public function render($attributes, $content = '', $block = null) {
        $post_id = isset($attributes['postId']) ? intval($attributes['postId']) : 0;

        // If no post selected, show placeholder in editor context only
        if (!$post_id) {
            return '<div class="awecal-event-countdown-placeholder">' .
                   esc_html__('Please select a post with event metadata.', 'awesome-calendar-events') .
                   '</div>';
        }

        // Validate post exists and has event data enabled
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return '';
        }

        $enabled = awecal_get_post_meta($post_id, '_awecal_event_date_enabled', true);
        if (!$enabled) {
            return '';
        }

        // Get next occurrence timestamp
        if (!class_exists('Awesome_Calendar_Events_Event_Meta')) {
            return '';
        }

        $next_occurrence = Awesome_Calendar_Events_Event_Meta::get_next_occurrence($post_id);
        if (!$next_occurrence) {
            return '';
        }

        // Parse next occurrence date (Y-m-d format) and combine with event start time if available
        $event_date = strtotime($next_occurrence . ' 00:00:00');
        $start_time = awecal_get_post_meta($post_id, '_awecal_event_start_time', true);

        if ($start_time && preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $start_time)) {
            // Combine date with time
            $event_timestamp = strtotime($next_occurrence . ' ' . $start_time . ':00');
        } else {
            // Default to start of day
            $event_timestamp = $event_date;
        }

        // If event is in the past, don't render (or show completed text)
        $now = current_time('timestamp');
        if ($event_timestamp <= $now) {
            $completed_text = isset($attributes['completedText']) ? $attributes['completedText'] : __('Event has started!', 'awesome-calendar-events');
            if (!$completed_text) {
                return '';
            }
            $wrapper_attrs = get_block_wrapper_attributes(['class' => 'awecal-event-countdown-completed']);
            return '<div ' . $wrapper_attrs . '><span class="awecal-countdown-completed-text">' .
                   esc_html($completed_text) . '</span></div>';
        }

        // Convert to ISO-8601 format with timezone for JavaScript (using server timezone)
        $target_iso = date_i18n('c', $event_timestamp);

        // Extract attributes
        $show_label = !empty($attributes['showLabel']);
        $label_text = isset($attributes['labelText']) ? $attributes['labelText'] : __('Countdown to Event:', 'awesome-calendar-events');
        $show_days = isset($attributes['showDays']) ? (bool)$attributes['showDays'] : true;
        $show_hours = isset($attributes['showHours']) ? (bool)$attributes['showHours'] : true;
        $show_minutes = isset($attributes['showMinutes']) ? (bool)$attributes['showMinutes'] : true;
        $show_seconds = isset($attributes['showSeconds']) ? (bool)$attributes['showSeconds'] : false;
        $separator = isset($attributes['separator']) ? $attributes['separator'] : ':';
        $completed_text = isset($attributes['completedText']) ? $attributes['completedText'] : __('Event has started!', 'awesome-calendar-events');
        $days_label = isset($attributes['daysLabel']) ? $attributes['daysLabel'] : __('d', 'awesome-calendar-events');
        $hours_label = isset($attributes['hoursLabel']) ? $attributes['hoursLabel'] : __('h', 'awesome-calendar-events');
        $minutes_label = isset($attributes['minutesLabel']) ? $attributes['minutesLabel'] : __('m', 'awesome-calendar-events');
        $seconds_label = isset($attributes['secondsLabel']) ? $attributes['secondsLabel'] : __('s', 'awesome-calendar-events');

        // Build data attributes for JavaScript
        $data_attrs = sprintf(
            'data-target-timestamp="%s" data-show-days="%d" data-show-hours="%d" data-show-minutes="%d" data-show-seconds="%d" data-separator="%s" data-completed-text="%s" data-days-label="%s" data-hours-label="%s" data-minutes-label="%s" data-seconds-label="%s"',
            esc_attr($target_iso),
            $show_days ? 1 : 0,
            $show_hours ? 1 : 0,
            $show_minutes ? 1 : 0,
            $show_seconds ? 1 : 0,
            esc_attr($separator),
            esc_attr($completed_text),
            esc_attr($days_label),
            esc_attr($hours_label),
            esc_attr($minutes_label),
            esc_attr($seconds_label)
        );

        $wrapper_attrs = get_block_wrapper_attributes([
            'class' => 'awecal-event-countdown',
            'data-target-timestamp' => $target_iso,
            'data-show-days' => $show_days ? '1' : '0',
            'data-show-hours' => $show_hours ? '1' : '0',
            'data-show-minutes' => $show_minutes ? '1' : '0',
            'data-show-seconds' => $show_seconds ? '1' : '0',
            'data-separator' => $separator,
            'data-completed-text' => $completed_text,
            'data-days-label' => $days_label,
            'data-hours-label' => $hours_label,
            'data-minutes-label' => $minutes_label,
            'data-seconds-label' => $seconds_label,
        ]);

        ob_start();
        ?>
        <div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns a complete core-escaped attribute string. ?>>
            <?php if ($show_label) : ?>
                <span class="awecal-countdown-label"><?php echo esc_html($label_text); ?></span>
            <?php endif; ?>
            <div class="awecal-countdown-timer">
                <?php
                $units_shown = 0;
                if ($show_days) :
                    if ($units_shown > 0 && $separator) : ?>
                        <span class="awecal-countdown-separator"><?php echo esc_html($separator); ?></span>
                    <?php endif; ?>
                    <div class="awecal-countdown-unit awecal-countdown-days">
                        <span class="awecal-countdown-value" data-unit="days">--</span>
                        <span class="awecal-countdown-unit-label"><?php echo esc_html($days_label); ?></span>
                    </div>
                <?php
                    $units_shown++;
                endif;
                if ($show_hours) :
                    if ($units_shown > 0 && $separator) : ?>
                        <span class="awecal-countdown-separator"><?php echo esc_html($separator); ?></span>
                    <?php endif; ?>
                    <div class="awecal-countdown-unit awecal-countdown-hours">
                        <span class="awecal-countdown-value" data-unit="hours">--</span>
                        <span class="awecal-countdown-unit-label"><?php echo esc_html($hours_label); ?></span>
                    </div>
                <?php
                    $units_shown++;
                endif;
                if ($show_minutes) :
                    if ($units_shown > 0 && $separator) : ?>
                        <span class="awecal-countdown-separator"><?php echo esc_html($separator); ?></span>
                    <?php endif; ?>
                    <div class="awecal-countdown-unit awecal-countdown-minutes">
                        <span class="awecal-countdown-value" data-unit="minutes">--</span>
                        <span class="awecal-countdown-unit-label"><?php echo esc_html($minutes_label); ?></span>
                    </div>
                <?php
                    $units_shown++;
                endif;
                if ($show_seconds) :
                    if ($units_shown > 0 && $separator) : ?>
                        <span class="awecal-countdown-separator"><?php echo esc_html($separator); ?></span>
                    <?php endif; ?>
                    <div class="awecal-countdown-unit awecal-countdown-seconds">
                        <span class="awecal-countdown-value" data-unit="seconds">--</span>
                        <span class="awecal-countdown-unit-label"><?php echo esc_html($seconds_label); ?></span>
                    </div>
                <?php
                    $units_shown++;
                endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

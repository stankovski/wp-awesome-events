<?php
/**
 * Event Date Block
 *
 * Provides a dynamic block to output the next (or original) event date for the current post.
 * If no event date or upcoming recurrence exists, block can render nothing or a placeholder.
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Events_Event_Date_Block {
    public function __construct() {
        // Register immediately so block is available on the same init cycle when instantiated inside another init hook.
        $this->register_block();
    }

    private function register_block() {
        // Register assets first
        $ver = defined('AWESOME_EVENTS_VERSION') ? AWESOME_EVENTS_VERSION : '1.0.0';
        if (!wp_script_is('awesome-events-event-date-block-editor', 'registered')) {
            wp_register_script(
                'awesome-events-event-date-block-editor',
                AWESOME_EVENTS_PLUGIN_URL . 'assets/js/event-date-block.js',
                ['wp-blocks','wp-element','wp-i18n','wp-block-editor','wp-components','wp-data'],
                $ver,
                true
            );
        }
        if (!wp_style_is('awesome-events-event-date-block-style', 'registered')) {
            wp_register_style(
                'awesome-events-event-date-block-style',
                AWESOME_EVENTS_PLUGIN_URL . 'assets/css/event-date-block.css',
                [],
                $ver
            );
        }

    if (!function_exists('register_block_type')) { return; }
    register_block_type('icob/event-date', [
            'api_version' => 3,
            'editor_script' => 'awesome-events-event-date-block-editor',
            'style' => 'awesome-events-event-date-block-style',
            'render_callback' => [$this, 'render'],
            'attributes' => [
                'format' => [ 'type' => 'string', 'default' => 'F j, Y' ],
                // For time output (if selected)
                'timeFormat' => [ 'type' => 'string', 'default' => 'g:i A' ],
                // Switch among date | time | location
                'dataType' => [ 'type' => 'string', 'default' => 'date' ],
                'fallbackText' => [ 'type' => 'string', 'default' => '' ],
                'showLabel' => [ 'type' => 'boolean', 'default' => false ],
                'labelText' => [ 'type' => 'string', 'default' => __('Event Date:', 'awesome-events') ],
                'showWeekdaysWhenMissing' => [ 'type' => 'boolean', 'default' => true ],
                'wrapTag' => [ 'type' => 'string', 'default' => 'div' ],
                'className' => [ 'type' => 'string', 'default' => '' ],
                // Optional explicit post ID when block context is unavailable (e.g. programmatic render).
                'postId' => [ 'type' => 'integer', 'default' => 0 ],
                // Location meta key (allows customization / future flexibility)
                'locationMetaKey' => [ 'type' => 'string', 'default' => '_icob_event_location' ],
                // When showing a Date, optionally output relative forms (weekdays plural for weekly recurrence, or "This Monday" for single events this week)
                'relativeCurrentWeek' => [ 'type' => 'boolean', 'default' => false ],
            ],
            'supports' => [
                'html' => false,
                'anchor' => true,
                'className' => true,
                // Allow user to set text/background colors & typography in editor
                'color' => [ 'text' => true, 'background' => true ],
                'typography' => [ 'fontSize' => true, 'lineHeight' => true ],
                'spacing' => ['margin'=>true,'padding'=>true]
            ],
            'category' => 'icob',
            'title' => __('Event Date', 'awesome-events'),
            'description' => __('Displays the upcoming event date or recurring weekdays for the current post.', 'awesome-events'),
            'keywords' => ['event','date','recurring','icob']
        ]);
    }

    public function render($attributes, $content = '', $block = null) {
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
        if (!in_array($dataType, ['date','time','location'], true)) { $dataType = 'date'; }
        $format        = isset($attributes['format']) && $attributes['format'] ? $attributes['format'] : 'F j, Y';
        $timeFormat    = isset($attributes['timeFormat']) && $attributes['timeFormat'] ? $attributes['timeFormat'] : 'g:i A';
        $fallbackText  = isset($attributes['fallbackText']) ? $attributes['fallbackText'] : '';
        $showLabel     = !empty($attributes['showLabel']);
        $labelTextAttr = isset($attributes['labelText']) ? $attributes['labelText'] : '';
        // Auto default label if user toggles showLabel but hasn't customized.
        if ($labelTextAttr === '' || in_array($labelTextAttr, [__('Event Date:', 'awesome-events'), __('Event Time:', 'awesome-events'), __('Event Location:', 'awesome-events')], true)) {
            switch($dataType) {
                case 'time': $labelText = __('Event Time:', 'awesome-events'); break;
                case 'location': $labelText = __('Event Location:', 'awesome-events'); break;
                case 'date':
                default: $labelText = __('Event Date:', 'awesome-events');
            }
        } else {
            $labelText = $labelTextAttr;
        }
    $showWeekdays  = !isset($attributes['showWeekdaysWhenMissing']) || $attributes['showWeekdaysWhenMissing'];
    $relativeWeek  = !empty($attributes['relativeCurrentWeek']) && $dataType === 'date';
        $wrapTag       = isset($attributes['wrapTag']) && in_array(strtolower($attributes['wrapTag']), ['div','span','p'], true) ? strtolower($attributes['wrapTag']) : 'div';
        $locationKey   = isset($attributes['locationMetaKey']) && $attributes['locationMetaKey'] ? sanitize_key($attributes['locationMetaKey']) : '_icob_event_location';
        // Use unified helper for date / weekday fallback logic.
        $output = '';
        if ($dataType === 'date') {
            if (class_exists('Awesome_Events_Event_Meta')) {
                $display = Awesome_Events_Event_Meta::get_event_date_display($post_ID, $format, true, $relativeWeek);
                if ($relativeWeek && !empty($display['relative'])) {
                    $output = esc_html($display['relative']);
                } elseif (!empty($display['date'])) {
                    $output = esc_html($display['date']);
                } elseif ($showWeekdays && !empty($display['weekdays'])) {
                    $output = esc_html($display['weekdays']);
                }
            }
        } elseif ($dataType === 'time') {
            // Expect time stored as meta _icob_event_time (HH:MM or similar). Not previously defined; we attempt retrieval gracefully.
            $time_raw = get_post_meta($post_ID, '_icob_event_time', true);
            if ($time_raw) {
                // Normalize and format via strtotime best-effort.
                $ts = strtotime(gmdate('Y-m-d') . ' ' . $time_raw);
                if ($ts) { $output = esc_html(date_i18n($timeFormat, $ts)); }
            }
        } elseif ($dataType === 'location') {
            $loc = get_post_meta($post_ID, $locationKey, true);
            if ($loc) { $output = esc_html($loc); }
        }

        if ($output === '' && $fallbackText === '') { return ''; }
        if ($output === '') { $output = esc_html($fallbackText); }

        $wrapper_attrs = get_block_wrapper_attributes(['class' => 'icob-event-date-block']);
        $inner  = '';
        if ($showLabel) {
            $inner .= '<span class="icob-event-date-label">' . esc_html($labelText) . ' </span>';
        }
        $inner .= '<span class="icob-event-date-value">' . $output . '</span>';

        // get_block_wrapper_attributes always assumes a div; if user chose span/p we'll adjust outer tag.
        if ($wrapTag !== 'div') {
            // Replace opening tag name while preserving attributes.
            $wrapper_attrs = preg_replace('/^<div /','<' . $wrapTag . ' ', $wrapper_attrs);
            $wrapper_attrs = preg_replace('/<\/div>$/','</' . $wrapTag . '>', $wrapper_attrs);
        }
        // Insert inner content before closing tag.
        $html = preg_replace('/>(\s*)<\/'.($wrapTag==='div'?'div':$wrapTag).'$/', '>' . $inner . '</' . $wrapTag . '>', $wrapper_attrs);
        // Fallback if regex failed (unlikely): construct manually.
        if (strpos($html, $inner) === false) {
            $html = '<' . $wrapTag . ' ' . substr($wrapper_attrs, strpos($wrapper_attrs, 'class=')) . '>' . $inner . '</' . $wrapTag . '>';
        }
        return $html;
    }
}

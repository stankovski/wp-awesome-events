<?php
/**
 * Block Patterns
 *
 * Registers block patterns for Awesome Calendar Events.
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Calendar_Events_Block_Patterns {
    public function __construct() {
        add_action('init', [$this, 'register_patterns'], 20);
    }

    public function register_patterns() {
        if (!function_exists('register_block_pattern')) {
            return;
        }

        register_block_pattern_category('awesome-calendar-events', [
            'label' => __('Awesome Calendar Events', 'awesome-calendar-events'),
        ]);

        register_block_pattern('awesome-calendar-events/event-details', [
            'title' => __('Event Details', 'awesome-calendar-events'),
            'description' => __('Announcement banner with event date, time, location, and an Add to Calendar button.', 'awesome-calendar-events'),
            'categories' => ['awesome-calendar-events'],
            'keywords' => ['event', 'calendar', 'details', 'announcement'],
            'blockTypes' => ['awesome-calendar-events/event-date'],
            'content' => <<<'CONTENT'
<!-- wp:awesome-calendar-events/announcement-container {"style":{"color":{"background":"#fef4dc"},"border":{"radius":{"topLeft":"6px","topRight":"6px","bottomLeft":"6px","bottomRight":"6px"},"color":"#695628","style":"solid","width":"1px"},"spacing":{"padding":{"right":"var:preset|spacing|x-small","left":"var:preset|spacing|x-small","top":"var:preset|spacing|x-small","bottom":"var:preset|spacing|x-small"}}}} -->
<!-- wp:paragraph {"placeholder":"Announcement content will be displayed here...","metadata":{"bindings":{"content":{"source":"awesome-calendar-events/announcement","args":{"key":"_awecal_announcement"}}}},"style":{"color":{"text":"#695628"},"elements":{"link":{"color":{"text":"#695628"}}},"spacing":{"padding":{"top":"0px","bottom":"0px","left":"10px","right":"10px"}}},"fontSize":"x-small"} -->
<p class="has-text-color has-link-color has-x-small-font-size" style="color:#695628;padding-top:0px;padding-right:10px;padding-bottom:0px;padding-left:10px"></p>
<!-- /wp:paragraph -->
<!-- /wp:awesome-calendar-events/announcement-container -->

<!-- wp:awesome-calendar-events/event-date {"label":"📅 When: "} /-->

<!-- wp:awesome-calendar-events/event-time {"label":"🕑 Time: "} /-->

<!-- wp:awesome-calendar-events/event-location {"label":"📍 Location:"} /-->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"metadata":{"name":"Add to Calendar"},"className":"is-style-add-to-calendar"} -->
<div class="wp-block-button is-style-add-to-calendar"><a class="wp-block-button__link wp-element-button" href="#add-to-calendar">Add to Calendar</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
CONTENT,
        ]);
    }
}

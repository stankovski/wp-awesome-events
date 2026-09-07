<?php
/**
 * Announcement Container Block
 *
 * Container block that conditionally renders when a current post has an
 * unexpired announcement.
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Calendar_Events_Announcement_Container_Block {
    public function __construct() {
        $this->register_block();
        $this->register_bindings_source();
    }

    /**
     * Register the block and its assets.
     */
    private function register_block() {
        $version = defined('AWESOME_CALENDAR_EVENTS_VERSION') ? AWESOME_CALENDAR_EVENTS_VERSION : '1.0.0';

        wp_register_script(
            'awesome-calendar-events-announcement-container-block-editor',
            AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/js/announcement-container-block.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-i18n'],
            $version,
            true
        );

        wp_register_style(
            'awesome-calendar-events-announcement-container-block-style',
            AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/css/announcement-container-block.css',
            [],
            $version
        );

        if (!function_exists('register_block_type')) {
            return;
        }

        register_block_type('awesome-calendar-events/announcement-container', [
            'api_version' => 3,
            'editor_script' => 'awesome-calendar-events-announcement-container-block-editor',
            'editor_style' => 'awesome-calendar-events-announcement-container-block-style',
            'style' => 'awesome-calendar-events-announcement-container-block-style',
            'render_callback' => [$this, 'render'],
            'uses_context' => ['postId'],
            'attributes' => [],
            'supports' => [
                'align' => true,
                'anchor' => true,
                'color' => [
                    'gradients' => true,
                    'link' => true,
                    'text' => true,
                    'background' => true,
                ],
                'spacing' => [
                    'margin' => true,
                    'padding' => true,
                ],
                'typography' => [
                    'fontSize' => true,
                    'lineHeight' => true,
                ],
                '__experimentalBorder' => [
                    'color' => true,
                    'radius' => true,
                    'style' => true,
                    'width' => true,
                ],
            ],
            'category' => 'awesome-calendar-events',
            'title' => __('Announcement Container', 'awesome-calendar-events'),
            'description' => __('A container that displays content when the current post has an unexpired announcement.', 'awesome-calendar-events'),
        ]);
    }

    /**
     * Register the announcement block bindings source.
     */
    private function register_bindings_source() {
        if (!function_exists('register_block_bindings_source')) {
            return;
        }

        register_block_bindings_source('awesome-calendar-events/announcement', [
            'label' => __('Announcement', 'awesome-calendar-events'),
            'get_value_callback' => [$this, 'get_bindings_value'],
            'uses_context' => ['postId'],
        ]);
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
     * Determine whether the post has an unexpired announcement.
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    private static function should_display_announcement($post_id) {
        if (!$post_id) {
            return false;
        }

        $announcement = trim((string) awecal_get_post_meta($post_id, '_awecal_announcement', true));
        if ($announcement === '' || $announcement === '0') {
            return false;
        }

        $expiration = trim((string) awecal_get_post_meta($post_id, '_awecal_announcement_expiration', true));
        if ($expiration === '') {
            return true;
        }

        $expiration_timestamp = strtotime($expiration);
        return $expiration_timestamp === false || $expiration_timestamp > current_time('timestamp');
    }

    /**
     * Return announcement metadata for a bound block attribute.
     *
     * @param array  $source_args Source arguments.
     * @param object $block_instance Block instance.
     * @param string $attribute_name Bound attribute name.
     * @return mixed|null
     */
    public function get_bindings_value($source_args, $block_instance, $attribute_name) {
        $post_id = self::get_post_id($block_instance);
        if (!self::should_display_announcement($post_id)) {
            return null;
        }

        $key = isset($source_args['key']) ? (string) $source_args['key'] : '';
        if (!in_array($key, ['_awecal_announcement', '_awecal_announcement_expiration'], true)) {
            return null;
        }

        $value = awecal_get_post_meta($post_id, $key, true);
        return $value !== '' ? $value : null;
    }

    /**
     * Render the block on the frontend.
     *
     * @param array    $attributes Block attributes.
     * @param string   $content Block inner content.
     * @param WP_Block $block Block instance.
     * @return string
     */
    public function render($attributes, $content, $block) {
        if (!self::should_display_announcement(self::get_post_id($block))) {
            return '';
        }

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => 'awecal-announcement-container',
        ]);

        return sprintf(
            '<div %1$s>%2$s</div>',
            $wrapper_attributes,
            $content
        );
    }
}

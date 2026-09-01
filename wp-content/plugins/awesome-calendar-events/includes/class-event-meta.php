<?php
/**
 * Event Meta Management
 *
 * Adds optional Event Date and Recurrence meta to posts.
 * Supports single events and recurring patterns (daily, weekly, monthly, yearly) with an optional end date
 * or number of occurrences. Provides helper to compute next upcoming occurrence.
 *
 * All keys use the canonical `_awecal_` prefix:
 *  - _awecal_event_date (string: Y-m-d in site timezone)
 *  - _awecal_event_date_enabled (int: 0|1) master enable flag; when 0 other event fields ignored
 *  - _awecal_event_start_time (string: HH:MM 24h) optional start time
 *  - _awecal_event_duration_hours (number: >=0) optional duration in hours (was minutes pre-change)
 *  - _awecal_event_duration_minutes (int: >=0) legacy field kept in sync for backward compatibility
 *  - _awecal_event_location (string) optional location text
 *  - _awecal_event_recurrence_type (string: none|daily|weekly|monthly|yearly)
 *  - _awecal_event_recurrence_interval (int: >=1) e.g. every 2 weeks
 *  - _awecal_event_recurrence_weekdays (string: "[0,2,4]" with Monday=0..Sunday=6) only for weekly when specifying specific weekdays.
 *  - _awecal_event_recurrence_end_type (string: none|date|count)
 *  - _awecal_event_recurrence_end_date (string: Y-m-d)
 *  - _awecal_event_recurrence_count (int: max number of occurrences)
 *  - _awecal_announcement (bool) marks the post as an announcement
 *  - _awecal_announcement_expiration (string: Y-m-d H:i:s site-local) when the announcement stops being shown
 *
 * NOTE: Posts written before the `_awecal_` prefix migration store their meta
 * under the historical prefix. Those keys are still registered (read-only for
 * REST consumers). All reads go through awecal_get_post_meta()
 * (see class-meta-helper.php), which is the single place aware of the legacy
 * prefix and transparently falls back to legacy data so existing posts, REST
 * consumers, and ICS feeds keep working.
 */

if (!defined('ABSPATH')) { exit; }

class Awesome_Calendar_Events_Event_Meta {
    public function __construct() {
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('save_post', [$this, 'save_meta']);
        add_action('init', [$this, 'register_meta']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * Enqueue admin JS for the event meta box (post edit screens only).
     */
    public function enqueue_admin_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'post') {
            return;
        }
        wp_enqueue_script(
            'awesome-calendar-events-event-meta-admin',
            AWESOME_CALENDAR_EVENTS_PLUGIN_URL . 'assets/js/event-meta-admin.js',
            [],
            AWESOME_CALENDAR_EVENTS_VERSION,
            true
        );
    }

    public function register_meta() {
        $meta_args_public = [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'auth_callback' => function() { return current_user_can('edit_posts'); }
        ];

        $meta_defs = [
            'event_date' => $meta_args_public,
            'event_date_enabled' => array_merge($meta_args_public, [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => function($val){ return (bool)$val; }
            ]),
            'event_recurrence_type' => array_merge($meta_args_public, ['type' => 'string']),
            'event_start_time' => array_merge($meta_args_public, [
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => function($val){
                    $val = trim((string)$val);
                    return preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $val) ? $val : '';
                }
            ]),
            'event_custom_time_label' => array_merge($meta_args_public, [
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => function($val){ return sanitize_text_field($val); }
            ]),
            // New canonical duration in hours (allows fractional values e.g., 1.5)
            'event_duration_hours' => array_merge($meta_args_public, [
                'type' => 'number',
                'default' => 0,
                'sanitize_callback' => function($val){ $v = floatval($val); return $v < 0 ? 0 : $v; }
            ]),
            // Legacy minutes field retained (read-only conceptually) for older code paths; kept synchronized on save.
            'event_duration_minutes' => array_merge($meta_args_public, [
                'type' => 'integer',
                'default' => 0,
                'sanitize_callback' => function($val){ return max(0, intval($val)); }
            ]),
            'event_location' => array_merge($meta_args_public, [
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => function($val){ return sanitize_text_field($val); }
            ]),
            'event_recurrence_interval' => array_merge($meta_args_public, ['type' => 'integer']),
            // Store weekdays as canonical bracketed comma string (e.g., "[0,1,3]"). We expose as string in REST.
            'event_recurrence_weekdays' => array_merge($meta_args_public, [
                'type' => 'string',
                'sanitize_callback' => function($val){
                    // Accept either string or legacy array; normalize to bracketed list
                    if (is_array($val)) {
                        $ints = array_values(array_intersect(array_map('intval',$val), range(0,6)));
                        sort($ints);
                        return '[' . implode(',', $ints) . ']';
                    }
                    $parsed = self::parse_weekday_string($val);
                    return $parsed ? ('[' . implode(',', $parsed) . ']') : '[]';
                }
            ]),
            'event_recurrence_end_type' => array_merge($meta_args_public, ['type' => 'string']),
            'event_recurrence_end_date' => array_merge($meta_args_public, ['type' => 'string']),
            'event_recurrence_count' => array_merge($meta_args_public, ['type' => 'integer']),
            // Announcement flag (legacy sites store `_icob_announcement`; reads fall back via awecal_get_post_meta()).
            'announcement' => array_merge($meta_args_public, [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => function($val){ return (bool)$val; }
            ]),
            // Announcement expiration (site-local datetime, empty = runs indefinitely).
            'announcement_expiration' => array_merge($meta_args_public, [
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => function($val){ return self::normalize_datetime_input($val); }
            ]),
        ];

        foreach ($meta_defs as $suffix => $args) {
            // Canonical key written by this plugin for new/updated posts.
            register_post_meta('post', AWECAL_META_PREFIX . $suffix, $args);
            // Legacy key kept registered so existing posts stay exposed in REST.
            register_post_meta('post', AWECAL_LEGACY_META_PREFIX . $suffix, $args);
        }
    }

    public function add_meta_box() {
        add_meta_box(
            'icob_event_meta',
            __('Event Date & Recurrence', 'awesome-calendar-events'),
            [$this, 'render_meta_box'],
            'post',
            'side',
            'default'
        );
        add_meta_box(
            'icob_event_shortcodes',
            __('Event Shortcodes', 'awesome-calendar-events'),
            [$this, 'render_shortcodes_meta_box'],
            'post',
            'side',
            'low'
        );
    }

    private function get_meta($post_id, $key, $default = '') {
        $val = awecal_get_post_meta($post_id, $key, true);
        return $val === '' ? $default : $val;
    }

    public function render_meta_box($post) {
        wp_nonce_field('icob_event_meta_save', 'icob_event_meta_nonce');
    $enabled = (bool) $this->get_meta($post->ID, '_awecal_event_date_enabled', 0);
        $event_date = esc_attr($this->get_meta($post->ID, '_awecal_event_date'));
        $recurrence_type = esc_attr($this->get_meta($post->ID, '_awecal_event_recurrence_type', 'none'));
        $interval = intval($this->get_meta($post->ID, '_awecal_event_recurrence_interval', 1));
        // Parse stored weekday string (or legacy array) for UI checkbox state
        $weekdays_raw = $this->get_meta($post->ID, '_awecal_event_recurrence_weekdays', []);
        if (is_string($weekdays_raw)) {
            $weekdays = self::parse_weekday_string($weekdays_raw);
        } else {
            $weekdays = array_values(array_intersect(array_map('intval', (array)$weekdays_raw), range(0,6)));
        }
        $end_type = esc_attr($this->get_meta($post->ID, '_awecal_event_recurrence_end_type', 'none'));
        $end_date = esc_attr($this->get_meta($post->ID, '_awecal_event_recurrence_end_date'));
        $count = intval($this->get_meta($post->ID, '_awecal_event_recurrence_count', 0));
    $start_time = $this->get_meta($post->ID, '_awecal_event_start_time', '');
    $custom_time_label = $this->get_meta($post->ID, '_awecal_event_custom_time_label', '');
    $duration_hours = $this->get_meta($post->ID, '_awecal_event_duration_hours', '');
    if ($duration_hours === '' || $duration_hours === '0') {
        // Fallback convert legacy minutes if present
        $legacy_minutes = intval($this->get_meta($post->ID, '_awecal_event_duration_minutes', 0));
        $duration_hours = $legacy_minutes > 0 ? round($legacy_minutes / 60, 2) : 0;
    }
    $duration_hours = floatval($duration_hours);
    $location = $this->get_meta($post->ID, '_awecal_event_location', '');
    $announcement = (bool) $this->get_meta($post->ID, '_awecal_announcement', 0);
    $announcement_expiration = $this->get_meta($post->ID, '_awecal_announcement_expiration', '');
    // datetime-local inputs expect Y-m-d\TH:i
    $announcement_expiration_local = $announcement_expiration ? esc_attr(gmdate('Y-m-d\TH:i', strtotime($announcement_expiration))) : '';

    // Weekday labels aligned to new Monday=0..Sunday=6 indexing
    $weekday_labels = [__('Mon','awesome-calendar-events'),__('Tue','awesome-calendar-events'),__('Wed','awesome-calendar-events'),__('Thu','awesome-calendar-events'),__('Fri','awesome-calendar-events'),__('Sat','awesome-calendar-events'),__('Sun','awesome-calendar-events')];
        ?>
        <p>
            <label for="icob_event_date_enabled"><strong><?php esc_html_e('Event Date Enabled', 'awesome-calendar-events'); ?></strong></label><br/>
            <label style="display:inline-flex;align-items:center;gap:4px;">
                <input type="checkbox" id="icob_event_date_enabled" name="icob_event_date_enabled" value="1" <?php checked($enabled); ?> /> <?php esc_html_e('Enable event date & recurrence for this post','awesome-calendar-events'); ?>
            </label>
        </p>
        <div id="icob_event_fields_wrap" style="opacity: <?php echo $enabled ? '1' : '.55'; ?>; pointer-events: <?php echo $enabled ? 'auto' : 'none'; ?>; transition: opacity .15s;">
        <p>
            <label for="icob_event_date"><strong><?php esc_html_e('Event Date', 'awesome-calendar-events'); ?></strong></label>
            <input type="date" id="icob_event_date" name="icob_event_date" value="<?php echo $event_date ? esc_attr( gmdate('Y-m-d', strtotime($event_date)) ) : ''; ?>" style="width:100%;" />
            <small><?php esc_html_e('Leave empty if not an event. Time is not stored.', 'awesome-calendar-events'); ?></small>
        </p>
        <div style="display:flex; gap:6px;">
            <p style="flex:1;">
                <label for="icob_event_start_time"><strong><?php esc_html_e('Start Time','awesome-calendar-events'); ?></strong></label>
                <input type="time" id="icob_event_start_time" name="icob_event_start_time" value="<?php echo esc_attr($start_time); ?>" style="width:100%;" />
            </p>
            <p style="flex:1;">
                <label for="icob_event_duration_hours"><strong><?php esc_html_e('Duration (hours)','awesome-calendar-events'); ?></strong></label>
                <input type="number" min="0" step="0.25" id="icob_event_duration_hours" name="icob_event_duration_hours" value="<?php echo esc_attr($duration_hours); ?>" style="width:100%;" />
            </p>
        </div>
        <p>
            <label for="icob_event_custom_time_label"><strong><?php esc_html_e('Custom Time Label','awesome-calendar-events'); ?></strong></label>
            <input type="text" id="icob_event_custom_time_label" name="icob_event_custom_time_label" value="<?php echo esc_attr($custom_time_label); ?>" style="width:100%;" placeholder="<?php esc_attr_e('e.g. After Sunset', 'awesome-calendar-events'); ?>" />
            <small><?php esc_html_e('Optional. If provided, this will be shown instead of the start time.', 'awesome-calendar-events'); ?></small>
        </p>
        <p>
            <label for="icob_event_location"><strong><?php esc_html_e('Location','awesome-calendar-events'); ?></strong></label>
            <input type="text" id="icob_event_location" name="icob_event_location" value="<?php echo esc_attr($location); ?>" style="width:100%;" />
        </p>
        <p>
            <label for="icob_event_recurrence_type"><strong><?php esc_html_e('Recurrence', 'awesome-calendar-events'); ?></strong></label>
            <select name="icob_event_recurrence_type" id="icob_event_recurrence_type" style="width:100%;">
                <?php foreach(['none'=>__('None','awesome-calendar-events'),'daily'=>__('Daily','awesome-calendar-events'),'weekly'=>__('Weekly','awesome-calendar-events'),'monthly'=>__('Monthly','awesome-calendar-events'),'yearly'=>__('Yearly','awesome-calendar-events')] as $k=>$label): ?>
                    <option value="<?php echo esc_attr($k); ?>" <?php selected($recurrence_type, $k); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label><strong><?php esc_html_e('Interval', 'awesome-calendar-events'); ?></strong></label>
            <input type="number" min="1" name="icob_event_recurrence_interval" value="<?php echo esc_attr($interval); ?>" style="width:60px;" />
            <small><?php esc_html_e('Every N units (days/weeks/months/years).', 'awesome-calendar-events'); ?></small>
        </p>
        <div id="icob_event_weekdays" style="margin-bottom:8px; <?php echo $recurrence_type==='weekly' ? '' : 'display:none;'; ?>">
            <label><strong><?php esc_html_e('Weekdays', 'awesome-calendar-events'); ?></strong></label><br/>
            <?php foreach($weekday_labels as $i=>$lbl): ?>
                <label style="display:inline-block;margin-right:4px;">
                    <input type="checkbox" name="icob_event_recurrence_weekdays[]" value="<?php echo esc_attr($i); ?>" <?php checked(in_array((string)$i, array_map('strval',$weekdays))); ?> /> <?php echo esc_html($lbl); ?>
                </label>
            <?php endforeach; ?>
            <small style="display:block;"><?php esc_html_e('If none selected, original event weekday is used.', 'awesome-calendar-events'); ?></small>
        </div>
        <p>
            <label for="icob_event_recurrence_end_type"><strong><?php esc_html_e('End Condition', 'awesome-calendar-events'); ?></strong></label>
            <select name="icob_event_recurrence_end_type" id="icob_event_recurrence_end_type" style="width:100%;">
                <?php foreach(['none'=>__('No End','awesome-calendar-events'),'date'=>__('End Date','awesome-calendar-events'),'count'=>__('Occurrence Count','awesome-calendar-events')] as $k=>$label): ?>
                    <option value="<?php echo esc_attr($k); ?>" <?php selected($end_type, $k); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p id="icob_event_end_date_wrap" style="<?php echo $end_type==='date' ? '' : 'display:none;'; ?>">
            <label><strong><?php esc_html_e('End Date', 'awesome-calendar-events'); ?></strong></label>
            <input type="date" name="icob_event_recurrence_end_date" value="<?php echo esc_attr($end_date); ?>" style="width:100%;" />
        </p>
        <p id="icob_event_count_wrap" style="<?php echo $end_type==='count' ? '' : 'display:none;'; ?>">
            <label><strong><?php esc_html_e('Occurrences', 'awesome-calendar-events'); ?></strong></label>
            <input type="number" min="0" name="icob_event_recurrence_count" value="<?php echo esc_attr($count); ?>" style="width:100%;" />
            <small><?php esc_html_e('0 or blank = unlimited', 'awesome-calendar-events'); ?></small>
        </p>
        </div>
        <p>
            <label style="display:inline-flex;align-items:center;gap:4px;">
                <input type="checkbox" id="icob_announcement" name="icob_announcement" value="1" <?php checked($announcement); ?> /> <?php esc_html_e('Announcement','awesome-calendar-events'); ?>
            </label>
        </p>
        <p id="icob_announcement_expiration_wrap">
            <label for="icob_announcement_expiration"><strong><?php esc_html_e('Announcement Ends','awesome-calendar-events'); ?></strong></label>
            <input type="datetime-local" id="icob_announcement_expiration" name="icob_announcement_expiration" value="<?php echo $announcement_expiration_local; ?>" style="width:100%;" />
            <small><?php esc_html_e('Leave empty to run indefinitely.', 'awesome-calendar-events'); ?></small>
        </p>
        <?php
    }

    public function render_shortcodes_meta_box($post) {
        ?>
        <div style="padding: 5px;">
            <p style="margin-top: 0; margin-bottom: 10px; font-size: 13px; color: #666;">
                <?php esc_html_e('Use these shortcodes in paragraph blocks or other content to display event information:', 'awesome-calendar-events'); ?>
            </p>
            <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
                <tbody>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 8px 8px 8px 0; font-family: monospace; color: #d63638; white-space: nowrap; vertical-align: top;">
                            [awecal_event_date]
                        </td>
                        <td style="padding: 8px 0;">
                            <?php esc_html_e('Event date in site format', 'awesome-calendar-events'); ?>
                            <div style="color: #666; font-size: 11px; margin-top: 2px;">
                                <?php esc_html_e('Optional: format="F j, Y"', 'awesome-calendar-events'); ?>
                            </div>
                        </td>
                    </tr>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 8px 8px 8px 0; font-family: monospace; color: #d63638; white-space: nowrap; vertical-align: top;">
                            [awecal_event_friendly_date]
                        </td>
                        <td style="padding: 8px 0;">
                            <?php esc_html_e('Friendly date (Today, Tomorrow, This Monday)', 'awesome-calendar-events'); ?>
                        </td>
                    </tr>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 8px 8px 8px 0; font-family: monospace; color: #d63638; white-space: nowrap; vertical-align: top;">
                            [awecal_event_time]
                        </td>
                        <td style="padding: 8px 0;">
                            <?php esc_html_e('Event start time', 'awesome-calendar-events'); ?>
                            <div style="color: #666; font-size: 11px; margin-top: 2px;">
                                <?php esc_html_e('Optional: format="g:i A"', 'awesome-calendar-events'); ?>
                            </div>
                        </td>
                    </tr>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 8px 8px 8px 0; font-family: monospace; color: #d63638; white-space: nowrap; vertical-align: top;">
                            [awecal_event_full_time]
                        </td>
                        <td style="padding: 8px 0;">
                            <?php esc_html_e('Event time range (start - end)', 'awesome-calendar-events'); ?>
                            <div style="color: #666; font-size: 11px; margin-top: 2px;">
                                <?php esc_html_e('Optional: format="g:i A" separator=" to "', 'awesome-calendar-events'); ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 8px 8px 0; font-family: monospace; color: #d63638; white-space: nowrap; vertical-align: top;">
                            [awecal_event_location]
                        </td>
                        <td style="padding: 8px 0;">
                            <?php esc_html_e('Event location', 'awesome-calendar-events'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p style="margin-top: 12px; margin-bottom: 0; font-size: 12px; color: #666;">
                <strong><?php esc_html_e('Example:', 'awesome-calendar-events'); ?></strong>
                <span style="font-family: monospace; color: #333;">Join us on [awecal_event_friendly_date] at [awecal_event_time] in [awecal_event_location]</span>
            </p>
        </div>
        <?php
    }

    public function save_meta($post_id) {
        if (!isset($_POST['icob_event_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['icob_event_meta_nonce'])), 'icob_event_meta_save')) { return; }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
        if (!current_user_can('edit_post', $post_id)) { return; }

        $date_raw = isset($_POST['icob_event_date']) ? sanitize_text_field(wp_unslash($_POST['icob_event_date'])) : '';
        $event_date = '';
        if ($date_raw) {
            $ts = strtotime($date_raw);
            if ($ts) { $event_date = gmdate('Y-m-d', $ts); }
        }
        // Enabled flag
        $enabled = isset($_POST['icob_event_date_enabled']) ? 1 : 0;
        update_post_meta($post_id, '_awecal_event_date_enabled', $enabled);
        update_post_meta($post_id, '_awecal_event_date', $enabled ? $event_date : '');

        $rec_type = isset($_POST['icob_event_recurrence_type']) ? sanitize_text_field(wp_unslash($_POST['icob_event_recurrence_type'])) : 'none';
            $valid_types = ['none','daily','weekly','monthly','yearly'];
            if (!in_array($rec_type, $valid_types, true)) { $rec_type='none'; }
        update_post_meta($post_id, '_awecal_event_recurrence_type', $enabled ? $rec_type : 'none');

        $interval = isset($_POST['icob_event_recurrence_interval']) ? max(1, intval(wp_unslash($_POST['icob_event_recurrence_interval']))) : 1;
        update_post_meta($post_id, '_awecal_event_recurrence_interval', $enabled ? $interval : 1);

        $weekdays = isset($_POST['icob_event_recurrence_weekdays']) ? array_map('intval', (array)wp_unslash($_POST['icob_event_recurrence_weekdays'])) : [];
        $weekdays = array_values(array_intersect($weekdays, range(0,6)));
        sort($weekdays);
        $weekday_string = '[' . implode(',', $weekdays) . ']';
        update_post_meta($post_id, '_awecal_event_recurrence_weekdays', $enabled ? $weekday_string : '[]');

        $end_type = isset($_POST['icob_event_recurrence_end_type']) ? sanitize_text_field(wp_unslash($_POST['icob_event_recurrence_end_type'])) : 'none';
        if (!in_array($end_type, ['none','date','count'], true)) { $end_type='none'; }
        update_post_meta($post_id, '_awecal_event_recurrence_end_type', $enabled ? $end_type : 'none');

        $end_date = isset($_POST['icob_event_recurrence_end_date']) ? sanitize_text_field(wp_unslash($_POST['icob_event_recurrence_end_date'])) : '';
        update_post_meta($post_id, '_awecal_event_recurrence_end_date', ($enabled && $end_type==='date') ? $end_date : '');

        $count = isset($_POST['icob_event_recurrence_count']) ? intval(wp_unslash($_POST['icob_event_recurrence_count'])) : 0;
        update_post_meta($post_id, '_awecal_event_recurrence_count', ($enabled && $end_type==='count') ? $count : 0);

        // Start time (HH:MM 24h)
        $start_time = isset($_POST['icob_event_start_time']) ? trim(sanitize_text_field(wp_unslash($_POST['icob_event_start_time']))) : '';
        if ($start_time && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $start_time)) { $start_time=''; }
        update_post_meta($post_id, '_awecal_event_start_time', $enabled ? $start_time : '');

        // Custom time label
        $custom_time_label = isset($_POST['icob_event_custom_time_label']) ? sanitize_text_field(wp_unslash($_POST['icob_event_custom_time_label'])) : '';
        update_post_meta($post_id, '_awecal_event_custom_time_label', $enabled ? $custom_time_label : '');

        // Duration hours (canonical) & sync legacy minutes
        $duration_hours = isset($_POST['icob_event_duration_hours']) ? floatval(wp_unslash($_POST['icob_event_duration_hours'])) : 0;
        if ($duration_hours < 0) { $duration_hours = 0; }
        $duration_hours = $enabled ? $duration_hours : 0;
        update_post_meta($post_id, '_awecal_event_duration_hours', $duration_hours);
        // Keep legacy minutes updated for any older code referencing it.
        $legacy_minutes = $duration_hours > 0 ? (int) round($duration_hours * 60) : 0;
        update_post_meta($post_id, '_awecal_event_duration_minutes', $legacy_minutes);

        // Location
        $location = isset($_POST['icob_event_location']) ? sanitize_text_field(wp_unslash($_POST['icob_event_location'])) : '';
        update_post_meta($post_id, '_awecal_event_location', $enabled ? $location : '');

        // Announcement (independent of the event date enabled flag)
        $announcement = isset($_POST['icob_announcement']) ? 1 : 0;
        update_post_meta($post_id, '_awecal_announcement', $announcement);

        $expiration_raw = isset($_POST['icob_announcement_expiration']) ? sanitize_text_field(wp_unslash($_POST['icob_announcement_expiration'])) : '';
        $expiration = $announcement ? self::normalize_datetime_input($expiration_raw) : '';
        update_post_meta($post_id, '_awecal_announcement_expiration', $expiration);
    }

    /**
     * Normalize a user-supplied announcement expiration value to a
     * site-local `Y-m-d H:i:s` string. Accepts datetime-local input
     * (Y-m-d\TH:i), MySQL datetimes and plain dates. Returns '' for
     * missing or unparseable values.
     *
     * @param mixed $val Raw input.
     * @return string
     */
    public static function normalize_datetime_input($val) {
        $val = trim((string) $val);
        if ($val === '') { return ''; }
        $ts = strtotime($val);
        return $ts ? date('Y-m-d H:i:s', $ts) : '';
    }

    /**
     * Compute the next occurrence date (Y-m-d) for a post or return original event date if in future.
     * Returns null if no more upcoming occurrences.
     */
    public static function get_next_occurrence($post_id, $from_time = null) {
    $enabled = awecal_get_post_meta($post_id, '_awecal_event_date_enabled', true);
    if (!$enabled) { return null; }
        $from = $from_time ? strtotime($from_time) : current_time('timestamp');
        $event_date_raw = awecal_get_post_meta($post_id, '_awecal_event_date', true);
        if (!$event_date_raw) { return null; }
    // Treat stored date as local site date (midnight)
    $start = strtotime(gmdate('Y-m-d', strtotime($event_date_raw)) . ' 00:00:00');
        if ($start === false) { return null; }

        $type = awecal_get_post_meta($post_id, '_awecal_event_recurrence_type', true) ?: 'none';
        $interval = max(1, intval(awecal_get_post_meta($post_id, '_awecal_event_recurrence_interval', true) ?: 1));
        $weekdays = self::get_weekdays($post_id);
        $end_type = awecal_get_post_meta($post_id, '_awecal_event_recurrence_end_type', true) ?: 'none';
        $end_date = awecal_get_post_meta($post_id, '_awecal_event_recurrence_end_date', true);
        $count_limit = intval(awecal_get_post_meta($post_id, '_awecal_event_recurrence_count', true));

        // If single event
        if ($type === 'none') {
            return ($start >= strtotime(gmdate('Y-m-d', $from) . ' 00:00:00')) ? gmdate('Y-m-d', $start) : null;
        }

        $occurrence = $start;
        $occurrence_index = 1; // first occurrence
        $max_iterations = 1000; // safety
        while ($max_iterations-- > 0) {
            if ($occurrence >= strtotime(gmdate('Y-m-d', $from) . ' 00:00:00')) {
                // Check end conditions
                if ($end_type === 'date' && $end_date && strtotime($end_date.' 23:59:59') < $occurrence) { return null; }
                if ($end_type === 'count' && $count_limit > 0 && $occurrence_index > $count_limit) { return null; }
                return gmdate('Y-m-d', $occurrence);
            }
            // Advance occurrence
            switch($type) {
                case 'daily':
                    $occurrence = strtotime("+{$interval} day", $occurrence);
                    break;
                case 'weekly':
                    if (!empty($weekdays)) {
                        // Move day by day until match weekday sequence
                        $next = strtotime('+1 day', $occurrence);
                        while(true) {
                            // Convert PHP gmdate('w') (0=Sunday..6=Saturday) to Monday=0..Sunday=6 mapping
                            $w = (intval(gmdate('w', $next)) + 6) % 7; // Sunday(0)->6, Monday(1)->0, ... Saturday(6)->5
                            if (in_array($w, $weekdays, true)) { $occurrence_index++; $occurrence = strtotime(gmdate('Y-m-d', $next).' 00:00:00'); break; }
                            $next = strtotime('+1 day', $next);
                            // safety
                            if ($next - $occurrence > 60*60*24*14) { break; }
                        }
                        continue 2;
                    } else {
                        $occurrence = strtotime("+{$interval} week", $occurrence);
                    }
                    break;
                case 'monthly':
                    $occurrence = strtotime("+{$interval} month", $occurrence);
                    break;
                case 'yearly':
                    $occurrence = strtotime("+{$interval} year", $occurrence);
                    break;
                default:
                    return null;
            }
            $occurrence_index++;
            // End type checks mid-loop
            if ($end_type === 'count' && $count_limit > 0 && $occurrence_index > $count_limit) { return null; }
            if ($end_type === 'date' && $end_date && strtotime($end_date.' 23:59:59') < $occurrence) { return null; }
        }
        return null; // fallback
    }

    /**
     * Unified display helper.
     * Returns array with keys:
     *  - 'date' => formatted date string (if next occurrence exists)
     *  - 'iso'  => Y-m-d date (if next occurrence exists)
     *  - 'weekdays' => weekday fallback string (pluralized) if no date but weekly recurrence weekdays exist
     *  - 'raw_weekdays' => array of int weekdays if present
     *  - 'has_value' => bool whether anything to show (date or weekdays)
     */
    public static function get_event_date_display($post_id, $date_format = null, $pluralize = true, $relative_week = false) {
        $date_format = $date_format ?: get_option('date_format');
        $result = [
            'date' => '',
            'iso' => '',
            'weekdays' => '',
            'raw_weekdays' => [],
            'relative' => '', // relative week string (e.g., This Monday or Mondays/Tuesdays)
            'start_time' => '',
            'duration_minutes' => 0, // legacy
            'duration_hours' => 0,
            'location' => '',
            'has_value' => false,
        ];

        if (!class_exists(__CLASS__)) { return $result; }

    $enabled = awecal_get_post_meta($post_id, '_awecal_event_date_enabled', true);
    if (!$enabled) { return $result; }

    // Add ancillary fields even if no date (consumer can decide usage)
    $start_time = awecal_get_post_meta($post_id, '_awecal_event_start_time', true);
    $duration_hours = floatval(awecal_get_post_meta($post_id, '_awecal_event_duration_hours', true));
    if ($duration_hours <= 0) {
        $legacy_minutes = intval(awecal_get_post_meta($post_id, '_awecal_event_duration_minutes', true));
        if ($legacy_minutes > 0) { $duration_hours = round($legacy_minutes / 60, 2); }
    }
    $location = awecal_get_post_meta($post_id, '_awecal_event_location', true);
    $result['start_time'] = $start_time;
    $result['duration_minutes'] = $duration_hours > 0 ? (int) round($duration_hours * 60) : 0; // maintain legacy
    $result['duration_hours'] = $duration_hours;
    $result['location'] = $location;

        $rec_type = awecal_get_post_meta($post_id, '_awecal_event_recurrence_type', true);

        $next = self::get_next_occurrence($post_id);
        if ($next) {
            $ts = strtotime($next . ' 00:00:00');
            if ($ts) {
                $result['iso'] = gmdate('Y-m-d', $ts);
                $result['date'] = date_i18n($date_format, $ts);
                $result['has_value'] = true;
            }
        } else {
            // New behavior: For non-recurring events (recurrence type 'none'), show the original event date
            // even if it is in the past. This supports blocks wanting to always display when the event occurred.
            if ($rec_type === 'none') {
                $original_raw = awecal_get_post_meta($post_id, '_awecal_event_date', true);
                if ($original_raw) {
                    $orig_ts = strtotime($original_raw . ' 00:00:00');
                    if ($orig_ts) {
                        $result['iso'] = gmdate('Y-m-d', $orig_ts);
                        $result['date'] = date_i18n($date_format, $orig_ts);
                        $result['has_value'] = true;
                    }
                }
            }
        }

        // Weekly recurrence weekday fallback if no concrete upcoming date (or for relative output)
        if ($rec_type === 'weekly') {
            $weekdays = self::get_weekdays($post_id);
            if (empty($weekdays) && $result['iso']) {
                // Fallback to weekday of next occurrence using Monday=0..Sunday=6 mapping
                $weekdays = [ (intval(gmdate('w', strtotime($result['iso']))) + 6) % 7 ];
            }
            if ($weekdays) {
                // Monday=0 .. Sunday=6 mapping for display
                $day_names = [
                    0 => __('Monday', 'awesome-calendar-events'),
                    1 => __('Tuesday', 'awesome-calendar-events'),
                    2 => __('Wednesday', 'awesome-calendar-events'),
                    3 => __('Thursday', 'awesome-calendar-events'),
                    4 => __('Friday', 'awesome-calendar-events'),
                    5 => __('Saturday', 'awesome-calendar-events'),
                    6 => __('Sunday', 'awesome-calendar-events'),
                ];
                $labels = [];
                foreach ($weekdays as $d) {
                    if (isset($day_names[$d])) {
                        $labels[] = $day_names[$d] . ($pluralize ? 's' : '');
                    }
                }
                if ($labels) {
                    // Always populate weekdays for weekly recurring events
                    $result['weekdays'] = implode('/', $labels);
                    $result['raw_weekdays'] = $weekdays;
                    $result['has_value'] = true;
                }
                if ($relative_week) {
                    // For weekly recurrences we use the weekday names as determined by the $pluralize parameter.
                    // This builds directly from site-local meta; no UTC conversion needed since current_time() elsewhere aligns to site zone.
                    $result['relative'] = implode('/', $labels);
                }
            }
        }

        // Relative for single events: prefer "Today" / "Tomorrow" (local site time) then fallback to "This <Weekday>" when in current week.
        // current_time('timestamp') returns the site-local timestamp (not UTC) which we intentionally use for comparisons.
        if ($relative_week && $rec_type === 'none' && $result['iso']) {
            $event_ts = strtotime($result['iso'] . ' 00:00:00');
            if ($event_ts) {
                $today_ts = current_time('timestamp'); // site local time
                $today_mid = strtotime(gmdate('Y-m-d', $today_ts) . ' 00:00:00');
                $tomorrow_mid = strtotime('+1 day', $today_mid);
                $event_date_str = gmdate('Y-m-d', $event_ts);
                $today_str = gmdate('Y-m-d', $today_mid);
                $tomorrow_str = gmdate('Y-m-d', $tomorrow_mid);
                if ($event_date_str === $today_str) {
                    $result['relative'] = __('Today', 'awesome-calendar-events');
                } elseif ($event_date_str === $tomorrow_str) {
                    $result['relative'] = __('Tomorrow', 'awesome-calendar-events');
                } else {
                    $start_of_week = intval(get_option('start_of_week', 1));
                    $today_w = intval(gmdate('w', $today_mid));
                    $delta = ($today_w - $start_of_week + 7) % 7;
                    $week_start_ts = strtotime('-' . $delta . ' day', $today_mid);
                    $week_end_ts = strtotime('+6 day', $week_start_ts) + 86399;
                    if ($event_ts >= $week_start_ts && $event_ts <= $week_end_ts) {
                        // gmdate('w') still returns Sunday=0, so for consistency we rely on date_i18n('l') but relative string
                        // does not need index remapping here (only label output).
                        $weekday = date_i18n('l', $event_ts);
                        // translators: %s is a day of the week.
                        $result['relative'] = sprintf(__('This %s', 'awesome-calendar-events'), $weekday);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Parse the stored weekday meta into an integer array (Monday=0..Sunday=6).
     * Supports new canonical string format "[0,2,5]" and legacy array storage.
     */
    public static function get_weekdays($post_id){
        $raw = awecal_get_post_meta($post_id, '_awecal_event_recurrence_weekdays', true);
        if (is_array($raw)) {
            $ints = array_values(array_intersect(array_map('intval',$raw), range(0,6)));
            sort($ints);
            return $ints;
        }
        if (is_string($raw)) {
            return self::parse_weekday_string($raw);
        }
        return [];
    }

    /**
     * Low-level parser for bracketed weekday string. Returns int[] or [].
     */
    public static function parse_weekday_string($val){
        $val = trim((string)$val);
        if ($val === '') { return []; }
        if (!preg_match('/^\[([^\]]*)\]$/', $val, $m)) { return []; }
        $inner = trim($m[1]);
        if ($inner === '') { return []; }
        $parts = array_map('trim', explode(',', $inner));
        $ints = [];
        foreach ($parts as $p){
            if ($p === '') continue;
            if (!preg_match('/^-?\d+$/', $p)) continue;
            $i = intval($p);
            if ($i >=0 && $i <=6) { $ints[] = $i; }
        }
        $ints = array_values(array_unique($ints));
        sort($ints);
        return $ints;
    }
}

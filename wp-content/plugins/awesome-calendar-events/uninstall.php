<?php
/**
 * Uninstall script for Awesome Calendar Events Plugin
 *
 * This file is called when the plugin is uninstalled
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// No cleanup performed: event post meta (_icob_event_*) is intentionally
// preserved so it remains available if the plugin is reinstalled.

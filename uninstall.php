<?php
/**
 * CloudScale Analytics - Uninstall
 *
 * Runs when the plugin is deleted via Plugins > Delete.
 * Data (database tables and options) is intentionally preserved on uninstall
 * so that reinstalling or switching to a renamed version of this plugin does
 * not result in data loss.
 *
 * @since 2.9.94
 *
 * @package CloudScale_Free_Analytics
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Data is intentionally preserved. No tables or options are deleted.

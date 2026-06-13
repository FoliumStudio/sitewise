<?php
/**
 * Plugin Name: Sitewise
 * Plugin URI: https://foliumstudio.co.uk
 * Description: A grounded on-page chat assistant that answers only from your own content, plus a built-in call-back request widget. <a href="admin.php?page=sitewise">Open Settings</a>
 * Version: 4.0.2
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Folium Studio
 * Author URI: https://foliumstudio.co.uk
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-call-me-back
 *
 * Copyright (C) 2014-2026 Folium Studio
 *
 * --------------------------------------------------------------------------
 * History: this plugin's wp.org slug is `wp-call-me-back`. It began life as
 * "Call me back widget" (3.x). Version 4.0.0 expands it into Sitewise: a
 * grounded on-site chatbot. The original call-back request feature lives on
 * as a built-in module (Settings → Sitewise → Call-back), so existing users
 * keep what they had and gain the assistant.
 * --------------------------------------------------------------------------
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'SITEWISE_VERSION', '4.0.2' );
define( 'SITEWISE_SLUG', 'wp-call-me-back' );          // wp.org slug + text domain (fixed forever).
define( 'SITEWISE_FILE', __FILE__ );
define( 'SITEWISE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SITEWISE_URL', plugin_dir_url( __FILE__ ) );
define( 'SITEWISE_OPTION', 'sitewise_settings' );      // primary settings array in wp_options.
define( 'SITEWISE_CRON_HOOK', 'sitewise_flush_sync_queue' );

// Shared Folium UI design language (vendored; newest active copy wins).
require_once SITEWISE_DIR . 'lib/folium-ui/loader.php';

require_once SITEWISE_DIR . 'includes/class-sitewise.php';
require_once SITEWISE_DIR . 'includes/class-sitewise-corpus.php';
require_once SITEWISE_DIR . 'includes/class-sitewise-sync.php';
require_once SITEWISE_DIR . 'includes/class-sitewise-admin.php';
require_once SITEWISE_DIR . 'includes/class-sitewise-widget.php';
require_once SITEWISE_DIR . 'includes/class-sitewise-callback.php';

/**
 * On activation: seed defaults, create the corpus directory, schedule cron.
 */
function sitewise_on_activate() {
	Sitewise::seed_default_settings();
	Sitewise_Corpus::ensure_storage_dir();

	if ( ! wp_next_scheduled( SITEWISE_CRON_HOOK ) ) {
		wp_schedule_event( time() + 300, 'sitewise_five_minutes', SITEWISE_CRON_HOOK );
	}

	// Build the corpus once on activation so the bot has something to answer from.
	wp_schedule_single_event( time() + 30, 'sitewise_rebuild_all' );
}
register_activation_hook( __FILE__, 'sitewise_on_activate' );

/**
 * On deactivation: clear scheduled jobs. (Data + settings are kept; removal
 * happens in uninstall.php only.)
 */
function sitewise_on_deactivate() {
	wp_clear_scheduled_hook( SITEWISE_CRON_HOOK );
	wp_clear_scheduled_hook( 'sitewise_rebuild_all' );
}
register_deactivation_hook( __FILE__, 'sitewise_on_deactivate' );

/**
 * Register the custom 5-minute cron interval used to flush the sync queue.
 *
 * @param array $schedules Existing cron schedules.
 * @return array
 */
function sitewise_cron_schedules( $schedules ) {
	$schedules['sitewise_five_minutes'] = array(
		'interval' => 300,
		'display'  => __( 'Every 5 minutes (Sitewise)', 'wp-call-me-back' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'sitewise_cron_schedules' );

// Boot the plugin.
add_action( 'plugins_loaded', array( 'Sitewise', 'get_instance' ) );

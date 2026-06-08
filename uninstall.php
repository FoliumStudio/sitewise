<?php
/**
 * Uninstall cleanup for Sitewise (slug: wp-call-me-back).
 *
 * Runs only on real plugin deletion. Removes settings, status options, the
 * scheduled cron, and the generated corpus files. Call-back submissions (a CPT)
 * are intentionally LEFT in place — they are user data the admin may still need.
 *
 * @package Sitewise
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Options.
delete_option( 'sitewise_settings' );
delete_option( 'sitewise_corpus_stats' );
delete_option( 'sitewise_sync_status' );
delete_option( 'sitewise_sync_pending' );

// Cron.
wp_clear_scheduled_hook( 'sitewise_flush_sync_queue' );
wp_clear_scheduled_hook( 'sitewise_rebuild_all' );

// Generated corpus files.
$uploads = wp_upload_dir();
if ( ! empty( $uploads['basedir'] ) ) {
	$dir = trailingslashit( $uploads['basedir'] ) . 'sitewise/';
	foreach ( array( 'llms.txt', 'llms-full.txt' ) as $file ) {
		$path = $dir . $file;
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
	if ( is_dir( $dir ) ) {
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

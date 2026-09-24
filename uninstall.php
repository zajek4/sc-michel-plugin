<?php
/**
 * Uninstall handler.
 *
 * By default NO data is deleted — compliance/audit/history must survive
 * deinstallation unless the admin explicitly opted in:
 * "Obriši sve podatke plugina pri deinstalaciji"
 *
 * @package CPTSC
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'cptsc_settings', array() );
$opt_in   = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

if ( ! $opt_in ) {
	// Keep everything (tables, options, files). Only stop scheduled events.
	wp_clear_scheduled_hook( 'cptsc_queue_tick' );
	wp_clear_scheduled_hook( 'cptsc_heartbeat' );
	wp_clear_scheduled_hook( 'cptsc_feed_publish' );
	wp_clear_scheduled_hook( 'cptsc_job_tick' );
	return;
}

// Explicit opt-in: remove plugin data.
global $wpdb;

$tables = array(
	$wpdb->prefix . 'cptsc_sources',
	$wpdb->prefix . 'cptsc_term_rules',
	$wpdb->prefix . 'cptsc_items',
	$wpdb->prefix . 'cptsc_queue',
	$wpdb->prefix . 'cptsc_jobs',
	$wpdb->prefix . 'cptsc_audit',
	$wpdb->prefix . 'cptsc_channels',
	$wpdb->prefix . 'cptsc_feed_versions',
);
foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table names.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

delete_option( 'cptsc_settings' );
delete_option( 'cptsc_discovery' );
delete_option( 'cptsc_health' );
delete_option( 'cptsc_cron_token' );
delete_option( 'cptsc_db_version' );
delete_option( 'cptsc_import_staged' );
delete_option( 'cptsc_import_job' );

// Locks.
global $wpdb;
$lock_options = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\_transient_cptsc_lock_%' OR option_name LIKE '\_transient_timeout_cptsc_lock_%' OR option_name LIKE 'cptsc_lock_%'"
);
foreach ( (array) $lock_options as $name ) {
	delete_option( $name );
}

wp_clear_scheduled_hook( 'cptsc_queue_tick' );
wp_clear_scheduled_hook( 'cptsc_heartbeat' );
wp_clear_scheduled_hook( 'cptsc_feed_publish' );
wp_clear_scheduled_hook( 'cptsc_job_tick' );

// Feed files.
$uploads = wp_upload_dir();
$base    = trailingslashit( $uploads['basedir'] ) . 'cptsc';
if ( is_dir( $base ) ) {
 cptsc_uninstall_rrmdir( $base ); // phpcs:ignore
}

/**
 * Recursive delete restricted to uploads/cptsc.
 *
 * @param string $dir Directory.
 */
function cptsc_uninstall_rrmdir( $dir ) {
	$real    = realpath( $dir );
	$allowed = realpath( trailingslashit( wp_upload_dir()['basedir'] ) . 'cptsc' );
	if ( ! $real || ! $allowed || 0 !== strpos( $real, $allowed ) ) {
		return;
	}
	foreach ( array_diff( (array) scandir( $real ), array( '.', '..' ) ) as $item ) {
		$path = $real . '/' . $item;
		if ( is_dir( $path ) ) {
			cptsc_uninstall_rrmdir( $path );
		} else {
			@unlink( $path ); // phpcs:ignore
		}
	}
	@rmdir( $real ); // phpcs:ignore
}

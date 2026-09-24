<?php
/**
 * Compliance audit trail.
 *
 * @package CPTSC
 */

namespace CPTSC\Anchor;

use CPTSC\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audit logger.
 */
final class Audit {

	/**
	 * Singleton.
	 *
	 * @var Audit|null
	 */
	private static $instance = null;

	/**
	 * Instance.
	 *
	 * @return Audit
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Events we expose for external monitoring.
	 */
	public function hooks() {
		// Intentionally no per-save hooks; log() is called explicitly for relevant events.
	}

	/**
	 * Write an audit row.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $event     Event key.
	 * @param string $old_value Old value (JSON/plain).
	 * @param string $new_value New value.
	 * @param string $source    Source (manual|csv_import|watch|system).
	 * @param int    $user_id   User.
	 */
	public function log( $object_id, $event, $old_value, $new_value, $source = 'manual', $user_id = 0 ) {
		global $wpdb;
		$table = Database::instance()->table( 'audit' );
		$wpdb->insert(
			$table,
			array(
				'object_id'  => (int) $object_id,
				'event'      => substr( (string) $event, 0, 48 ),
				'old_value'  => (string) $old_value,
				'new_value'  => (string) $new_value,
				'source'     => substr( (string) $source, 0, 32 ),
				'user_id'    => (int) $user_id,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		do_action( 'cptsc_audit_logged', (int) $object_id, $event, $old_value, $new_value, $source );
	}

	/**
	 * Recent entries for an object.
	 *
	 * @param int $object_id Object.
	 * @param int $limit     Limit.
	 * @return array[]
	 */
	public function for_object( $object_id, $limit = 20 ) {
		global $wpdb;
		$table = Database::instance()->table( 'audit' );
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE object_id = %d ORDER BY id DESC LIMIT %d",
				(int) $object_id,
				(int) $limit
			),
			ARRAY_A
		);
	}

	/**
	 * Count of audit rows (diagnostics).
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;
		$table = Database::instance()->table( 'audit' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}

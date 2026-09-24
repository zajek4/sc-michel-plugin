<?php
/**
 * Idempotent schema migrations.
 *
 * @package CPTSC
 */

namespace CPTSC\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs Database::install() when DB_VERSION changes.
 */
final class Migrator {

	/**
	 * Option holding last applied DB version.
	 */
	const OPTION = 'cptsc_db_version';

	/**
	 * Migrate when needed.
	 */
	public static function maybe_migrate() {
		$current = get_option( self::OPTION, '' );
		if ( CPTSC_DB_VERSION === $current ) {
			return;
		}
		// dbDelta is idempotent — safe on every version bump (and on first run).
		\CPTSC\Database::instance()->install();
		do_action( 'cptsc_migrated', $current, CPTSC_DB_VERSION );
	}
}

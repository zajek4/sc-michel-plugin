<?php
/**
 * Database access: tables, install, small helpers.
 *
 * @package CPTSC
 */

namespace CPTSC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database manager.
 */
final class Database {

	/**
	 * Singleton.
	 *
	 * @var Database|null
	 */
	private static $instance = null;

	/**
	 * Table names.
	 *
	 * @var string[]
	 */
	private $tables = array();

	/**
	 * Instance.
	 *
	 * @return Database
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;
		$names = array( 'sources', 'term_rules', 'items', 'queue', 'jobs', 'audit', 'channels', 'feed_versions' );
		foreach ( $names as $name ) {
			$this->tables[ $name ] = $wpdb->prefix . 'cptsc_' . $name;
		}
	}

	/**
	 * Table name.
	 *
	 * @param string $key Key (sources|term_rules|items|queue|jobs|audit|channels|feed_versions).
	 * @return string
	 */
	public function table( $key ) {
		return isset( $this->tables[ $key ] ) ? $this->tables[ $key ] : '';
	}

	/**
	 * All table names.
	 *
	 * @return string[]
	 */
	public function tables() {
		return $this->tables;
	}

	/**
	 * Runtime hooks (none yet; kept for future needs).
	 */
	public function hooks() {
		// Intentionally empty — all writes go through prepared queries in modules.
	}

	/**
	 * Install / upgrade schema (idempotent, dbDelta).
	 */
	public function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$t       = $this->tables;

		$sql = array();

		$sql[] = "CREATE TABLE {$t['sources']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_type VARCHAR(64) NOT NULL,
			item_type VARCHAR(16) NOT NULL DEFAULT 'product',
			profile LONGTEXT NULL,
			status VARCHAR(24) NOT NULL DEFAULT 'draft',
			created_at DATETIME NULL DEFAULT NULL,
			updated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_type (post_type)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['term_rules']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			taxonomy VARCHAR(64) NOT NULL,
			term_id BIGINT(20) UNSIGNED NOT NULL,
			anchor_group VARCHAR(64) NOT NULL,
			include_descendants TINYINT(1) NOT NULL DEFAULT 1,
			priority INT(11) NOT NULL DEFAULT 0,
			created_at DATETIME NULL DEFAULT NULL,
			updated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY taxonomy_term (taxonomy, term_id),
			KEY anchor_group (anchor_group)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['items']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			source_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			canonical_object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			post_type VARCHAR(64) NOT NULL DEFAULT '',
			item_type VARCHAR(16) NOT NULL DEFAULT 'product',
			name VARCHAR(500) NOT NULL DEFAULT '',
			code VARCHAR(191) NULL DEFAULT NULL,
			brand VARCHAR(191) NULL DEFAULT NULL,
			unit VARCHAR(64) NULL DEFAULT NULL,
			unit_price DECIMAL(20,6) NULL DEFAULT NULL,
			regular_price DECIMAL(20,6) NULL DEFAULT NULL,
			sale_price DECIMAL(20,6) NULL DEFAULT NULL,
			current_price DECIMAL(20,6) NULL DEFAULT NULL,
			special_sale TINYINT(1) NULL DEFAULT NULL,
			special_sale_name VARCHAR(255) NULL DEFAULT NULL,
			barcode VARCHAR(64) NULL DEFAULT NULL,
			availability VARCHAR(16) NULL DEFAULT NULL,
			anchor_group VARCHAR(64) NULL DEFAULT NULL,
			group_override VARCHAR(64) NULL DEFAULT NULL,
			anchor_price DECIMAL(20,6) NULL DEFAULT NULL,
			anchor_date DATE NULL DEFAULT NULL,
			anchor_source VARCHAR(32) NULL DEFAULT NULL,
			anchor_verified TINYINT(1) NOT NULL DEFAULT 0,
			anchor_override TINYINT(1) NOT NULL DEFAULT 0,
			first_listing_at DATETIME NULL DEFAULT NULL,
			first_listing_price DECIMAL(20,6) NULL DEFAULT NULL,
			first_listing_state VARCHAR(32) NULL DEFAULT NULL,
			publication_state VARCHAR(24) NOT NULL DEFAULT 'publish',
			validation_level TINYINT(1) NOT NULL DEFAULT 1,
			issue_mask BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			row_hash CHAR(64) NULL DEFAULT NULL,
			feed_dirty TINYINT(1) NOT NULL DEFAULT 0,
			indexed_at DATETIME NULL DEFAULT NULL,
			changed_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_object (source_id, object_id),
			KEY object_id (object_id),
			KEY canonical_object_id (canonical_object_id),
			KEY post_type (post_type),
			KEY validation_level (validation_level),
			KEY issue_mask (issue_mask),
			KEY code (code),
			KEY barcode (barcode),
			KEY anchor_group (anchor_group),
			KEY feed_dirty (feed_dirty),
			KEY name_prefix (name(64))
		) $charset;";

		$sql[] = "CREATE TABLE {$t['queue']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			source_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			reason VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			locked_until DATETIME NULL DEFAULT NULL,
			last_error VARCHAR(500) NULL DEFAULT NULL,
			created_at DATETIME NULL DEFAULT NULL,
			updated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_object (source_id, object_id),
			KEY status (status, locked_until)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['jobs']} (
			job_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(32) NOT NULL,
			status VARCHAR(24) NOT NULL DEFAULT 'queued',
			cursor BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			total BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			processed BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			failed BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			payload LONGTEXT NULL,
			last_error TEXT NULL,
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			started_at DATETIME NULL DEFAULT NULL,
			updated_at DATETIME NULL DEFAULT NULL,
			heartbeat_at DATETIME NULL DEFAULT NULL,
			finished_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (job_id),
			KEY type_status (type, status),
			KEY status (status)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['audit']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			event VARCHAR(48) NOT NULL,
			old_value LONGTEXT NULL,
			new_value LONGTEXT NULL,
			source VARCHAR(32) NOT NULL DEFAULT '',
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY object_id (object_id),
			KEY event (event),
			KEY created_at (created_at)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['channels']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(24) NOT NULL DEFAULT 'main',
			name VARCHAR(191) NOT NULL DEFAULT '',
			object_kind VARCHAR(64) NOT NULL DEFAULT '',
			object_code VARCHAR(64) NOT NULL DEFAULT '',
			address VARCHAR(255) NOT NULL DEFAULT '',
			storage_sequence BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			enabled TINYINT(1) NOT NULL DEFAULT 1,
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NULL DEFAULT NULL,
			updated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY enabled (enabled)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['feed_versions']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			channel_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			sequence BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			filename VARCHAR(255) NOT NULL DEFAULT '',
			generated_at DATETIME NULL DEFAULT NULL,
			published_at DATETIME NULL DEFAULT NULL,
			row_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			file_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			fingerprint CHAR(64) NULL DEFAULT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'generated',
			validation_errors TEXT NULL,
			validation_warnings TEXT NULL,
			created_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY channel_status (channel_id, status),
			KEY status (status)
		) $charset;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'cptsc_db_version', CPTSC_DB_VERSION );
	}
}

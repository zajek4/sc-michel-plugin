<?php
/**
 * Public archive of published price lists (≥30 days retention, default 40).
 *
 * @package CPTSC
 */

namespace CPTSC\Feed;

use CPTSC\Database;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Archive manager.
 */
final class Archive {

	/**
	 * Absolute storage directory for feeds.
	 *
	 * @return string
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'cptsc/feed';
		return $dir;
	}

	/**
	 * Directory for a channel.
	 *
	 * @param int $channel_id Channel.
	 * @return string
	 */
	public static function channel_dir( $channel_id ) {
		return trailingslashit( self::base_dir() ) . 'ch' . (string) (int) $channel_id;
	}

	/**
	 * Temp directory.
	 *
	 * @return string
	 */
	public static function tmp_dir() {
		return trailingslashit( wp_upload_dir()['basedir'] ) . 'cptsc/tmp';
	}

	/**
	 * Ensure directories exist (with .htaccess deny for raw PHP safety — files are data only).
	 *
	 * @param string $dir Dir.
	 * @return bool
	 */
	public static function ensure_dir( $dir ) {
		if ( is_dir( $dir ) ) {
			return true;
		}
		return wp_mkdir_p( $dir );
	}

	/**
	 * Published versions for a channel.
	 *
	 * @param int $channel_id Channel.
	 * @param int $limit      Limit.
	 * @return array[]
	 */
	public static function versions( $channel_id, $limit = 100 ) {
		global $wpdb;
		$table = Database::instance()->table( 'feed_versions' );
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE channel_id = %d AND status = 'published' ORDER BY id DESC LIMIT %d",
				(int) $channel_id,
				(int) $limit
			),
			ARRAY_A
		);
	}

	/**
	 * Latest published version across default channel.
	 *
	 * @return array|null
	 */
	public static function latest_published( $channel_id = 0 ) {
		global $wpdb;
		$table = Database::instance()->table( 'feed_versions' );
		if ( ! $channel_id ) {
			$default = Channel::default_channel();
			$channel_id = $default ? $default['id'] : 0;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE channel_id = %d AND status = 'published' ORDER BY id DESC LIMIT 1",
				(int) $channel_id
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Retention days (never below legal minimum of 30).
	 *
	 * @return int
	 */
	public static function retention_days() {
		$days = (int) Settings::get( 'feed.retention_days', 40 );
		return max( 30, $days );
	}

	/**
	 * Delete published files/rows older than retention. Never touches the current file.
	 *
	 * @return int Removed count.
	 */
	public function cleanup() {
		global $wpdb;
		$table    = Database::instance()->table( 'feed_versions' );
		$retention = self::retention_days();
		$cutoff    = gmdate( 'Y-m-d H:i:s', time() - $retention * DAY_IN_SECONDS );
		$current   = self::current_filename(); // Never delete current.

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'published' AND published_at IS NOT NULL AND published_at < %s ORDER BY id ASC",
				$cutoff
			),
			ARRAY_A
		);

		$removed = 0;
		foreach ( $rows as $row ) {
			if ( $row['filename'] === $current ) {
				continue;
			}
			foreach ( Channel::all() as $ch ) {
				$path = trailingslashit( self::channel_dir( $ch['id'] ) ) . basename( $row['filename'] );
				if ( is_readable( $path ) ) {
					@unlink( $path ); // phpcs:ignore
				}
			}
			$wpdb->delete( $table, array( 'id' => (int) $row['id'] ) );
			$removed++;
		}
		return $removed;
	}

	/**
	 * Stable "current" filename on disk. The default channel always uses
	 * aktualni.csv (its DB id may be > 0) — matches Generator publish logic.
	 *
	 * @param int $channel_id Channel.
	 * @return string
	 */
	public static function current_filename( $channel_id = 0 ) {
		$default    = Channel::default_channel();
		$default_id = $default ? (int) $default['id'] : 0;
		if ( ! $channel_id || (int) $channel_id === $default_id ) {
			return 'aktualni.csv';
		}
		return 'aktualni-' . (int) $channel_id . '.csv';
	}

	/**
	 * Public URL of the current CSV for a channel.
	 *
	 * @param int $channel_id Channel (0 = default).
	 * @return string
	 */
	public static function current_url( $channel_id = 0 ) {
		$default = Channel::default_channel();
		$default_id = $default ? (int) $default['id'] : 0;
		$base = home_url( '/cjenik/' );
		if ( ! $channel_id || (int) $channel_id === $default_id ) {
			return trailingslashit( $base ) . 'aktualni.csv';
		}
		return trailingslashit( $base ) . 'aktualni-' . (int) $channel_id . '.csv';
	}

	/**
	 * Verify a filename is safe to serve (whitelist, no traversal).
	 *
	 * @param string $filename Filename.
	 * @return bool
	 */
	public static function is_safe_filename( $filename ) {
		if ( ! is_string( $filename ) || '' === $filename ) {
			return false;
		}
		if ( strlen( $filename ) > 255 ) {
			return false;
		}
		if ( false !== strpos( $filename, '..' ) || false !== strpos( $filename, '/' ) || false !== strpos( $filename, '\\' ) ) {
			return false;
		}
		return (bool) preg_match( '/^[A-Za-z0-9._:-]+\.csv$/', $filename );
	}

	/**
	 * Absolute path for a validated archive filename within a channel dir.
	 *
	 * @param string $filename   Filename.
	 * @param int    $channel_id Channel.
	 * @return string|null
	 */
	public static function archive_path( $filename, $channel_id ) {
		if ( ! self::is_safe_filename( $filename ) ) {
			return null;
		}
		$path = trailingslashit( self::channel_dir( $channel_id ) ) . $filename;
		$real = realpath( $path );
		$base = realpath( self::channel_dir( $channel_id ) );
		if ( ! $real || ! $base || 0 !== strpos( $real, $base ) ) {
			return null;
		}
		return $real;
	}
}

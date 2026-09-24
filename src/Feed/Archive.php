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
	 * Optional hooks (orphan temp cleanup on heartbeat-safe admin paths).
	 */
	public function hooks() {
		// Lightweight: clean abandoned temp files when diagnostics/feeds are viewed.
		add_action( 'admin_init', array( __CLASS__, 'maybe_clean_temps' ) );
	}

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
		// published_at is stored via current_time() (site-local) — cutoff must match that clock.
		$cutoff    = gmdate( 'Y-m-d H:i:s', time() - $retention * DAY_IN_SECONDS + (int) ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) );
		$current_by_channel = array();
		foreach ( Channel::all() as $ch ) {
			$current_by_channel[ (int) $ch['id'] ] = self::current_filename( (int) $ch['id'] );
		}

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'published' AND published_at IS NOT NULL AND published_at < %s ORDER BY id ASC",
				$cutoff
			),
			ARRAY_A
		);

		$removed = 0;
		foreach ( $rows as $row ) {
			$ch_id = (int) $row['channel_id'];
			// Never delete a channel's current file.
			if ( isset( $current_by_channel[ $ch_id ] ) && $row['filename'] === $current_by_channel[ $ch_id ] ) {
				continue;
			}
			$path = trailingslashit( self::channel_dir( $ch_id ) ) . basename( (string) $row['filename'] );
			if ( is_readable( $path ) ) {
				@unlink( $path ); // phpcs:ignore
			}
			$wpdb->delete( $table, array( 'id' => (int) $row['id'] ) );
			$removed++;
		}
		self::maybe_clean_temps();
		return $removed;
	}

	/**
	 * Remove abandoned temporary feed files (strict pattern, age-gated, plugin dirs only).
	 */
	public static function maybe_clean_temps() {
		$threshold = time() - HOUR_IN_SECONDS;
		$dirs      = array( self::tmp_dir(), self::base_dir() );
		foreach ( Channel::all() as $ch ) {
			$dirs[] = self::channel_dir( (int) $ch['id'] );
		}
		foreach ( array_unique( $dirs ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$real = realpath( $dir );
			$base = realpath( self::base_dir() );
			$tmp  = realpath( self::tmp_dir() );
			$ok   = ( $real && ( ( $base && 0 === strpos( $real, $base ) ) || ( $tmp && 0 === strpos( $real, $tmp ) ) ) );
			if ( ! $ok ) {
				continue;
			}
			foreach ( (array) glob( $real . '/*' ) ?: array() as $file ) {
				if ( ! is_file( $file ) ) {
					continue;
				}
				$name = basename( $file );
				// Only our known temp patterns: feed-*.csv.tmp, .stage-*.csv, *.csv.tmp, *.partial
				$ours = (bool) preg_match( '/^(feed-\d+-[A-Za-z0-9]+\.csv\.tmp|\.stage-[A-Za-z0-9]+\.csv|[A-Za-z0-9._:-]+\.csv\.tmp|[A-Za-z0-9._:-]+\.partial)$/', $name );
				if ( ! $ours ) {
					continue;
				}
				$mtime = (int) filemtime( $file );
				if ( $mtime && $mtime < $threshold ) {
					@unlink( $file ); // phpcs:ignore
				}
			}
		}
	}

	/**
	 * Reconcile DB feed metadata with files on disk (diagnostics / after failures).
	 *
	 * @return array {missing_current: string[], fingerprint_mismatch: string[], orphan_tmp: int}
	 */
	public static function reconcile() {
		global $wpdb;
		$out    = array(
			'missing_current'     => array(),
			'fingerprint_mismatch' => array(),
			'orphan_tmp'          => 0,
		);
		$table  = Database::instance()->table( 'feed_versions' );
		foreach ( Channel::all( true ) as $ch ) {
			$ch_id  = (int) $ch['id'];
			$latest = self::latest_published( $ch_id );
			if ( ! $latest ) {
				continue;
			}
			$path = trailingslashit( self::channel_dir( $ch_id ) ) . self::current_filename( $ch_id );
			if ( ! is_readable( $path ) ) {
				$out['missing_current'][] = self::current_filename( $ch_id );
				continue;
			}
			$fp = hash_file( 'sha256', $path );
			if ( $fp && ! empty( $latest['fingerprint'] ) && ! hash_equals( (string) $latest['fingerprint'], (string) $fp ) ) {
				$out['fingerprint_mismatch'][] = self::current_filename( $ch_id );
			}
		}
		// Count orphan temps (without deleting here — cleanup is separate).
		foreach ( array( self::tmp_dir(), self::base_dir() ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			foreach ( (array) glob( $dir . '/*.tmp' ) ?: array() as $f ) {
				if ( is_file( $f ) && filemtime( $f ) < time() - HOUR_IN_SECONDS ) {
					$out['orphan_tmp']++;
				}
			}
		}
		return $out;
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

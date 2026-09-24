<?php
/**
 * Token-based locks with heartbeat and stale takeover.
 *
 * @package CPTSC
 */

namespace CPTSC\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Named locks stored in a dedicated option (autoload=no).
 */
final class Lock {

	const OPTION_PREFIX = 'cptsc_lock_';

	/**
	 * Try to acquire a lock.
	 *
	 * @param string $name           Lock name.
	 * @param int    $ttl            Seconds the lock is valid without renewal.
	 * @param string $token          Token (generated when empty).
	 * @return bool
	 */
	public static function acquire( $name, $ttl = 60, $token = '' ) {
		$name = sanitize_key( $name );
		$opt  = self::OPTION_PREFIX . $name;
		if ( '' === $token ) {
			$token = wp_generate_password( 20, false, false );
		}
		$payload = array(
			'token'     => $token,
			'expires'   => time() + max( 5, (int) $ttl ),
			'heartbeat' => time(),
		);

		// add_option is an atomic INSERT — safe acquisition.
		if ( add_option( $opt, $payload, '', false ) ) {
			return true;
		}

		$current = get_option( $opt );
		if ( ! is_array( $current ) || empty( $current['expires'] ) || (int) $current['expires'] < time() ) {
			// Stale lock takeover.
			update_option( $opt, $payload, false );
			// Re-read to reduce (not eliminate) race window; acceptable for this workload.
			$verify = get_option( $opt );
			return is_array( $verify ) && isset( $verify['token'] ) && $verify['token'] === $token;
		}
		return false;
	}

	/**
	 * Renew heartbeat if we still own the lock.
	 *
	 * @param string $name  Name.
	 * @param string $token Token.
	 * @param int    $ttl   New TTL.
	 * @return bool
	 */
	public static function heartbeat( $name, $token, $ttl = 60 ) {
		$opt = self::OPTION_PREFIX . sanitize_key( $name );
		$cur = get_option( $opt );
		if ( ! is_array( $cur ) || ! isset( $cur['token'] ) || ! hash_equals( (string) $cur['token'], (string) $token ) ) {
			return false;
		}
		$cur['heartbeat'] = time();
		$cur['expires']   = time() + max( 5, (int) $ttl );
		update_option( $opt, $cur, false );
		return true;
	}

	/**
	 * Release a lock we own.
	 *
	 * @param string $name  Name.
	 * @param string $token Token (optional — force release when empty).
	 */
	public static function release( $name, $token = '' ) {
		$opt = self::OPTION_PREFIX . sanitize_key( $name );
		if ( '' === $token ) {
			delete_option( $opt );
			return;
		}
		$cur = get_option( $opt );
		if ( is_array( $cur ) && isset( $cur['token'] ) && hash_equals( (string) $cur['token'], (string) $token ) ) {
			delete_option( $opt );
		}
	}

	/**
	 * Lock info.
	 *
	 * @param string $name Name.
	 * @return array|null
	 */
	public static function info( $name ) {
		$cur = get_option( self::OPTION_PREFIX . sanitize_key( $name ) );
		return is_array( $cur ) ? $cur : null;
	}

	/**
	 * Periodic reconciliation: remove expired/stale lock options so a fatal
	 * in one worker never leaves the system stuck (heartbeat calls this).
	 *
	 * @return int Removed count.
	 */
	public static function sweep() {
		global $wpdb;
		$like  = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
		$names = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		$removed = 0;
		$grace   = 300; // Extra seconds after expiry before sweeping.
		foreach ( (array) $names as $name ) {
			$payload = get_option( $name );
			if ( ! is_array( $payload ) || empty( $payload['expires'] ) || (int) $payload['expires'] + $grace < time() ) {
				delete_option( $name );
				$removed++;
			}
		}
		return $removed;
	}
}

<?php
/**
 * Core date helpers — internal ISO, HR display without leading zeros.
 *
 * @package CPTSC
 */

namespace CPTSC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dates.
 */
final class Dates {

	/**
	 * Today as Y-m-d (site local time).
	 *
	 * @return string
	 */
	public static function today() {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Now as Y-m-d H:i:s (site local time).
	 *
	 * @return string
	 */
	public static function now() {
		return current_time( 'mysql' );
	}

	/**
	 * Format an ISO date (Y-m-d) for HR frontend: 24.9.2026. (no leading zeros).
	 *
	 * @param string|null $iso ISO date.
	 * @return string
	 */
	public static function format_hr_date( $iso ) {
		if ( ! $iso ) {
			return '';
		}
		$time = strtotime( $iso );
		if ( ! $time ) {
			return '';
		}
		return (int) date( 'j', $time ) . '.' . (int) date( 'n', $time ) . '.' . date( 'Y', $time ) . '.';
	}

	/**
	 * Validate Y-m-d date string.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_iso_date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}
}

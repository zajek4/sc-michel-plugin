<?php
/**
 * Deterministic money normalization. PHP floats are never used as a durable model —
 * values travel as canonical decimal strings and live in DECIMAL(20,6) columns.
 *
 * @package CPTSC
 */

namespace CPTSC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Money helpers.
 */
final class Money {

	/**
	 * Parse an arbitrary human/CSV price string into a canonical decimal string.
	 * Returns null when no numeric value can be extracted.
	 *
	 * @param mixed $raw Raw value.
	 * @return string|null
	 */
	public static function to_string( $raw ) {
		if ( null === $raw || '' === $raw || false === $raw ) {
			return null;
		}
		if ( is_int( $raw ) ) {
			return (string) $raw;
		}
		if ( is_float( $raw ) ) {
			// Only used for transient input, never as storage.
			return rtrim( rtrim( sprintf( '%.6F', $raw ), '0' ), '.' );
		}
		$s = trim( (string) $raw );
		if ( '' === $s ) {
			return null;
		}

		// Extract the first numeric-looking token.
		if ( ! preg_match( '/-?\d[\d\s\.,\x{00A0}\']*/u', $s, $m ) ) {
			return null;
		}
		$num = str_replace( array( ' ', "\xc2\xa0", "'", "\t" ), '', $m[0] );

		$has_comma = false !== strpos( $num, ',' );
		$has_dot   = false !== strpos( $num, '.' );

		if ( $has_comma && $has_dot ) {
			// Right-most separator is the decimal separator.
			$comma = strrpos( $num, ',' );
			$dot   = strrpos( $num, '.' );
			if ( $comma > $dot ) {
				$num = str_replace( '.', '', $num );
				$num = str_replace( ',', '.', $num );
			} else {
				$num = str_replace( ',', '', $num );
			}
		} elseif ( $has_comma ) {
			// Croatian convention: comma is the decimal separator.
			$num = str_replace( ',', '.', $num );
		} elseif ( $has_dot ) {
			$dot_count = substr_count( $num, '.' );
			if ( $dot_count > 1 ) {
				$num = str_replace( '.', '', $num );
			} elseif ( preg_match( '/^\d{1,3}(\.\d{3})+$/', $num ) ) {
				// 1.234 / 12.345 — thousands grouping.
				$num = str_replace( '.', '', $num );
			}
			// else: single dot with 1–2 decimals (or >3 digit left side) stays decimal.
		}

		if ( ! preg_match( '/^-?\d*(\.\d+)?$/', $num ) || '' === $num || '-' === $num || '.' === $num ) {
			return null;
		}

		return self::canonical( $num );
	}

	/**
	 * Normalize a decimal string to canonical form (dot separator, no excess zeros,
	 * max 6 decimals, keeps minus sign, strips leading zeros).
	 *
	 * @param string $value Value.
	 * @return string|null
	 */
	public static function canonical( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$neg = false;
		if ( '-' === $value[0] ) {
			$neg   = true;
			$value = substr( $value, 1 );
		}
		if ( ! preg_match( '/^\d*(\.\d*)?$/', $value ) ) {
			return null;
		}
		if ( '' === $value || '.' === $value ) {
			return null;
		}
		if ( false !== strpos( $value, '.' ) ) {
			list( $int, $dec ) = explode( '.', $value, 2 );
		} else {
			$int = $value;
			$dec = '';
		}
		$int = ltrim( $int, '0' );
		if ( '' === $int ) {
			$int = '0';
		}
		$dec = substr( str_pad( $dec, 6, '0' ), 0, 6 );
		$dec = rtrim( $dec, '0' );
		$out = '' === $dec ? $int : $int . '.' . $dec;
		return ( $neg && '0' !== $out && '0.00' !== $out ) ? '-' . $out : $out;
	}

	/**
	 * Format for human/HR frontend output: 1.234,56 (no symbol).
	 *
	 * @param string|null $decimal   Decimal string.
	 * @param int         $decimals  Decimals.
	 * @return string
	 */
	public static function format_hr( $decimal, $decimals = 2 ) {
		if ( null === $decimal || '' === $decimal ) {
			return '';
		}
		return number_format( (float) $decimal, $decimals, ',', '.' );
	}

	/**
	 * Format for machine CSV: always dot decimal, no thousands separator, fixed decimals.
	 *
	 * @param string|null $decimal  Decimal string.
	 * @param int         $decimals Decimals.
	 * @return string
	 */
	public static function format_csv( $decimal, $decimals = 2 ) {
		if ( null === $decimal || '' === $decimal ) {
			return '';
		}
		return number_format( (float) $decimal, $decimals, '.', '' );
	}

	/**
	 * Compare two decimal strings for equality (within 6 decimals).
	 *
	 * @param string|null $a A.
	 * @param string|null $b B.
	 * @return bool
	 */
	public static function eq( $a, $b ) {
		$a = self::canonical( null === $a || '' === $a ? '0' : $a );
		$b = self::canonical( null === $b || '' === $b ? '0' : $b );
		if ( null === $a || null === $b ) {
			return false;
		}
		return $a === $b;
	}
}

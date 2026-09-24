<?php
/**
 * Issue bitmask definitions and helpers.
 *
 * @package CPTSC
 */

namespace CPTSC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issue bits for cptsc_items.issue_mask.
 */
final class Issues {

	const MISSING_NAME              = 1;          // bit 0
	const MISSING_CURRENT_PRICE     = 2;          // bit 1
	const MISSING_ANCHOR_PRICE      = 4;          // bit 2
	const ANCHOR_DATE_MISMATCH      = 8;          // bit 3
	const ANCHOR_GROUP_CONFLICT     = 16;         // bit 4
	const MISSING_CODE              = 32;         // bit 5
	const MISSING_BRAND             = 64;         // bit 6
	const MISSING_BARCODE           = 128;        // bit 7
	const MISSING_UNIT              = 256;        // bit 8
	const MISSING_UNIT_PRICE        = 512;        // bit 9
	const MISSING_AVAILABILITY      = 1024;       // bit 10
	const SPECIAL_SALE_NAME_MISSING = 2048;       // bit 11
	const FIRST_LISTING_UNVERIFIED  = 4096;       // bit 12
	const SOURCE_VALUE_INVALID      = 8192;       // bit 13

	/**
	 * Validation level: excluded.
	 */
	const EXCLUDED_LEVEL = 3;

	/**
	 * All bits => machine keys.
	 *
	 * @return array
	 */
	public static function all() {
		return array(
			'MISSING_NAME'              => self::MISSING_NAME,
			'MISSING_CURRENT_PRICE'     => self::MISSING_CURRENT_PRICE,
			'MISSING_ANCHOR_PRICE'      => self::MISSING_ANCHOR_PRICE,
			'ANCHOR_DATE_MISMATCH'      => self::ANCHOR_DATE_MISMATCH,
			'ANCHOR_GROUP_CONFLICT'     => self::ANCHOR_GROUP_CONFLICT,
			'MISSING_CODE'              => self::MISSING_CODE,
			'MISSING_BRAND'             => self::MISSING_BRAND,
			'MISSING_BARCODE'           => self::MISSING_BARCODE,
			'MISSING_UNIT'              => self::MISSING_UNIT,
			'MISSING_UNIT_PRICE'        => self::MISSING_UNIT_PRICE,
			'MISSING_AVAILABILITY'      => self::MISSING_AVAILABILITY,
			'SPECIAL_SALE_NAME_MISSING' => self::SPECIAL_SALE_NAME_MISSING,
			'FIRST_LISTING_UNVERIFIED'  => self::FIRST_LISTING_UNVERIFIED,
			'SOURCE_VALUE_INVALID'      => self::SOURCE_VALUE_INVALID,
		);
	}

	/**
	 * Human (HR) labels.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'MISSING_NAME'              => __( 'Nedostaje naziv', 'wp-cpt-sidrene-cijene' ),
			'MISSING_CURRENT_PRICE'     => __( 'Nedostaje aktualna cijena', 'wp-cpt-sidrene-cijene' ),
			'MISSING_ANCHOR_PRICE'      => __( 'Nedostaje sidrena cijena', 'wp-cpt-sidrene-cijene' ),
			'ANCHOR_DATE_MISMATCH'      => __( 'Sidreni datum ne odgovara grupi', 'wp-cpt-sidrene-cijene' ),
			'ANCHOR_GROUP_CONFLICT'     => __( 'Sukob pravila sidrene grupe', 'wp-cpt-sidrene-cijene' ),
			'MISSING_CODE'              => __( 'Nedostaje šifra', 'wp-cpt-sidrene-cijene' ),
			'MISSING_BRAND'             => __( 'Nedostaje marka', 'wp-cpt-sidrene-cijene' ),
			'MISSING_BARCODE'           => __( 'Nedostaje barkod', 'wp-cpt-sidrene-cijene' ),
			'MISSING_UNIT'              => __( 'Nedostaje jedinica mjere', 'wp-cpt-sidrene-cijene' ),
			'MISSING_UNIT_PRICE'        => __( 'Nedostaje cijena jedinice mjere', 'wp-cpt-sidrene-cijene' ),
			'MISSING_AVAILABILITY'      => __( 'Nedostaje dostupnost', 'wp-cpt-sidrene-cijene' ),
			'SPECIAL_SALE_NAME_MISSING' => __( 'Nedostaje naziv posebnog oblika prodaje', 'wp-cpt-sidrene-cijene' ),
			'FIRST_LISTING_UNVERIFIED'  => __( 'Prvo uvrštenje nije potvrđeno', 'wp-cpt-sidrene-cijene' ),
			'SOURCE_VALUE_INVALID'      => __( 'Neispravna vrijednost izvora', 'wp-cpt-sidrene-cijene' ),
		);
	}

	/**
	 * Decode mask to list of issue keys.
	 *
	 * @param int $mask Mask.
	 * @return string[]
	 */
	public static function decode( $mask ) {
		$mask  = (int) $mask;
		$found = array();
		foreach ( self::all() as $key => $bit ) {
			if ( $mask & $bit ) {
				$found[] = $key;
			}
		}
		return $found;
	}

	/**
	 * Build mask from issue keys.
	 *
	 * @param string[] $keys Keys.
	 * @return int
	 */
	public static function encode( array $keys ) {
		$all = self::all();
		$mask = 0;
		foreach ( $keys as $key ) {
			if ( isset( $all[ $key ] ) ) {
				$mask |= $all[ $key ];
			}
		}
		return $mask;
	}

	/**
	 * Human descriptions for a mask.
	 *
	 * @param int $mask Mask.
	 * @return string[]
	 */
	public static function describe( $mask ) {
		$labels = self::labels();
		$out    = array();
		foreach ( self::decode( $mask ) as $key ) {
			$out[] = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
		}
		return $out;
	}
}

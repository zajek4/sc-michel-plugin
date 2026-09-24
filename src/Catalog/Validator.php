<?php
/**
 * Product vs Service aware validation with explicit applicability configuration.
 *
 * @package CPTSC
 */

namespace CPTSC\Catalog;

use CPTSC\Anchor\Groups;
use CPTSC\Issues;
use CPTSC\Mapping\SourceProfile;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Builder is in the same namespace — used by revalidate_row().

/**
 * Item validator.
 */
final class Validator {

	/**
	 * Validation levels.
	 */
	const READY = 0;
	const REVIEW = 1;
	const BLOCKED = 2;
	const EXCLUDED = 3;

	/**
	 * Validate a normalized row.
	 *
	 * @param array         $row        Row under evaluation.
	 * @param SourceProfile $profile    Profile.
	 * @param array         $group_info Group resolution.
	 * @param string[]      $invalid    Fields with unparsable source values.
	 * @return array {level: int, mask: int}
	 */
	public static function validate( array $row, SourceProfile $profile, array $group_info = array(), array $invalid = array() ) {
		$keys = array();

		$is_service = ( 'service' === $profile->item_type );

		// Critical.
		if ( '' === trim( (string) ( $row['name'] ?? '' ) ) ) {
			$keys[] = 'MISSING_NAME';
		}
		if ( null === ( $row['current_price'] ?? null ) || '' === (string) ( $row['current_price'] ?? '' ) ) {
			$keys[] = 'MISSING_CURRENT_PRICE';
		}
		foreach ( array_unique( $invalid ) as $field ) {
			$keys[] = 'SOURCE_VALUE_INVALID';
			break; // Single bit; field detail stays in logs.
		}

		// Anchor block.
		if ( null === ( $row['anchor_price'] ?? null ) || '' === (string) ( $row['anchor_price'] ?? '' ) ) {
			$keys[] = 'MISSING_ANCHOR_PRICE';
		}
		if ( 'requires_confirmation' === ( $row['first_listing_state'] ?? '' ) ) {
			$keys[] = 'FIRST_LISTING_UNVERIFIED';
		}
		if ( ! empty( $group_info['conflict'] ) ) {
			$keys[] = 'ANCHOR_GROUP_CONFLICT';
		} else {
			// Date mismatch: verified non-first-listing anchor should match group reference date.
			$anchor_date = $row['anchor_date'] ?? null;
			$source      = $row['anchor_source'] ?? '';
			$group       = $row['anchor_group'] ?? '';
			$ref         = $group ? Groups::reference_date( $group ) : null;
			if ( $anchor_date && $ref && in_array( $source, array( 'csv_import', 'manual', 'historical_field', 'migration' ), true ) && $anchor_date !== $ref ) {
				$keys[] = 'ANCHOR_DATE_MISMATCH';
			}
			// Expected group changed after anchor was stored.
			$expected = $group_info['group'] ?? null;
			if ( $expected && ! empty( $row['anchor_group'] ) && $expected !== $row['anchor_group'] && ! empty( $group_info['override'] ) === false && null !== ( $row['anchor_price'] ?? null ) ) {
				$keys[] = 'ANCHOR_DATE_MISMATCH';
			}
		}

		// Special sale.
		if ( ! empty( $row['special_sale'] ) && empty( $row['special_sale_name'] ) ) {
			$keys[] = 'SPECIAL_SALE_NAME_MISSING';
		}

		// Applicability-driven optional fields.
		$checks = array();
		if ( ! $is_service ) {
			// Product CSV/legal fields.
			$checks = array(
				'code'         => 'MISSING_CODE',
				'brand'        => 'MISSING_BRAND',
				'barcode'      => 'MISSING_BARCODE',
				'unit'         => 'MISSING_UNIT',
				'unit_price'   => 'MISSING_UNIT_PRICE',
				'availability' => 'MISSING_AVAILABILITY',
			);
		}
		// Services: only name, price, anchor and special-sale fields are legally relevant —
		// product-only fields are not validated at all.

		foreach ( $checks as $field => $issue_key ) {
			if ( ! self::applicable( $profile, $field ) ) {
				continue;
			}
			$value = $row[ $field ] ?? null;
			if ( null === $value || '' === (string) $value ) {
				$keys[] = $issue_key;
			}
		}

		$keys = apply_filters( 'cptsc_validation_issue_keys', array_unique( $keys ), $row, $profile );
		$mask = Issues::encode( $keys );

		$publication = $row['publication_state'] ?? 'publish';
		$critical    = ( $mask & ( Issues::MISSING_NAME | Issues::MISSING_CURRENT_PRICE | Issues::SOURCE_VALUE_INVALID ) ) > 0;

		if ( in_array( $publication, array( 'trash', 'excluded' ), true ) ) {
			$level = self::EXCLUDED;
		} elseif ( $critical ) {
			$level = self::BLOCKED;
		} elseif ( $mask > 0 ) {
			$level = self::REVIEW;
		} else {
			$level = self::READY;
		}

		/**
		 * Filter final validation result.
		 *
		 * @param array $result {level, mask}.
		 * @param array $row    Row.
		 */
		$result = apply_filters( 'cptsc_validation_result', array( 'level' => $level, 'mask' => $mask ), $row );
		return $result;
	}

	/**
	 * Applicability lookup.
	 *
	 * @param SourceProfile $profile Profile.
	 * @param string        $field   Field.
	 * @return bool
	 */
	private static function applicable( SourceProfile $profile, $field ) {
		$flag = $profile->applicable( $field, Settings::get( 'applicability.' . $field, 'applicable' ) );
		return 'applicable' === $flag;
	}

	/**
	 * Revalidate a stored row in place (REVALIDATE job).
	 *
	 * @param array $row Stored row.
	 * @return bool Changed.
	 */
	public static function revalidate_row( array $row ) {
		$profile = SourceProfile::find( $row['post_type'] );
		if ( ! $profile ) {
			return false;
		}
		$post = get_post( (int) $row['object_id'] );
		if ( ! $post ) {
			return false;
		}
		// Recompute group expectations fresh (clears stale conflict bits).
		$group_arr = Builder::resolve_group( (int) $row['object_id'], $row );
		$had_anchor = null !== $row['anchor_price'] && '' !== (string) $row['anchor_price'];

		$result = self::validate( $row, $profile, $group_arr, array() );

		$changed = ( (int) $row['validation_level'] !== $result['level'] || (int) $row['issue_mask'] !== $result['mask'] );
		$update  = array(
			'validation_level' => $result['level'],
			'issue_mask'       => $result['mask'],
		);
		// Without an anchor, the expected group follows current rules.
		if ( ! $had_anchor && ! $group_arr['conflict'] && ! empty( $group_arr['group'] ) && $group_arr['group'] !== $row['anchor_group'] ) {
			$update['anchor_group'] = $group_arr['group'];
			$changed = true;
		}
		if ( $changed ) {
			global $wpdb;
			$wpdb->update(
				\CPTSC\Database::instance()->table( 'items' ),
				$update,
				array( 'id' => (int) $row['id'] )
			);
			return true;
		}
		return false;
	}
}

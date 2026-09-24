<?php
/**
 * Index builder: resolve → normalize → anchor → validate → upsert.
 * Source-of-truth stays in the CPT; this produces the derived index row.
 *
 * @package CPTSC
 */

namespace CPTSC\Catalog;

use CPTSC\Anchor\FirstListing;
use CPTSC\Anchor\Groups;
use CPTSC\Anchor\Rules;
use CPTSC\Database;
use CPTSC\Issues;
use CPTSC\Mapping\Resolver;
use CPTSC\Mapping\SourceProfile;
use CPTSC\Money;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds/updates one index row per post.
 */
final class Builder {

	/**
	 * Logical fields we normalize.
	 *
	 * @return array
	 */
	public static function field_names() {
		return array(
			'name',
			'code',
			'brand',
			'unit',
			'unit_price',
			'regular_price',
			'sale_price',
			'current_price',
			'special_sale',
			'special_sale_name',
			'barcode',
			'availability',
			'anchor_historical_price',
			'anchor_historical_date',
		);
	}

	/**
	 * Resolve current price for a post under a profile (used by first-listing too).
	 *
	 * @param SourceProfile $profile Profile.
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post.
	 * @return string|null Decimal string.
	 */
	public static function resolve_current_price( SourceProfile $profile, $post_id, $post = null ) {
		$spec = $profile->field( 'current_price' );
		if ( ! $spec || Resolver::is_na( $spec ) ) {
			return null;
		}
		$raw  = Resolver::resolve( $spec, $post_id, $post );
		return Money::to_string( $raw );
	}

	/**
	 * Index a single post. Returns true when a row was written/updated.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function index_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			self::remove_by_object( $post_id );
			return false;
		}

		$profile = SourceProfile::find( $post->post_type );
		if ( ! $profile || ! $profile->is_confirmed() ) {
			return false;
		}

		// Multilingual: index canonical only; drop stale translation rows.
		$canonical = \CPTSC\Integrations\Multilingual::canonical_post_id( $post->ID );
		if ( $canonical !== (int) $post->ID ) {
			self::remove_by_object( $post->ID );
			return false;
		}

		$existing = self::fetch_row( $profile->id, $post->ID );
		$invalid  = array();
		$values   = self::resolve_values( $profile, $post, $invalid );

		// Availability policy.
		$policy = ! empty( $profile->flags['availability_policy'] ) ? $profile->flags['availability_policy'] : Settings::get( 'availability_policy', 'not_configured' );
		if ( 'published_means_available' === $policy ) {
			$values['availability'] = ( 'publish' === $post->post_status ) ? 'available' : 'unavailable';
		} elseif ( 'mapped' === $policy ) {
			$values['availability'] = self::normalize_availability( $values['availability'] );
		} else {
			$values['availability'] = null;
		}

		// Special sale mode.
		$special_mode = ! empty( $profile->flags['special_sale_mode'] ) ? $profile->flags['special_sale_mode'] : Settings::get( 'special_sale_mode', 'not_configured' );
		if ( 'constant_false' === $special_mode ) {
			$values['special_sale']     = 0;
			$values['special_sale_name'] = null;
		} else {
			$values['special_sale'] = self::normalize_bool( $values['special_sale'] );
			if ( empty( $values['special_sale'] ) ) {
				$values['special_sale_name'] = null;
			} else {
				$values['special_sale_name'] = null === $values['special_sale_name'] || '' === $values['special_sale_name'] ? null : (string) $values['special_sale_name'];
			}
		}

		// Anchor group resolution.
		$group_info = self::resolve_group( $post->ID, $existing );
		$expected_group = $group_info['conflict'] ? null : $group_info['group'];
		if ( null === $expected_group ) {
			$expected_group = Settings::get( 'default_anchor_group', 'general_2026' );
		}

		// First listing inference (immutable once present).
		$first_listing = self::resolve_first_listing( $post, $expected_group, $existing );

		// Anchor: NEVER invent; NEVER touch a verified anchor.
		$anchor = self::resolve_anchor( $profile, $post, $expected_group, $existing, $first_listing );

		$row = array(
			'source_id'            => (int) $profile->id,
			'object_id'            => (int) $post->ID,
			'canonical_object_id'  => (int) $post->ID,
			'post_type'            => $post->post_type,
			'item_type'            => $profile->item_type,
			'name'                 => isset( $values['name'] ) && null !== $values['name'] ? self::clip( $values['name'], 500 ) : '',
			'code'                 => self::clip_nullable( $values['code'], 191 ),
			'brand'                => self::clip_nullable( $values['brand'], 191 ),
			'unit'                 => self::clip_nullable( $values['unit'], 64 ),
			'unit_price'           => $values['unit_price'],
			'regular_price'        => $values['regular_price'],
			'sale_price'           => $values['sale_price'],
			'current_price'        => $values['current_price'],
			'special_sale'         => null === $values['special_sale'] ? null : (int) $values['special_sale'],
			'special_sale_name'    => self::clip_nullable( $values['special_sale_name'], 255 ),
			'barcode'              => self::clip_nullable( $values['barcode'], 64 ),
			'availability'         => $values['availability'],
			'anchor_group'         => $anchor['anchor_group'],
			'group_override'       => $group_info['override'],
			'anchor_price'         => $anchor['anchor_price'],
			'anchor_date'          => $anchor['anchor_date'],
			'anchor_source'        => $anchor['anchor_source'],
			'anchor_verified'      => $anchor['anchor_verified'] ? 1 : 0,
			'anchor_override'      => $anchor['anchor_override'] ? 1 : 0,
			'first_listing_at'     => $first_listing['first_listing_at'],
			'first_listing_price'  => $first_listing['first_listing_price'],
			'first_listing_state'  => $first_listing['first_listing_state'],
			'publication_state'    => 'publish' === $post->post_status ? 'publish' : $post->post_status,
			'indexed_at'           => current_time( 'mysql' ),
		);

		// Validation.
		$validation = Validator::validate( $row, $profile, $group_info, $invalid );
		$row['validation_level'] = $validation['level'];
		$row['issue_mask']       = $validation['mask'];

		// Row hash (stable, timestamps excluded).
		$hash = self::row_hash( $row );
		$row['row_hash'] = $hash;

		$changed = ( ! $existing ) || ( isset( $existing['row_hash'] ) && $existing['row_hash'] !== $hash );
		if ( $changed ) {
			$row['changed_at'] = current_time( 'mysql' );
			$row['feed_dirty'] = 1;
		} else {
			$row['feed_dirty'] = isset( $existing['feed_dirty'] ) ? (int) $existing['feed_dirty'] : 0;
		}

		self::upsert( $row, $existing );

		/**
		 * Fires after an item was (re)indexed.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $row     Row.
		 * @param bool  $changed Hash changed.
		 */
		do_action( 'cptsc_item_indexed', (int) $post_id, $row, $changed );
		return true;
	}

	/**
	 * Resolve all mapped values.
	 *
	 * @param SourceProfile $profile Profile.
	 * @param \WP_Post      $post    Post.
	 * @param array         $invalid Receives names of fields with unparsable values.
	 * @return array
	 */
	private static function resolve_values( SourceProfile $profile, $post, array &$invalid ) {
		$out = array();
		foreach ( self::field_names() as $field ) {
			$spec = $profile->field( $field );
			if ( ! $spec || Resolver::is_na( $spec ) ) {
				$out[ $field ] = null;
				continue;
			}
			$raw = Resolver::resolve( $spec, $post->ID, $post );
			if ( null === $raw || '' === $raw ) {
				$out[ $field ] = null;
				continue;
			}
			switch ( $field ) {
				case 'name':
					$out[ $field ] = is_scalar( $raw ) ? trim( (string) $raw ) : null;
					break;
				case 'code':
				case 'brand':
				case 'unit':
				case 'special_sale_name':
				case 'barcode':
					$out[ $field ] = is_scalar( $raw ) ? trim( (string) $raw ) : null;
					break;
				case 'unit_price':
				case 'regular_price':
				case 'sale_price':
				case 'current_price':
				case 'anchor_historical_price':
					$dec = Money::to_string( $raw );
					if ( null === $dec ) {
						$invalid[] = $field;
						$out[ $field ] = null;
					} else {
						$out[ $field ] = $dec;
					}
					break;
				case 'anchor_historical_date':
					$date = self::normalize_date( $raw );
					if ( null === $date ) {
						$invalid[] = $field;
					}
					$out[ $field ] = $date;
					break;
				case 'special_sale':
					$out[ $field ] = self::normalize_bool( $raw );
					break;
				case 'availability':
					$out[ $field ] = is_scalar( $raw ) ? self::normalize_availability( $raw ) : null;
					break;
				default:
					$out[ $field ] = is_scalar( $raw ) ? $raw : null;
			}
		}
		return apply_filters( 'cptsc_normalized_values', $out, $profile, $post->ID );
	}

	/**
	 * Group resolution respecting manual override.
	 *
	 * @param int        $post_id  Post.
	 * @param array|null $existing Existing row.
	 * @return array {group, conflict, override, matched_groups}
	 */
	public static function resolve_group( $post_id, $existing ) {
		$override = ( $existing && ! empty( $existing['group_override'] ) ) ? (string) $existing['group_override'] : null;

		$rules      = Rules::all();
		$resolution = Rules::resolve_for_post( $post_id, '', $rules );

		if ( $resolution['conflict'] ) {
			return array(
				'group'          => $override ? $override : null,
				'conflict'       => true,
				'override'       => $override,
				'matched_groups' => $resolution['matched_groups'],
			);
		}

		// Priority: manual override > term rules > default.
		$group = $override ? $override : $resolution['group'];
		if ( ! $group ) {
			$group = Settings::get( 'default_anchor_group', 'general_2026' );
		}
		return array(
			'group'          => $group,
			'conflict'       => false,
			'override'       => $override,
			'matched_groups' => $resolution['matched_groups'],
		);
	}

	/**
	 * Test-mode group resolution (no DB row dependency).
	 *
	 * @param int $post_id Post.
	 * @return array
	 */
	public static function resolve_group_for_test( $post_id ) {
		return self::resolve_group( $post_id, null );
	}

	/**
	 * First-listing resolution (immutable).
	 *
	 * @param \WP_Post      $post           Post.
	 * @param string        $expected_group Expected group.
	 * @param array|null    $existing       Existing row.
	 * @return array
	 */
	private static function resolve_first_listing( $post, $expected_group, $existing ) {
		// Keep immutable values.
		if ( $existing && ! empty( $existing['first_listing_state'] ) ) {
			return array(
				'first_listing_at'    => $existing['first_listing_at'],
				'first_listing_price' => $existing['first_listing_price'],
				'first_listing_state' => $existing['first_listing_state'],
			);
		}

		$pending = FirstListing::consume_pending( $post->ID );
		if ( $pending ) {
			return array(
				'first_listing_at'    => $pending['first_listing_at'],
				'first_listing_price' => isset( $pending['first_listing_price'] ) ? $pending['first_listing_price'] : null,
				'first_listing_state' => $pending['first_listing_state'],
			);
		}

		$ref  = Groups::reference_date( $expected_group );
		$pdate = substr( (string) $post->post_date, 0, 10 );
		if ( $ref && $pdate > $ref ) {
			return array(
				'first_listing_at'    => $post->post_date,
				'first_listing_price' => null, // Never claim a price we did not witness.
				'first_listing_state' => 'requires_confirmation',
			);
		}
		return array(
			'first_listing_at'    => null,
			'first_listing_price' => null,
			'first_listing_state' => 'not_applicable',
		);
	}

	/**
	 * Anchor resolution. Never invents an anchor price.
	 *
	 * @param SourceProfile $profile        Profile.
	 * @param \WP_Post      $post           Post.
	 * @param string        $expected_group Expected group.
	 * @param array|null    $existing       Existing row.
	 * @param array         $first_listing  First listing data.
	 * @return array
	 */
	private static function resolve_anchor( SourceProfile $profile, $post, $expected_group, $existing, array $first_listing ) {
		// 1) Verified anchor is protected — keep as-is (group mismatch surfaces as an issue).
		if ( $existing && ! empty( $existing['anchor_verified'] ) && null !== $existing['anchor_price'] && '' !== $existing['anchor_price'] ) {
			return array(
				'anchor_group'    => $existing['anchor_group'],
				'anchor_price'    => $existing['anchor_price'],
				'anchor_date'     => $existing['anchor_date'],
				'anchor_source'   => $existing['anchor_source'],
				'anchor_verified' => 1,
				'anchor_override' => ! empty( $existing['anchor_override'] ),
			);
		}

		// 2) Explicit manual override already present but unverified — keep values.
		if ( $existing && ! empty( $existing['anchor_override'] ) && null !== $existing['anchor_price'] && '' !== $existing['anchor_price'] ) {
			return array(
				'anchor_group'    => $existing['anchor_group'] ? $existing['anchor_group'] : $expected_group,
				'anchor_price'    => $existing['anchor_price'],
				'anchor_date'     => $existing['anchor_date'],
				'anchor_source'   => $existing['anchor_source'] ? $existing['anchor_source'] : 'manual',
				'anchor_verified' => ! empty( $existing['anchor_verified'] ),
				'anchor_override' => 1,
			);
		}

		// 3) Historical field mapping (admin explicitly mapped historical evidence).
		$h_price_spec = $profile->field( 'anchor_historical_price' );
		$h_date_spec  = $profile->field( 'anchor_historical_date' );
		if ( $h_price_spec && $h_date_spec && ! Resolver::is_na( $h_price_spec ) && ! Resolver::is_na( $h_date_spec ) ) {
			$h_price = Money::to_string( Resolver::resolve( $h_price_spec, $post->ID, $post ) );
			$h_date  = self::normalize_date( Resolver::resolve( $h_date_spec, $post->ID, $post ) );
			if ( null !== $h_price && null !== $h_date ) {
				return array(
					'anchor_group'    => $expected_group,
					'anchor_price'    => $h_price,
					'anchor_date'     => $h_date,
					'anchor_source'   => 'historical_field',
					'anchor_verified' => 1,
					'anchor_override' => false,
				);
			}
		}

		// 4) First-listing capture (new product introduced after its reference date).
		if ( 'captured' === $first_listing['first_listing_state'] && null !== $first_listing['first_listing_price'] && $first_listing['first_listing_at'] ) {
			$fl_date    = substr( $first_listing['first_listing_at'], 0, 10 );
			$ref        = Groups::reference_date( $expected_group );
			if ( $ref && $fl_date > $ref ) {
				return array(
					'anchor_group'    => $expected_group,
					'anchor_price'    => $first_listing['first_listing_price'],
					'anchor_date'     => $fl_date,
					'anchor_source'   => 'first_listing_capture',
					'anchor_verified' => 1,
					'anchor_override' => false,
				);
			}
		}

		// 5) Nothing trustworthy — stay empty. MISSING_ANCHOR_PRICE will be flagged.
		return array(
			'anchor_group'    => $expected_group,
			'anchor_price'    => null,
			'anchor_date'     => null,
			'anchor_source'   => null,
			'anchor_verified' => 0,
			'anchor_override' => false,
		);
	}

	/**
	 * Deterministic row hash over relevant normalized data (no volatile timestamps).
	 *
	 * @param array $row Row.
	 * @return string
	 */
	public static function row_hash( $row ) {
		$relevant = array(
			'name'                 => isset( $row['name'] ) ? (string) $row['name'] : '',
			'code'                 => isset( $row['code'] ) ? (string) $row['code'] : '',
			'brand'                => isset( $row['brand'] ) ? (string) $row['brand'] : '',
			'unit'                 => isset( $row['unit'] ) ? (string) $row['unit'] : '',
			'unit_price'           => isset( $row['unit_price'] ) ? (string) $row['unit_price'] : null,
			'regular_price'        => isset( $row['regular_price'] ) ? (string) $row['regular_price'] : null,
			'sale_price'           => isset( $row['sale_price'] ) ? (string) $row['sale_price'] : null,
			'current_price'        => isset( $row['current_price'] ) ? (string) $row['current_price'] : null,
			'special_sale'         => isset( $row['special_sale'] ) ? (string) $row['special_sale'] : null,
			'special_sale_name'    => isset( $row['special_sale_name'] ) ? (string) $row['special_sale_name'] : '',
			'barcode'              => isset( $row['barcode'] ) ? (string) $row['barcode'] : '',
			'availability'         => isset( $row['availability'] ) ? (string) $row['availability'] : '',
			'anchor_group'         => isset( $row['anchor_group'] ) ? (string) $row['anchor_group'] : '',
			'anchor_price'         => isset( $row['anchor_price'] ) ? (string) $row['anchor_price'] : null,
			'anchor_date'          => isset( $row['anchor_date'] ) ? (string) $row['anchor_date'] : null,
			'first_listing_at'     => isset( $row['first_listing_at'] ) ? (string) $row['first_listing_at'] : null,
			'first_listing_price'  => isset( $row['first_listing_price'] ) ? (string) $row['first_listing_price'] : null,
			'first_listing_state'  => isset( $row['first_listing_state'] ) ? (string) $row['first_listing_state'] : '',
			'publication_state'    => isset( $row['publication_state'] ) ? (string) $row['publication_state'] : '',
			'validation_level'     => isset( $row['validation_level'] ) ? (int) $row['validation_level'] : 0,
			'issue_mask'           => isset( $row['issue_mask'] ) ? (int) $row['issue_mask'] : 0,
		);
		$relevant = apply_filters( 'cptsc_row_hash_input', $relevant, $row );
		ksort( $relevant );
		return hash( 'sha256', (string) wp_json_encode( $relevant ) );
	}

	/**
	 * Upsert by (source_id, object_id).
	 *
	 * @param array      $row      Row.
	 * @param array|null $existing Existing.
	 */
	private static function upsert( array $row, $existing ) {
		global $wpdb;
		$table = Database::instance()->table( 'items' );
		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $existing['id'] ) );
		} else {
			$wpdb->insert( $table, $row );
		}
	}

	/**
	 * Fetch raw row.
	 *
	 * @param int $source_id Source.
	 * @param int $object_id Object.
	 * @return array|null
	 */
	public static function fetch_row( $source_id, $object_id ) {
		global $wpdb;
		$table = Database::instance()->table( 'items' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source_id = %d AND object_id = %d",
				(int) $source_id,
				(int) $object_id
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Remove an index row (post deleted).
	 *
	 * @param int $object_id Object.
	 */
	public static function remove_by_object( $object_id ) {
		global $wpdb;
		$wpdb->delete( Database::instance()->table( 'items' ), array( 'object_id' => (int) $object_id ) );
	}

	/* ------------------------------------------------------ normalizers */

	/**
	 * Normalize availability to available|unavailable|null.
	 *
	 * @param mixed $raw Raw.
	 * @return string|null
	 */
	private static function normalize_availability( $raw ) {
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		if ( is_bool( $raw ) ) {
			return $raw ? 'available' : 'unavailable';
		}
		$v = strtolower( trim( (string) $raw ) );
		$yes = array( '1', 'true', 'da', 'yes', 'y', 'available', 'dostupno', 'dostupan', 'in_stock', 'instock', 'na_zalihi', 'available_now' );
		$no  = array( '0', 'false', 'ne', 'no', 'n', 'unavailable', 'nedostupno', 'nedostupan', 'out_of_stock', 'outofstock', 'nema_na_zalihi' );
		if ( in_array( $v, $yes, true ) ) {
			return 'available';
		}
		if ( in_array( $v, $no, true ) ) {
			return 'unavailable';
		}
		return null;
	}

	/**
	 * Normalize to 0/1/null.
	 *
	 * @param mixed $raw Raw.
	 * @return int|null
	 */
	private static function normalize_bool( $raw ) {
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		if ( is_bool( $raw ) ) {
			return $raw ? 1 : 0;
		}
		$v = strtolower( trim( (string) $raw ) );
		if ( in_array( $v, array( '1', 'true', 'da', 'yes', 'y', 'on' ), true ) ) {
			return 1;
		}
		if ( in_array( $v, array( '0', 'false', 'ne', 'no', 'n', 'off' ), true ) ) {
			return 0;
		}
		if ( is_numeric( $v ) ) {
			return ( (int) $v ) > 0 ? 1 : 0;
		}
		return null;
	}

	/**
	 * Normalize various date inputs to Y-m-d.
	 *
	 * @param mixed $raw Raw.
	 * @return string|null
	 */
	private static function normalize_date( $raw ) {
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		$s = trim( (string) $raw );
		if ( \CPTSC\Dates::is_iso_date( $s ) ) {
			return $s;
		}
		// 24.9.2026. / 24.09.2026. / 10/09/2026.
		if ( preg_match( '#^(\d{1,2})[./](\d{1,2})[./](\d{4})\.?$#', $s, $m ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1] );
		}
		$ts = strtotime( $s );
		if ( $ts ) {
			return date( 'Y-m-d', $ts );
		}
		return null;
	}

	/**
	 * Clip string.
	 *
	 * @param string|null $value Value.
	 * @param int         $max   Max.
	 * @return string|null
	 */
	private static function clip_nullable( $value, $max ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return self::clip( $value, $max );
	}

	/**
	 * Clip string to length.
	 *
	 * @param string $value Value.
	 * @param int    $max   Max.
	 * @return string
	 */
	private static function clip( $value, $max ) {
		$value = (string) $value;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}
		return substr( $value, 0, $max );
	}
}

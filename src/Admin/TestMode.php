<?php
/**
 * Test mode: dry-run pipeline over N sample items.
 * Writes NOTHING to the production index, queue, feed or setup state.
 *
 * @package CPTSC
 */

namespace CPTSC\Admin;

use CPTSC\Anchor\Groups;
use CPTSC\Anchor\Rules;
use CPTSC\Catalog\Builder;
use CPTSC\Catalog\Validator;
use CPTSC\Feed\Validator as FeedValidator;
use CPTSC\Mapping\Resolver;
use CPTSC\Mapping\SourceProfile;
use CPTSC\Money;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test mode runner.
 */
final class TestMode {

	/**
	 * Run tests.
	 *
	 * @param array $request Request data.
	 * @return array
	 */
	public function run( $request ) {
		$limit = isset( $request['limit'] ) ? max( 1, min( 50, (int) $request['limit'] ) ) : 5;
		$only  = array();
		if ( ! empty( $request['post_ids'] ) && is_array( $request['post_ids'] ) ) {
			$only = array_map( 'intval', $request['post_ids'] );
		}

		$profiles = SourceProfile::confirmed();
		if ( empty( $profiles ) ) {
			return array( 'error' => __( 'Mapiranje još nije potvrđeno.', 'wp-cpt-sidrene-cijene' ) );
		}

		$rows = array();
		foreach ( $profiles as $profile ) {
			if ( $only ) {
				$post_ids = $only;
			} else {
				$post_ids = get_posts(
					array(
						'post_type'      => $profile->post_type,
						'post_status'    => 'publish',
						'posts_per_page' => $limit,
						'fields'         => 'ids',
						'orderby'        => 'ID',
						'order'          => 'ASC',
					)
				);
			}
			foreach ( $post_ids as $post_id ) {
				$rows[] = $this->evaluate_post( $profile, (int) $post_id );
				if ( count( $rows ) >= $limit ) {
					break 2;
				}
			}
		}

		return array(
			'rows'      => $rows,
			'csv'       => $this->csv_preview( $rows ),
			'note'      => __( 'Testni način ne mijenja produkcijske podatke.', 'wp-cpt-sidrene-cijene' ),
		);
	}

	/**
	 * Evaluate one post through resolve → normalize → group → validate (no writes).
	 *
	 * @param SourceProfile $profile Profile.
	 * @param int           $post_id Post.
	 * @return array
	 */
	private function evaluate_post( SourceProfile $profile, $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'post_id' => $post_id, 'error' => 'not found' );
		}

		$invalid  = array();
		$values   = array();
		foreach ( Builder::field_names() as $field ) {
			$spec = $profile->field( $field );
			if ( ! $spec || Resolver::is_na( $spec ) ) {
				$values[ $field ] = null;
				continue;
			}
			$raw = Resolver::resolve( $spec, $post_id, $post );
			if ( in_array( $field, array( 'current_price', 'unit_price', 'regular_price', 'sale_price', 'anchor_historical_price' ), true ) ) {
				$dec = Money::to_string( $raw );
				if ( null === $dec && null !== $raw && '' !== $raw ) {
					$invalid[] = $field;
				}
				$values[ $field ] = $dec;
			} else {
				$values[ $field ] = is_scalar( $raw ) ? $raw : null;
			}
		}

		$group_info = Builder::resolve_group_for_test( $post_id );
		$row        = array_merge(
			array(
				'name'              => isset( $values['name'] ) ? (string) $values['name'] : '',
				'publication_state' => 'publish',
				'anchor_price'      => null,
				'anchor_date'       => null,
				'anchor_source'     => null,
				'anchor_group'      => $group_info['group'],
				'first_listing_state' => 'not_applicable',
				'special_sale'      => isset( $values['special_sale'] ) ? $values['special_sale'] : null,
				'special_sale_name' => isset( $values['special_sale_name'] ) ? $values['special_sale_name'] : null,
			),
			$values
		);
		// Simulate anchor availability preview (read-only: uses stored row if any).
		$existing = Builder::fetch_row( $profile->id, $post_id );
		if ( $existing && null !== $existing['anchor_price'] ) {
			$row['anchor_price']  = $existing['anchor_price'];
			$row['anchor_date']   = $existing['anchor_date'];
			$row['anchor_source'] = $existing['anchor_source'];
			$row['first_listing_state'] = $existing['first_listing_state'];
		} elseif ( null !== $values['current_price'] && null !== $values['anchor_historical_price'] ) {
			$row['anchor_price']  = $values['anchor_historical_price'];
			$row['anchor_date']   = isset( $values['anchor_historical_date'] ) ? $values['anchor_historical_date'] : Groups::reference_date( $group_info['group'] );
			$row['anchor_source'] = 'historical_field';
		}

		$validation = Validator::validate( $row, $profile, $group_info, $invalid );

		return array(
			'post_id'    => $post_id,
			'title'      => $post->post_title,
			'values'     => $values,
			'group_info' => $group_info,
			'validation' => $validation,
			'issues'     => \CPTSC\Issues::describe( $validation['mask'] ),
			'frontend'   => \CPTSC\Frontend\Renderer::render_data( $row, 'shortcode' ),
			'invalid'    => $invalid,
		);
	}

	/**
	 * CSV preview string (first evaluated rows).
	 *
	 * @param array $rows Rows.
	 * @return string
	 */
	private function csv_preview( array $rows ) {
		$item_type = Settings::get( 'item_type', 'product' );
		$header    = FeedValidator::expected_header( $item_type );
		$delimiter = ';';
		$fh        = fopen( 'php://memory', 'r+b' );
		fputcsv( $fh, $header, $delimiter );
		foreach ( $rows as $r ) {
			if ( 'service' === $item_type ) {
				$line = array(
					$r['values']['name'] ?? '',
					Money::format_csv( $r['values']['current_price'] ?? null ),
					! empty( $r['values']['special_sale'] ) ? 'da' : 'ne',
					(string) ( $r['values']['special_sale_name'] ?? '' ),
					Money::format_csv( $r['anchor_price'] ),
					(string) ( $r['anchor_date'] ?? '' ),
				);
			} else {
				$line = array(
					$r['values']['name'] ?? '',
					(string) ( $r['values']['code'] ?? '' ),
					(string) ( $r['values']['brand'] ?? '' ),
					(string) ( $r['values']['unit'] ?? '' ),
					Money::format_csv( $r['values']['unit_price'] ?? null ),
					Money::format_csv( $r['values']['current_price'] ?? null ),
					! empty( $r['values']['special_sale'] ) ? 'da' : 'ne',
					(string) ( $r['values']['special_sale_name'] ?? '' ),
					Money::format_csv( $r['anchor_price'] ),
					(string) ( $r['anchor_date'] ?? '' ),
					(string) ( $r['values']['barcode'] ?? '' ),
					'',
				);
			}
			fputcsv( $fh, $line, $delimiter );
		}
		rewind( $fh );
		$out = stream_get_contents( $fh );
		fclose( $fh );
		return (string) $out;
	}
}

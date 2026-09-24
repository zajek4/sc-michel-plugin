<?php
/**
 * Post meta discovery: actually-used keys from a sample + registered meta.
 * Never loads the full wp_postmeta table.
 *
 * @package CPTSC
 */

namespace CPTSC\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta key scanner.
 */
class MetaScanner {

	/**
	 * Aggregate meta keys used by the given posts with usage counts and samples.
	 *
	 * @param int[] $post_ids Sample post IDs.
	 * @return array[] list of {key, count, samples[]}
	 */
	public function keys_for_posts( array $post_ids ) {
		global $wpdb;
		if ( empty( $post_ids ) ) {
			return array();
		}
		$ids_sql = implode( ',', array_map( 'intval', $post_ids ) ); // Sanitized above.

		$rows = $wpdb->get_results(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
			 WHERE post_id IN ($ids_sql) AND meta_key NOT LIKE '\_%'
			 ORDER BY meta_key ASC, meta_id ASC"
		);

		$agg = array();
		foreach ( (array) $rows as $row ) {
			$key = (string) $row->meta_key;
			if ( ! isset( $agg[ $key ] ) ) {
				$agg[ $key ] = array(
					'key'     => $key,
					'count'   => 0,
					'samples' => array(),
				);
			}
			$agg[ $key ]['count']++;
			if ( count( $agg[ $key ]['samples'] ) < 5 && '' !== trim( (string) $row->meta_value ) && maybe_unserialize( $row->meta_value ) === $row->meta_value ) {
				$agg[ $key ]['samples'][] = substr( (string) $row->meta_value, 0, 120 );
			}
		}

		$list = array_values( $agg );
		usort(
			$list,
			function ( $a, $b ) {
				if ( $a['count'] === $b['count'] ) {
					return strcmp( $a['key'], $b['key'] );
				}
				return $b['count'] - $a['count'];
			}
		);
		return $list;
	}

	/**
	 * Keys registered via register_post_meta / show_in_rest etc.
	 *
	 * @param string $post_type Post type.
	 * @return array[]
	 */
	public function registered_keys( $post_type ) {
		$out = array();
		if ( ! function_exists( 'get_registered_meta_keys' ) ) {
			return $out;
		}
		$scopes = array( 'post', $post_type );
		foreach ( $scopes as $scope ) {
			$keys = get_registered_meta_keys( $scope );
			foreach ( (array) $keys as $key => $args ) {
				$out[] = array(
					'key'   => (string) $key,
					'label' => isset( $args['label'] ) ? (string) $args['label'] : '',
					'type'  => isset( $args['type'] ) ? (string) $args['type'] : '',
					'scope' => $scope,
				);
			}
		}
		return $out;
	}
}

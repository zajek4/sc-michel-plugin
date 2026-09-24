<?php
/**
 * Taxonomy discovery: hierarchy, terms, approximate usage on the sample.
 *
 * @package CPTSC
 */

namespace CPTSC\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomy scanner.
 */
class TaxonomyScanner {

	/**
	 * Taxonomies attached to a post type with term stats from the sample.
	 *
	 * @param string $post_type Post type.
	 * @param int[]  $post_ids  Sample IDs.
	 * @return array[]
	 */
	public function taxonomies( $post_type, array $post_ids ) {
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		$out        = array();
		foreach ( (array) $taxonomies as $tax ) {
			if ( ! isset( $tax->name ) ) {
				continue;
			}
			$usage = array();
			foreach ( array_slice( $post_ids, 0, 50 ) as $pid ) {
				$terms = wp_get_object_terms( $pid, $tax->name, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $terms ) ) {
					continue;
				}
				foreach ( $terms as $tid ) {
					$usage[ $tid ] = ( isset( $usage[ $tid ] ) ? $usage[ $tid ] : 0 ) + 1;
				}
			}
			arsort( $usage );
			$terms_info = array();
			$counter    = 0;
			foreach ( array_keys( $usage ) as $tid ) {
				if ( $counter++ >= 30 ) {
					break;
				}
				$term = get_term( (int) $tid, $tax->name );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				$terms_info[] = array(
					'term_id'  => (int) $term->term_id,
					'name'     => $term->name,
					'slug'     => $term->slug,
					'parent'   => (int) $term->parent,
					'count'    => (int) $usage[ $tid ],
				);
			}
			$out[] = array(
				'taxonomy'          => $tax->name,
				'label'             => isset( $tax->label ) ? $tax->label : $tax->name,
				'hierarchical'      => ! empty( $tax->hierarchical ),
				'total_terms'       => function_exists( 'wp_count_terms' ) ? (int) wp_count_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false ) ) : 0,
				'sample_usage'      => $terms_info,
			);
		}
		return $out;
	}
}

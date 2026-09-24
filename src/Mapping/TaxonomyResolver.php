<?php
/**
 * Taxonomy/term resolver.
 *
 * @package CPTSC
 */

namespace CPTSC\Mapping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomy resolver.
 */
final class TaxonomyResolver {

	/**
	 * Resolve taxonomy value.
	 *
	 * Spec: {source: taxonomy, key: taxonomy_name, mode: name|slug|id|ids (default name)}
	 *
	 * @param array         $spec    Spec.
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post.
	 * @return mixed
	 */
	public static function resolve( array $spec, $post_id, $post = null ) {
		$taxonomy = isset( $spec['key'] ) ? (string) $spec['key'] : '';
		$mode     = isset( $spec['mode'] ) ? (string) $spec['mode'] : 'name';
		if ( '' === $taxonomy || $post_id <= 0 ) {
			return null;
		}
		$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'all' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}
		$first = $terms[0];
		switch ( $mode ) {
			case 'slug':
				return $first->slug;
			case 'id':
				return (string) $first->term_id;
			case 'ids':
				return implode( ',', wp_list_pluck( $terms, 'term_id' ) );
			case 'names':
				return implode( ',', wp_list_pluck( $terms, 'name' ) );
			case 'name':
			default:
				return $first->name;
		}
	}
}

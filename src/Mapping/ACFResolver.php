<?php
/**
 * ACF resolver — safe no-op when ACF is absent.
 *
 * @package CPTSC
 */

namespace CPTSC\Mapping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ACF resolver.
 */
final class ACFResolver {

	/**
	 * Resolve ACF scalar field by field name or field key.
	 *
	 * @param array         $spec    Spec.
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post.
	 * @return mixed
	 */
	public static function resolve( array $spec, $post_id, $post = null ) {
		$key = isset( $spec['key'] ) ? (string) $spec['key'] : '';
		if ( '' === $key || $post_id <= 0 ) {
			return null;
		}
		if ( function_exists( 'get_field' ) ) {
			// get_field handles name or field key; raw=true avoids formatting surprises.
			return get_field( $key, $post_id, true );
		}
		// Fallback: ACF stores values as post meta under field name.
		return get_post_meta( $post_id, $key, true );
	}
}

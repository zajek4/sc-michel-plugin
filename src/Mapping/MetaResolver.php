<?php
/**
 * Post meta resolver.
 *
 * @package CPTSC
 */

namespace CPTSC\Mapping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta resolver.
 */
final class MetaResolver {

	/**
	 * Resolve meta value. Uses direct $wpdb fetch to avoid loading everything.
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
		return get_post_meta( $post_id, $key, true );
	}
}

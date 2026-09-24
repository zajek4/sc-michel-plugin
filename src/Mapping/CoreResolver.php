<?php
/**
 * WordPress core post field resolver.
 *
 * @package CPTSC
 */

namespace CPTSC\Mapping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core resolver.
 */
final class CoreResolver {

	/**
	 * Resolve core field.
	 *
	 * @param array         $spec    Spec.
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post.
	 * @return mixed
	 */
	public static function resolve( array $spec, $post_id, $post = null ) {
		$key = isset( $spec['key'] ) ? (string) $spec['key'] : '';
		if ( '' === $key ) {
			return null;
		}
		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( $post_id );
		}
		if ( ! $post ) {
			return null;
		}
		if ( isset( $post->$key ) ) {
			return $post->$key;
		}
		return null;
	}
}

<?php
/**
 * Resolver dispatcher: turns a mapping spec into a concrete value for one post.
 *
 * @package CPTSC
 */

namespace CPTSC\Mapping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Value resolver.
 */
class Resolver {

	/**
	 * Registered custom resolvers: source type => callable(spec, post_id, post).
	 *
	 * @var array
	 */
	private static $custom = array();

	/**
	 * Register an adapter resolver for custom source types.
	 *
	 * @param string   $source   Source type key (e.g. "mydb").
	 * @param callable $callback fn(array $spec, int $post_id, \WP_Post $post): mixed.
	 */
	public static function register( $source, $callback ) {
		if ( is_string( $source ) && is_callable( $callback ) ) {
			self::$custom[ sanitize_key( $source ) ] = $callback;
		}
	}

	/**
	 * Resolve one mapped field.
	 *
	 * @param array         $spec    Mapping spec {source, key, value, mode…}.
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object.
	 * @return mixed|null
	 */
	public static function resolve( array $spec, $post_id, $post = null ) {
		$source = isset( $spec['source'] ) ? (string) $spec['source'] : '';
		$value  = null;

		switch ( $source ) {
			case 'core':
				$value = CoreResolver::resolve( $spec, $post_id, $post );
				break;
			case 'meta':
				$value = MetaResolver::resolve( $spec, $post_id, $post );
				break;
			case 'acf':
				$value = ACFResolver::resolve( $spec, $post_id, $post );
				break;
			case 'taxonomy':
				$value = TaxonomyResolver::resolve( $spec, $post_id, $post );
				break;
			case 'constant':
				$value = array_key_exists( 'value', $spec ) ? $spec['value'] : null;
				break;
			case 'manual':
				$value = array_key_exists( 'value', $spec ) ? $spec['value'] : null;
				break;
			default:
				if ( isset( self::$custom[ $source ] ) && is_callable( self::$custom[ $source ] ) ) {
					$value = call_user_func( self::$custom[ $source ], $spec, $post_id, $post );
				} elseif ( 'adapter' === $source && ! empty( $spec['resolver'] ) ) {
					/**
					 * Filter lets themes/plugins resolve adapter values.
					 *
					 * @param mixed       $value  Resolved value.
					 * @param array       $spec   Spec.
					 * @param int         $post_id Post ID.
					 */
					$value = apply_filters( 'cptsc_resolve_adapter', null, $spec, $post_id );
				}
				break;
		}

		/**
		 * Filter any resolved value before normalization.
		 *
		 * @param mixed $value   Value.
		 * @param array $spec    Spec.
		 * @param int   $post_id Post ID.
		 */
		return apply_filters( 'cptsc_resolve_field', $value, $spec, $post_id );
	}

	/**
	 * Whether a spec is "not applicable".
	 *
	 * @param array $spec Spec.
	 * @return bool
	 */
	public static function is_na( array $spec ) {
		return isset( $spec['source'] ) && 'na' === $spec['source'];
	}
}

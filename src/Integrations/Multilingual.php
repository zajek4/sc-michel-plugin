<?php
/**
 * Canonical post resolution across multilingual plugins.
 * The default/main-language post is the canonical catalog & feed identity.
 *
 * @package CPTSC
 */

namespace CPTSC\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Language-agnostic canonical resolver.
 */
final class Multilingual {

	/**
	 * Cache per request.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Canonical post ID for any post (self when no translation plugin).
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function canonical_post_id( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return 0;
		}
		if ( isset( self::$cache[ $post_id ] ) ) {
			return self::$cache[ $post_id ];
		}
		$canonical = $post_id;

		// WPML.
		if ( function_exists( 'wpml_object_id' ) ) {
			$post_type = get_post_type( $post_id );
			$default_lang = apply_filters( 'wpml_default_language', null );
			if ( $default_lang ) {
				$mapped = apply_filters( 'wpml_object_id', $post_id, $post_type, false, $default_lang );
				if ( $mapped ) {
					$canonical = (int) $mapped;
				}
			} else {
				// Fallback: original language translation.
				$source = apply_filters( 'wpml_object_id', $post_id, $post_type, false, null );
				if ( $source ) {
					$canonical = (int) $source;
				}
			}
		} elseif ( function_exists( 'pll_default_post' ) ) {
			// Polylang: translation in the default language.
			$mapped = pll_default_post( $post_id );
			if ( $mapped ) {
				$canonical = (int) $mapped;
			}
		}

		/**
		 * Filter canonical post identity (custom multilingual setups).
		 *
		 * @param int $canonical Canonical ID.
		 * @param int $post_id   Original ID.
		 */
		$canonical = (int) apply_filters( 'cptsc_canonical_post_id', $canonical, $post_id );
		if ( $canonical <= 0 ) {
			$canonical = $post_id;
		}
		self::$cache[ $post_id ] = $canonical;
		return $canonical;
	}

	/**
	 * Whether any multilingual plugin is active.
	 *
	 * @return bool
	 */
	public static function active() {
		return function_exists( 'wpml_object_id' ) || function_exists( 'pll_default_post' );
	}
}

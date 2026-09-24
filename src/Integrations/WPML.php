<?php
/**
 * WPML integration — safe no-op when WPML is absent.
 *
 * @package CPTSC
 */

namespace CPTSC\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPML hooks.
 */
final class WPML {

	/**
	 * Hooks.
	 */
	public function hooks() {
		if ( ! function_exists( 'wpml_object_id' ) ) {
			return;
		}
		// Currently all canonical logic lives in Multilingual; nothing extra needed.
		do_action( 'cptsc_wpml_ready' );
	}

	/**
	 * Available?
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'wpml_object_id' );
	}
}

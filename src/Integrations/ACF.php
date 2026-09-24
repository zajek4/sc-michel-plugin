<?php
/**
 * ACF integration — safe no-op when ACF is absent.
 *
 * @package CPTSC
 */

namespace CPTSC\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Advanced Custom Fields bridge.
 */
final class ACF {

	/**
	 * Hooks.
	 */
	public function hooks() {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return;
		}
		// acf/save_post is consumed by Catalog\Queue; nothing else required here.
		add_action( 'cptsc_acf_probe', '__return_true' );
	}

	/**
	 * Available?
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'acf_get_field_groups' );
	}
}

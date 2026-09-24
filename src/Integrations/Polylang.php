<?php
/**
 * Polylang integration — safe no-op when Polylang is absent.
 *
 * @package CPTSC
 */

namespace CPTSC\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Polylang hooks.
 */
final class Polylang {

	/**
	 * Hooks.
	 */
	public function hooks() {
		if ( ! function_exists( 'pll_default_post' ) ) {
			return;
		}
		do_action( 'cptsc_polylang_ready' );
	}

	/**
	 * Available?
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'pll_default_post' );
	}
}

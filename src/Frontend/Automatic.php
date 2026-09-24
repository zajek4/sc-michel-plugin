<?php
/**
 * Automatic frontend integration with a CONFIRMED CSS selector.
 * Fail-silent: if the selector is missing on a request, nothing is inserted.
 *
 * @package CPTSC
 */

namespace CPTSC\Frontend;

use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Automatic content injection.
 */
final class Automatic {

	/**
	 * Hooks.
	 */
	public function hooks() {
		$state = Settings::get( 'frontend.state', 'auto_disabled' );
		if ( 'auto_verified' !== $state ) {
			return;
		}
		add_filter( 'the_content', array( $this, 'inject' ), 90 );
	}

	/**
	 * Inject anchor near the confirmed price element.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function inject( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}
		$selected = Settings::get( 'post_types', array() );
		if ( ! in_array( $post->post_type, (array) $selected, true ) ) {
			return $content;
		}
		$selector = (string) Settings::get( 'frontend.selector', '' );
		if ( '' === $selector || ! Inspector::is_safe_selector( $selector ) ) {
			return $content;
		}
		// Already rendered (shortcode in content)?
		if ( false !== strpos( $content, 'cptsc-anchor-price' ) ) {
			return $content;
		}

		$injected = Inspector::insert_after_selector( $content, $selector, Renderer::render( $post->ID, 'automatic' ) );
		if ( '' === $injected ) {
			return $content; // Selector missing → do nothing (fail safe).
		}
		Shortcode::enqueue_assets();
		return $injected;
	}
}

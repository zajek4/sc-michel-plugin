<?php
/**
 * Automatic frontend integration with a CONFIRMED CSS selector.
 * Strategies: (1) the_content DOM insert when price is in content;
 * (2) lightweight footer script that inserts the same renderer output
 * next to the verified selector when price is outside the_content
 * (Elementor/Bricks/ACF templates). Fail-silent in both paths.
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
		// Selector assist for content outside the_content (builder templates).
		add_action( 'wp_footer', array( $this, 'maybe_footer_assist' ), 5 );
	}

	/**
	 * Context guard: singular CPT page selected for this plugin.
	 *
	 * @return \WP_Post|null
	 */
	private function context_post() {
		if ( ! is_singular() ) {
			return null;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		$selected = Settings::get( 'post_types', array() );
		if ( ! in_array( $post->post_type, (array) $selected, true ) ) {
			return null;
		}
		return $post;
	}

	/**
	 * Inject anchor near the confirmed price element (the_content strategy).
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function inject( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = $this->context_post();
		if ( ! $post ) {
			return $content;
		}
		$selector = (string) Settings::get( 'frontend.selector', '' );
		if ( '' === $selector || ! Inspector::is_safe_selector( $selector ) ) {
			return $content;
		}
		// Already rendered (shortcode in content)?
		if ( false !== strpos( $content, 'cptsc-anchor-price' ) ) {
			if ( ! defined( 'CPTSC_ANCHOR_EMITTED' ) ) {
				define( 'CPTSC_ANCHOR_EMITTED', 1 );
			}
			return $content;
		}

		$html = Renderer::render( $post->ID, 'automatic' );
		if ( '' === $html ) {
			return $content;
		}
		$injected = Inspector::insert_after_selector( $content, $selector, $html );
		if ( '' === $injected ) {
			// Selector not in content → footer assist may handle builder templates.
			return $content; // Fail safe: nothing guessed inside content.
		}
		if ( ! defined( 'CPTSC_ANCHOR_EMITTED' ) ) {
			define( 'CPTSC_ANCHOR_EMITTED', 1 );
		}
		Shortcode::enqueue_assets();
		return $injected;
	}

	/**
	 * Footer assist: verified selector outside the_content (Elementor/Bricks/ACF
	 * templates). Same Renderer output; deterministic selector only; fail-silent.
	 */
	public function maybe_footer_assist() {
		if ( defined( 'CPTSC_ANCHOR_EMITTED' ) ) {
			return;
		}
		$post = $this->context_post();
		if ( ! $post ) {
			return;
		}
		$selector = (string) Settings::get( 'frontend.selector', '' );
		if ( '' === $selector || ! Inspector::is_safe_selector( $selector ) ) {
			return;
		}
		$html = Renderer::render( $post->ID, 'automatic' );
		if ( '' === $html ) {
			return;
		}
		Shortcode::enqueue_assets();
		$insert = (string) Settings::get( 'frontend.insert', 'after' );
		if ( ! in_array( $insert, array( 'after', 'before', 'append' ), true ) ) {
			$insert = 'after';
		}
		$selector_json = wp_json_encode( $selector );
		$html_json     = wp_json_encode( $html );
		$insert_json   = wp_json_encode( $insert );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode of admin selector + renderer HTML.
		echo '<script id="cptsc-auto-inject">(function(){try{if(document.querySelector(".cptsc-anchor-price"))return;'
			. 'var t=document.querySelector(' . $selector_json . ');if(!t)return;'
			. 'var h=' . $html_json . ',m=' . $insert_json . ';'
			. 'if(m==="before"){t.insertAdjacentHTML("beforebegin",h);}'
			. 'else if(m==="append"){t.insertAdjacentHTML("beforeend",h);}'
			. 'else{t.insertAdjacentHTML("afterend",h);}'
			. '}catch(e){}})();</script>';
		// phpcs:enable
	}
}

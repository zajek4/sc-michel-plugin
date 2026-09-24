<?php
/**
 * Shortcode [sidrena_cijena] — works in classic content, Gutenberg shortcode block,
 * Elementor shortcode widget and do_shortcode() contexts.
 *
 * @package CPTSC
 */

namespace CPTSC\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode registration.
 */
final class Shortcode {

	/**
	 * Tag.
	 */
	const TAG = 'sidrena_cijena';

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Render shortcode.
	 *
	 * @param array $atts Attributes: id.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'id' => 0,
			),
			$atts,
			self::TAG
		);
		$post_id = (int) $atts['id'];
		if ( $post_id <= 0 ) {
			$post_id = get_the_ID();
		}
		if ( $post_id <= 0 ) {
			return '';
		}
		// Enqueue tiny frontend style once.
		self::enqueue_assets();
		return Renderer::render( $post_id, 'shortcode' );
	}

	/**
	 * Register + enqueue minimal stylesheet (only when output happens).
	 */
	public static function enqueue_assets() {
		wp_register_style(
			'cptsc-frontend',
			CPTSC_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			CPTSC_VERSION
		);
		wp_enqueue_style( 'cptsc-frontend' );
	}
}

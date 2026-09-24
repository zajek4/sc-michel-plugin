<?php
/**
 * Shared anchor-price renderer — used by automatic integration AND shortcode
 * so formatting logic exists exactly once.
 *
 * @package CPTSC
 */

namespace CPTSC\Frontend;

use CPTSC\Dates;
use CPTSC\Money;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend renderer.
 */
final class Renderer {

	/**
	 * Render anchor HTML for a post. Returns '' when nothing trustworthy to show.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $context shortcode|automatic|listing.
	 * @return string
	 */
	public static function render( $post_id, $context = 'automatic' ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return '';
		}
		$item = \CPTSC\Catalog\Index::resolve_for_post( $post_id );
		if ( ! $item ) {
			return '';
		}
		return self::render_item( $item, $context );
	}

	/**
	 * Render from an index item.
	 *
	 * @param \CPTSC\Catalog\Item $item    Item.
	 * @param string              $context Context.
	 * @return string
	 */
	public static function render_item( $item, $context = 'automatic' ) {
		if ( ! $item instanceof \CPTSC\Catalog\Item ) {
			return '';
		}
		return self::render_data( $item->data, $context );
	}

	/**
	 * Core rendering from raw row.
	 *
	 * @param array  $row     Row.
	 * @param string $context Context.
	 * @return string
	 */
	public static function render_data( array $row, $context = 'automatic' ) {
		// Fail-safe: no anchor → no output. Excluded items never render.
		if ( empty( $row['anchor_price'] ) || empty( $row['anchor_date'] ) ) {
			return '';
		}
		if ( isset( $row['validation_level'] ) && (int) $row['validation_level'] === \CPTSC\Issues::EXCLUDED_LEVEL ) {
			return '';
		}

		$date_hr   = Dates::format_hr_date( $row['anchor_date'] );
		$price_hr  = Money::format_hr( $row['anchor_price'] );
		$currency  = Settings::get( 'currency_symbol', '€' );

		/* translators: %s: reference date like 10.9.2026. */
		$label = sprintf( __( 'Cijena na %s:', 'wp-cpt-sidrene-cijene' ), $date_hr );
		$label = apply_filters( 'cptsc_anchor_label', $label, $row );

		$html = sprintf(
			'<span class="cptsc-anchor-price cptsc-context-%1$s"><span class="cptsc-anchor-label">%2$s</span> <span class="cptsc-anchor-value">%3$s%4$s</span></span>',
			esc_attr( $context ),
			esc_html( $label ),
			esc_html( $price_hr ),
			'&nbsp;' . esc_html( $currency )
		);

		/**
		 * Filter final renderer output (custom renderers can replace it).
		 *
		 * @param string $html    HTML.
		 * @param array  $row     Row.
		 * @param string $context Context.
		 */
		return apply_filters( 'cptsc_renderer_output', $html, $row, $context );
	}
}

<?php
/**
 * First listing capture: immutable draft→publish evidence.
 *
 * @package CPTSC
 */

namespace CPTSC\Anchor;

use CPTSC\Database;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * First-listing hooks.
 */
final class FirstListing {

	/**
	 * Hook in.
	 */
	public function hooks() {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
	}

	/**
	 * Capture first publish while the plugin actively watches.
	 *
	 * @param string   $new   New status.
	 * @param string   $old   Old status.
	 * @param \WP_Post $post  Post.
	 */
	public function on_transition( $new, $old, $post ) {
		if ( 'publish' !== $new || 'publish' === $old ) {
			return;
		}
		if ( 'auto-draft' === $old ) {
			// First "publish" from auto-draft still counts as first listing.
		}
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$profiles = \CPTSC\Mapping\SourceProfile::confirmed();
		$profile  = null;
		foreach ( $profiles as $p ) {
			if ( $p->post_type === $post->post_type ) {
				$profile = $p;
				break;
			}
		}
		if ( ! $profile ) {
			return;
		}

		$items = Database::instance()->table( 'items' );
		global $wpdb;

		// Only capture when we do not already hold an immutable record.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, first_listing_state, first_listing_at FROM {$items} WHERE object_id = %d AND source_id = %d",
				(int) $post->ID,
				(int) $profile->id
			),
			ARRAY_A
		);
		if ( $existing && ! empty( $existing['first_listing_state'] ) ) {
			return;
		}

		$price = \CPTSC\Catalog\Builder::resolve_current_price( $profile, $post->ID, $post );

		if ( null !== $price ) {
			// Reliable, actively captured evidence.
			$row = array(
				'first_listing_at'     => $post->post_date,
				'first_listing_price'  => $price,
				'first_listing_state'  => 'captured',
			);
			Audit::instance()->log( $post->ID, 'FIRST_LISTING_CAPTURED', '', wp_json_encode( $row ), 'watch', get_current_user_id() );
			// Row may not exist yet — queue will build it; store via upsert after build if missing.
			if ( $existing ) {
				global $wpdb;
				$wpdb->update( $items, $row, array( 'id' => (int) $existing['id'] ) );
			} else {
				// Persist pending capture so the first index run picks it up.
				update_option( 'cptsc_pending_first_listing_' . (int) $post->ID, $row, false );
			}
		} else {
			$row = array(
				'first_listing_at'    => $post->post_date,
				'first_listing_price' => null,
				'first_listing_state' => 'requires_confirmation',
			);
			if ( $existing ) {
				$wpdb->update( $items, $row, array( 'id' => (int) $existing['id'] ) );
			} else {
				update_option( 'cptsc_pending_first_listing_' . (int) $post->ID, $row, false );
			}
			Audit::instance()->log( $post->ID, 'FIRST_LISTING_CAPTURED', '', wp_json_encode( $row ), 'watch', get_current_user_id() );
		}
	}

	/**
	 * Pending capture payload (consumed by Builder).
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function consume_pending( $post_id ) {
		$key  = 'cptsc_pending_first_listing_' . (int) $post_id;
		$row  = get_option( $key, null );
		if ( is_array( $row ) ) {
			delete_option( $key );
			return $row;
		}
		return null;
	}

	/**
	 * Would a missing price be an issue for first listing? Helper for validator.
	 *
	 * @param array $item Item row.
	 * @return bool
	 */
	public static function needs_confirmation_issue( array $item ) {
		return isset( $item['first_listing_state'] ) && 'requires_confirmation' === $item['first_listing_state'];
	}
}

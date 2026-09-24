<?php
/**
 * Channel (location) management — one file per channel per regulation.
 *
 * @package CPTSC
 */

namespace CPTSC\Feed;

use CPTSC\Database;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sales/service location channels.
 */
final class Channel {

	/**
	 * All channels.
	 *
	 * @param bool $enabled_only Enabled only.
	 * @return array[]
	 */
	public static function all( $enabled_only = false ) {
		global $wpdb;
		$table = Database::instance()->table( 'channels' );
		$sql   = "SELECT * FROM {$table}";
		if ( $enabled_only ) {
			$sql .= ' WHERE enabled = 1';
		}
		$sql .= ' ORDER BY is_default DESC, id ASC';
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A );
		return array_map(
			function ( $row ) {
				$row['id']               = (int) $row['id'];
				$row['enabled']          = (int) $row['enabled'];
				$row['is_default']       = (int) $row['is_default'];
				$row['storage_sequence'] = (int) $row['storage_sequence'];
				return $row;
			},
			$rows
		);
	}

	/**
	 * One channel.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = Database::instance()->table( 'channels' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Default channel (creates the standard single-channel setup when missing).
	 *
	 * @return array|null
	 */
	public static function default_channel() {
		$all = self::all();
		foreach ( $all as $ch ) {
			if ( $ch['is_default'] ) {
				return $ch;
			}
		}
		return ! empty( $all ) ? $all[0] : null;
	}

	/**
	 * Ensure the default channel exists.
	 */
	public static function ensure_default() {
		if ( self::default_channel() ) {
			return;
		}
		global $wpdb;
		$table    = Database::instance()->table( 'channels' );
		$item_type = Settings::get( 'item_type', 'product' );
		$wpdb->insert(
			$table,
			array(
				'type'           => 'web',
				'name'           => get_bloginfo( 'name' ),
				'object_kind'    => 'service' === $item_type ? 'usluzni_objekt' : 'prodavaonica',
				'object_code'    => 'service' === $item_type ? 'U-01' : 'P-01',
				'address'        => wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ? wp_parse_url( home_url( '/' ), PHP_URL_HOST ) : '',
				'storage_sequence' => 0,
				'enabled'        => 1,
				'is_default'     => 1,
				'created_at'     => current_time( 'mysql' ),
				'updated_at'     => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Insert/update channel.
	 *
	 * @param array $data Data.
	 * @param int   $id   Existing id.
	 * @return int Channel id.
	 */
	public static function save( array $data, $id = 0 ) {
		global $wpdb;
		$table = Database::instance()->table( 'channels' );
		$row   = array(
			'type'        => isset( $data['type'] ) ? sanitize_key( $data['type'] ) : 'web',
			'name'        => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
			'object_kind' => isset( $data['object_kind'] ) ? self::sanitize_kind( $data['object_kind'] ) : '',
			'object_code' => isset( $data['object_code'] ) ? self::sanitize_code( $data['object_code'] ) : '',
			'address'     => isset( $data['address'] ) ? sanitize_text_field( $data['address'] ) : '',
			'enabled'     => empty( $data['enabled'] ) ? 0 : 1,
			'is_default'  => empty( $data['is_default'] ) ? 0 : 1,
			'updated_at'  => current_time( 'mysql' ),
		);
		if ( $id > 0 ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $id ) );
			return (int) $id;
		}
		$row['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $table, $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete channel (not the default).
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		$ch = self::find( $id );
		if ( ! $ch || $ch['is_default'] ) {
			return false;
		}
		global $wpdb;
		$wpdb->delete( Database::instance()->table( 'channels' ), array( 'id' => (int) $id ) );
		return true;
	}

	/**
	 * Increment storage sequence (broj pohrane).
	 *
	 * @param int $id Channel.
	 * @return int New sequence.
	 */
	public static function next_sequence( $id ) {
		global $wpdb;
		$table = Database::instance()->table( 'channels' );
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET storage_sequence = storage_sequence + 1 WHERE id = %d", (int) $id )
		);
		return (int) self::find( $id )['storage_sequence'];
	}

	/**
	 * Sanitize object kind for filename (prodavaonica, usluzni_objekt, webshop…).
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function sanitize_kind( $kind ) {
		$kind = mb_strtolower( trim( (string) $kind ) );
		$kind = self::transliterate( $kind );
		$kind = preg_replace( '/[^a-z0-9_-]+/', '_', $kind );
		return substr( (string) $kind, 0, 40 );
	}

	/**
	 * Sanitize object code (P-01, U-03, NP…).
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function sanitize_code( $code ) {
		$code = mb_strtoupper( trim( (string) $code ) );
		$code = self::transliterate( $code );
		return substr( (string) preg_replace( '/[^A-Z0-9_-]+/', '-', $code ), 0, 24 );
	}

	/**
	 * ASCII transliteration fallback.
	 *
	 * @param string $s String.
	 * @return string
	 */
	private static function transliterate( $s ) {
		if ( function_exists( 'iconv' ) ) {
			$conv = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $s ); // phpcs:ignore
			if ( false !== $conv ) {
				return $conv;
			}
		}
		return (string) $s;
	}
}

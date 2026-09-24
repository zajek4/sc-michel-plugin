<?php
/**
 * Central settings wrapper (few options only — never a catalog).
 *
 * @package CPTSC
 */

namespace CPTSC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings accessor.
 */
final class Settings {

	const OPTION = 'cptsc_settings';
	const DISCOVERY_OPTION = 'cptsc_discovery';
	const HEALTH_OPTION = 'cptsc_health';
	const TOKEN_OPTION = 'cptsc_cron_token';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'setup_state'            => 'NOT_STARTED',
			'setup_step'             => 1,
			'item_type'              => '', // product|service
			'post_types'             => array(),
			'sources_confirmed'      => false,
			'mapping_confirmed'      => false,
			'anchor_rules_confirmed' => false,
			'frontend'               => array(
				'state'         => 'auto_disabled', // auto_unverified|auto_verified|auto_disabled
				'selector'      => '',
				'insert'        => 'after',
				'sample_post_id' => 0,
			),
			'feed'                   => array(
				'enabled'        => false,
				'html_enabled'   => true,
				'retention_days'  => 40,
				'delimiter'       => ';',
				'bom'             => true,
				'auto_publish'    => true,
				'force_publish'   => false,
			),
			'applicability'          => array(
				'unit'        => 'applicable',
				'unit_price'  => 'applicable',
				'barcode'     => 'applicable',
				'brand'       => 'applicable',
				'availability' => 'applicable',
			),
			'availability_policy'    => 'not_configured', // mapped|published_means_available|not_configured
			'special_sale_mode'      => 'not_configured', // mapped|constant_false|not_configured
			'default_anchor_group'   => 'general_2026',
			'multi_location'         => false,
			'currency_symbol'        => '€',
			'delete_data_on_uninstall' => false,
			'last_import_file'       => '',
			'preflight_ack'          => false,
			'version'                => '',
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			self::$cache = wp_parse_args( $stored, self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Get single setting (dot notation supported).
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all      = self::all();
		$parts    = explode( '.', $key );
		$value    = $all;
		$exists   = true;
		foreach ( $parts as $part ) {
			if ( is_array( $value ) && array_key_exists( $part, $value ) ) {
				$value = $value[ $part ];
			} else {
				$exists = false;
				break;
			}
		}
		if ( ! $exists ) {
			return $default;
		}
		return $value;
	}

	/**
	 * Update settings (shallow merge at top level, deep for known arrays).
	 *
	 * @param array $patch Patch.
	 */
	public static function update( array $patch ) {
		$all = self::all();
		foreach ( $patch as $key => $value ) {
			if ( isset( $all[ $key ] ) && is_array( $all[ $key ] ) && is_array( $value ) ) {
				$all[ $key ] = array_merge( $all[ $key ], $value );
			} else {
				$all[ $key ] = $value;
			}
		}
		self::$cache = $all;
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Set a single top-level or dot-notation key.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public static function set( $key, $value ) {
		$all   = self::all();
		$parts = explode( '.', $key );
		$ref   = &$all;
		while ( count( $parts ) > 1 ) {
			$part = array_shift( $parts );
			if ( ! isset( $ref[ $part ] ) || ! is_array( $ref[ $part ] ) ) {
				$ref[ $part ] = array();
			}
			$ref = &$ref[ $part ];
		}
		$ref[ array_shift( $parts ) ] = $value;
		self::$cache                  = $all;
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Reset cache (tests / after uninstall-type operations).
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Install defaults on activation.
	 */
	public static function install_defaults() {
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
		if ( false === get_option( self::HEALTH_OPTION ) ) {
			add_option(
				self::HEALTH_OPTION,
				array(
					'last_worker'      => 0,
					'last_heartbeat'   => 0,
					'last_server_cron' => 0,
					'last_feed_publish' => 0,
					'last_feed_fingerprint' => '',
				),
				'',
				false
			);
		}
		if ( ! get_option( self::TOKEN_OPTION ) ) {
			add_option( self::TOKEN_OPTION, self::generate_token(), '', false );
		}
		self::flush_cache();
	}

	/**
	 * Generate a long random cron token.
	 *
	 * @return string
	 */
	public static function generate_token() {
		return wp_generate_password( 48, false, false );
	}

	/**
	 * Current cron token.
	 *
	 * @return string
	 */
	public static function cron_token() {
		$token = get_option( self::TOKEN_OPTION, '' );
		if ( ! is_string( $token ) || '' === $token ) {
			$token = self::generate_token();
			update_option( self::TOKEN_OPTION, $token, false );
		}
		return $token;
	}

	/**
	 * Health counters.
	 *
	 * @return array
	 */
	public static function health() {
		$h = get_option( self::HEALTH_OPTION, array() );
		return is_array( $h ) ? $h : array();
	}

	/**
	 * Update health counter.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public static function health_set( $key, $value ) {
		$h           = self::health();
		$h[ $key ]   = $value;
		update_option( self::HEALTH_OPTION, $h, false );
	}

	/**
	 * Discovery result cache (per post type).
	 *
	 * @param string|null $post_type Post type or null for all.
	 * @return array
	 */
	public static function discovery( $post_type = null ) {
		$data = get_option( self::DISCOVERY_OPTION, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		if ( null === $post_type ) {
			return $data;
		}
		return isset( $data[ $post_type ] ) ? $data[ $post_type ] : array();
	}

	/**
	 * Store discovery result for a post type.
	 *
	 * @param string $post_type Post type.
	 * @param array  $result    Result.
	 */
	public static function save_discovery( $post_type, array $result ) {
		$data                          = self::discovery();
		$data[ $post_type ]            = $result;
		update_option( self::DISCOVERY_OPTION, $data, false );
	}

	/**
	 * Delete all discovery data.
	 */
	public static function clear_discovery() {
		delete_option( self::DISCOVERY_OPTION );
	}
}

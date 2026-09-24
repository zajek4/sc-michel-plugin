<?php
/**
 * Source profile: the confirmed mapping configuration for one CPT.
 *
 * @package CPTSC
 */

namespace CPTSC\Mapping;

use CPTSC\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Source profile value object + repository.
 */
class SourceProfile {

	/**
	 * Row id.
	 *
	 * @var int
	 */
	public $id = 0;

	/**
	 * Post type.
	 *
	 * @var string
	 */
	public $post_type = '';

	/**
	 * product|service.
	 *
	 * @var string
	 */
	public $item_type = 'product';

	/**
	 * Field map: field name => mapping spec.
	 *
	 * @var array
	 */
	public $fields = array();

	/**
	 * draft|confirmed.
	 *
	 * @var string
	 */
	public $status = 'draft';

	/**
	 * Extra flags (availability policy, applicability, special sale mode…).
	 *
	 * @var array
	 */
	public $flags = array();

	/**
	 * Build from DB row / array.
	 *
	 * @param array $row Row.
	 * @return SourceProfile
	 */
	public static function from_array( array $row ) {
		$p              = new self();
		$p->id          = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$p->post_type   = isset( $row['post_type'] ) ? (string) $row['post_type'] : '';
		$p->item_type   = isset( $row['item_type'] ) ? (string) $row['item_type'] : 'product';
		$p->status      = isset( $row['status'] ) ? (string) $row['status'] : 'draft';
		$profile        = isset( $row['profile'] ) && is_string( $row['profile'] ) ? json_decode( $row['profile'], true ) : array();
		if ( ! is_array( $profile ) ) {
			$profile = array();
		}
		$p->fields = isset( $profile['fields'] ) && is_array( $profile['fields'] ) ? $profile['fields'] : array();
		$p->flags  = isset( $profile['flags'] ) && is_array( $profile['flags'] ) ? $profile['flags'] : array();
		return $p;
	}

	/**
	 * JSON payload for storage.
	 *
	 * @return string
	 */
	public function to_json() {
		return wp_json_encode(
			array(
				'fields' => $this->fields,
				'flags'  => $this->flags,
			)
		);
	}

	/**
	 * Is this profile confirmed?
	 *
	 * @return bool
	 */
	public function is_confirmed() {
		return 'confirmed' === $this->status;
	}

	/**
	 * Mapping spec for a logical field.
	 *
	 * @param string $field Field name.
	 * @return array|null
	 */
	public function field( $field ) {
		if ( isset( $this->fields[ $field ] ) && is_array( $this->fields[ $field ] ) ) {
			return $this->fields[ $field ];
		}
		return null;
	}

	/**
	 * Applicability for a field: applicable|not_applicable.
	 *
	 * @param string $field Field.
	 * @param string $default Default.
	 * @return string
	 */
	public function applicable( $field, $default = 'applicable' ) {
		if ( isset( $this->flags['applicability'][ $field ] ) ) {
			return $this->flags['applicability'][ $field ];
		}
		return $default;
	}

	/**
	 * Is field strictly required (not_applicable => skip validation)?
	 *
	 * @param string $field Field.
	 * @return bool
	 */
	public function is_applicable( $field ) {
		return 'applicable' === $this->applicable( $field );
	}

	/* ---------------------------------------------------------- repository */

	/**
	 * All profiles.
	 *
	 * @return SourceProfile[]
	 */
	public static function all() {
		global $wpdb;
		$table = Database::instance()->table( 'sources' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::from_array( $row );
		}
		return $out;
	}

	/**
	 * Confirmed profiles only.
	 *
	 * @return SourceProfile[]
	 */
	public static function confirmed() {
		$out = array();
		foreach ( self::all() as $p ) {
			if ( $p->is_confirmed() ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Profile by post type.
	 *
	 * @param string $post_type Post type.
	 * @return SourceProfile|null
	 */
	public static function find( $post_type ) {
		global $wpdb;
		$table = Database::instance()->table( 'sources' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE post_type = %s", $post_type ),
			ARRAY_A
		);
		return $row ? self::from_array( $row ) : null;
	}

	/**
	 * Profile by id.
	 *
	 * @param int $id ID.
	 * @return SourceProfile|null
	 */
	public static function find_by_id( $id ) {
		global $wpdb;
		$table = Database::instance()->table( 'sources' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ),
			ARRAY_A
		);
		return $row ? self::from_array( $row ) : null;
	}

	/**
	 * Insert or update a profile.
	 *
	 * @param SourceProfile $profile Profile.
	 * @return SourceProfile
	 */
	public static function save( SourceProfile $profile ) {
		global $wpdb;
		$table   = Database::instance()->table( 'sources' );
		$now     = current_time( 'mysql' );
		$data    = array(
			'post_type' => $profile->post_type,
			'item_type' => $profile->item_type,
			'profile'   => $profile->to_json(),
			'status'    => $profile->status,
			'updated_at'=> $now,
		);
		if ( $profile->id > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => $profile->id ) );
		} else {
			$existing = self::find( $profile->post_type );
			if ( $existing ) {
				$profile->id = $existing->id;
				$wpdb->update( $table, $data, array( 'id' => $profile->id ) );
			} else {
				$data['created_at'] = $now;
				$wpdb->insert( $table, $data );
				$profile->id = (int) $wpdb->insert_id;
			}
		}
		return $profile;
	}

	/**
	 * Delete a profile (does NOT touch items/anchors).
	 *
	 * @param int $id ID.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( Database::instance()->table( 'sources' ), array( 'id' => (int) $id ) );
	}

	/**
	 * All mapped meta/ACF keys across profiles (change-detection relevance).
	 *
	 * @return string[]
	 */
	public static function relevant_meta_keys() {
		$keys = array();
		foreach ( self::confirmed() as $p ) {
			foreach ( $p->fields as $spec ) {
				if ( ! isset( $spec['source'] ) ) {
					continue;
				}
				if ( in_array( $spec['source'], array( 'meta', 'acf' ), true ) && ! empty( $spec['key'] ) ) {
					$keys[] = (string) $spec['key'];
				}
			}
		}
		$keys = array_values( array_unique( $keys ) );
		return apply_filters( 'cptsc_relevant_meta_keys', $keys );
	}

	/**
	 * Relevant taxonomies across profiles + term rules.
	 *
	 * @return string[]
	 */
	public static function relevant_taxonomies() {
		$tax = array();
		foreach ( self::confirmed() as $p ) {
			foreach ( $p->fields as $spec ) {
				if ( isset( $spec['source'] ) && 'taxonomy' === $spec['source'] && ! empty( $spec['key'] ) ) {
					$tax[] = (string) $spec['key'];
				}
			}
		}
		foreach ( \CPTSC\Anchor\Rules::all() as $rule ) {
			$tax[] = $rule['taxonomy'];
		}
		$post_types = \CPTSC\Settings::get( 'post_types', array() );
		foreach ( (array) $post_types as $pt ) {
			foreach ( get_object_taxonomies( $pt ) as $t ) {
				$tax[] = $t;
			}
		}
		return array_values( array_unique( $tax ) );
	}
}

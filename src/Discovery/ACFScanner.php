<?php
/**
 * ACF field discovery — safe no-op when ACF is not installed.
 *
 * @package CPTSC
 */

namespace CPTSC\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ACF scanner.
 */
class ACFScanner {

	/**
	 * Scalar field types we understand natively.
	 *
	 * @var string[]
	 */
	private $scalar_types = array(
		'text',
		'number',
		'range',
		'price',
		'textarea',
		'select',
		'radio',
		'checkbox',
		'true_false',
		'date_picker',
		'date_time_picker',
		'color_picker',
		'url',
		'email',
		'phone',
	);

	/**
	 * Scalar fields attached to a post type.
	 *
	 * @param string $post_type Post type.
	 * @return array[]
	 */
	public function fields( $post_type ) {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return array();
		}
		$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );
		$out    = array();
		foreach ( (array) $groups as $group ) {
			$group_key = isset( $group['key'] ) ? $group['key'] : ( isset( $group['ID'] ) ? $group['ID'] : 0 );
			$fields    = function_exists( 'acf_get_fields' ) ? acf_get_fields( $group_key ) : array();
			foreach ( (array) $fields as $field ) {
				$type = isset( $field['type'] ) ? (string) $field['type'] : '';
				$entry = array(
					'group'      => isset( $group['title'] ) ? (string) $group['title'] : '',
					'field_key'  => isset( $field['key'] ) ? (string) $field['key'] : '',
					'name'       => isset( $field['name'] ) ? (string) $field['name'] : '',
					'label'      => isset( $field['label'] ) ? (string) $field['label'] : '',
					'type'       => $type,
					'scalar'     => in_array( $type, $this->scalar_types, true ),
					'instructions' => isset( $field['instructions'] ) ? (string) $field['instructions'] : '',
				);
				// Keep repeater/complex markers visible but flagged (adapter territory).
				if ( in_array( $type, array( 'repeater', 'flexible_content', 'gallery', 'clone' ), true ) ) {
					$entry['scalar'] = false;
					$entry['complex'] = true;
				}
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Whether ACF is available.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'acf_get_field_groups' );
	}
}

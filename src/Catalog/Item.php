<?php
/**
 * Normalized item value object.
 *
 * @package CPTSC
 */

namespace CPTSC\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalized catalog item.
 */
class Item {

	/**
	 * Row columns.
	 *
	 * @var array
	 */
	public $data = array();

	/**
	 * From DB row.
	 *
	 * @param array $row Row.
	 * @return Item
	 */
	public static function from_row( array $row ) {
		$item       = new self();
		$item->data = $row;
		return $item;
	}

	/**
	 * Column value.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
	}

	/**
	 * Has a verified, protected anchor?
	 *
	 * @return bool
	 */
	public function has_verified_anchor() {
		return ! empty( $this->data['anchor_verified'] ) && null !== $this->data['anchor_price'] && '' !== (string) $this->data['anchor_price'];
	}

	/**
	 * Issue keys.
	 *
	 * @return string[]
	 */
	public function issues() {
		return \CPTSC\Issues::decode( isset( $this->data['issue_mask'] ) ? (int) $this->data['issue_mask'] : 0 );
	}
}

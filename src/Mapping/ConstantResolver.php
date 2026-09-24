<?php
/**
 * Constant / manual / N-A spec helpers (values live inside the spec itself).
 *
 * @package CPTSC
 */

namespace CPTSC\Mapping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Constant resolver (kept as a discrete class per architecture outline).
 */
final class ConstantResolver {

	/**
	 * Resolve constant spec value.
	 *
	 * @param array $spec Spec.
	 * @return mixed
	 */
	public static function resolve( array $spec ) {
		return array_key_exists( 'value', $spec ) ? $spec['value'] : null;
	}
}

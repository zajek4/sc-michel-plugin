<?php
/**
 * Anchor groups: system-defined reference dates per NN 101/2026.
 *
 * @package CPTSC
 */

namespace CPTSC\Anchor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anchor groups registry.
 */
final class Groups {

	/**
	 * System groups.
	 *
	 * @return array key => {label, reference_date, system}
	 */
	public static function system() {
		return array(
			'legacy_fmcg'   => array(
				'label'          => __( 'FMCG (ranije obuhvaćene kategorije)', 'wp-cpt-sidrene-cijene' ),
				'reference_date' => '2025-05-02',
				'system'         => true,
				'description'    => __( 'Hrana, piće, kozmetika, sredstva za čišćenje, toaletne potrepštine i proizvodi za kućanstvo.', 'wp-cpt-sidrene-cijene' ),
			),
			'general_2026'  => array(
				'label'          => __( 'Svi ostali proizvodi i usluge', 'wp-cpt-sidrene-cijene' ),
				'reference_date' => '2026-09-10',
				'system'         => true,
				'description'    => __( 'Referentni datum za novobuhvaćene proizvode i sve usluge.', 'wp-cpt-sidrene-cijene' ),
			),
		);
	}

	/**
	 * All groups (system + admin-defined custom groups stored in term rules? none in v1).
	 *
	 * @return array
	 */
	public static function all() {
		$groups = self::system();
		/**
		 * Filter allows additional anchor groups (still requires an explicit reference date).
		 *
		 * @param array $groups Groups.
		 */
		return apply_filters( 'cptsc_anchor_groups', $groups );
	}

	/**
	 * Reference date for a group key.
	 *
	 * @param string $group Group key.
	 * @return string|null ISO date or null.
	 */
	public static function reference_date( $group ) {
		$all = self::all();
		return isset( $all[ $group ]['reference_date'] ) ? $all[ $group ]['reference_date'] : null;
	}

	/**
	 * Group label.
	 *
	 * @param string $group Group key.
	 * @return string
	 */
	public static function label( $group ) {
		$all = self::all();
		if ( ! isset( $all[ $group ] ) ) {
			return $group;
		}
		return $all[ $group ]['label'];
	}

	/**
	 * Select options for admin UI.
	 *
	 * @return array key => label (with date).
	 */
	public static function options() {
		$out = array();
		foreach ( self::all() as $key => $g ) {
			$out[ $key ] = sprintf(
				/* translators: 1: group label, 2: reference date in HR format */
				__( '%1$s — cijena na %2$s', 'wp-cpt-sidrene-cijene' ),
				$g['label'],
				\CPTSC\Dates::format_hr_date( $g['reference_date'] )
			);
		}
		return $out;
	}
}

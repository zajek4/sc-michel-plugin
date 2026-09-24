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
		$fmcg = array(
			'reference_date' => '2025-05-02',
			'system'         => true,
		);
		return array(
			'legacy_fmcg'   => array(
				'label'          => __( 'FMCG (ranije obuhvaćene kategorije)', 'wp-cpt-sidrene-cijene' ),
				'reference_date' => '2025-05-02',
				'system'         => true,
				'description'    => __( 'Hrana, piće, kozmetika, sredstva za čišćenje, toaletne potrepštine i proizvodi za kućanstvo.', 'wp-cpt-sidrene-cijene' ),
			),
			// Named NN 101/2026 FMCG categories (same reference date as legacy_fmcg —
			// offered for finer per-term mapping; never auto-assigned).
			'fmcg_hrana'    => array_merge( $fmcg, array( 'label' => __( 'Hrana', 'wp-cpt-sidrene-cijene' ) ) ),
			'fmcg_pice'     => array_merge( $fmcg, array( 'label' => __( 'Piće', 'wp-cpt-sidrene-cijene' ) ) ),
			'fmcg_kozmetika'=> array_merge( $fmcg, array( 'label' => __( 'Kozmetika', 'wp-cpt-sidrene-cijene' ) ) ),
			'fmcg_ciscenje' => array_merge( $fmcg, array( 'label' => __( 'Sredstva za čišćenje', 'wp-cpt-sidrene-cijene' ) ) ),
			'fmcg_toaleta'  => array_merge( $fmcg, array( 'label' => __( 'Toaletne potrepštine', 'wp-cpt-sidrene-cijene' ) ) ),
			'fmcg_kucanstvo'=> array_merge( $fmcg, array( 'label' => __( 'Proizvodi za kućanstvo', 'wp-cpt-sidrene-cijene' ) ) ),
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

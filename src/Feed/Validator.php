<?php
/**
 * Feed preflight validator — inspects a generated file BEFORE it can become current.
 *
 * @package CPTSC
 */

namespace CPTSC\Feed;

use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV preflight.
 */
final class Validator {

	/**
	 * Expected headers per item type.
	 *
	 * @param string $item_type product|service.
	 * @return string[]
	 */
	public static function expected_header( $item_type ) {
		if ( 'service' === $item_type ) {
			$header = array(
				'naziv_usluge',
				'maloprodajna_cijena',
				'posebni_oblik_prodaje',
				'naziv_posebnog_oblika_prodaje',
				'sidrena_cijena',
				'sidreni_datum',
			);
		} else {
			$header = array(
				'naziv',
				'sifra',
				'marka',
				'jedinica_mjere',
				'cijena_jedinice_mjere',
				'maloprodajna_cijena',
				'posebni_oblik_prodaje',
				'naziv_posebnog_oblika_prodaje',
				'sidrena_cijena',
				'sidreni_datum',
				'barkod',
				'dostupnost',
			);
		}
		return apply_filters( 'cptsc_feed_header', $header, $item_type );
	}

	/**
	 * Validate a generated temp file.
	 *
	 * @param string $path      File path.
	 * @param string $item_type product|service.
	 * @return array {ok: bool, errors: string[], warnings: string[], row_count: int, fingerprint: string, file_size: int}
	 */
	public static function preflight( $path, $item_type ) {
		$errors   = array();
		$warnings = array();
		$rows     = 0;

		if ( ! is_readable( $path ) ) {
			return array(
				'ok'           => false,
				'errors'       => array( 'Datoteka nije čitljiva.' ),
				'warnings'     => array(),
				'row_count'    => 0,
				'fingerprint'  => '',
				'file_size'    => 0,
			);
		}

		$delimiter = Settings::get( 'feed.delimiter', ';' );
		$fh        = fopen( $path, 'rb' );
		if ( ! $fh ) {
			return array(
				'ok'          => false,
				'errors'      => array( 'Datoteka se ne može otvoriti.' ),
				'warnings'    => array(),
				'row_count'   => 0,
				'fingerprint' => '',
				'file_size'   => 0,
			);
		}

		// Optional BOM.
		$expected = self::expected_header( $item_type );
		$first    = fgetcsv( $fh, 0, $delimiter );
		if ( false === $first ) {
			$errors[] = 'Datoteka je prazna.';
		} else {
			// Strip BOM from first cell.
			if ( isset( $first[0] ) ) {
				$first[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $first[0] );
			}
			$normalized = array_map( 'strval', $first );
			if ( $normalized !== array_map( 'strval', $expected ) ) {
				$errors[] = 'Header ne odgovara očekivanoj strukturi.';
			}
		}

		$expected_cols = count( $expected );
		while ( ( $line = fgetcsv( $fh, 0, $delimiter ) ) !== false ) {
			// Skip fully empty trailing lines.
			if ( 1 === count( $line ) && ( null === $line[0] || '' === trim( (string) $line[0] ) ) ) {
				continue;
			}
			$rows++;
			if ( count( $line ) !== $expected_cols ) {
				$errors[] = 'Redak ' . ( $rows + 1 ) . ' ima krivi broj stupaca.';
				if ( count( $errors ) > 20 ) {
					$errors[] = '…previše grešaka, prekidam.';
					break;
				}
			}
		}
		fclose( $fh );

		// Index health snapshot (critical rows must not be published).
		$counts = \CPTSC\Catalog\Index::counts();
		if ( $counts['blocked'] > 0 ) {
			$errors[] = sprintf( 'Katalog sadrži %d blokiranih stavki.', $counts['blocked'] );
		}
		if ( $counts['missing_anchor'] > 0 ) {
			$warnings[] = sprintf( '%d stavki nema sidrenu cijenu.', $counts['missing_anchor'] );
		}
		if ( 0 === $rows ) {
			$warnings[] = 'Cjenik nema nijedan redak.';
		}

		$fingerprint = hash_file( 'sha256', $path );
		$size        = (int) filesize( $path );

		return array(
			'ok'           => empty( $errors ),
			'errors'       => $errors,
			'warnings'     => $warnings,
			'row_count'    => $rows,
			'fingerprint'  => $fingerprint ? $fingerprint : '',
			'file_size'    => $size,
		);
	}
}

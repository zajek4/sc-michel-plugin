<?php
/**
 * Atomic CSV feed generation:
 * INDEX → TEMP → VALIDATION → ROW COUNT → SHA-256 → ARCHIVE COPY → ATOMIC CURRENT PUBLISH.
 * The production current file is never opened for writing before the new version is complete.
 *
 * @package CPTSC
 */

namespace CPTSC\Feed;

use CPTSC\Database;
use CPTSC\Money;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed generator.
 */
final class Generator {

	/**
	 * Worker hooks.
	 */
	public function hooks() {
		// Generation is job-driven; nothing to hook here.
	}

	/**
	 * Generate + publish for the given channels (or all enabled).
	 *
	 * @param int[]    $channel_ids Channels.
	 * @param bool     $force       Publish even when fingerprint unchanged.
	 * @param callable $progress    Progress callback(job_id, cursor, processed, total).
	 * @param int      $job_id      Job id for progress.
	 * @param int      $deadline    Deadline (single run; generation is streamed fast).
	 * @return true|\WP_Error
	 */
	public function generate_for_channels( array $channel_ids = array(), $force = false, $progress = null, $job_id = 0, $deadline = 0 ) {
		if ( empty( $channel_ids ) ) {
			foreach ( Channel::all( true ) as $ch ) {
				$channel_ids[] = $ch['id'];
			}
		}
		$item_type = Settings::get( 'item_type', 'product' );
		foreach ( $channel_ids as $cid ) {
			$result = $this->generate_one( (int) $cid, $item_type, (bool) $force, $progress, $job_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	/**
	 * Generate for one channel.
	 *
	 * @param int      $channel_id Channel.
	 * @param string   $item_type  product|service.
	 * @param bool     $force      Force publish.
	 * @param callable $progress   Progress.
	 * @param int      $job_id     Job id.
	 * @return true|\WP_Error
	 */
	public function generate_one( $channel_id, $item_type, $force = false, $progress = null, $job_id = 0 ) {
		$channel = Channel::find( $channel_id );
		if ( ! $channel ) {
			return new \WP_Error( 'cptsc_channel', 'Kanal ne postoji.' );
		}

		$dir = Archive::channel_dir( $channel_id );
		if ( ! Archive::ensure_dir( $dir ) || ! Archive::ensure_dir( Archive::tmp_dir() ) ) {
			return new \WP_Error( 'cptsc_storage', 'Spremište cjenika nije dostupno.' );
		}
		if ( ! is_writable( $dir ) ) {
			return new \WP_Error( 'cptsc_storage', 'Spremište cjenika nije zapisivo.' );
		}

		// 1) Stream rows to a temp file.
		$tmp = trailingslashit( Archive::tmp_dir() ) . 'feed-' . $channel_id . '-' . wp_generate_password( 12, false, false ) . '.csv.tmp';
		$fh  = fopen( $tmp, 'wb' );
		if ( ! $fh ) {
			return new \WP_Error( 'cptsc_write', 'Datoteka se ne može stvoriti.' );
		}

		$delimiter = Settings::get( 'feed.delimiter', ';' );
		if ( Settings::get( 'feed.bom', true ) ) {
			fwrite( $fh, "\xEF\xBB\xBF" );
		}

		$header = Validator::expected_header( $item_type );
		fputcsv( $fh, $header, $delimiter );

		$batch_size = (int) apply_filters( 'cptsc_feed_batch_size', 500 );
		$last_id    = 0;
		$rows       = 0;
		$processed  = 0;

		// Total for progress.
		global $wpdb;
		$table = Database::instance()->table( 'items' );
		$total = (int) $wpdb->get_var(
		 "SELECT COUNT(*) FROM {$table} WHERE validation_level < 2 AND publication_state = 'publish' AND object_id = canonical_object_id"
		);

		if ( $progress && $job_id ) {
			call_user_func( $progress, $job_id, 0, 0, $total );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- static table string only.
		while ( true ) {
			$batch = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE id > %d AND validation_level < 2 AND publication_state = 'publish' AND object_id = canonical_object_id ORDER BY id ASC LIMIT %d",
					$last_id,
					$batch_size
				),
				ARRAY_A
			);
			// phpcs:enable
			if ( empty( $batch ) ) {
				break;
			}
			foreach ( $batch as $item ) {
				$row = $this->map_row( $item, $item_type, $channel );
				/**
				 * Filter a feed row before writing.
				 *
				 * @param array $row     Associative row.
				 * @param array $item    Index item.
				 * @param array $channel Channel.
				 */
				$row = apply_filters( 'cptsc_feed_row', $row, $item, $channel );
				fputcsv( $fh, array_values( $row ), $delimiter );
				$rows++;
				$last_id = (int) $item['id'];
				$processed++;
			}
			if ( $progress && $job_id ) {
				call_user_func( $progress, $job_id, $last_id, $processed, $total );
			}
		}
		fclose( $fh );

		// 2) Preflight validation.
		$check = Validator::preflight( $tmp, $item_type );
		if ( ! $check['ok'] ) {
			@unlink( $tmp ); // phpcs:ignore
			$this->record_failure( $channel_id, $check );
			return new \WP_Error( 'cptsc_preflight', implode( ' ', $check['errors'] ) );
		}

		// 3) Fingerprint + recovery: skip ONLY if the public current file exists and
		// its on-disk hash still matches the last published fingerprint.
		$fingerprint  = $check['fingerprint'];
		$latest       = Archive::latest_published( $channel_id );
		$current_path = trailingslashit( Archive::channel_dir( $channel_id ) ) . Archive::current_filename( $channel_id );
		$current_ok   = false;
		if ( is_readable( $current_path ) ) {
			$current_fp = hash_file( 'sha256', $current_path );
			$current_ok = $current_fp && ! empty( $latest['fingerprint'] ) && hash_equals( (string) $latest['fingerprint'], (string) $current_fp );
		}
		if ( ! $force && $latest && ! empty( $latest['fingerprint'] ) && $latest['fingerprint'] === $fingerprint && $current_ok && Settings::get( 'feed.skip_identical', true ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return true; // Nothing changed AND current file is intact on disk.
		}
		// If fingerprint matched but current is missing/corrupt → fall through and restore.

		// 4) Archive filename per regulation: oblik_adresa_oznaka_broj_datum_i_vrijeme.csv
		$sequence  = Channel::next_sequence( $channel_id );
		$filename  = self::build_filename( $channel, $sequence );
		$archive_path = trailingslashit( Archive::channel_dir( $channel_id ) ) . $filename;

		// Guard: never overwrite an existing archive file.
		if ( file_exists( $archive_path ) ) {
			$filename     = self::build_filename( $channel, $sequence, true );
			$archive_path = trailingslashit( Archive::channel_dir( $channel_id ) ) . $filename;
		}

		// 5) Stage archive in the SAME directory, then atomic rename → final name.
		//    Never copy progressively into the public archive filename.
		$arch_stage = $archive_path . '.tmp';
		if ( ! @copy( $tmp, $arch_stage ) ) { // phpcs:ignore
			@unlink( $tmp ); // phpcs:ignore
			return new \WP_Error( 'cptsc_archive', 'Arhivska datoteka se ne može zapisati. Prethodna verzija ostaje dostupna.' );
		}
		if ( ! @rename( $arch_stage, $archive_path ) ) { // phpcs:ignore
			@unlink( $arch_stage ); // phpcs:ignore
			@unlink( $tmp ); // phpcs:ignore
			return new \WP_Error( 'cptsc_archive', 'Arhivska datoteka se ne može objaviti. Prethodna verzija ostaje dostupna.' );
		}

		// 6) Atomic current publish: stage in the SAME directory as current, then rename.
		//    NEVER copy() over the live file. If rename fails → FAIL, keep previous current.
		$stage_current = trailingslashit( Archive::channel_dir( $channel_id ) ) . '.stage-' . wp_generate_password( 12, false, false ) . '.csv';
		if ( ! @copy( $tmp, $stage_current ) ) { // phpcs:ignore
			@unlink( $tmp ); // phpcs:ignore
			// Archive file exists without DB record — reconcile will clean orphan.
			return new \WP_Error( 'cptsc_publish', 'Aktualni cjenik se ne može pripremiti. Prethodna verzija ostaje netaknuta.' );
		}
		@unlink( $tmp ); // phpcs:ignore
		$renamed = @rename( $stage_current, $current_path ); // phpcs:ignore
		if ( ! $renamed ) {
			@unlink( $stage_current ); // phpcs:ignore
			return new \WP_Error( 'cptsc_publish', 'Aktualni cjenik se ne može ažurirati. Prethodna verzija ostaje netaknuta.' );
		}

		// 7) Record published version.
		global $wpdb;
		$vt = Database::instance()->table( 'feed_versions' );
		$inserted = $wpdb->insert(
			$vt,
			array(
				'channel_id'         => $channel_id,
				'sequence'           => $sequence,
				'filename'           => $filename,
				'generated_at'       => current_time( 'mysql' ),
				'published_at'       => current_time( 'mysql' ),
				'row_count'          => $check['row_count'],
				'file_size'          => $check['file_size'],
				'fingerprint'        => $fingerprint,
				'status'             => 'published',
				'validation_errors'  => '',
				'validation_warnings'=> wp_json_encode( $check['warnings'] ),
				'created_at'         => current_time( 'mysql' ),
			)
		);

		if ( false === $inserted ) {
			// File publish succeeded but DB metadata failed — never claim clean success.
			Settings::health_set( 'feed_db_drift', 1 );
			Settings::health_set( 'last_feed_publish', 0 );
			return new \WP_Error(
				'cptsc_publish_db',
				'Cjenik je datotečno objavljen, ali zapis o verziji nije spremljen u bazu. Pokrenite usklađivanje u Dijagnostici.'
			);
		}

		// 8) Clear feed_dirty flags (cheap bulk).
		$wpdb->query( "UPDATE {$table} SET feed_dirty = 0" );

		Settings::health_set( 'last_feed_publish', time() );
		Settings::health_set( 'feed_retry_attempts', 0 ); // Successful publish ends the retry cycle.
		Settings::health_set( 'last_feed_fingerprint', $fingerprint );
		Settings::health_set( 'last_feed_filename', $filename );
		Settings::health_set( 'feed_db_drift', 0 );

		/**
		 * Fires after a feed version was published.
		 *
		 * @param int   $channel_id Channel.
		 * @param array $version    Version row info.
		 */
		do_action( 'cptsc_feed_published', $channel_id, array( 'filename' => $filename, 'rows' => $check['row_count'], 'fingerprint' => $fingerprint ) );
		return true;
	}

	/**
	 * Map one index item to a CSV row.
	 *
	 * @param array $item      Item.
	 * @param string $item_type Type.
	 * @param array $channel   Channel.
	 * @return array
	 */
	private function map_row( array $item, $item_type, array $channel ) {
		$special = ! empty( $item['special_sale'] ) ? 'da' : 'ne';
		if ( 'service' === $item_type ) {
			$row = array(
				'naziv_usluge'                     => (string) $item['name'],
				'maloprodajna_cijena'              => Money::format_csv( $item['current_price'] ),
				'posebni_oblik_prodaje'            => $special,
				'naziv_posebnog_oblika_prodaje'    => (string) ( $item['special_sale_name'] ?? '' ),
				'sidrena_cijena'                   => Money::format_csv( $item['anchor_price'] ),
				'sidreni_datum'                    => (string) ( $item['anchor_date'] ?? '' ),
			);
		} else {
			$availability = 'available' === ( $item['availability'] ?? '' ) ? 'dostupno' : ( 'unavailable' === ( $item['availability'] ?? '' ) ? 'nedostupno' : '' );
			$row          = array(
				'naziv'                            => (string) $item['name'],
				'sifra'                            => (string) ( $item['code'] ?? '' ),
				'marka'                            => (string) ( $item['brand'] ?? '' ),
				'jedinica_mjere'                   => (string) ( $item['unit'] ?? '' ),
				'cijena_jedinice_mjere'            => Money::format_csv( $item['unit_price'] ),
				'maloprodajna_cijena'              => Money::format_csv( $item['current_price'] ),
				'posebni_oblik_prodaje'            => $special,
				'naziv_posebnog_oblika_prodaje'    => (string) ( $item['special_sale_name'] ?? '' ),
				'sidrena_cijena'                   => Money::format_csv( $item['anchor_price'] ),
				'sidreni_datum'                    => (string) ( $item['anchor_date'] ?? '' ),
				'barkod'                           => (string) ( $item['barcode'] ?? '' ),
				'dostupnost'                       => $availability,
			);
		}
		return $row;
	}

	/**
	 * Regulation filename:
	 * oblik_adresa_oznaka_broj_pohrane_DD.MM.YYYY_HH:MM.csv
	 *
	 * @param array $channel  Channel.
	 * @param int   $sequence Storage sequence.
	 * @param bool  $suffix_tie Ensure uniqueness.
	 * @return string
	 */
	public static function build_filename( array $channel, $sequence, $suffix_tie = false ) {
		$kind = $channel['object_kind'] ? $channel['object_kind'] : 'objekt';
		$addr = $channel['address'] ? $channel['address'] : 'web';
		$code = $channel['object_code'] ? $channel['object_code'] : 'P-01';

		$addr = mb_strtolower( trim( (string) $addr ) );
		if ( function_exists( 'iconv' ) ) {
			$conv = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $addr ); // phpcs:ignore
			if ( false !== $conv ) {
				$addr = $conv;
			}
		}
		$addr = trim( preg_replace( '/[^a-z0-9]+/i', '-', $addr ), '-' );

		$time = current_time( 'H:i' );
		$date = current_time( 'd.m.Y' );
		if ( $suffix_tie ) {
			$time = current_time( 'H:i:s' );
			$time = str_replace( ':', '-', $time );
		}

		$name = sprintf(
			'%s_%s_%s_%d_%s_%s.csv',
			preg_replace( '/[^a-z0-9_-]+/i', '-', (string) $kind ),
			$addr,
			preg_replace( '/[^A-Z0-9_-]+/i', '-', (string) $code ),
			max( 1, (int) $sequence ),
			$date,
			$time
		);
		$name = preg_replace( '/[^A-Za-z0-9._:-]+/', '-', $name );
		$name = preg_replace( '/-+/', '-', $name );
		return substr( (string) $name, 0, 240 );
	}

	/**
	 * Record a failed generation attempt.
	 *
	 * @param int   $channel_id Channel.
	 * @param array $check      Preflight result.
	 */
	private function record_failure( $channel_id, array $check ) {
		global $wpdb;
		$vt = Database::instance()->table( 'feed_versions' );
		$wpdb->insert(
			$vt,
			array(
				'channel_id'          => (int) $channel_id,
				'sequence'            => 0,
				'filename'            => '',
				'generated_at'        => current_time( 'mysql' ),
				'published_at'        => null,
				'row_count'           => (int) $check['row_count'],
				'file_size'           => 0,
				'fingerprint'         => '',
				'status'              => 'failed',
				'validation_errors'   => wp_json_encode( $check['errors'] ),
				'validation_warnings' => wp_json_encode( $check['warnings'] ),
				'created_at'          => current_time( 'mysql' ),
			)
		);
	}
}

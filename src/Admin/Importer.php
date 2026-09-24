<?php
/**
 * CSV anchor import: upload → dry-run preview → batch job.
 * Matching: 1) object ID, 2) confirmed unique code. Name is never a key.
 *
 * @package CPTSC
 */

namespace CPTSC\Admin;

use CPTSC\Anchor\Audit;
use CPTSC\Anchor\Groups;
use CPTSC\Database;
use CPTSC\Money;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV importer.
 */
final class Importer {

	/**
	 * Temp file option key holding the staged upload path hash.
	 */
	const STAGED = 'cptsc_import_staged';

	/**
	 * Upload error to display.
	 *
	 * @var string|null
	 */
	private $upload_error = null;

	/**
	 * Render import page.
	 */
	public function render() {
		// Process form POSTs first (same-page forms).
		$this->handle_form_upload();
		$this->handle_start();

		$staged = get_option( self::STAGED, null );
		$preview = is_array( $staged ) ? $this->preview( $staged['path'] ) : null;
		$active_job = $this->active_job();
		?>
		<div class="wrap cptsc-wrap">
			<h1><?php esc_html_e( 'Uvoz sidrenih podataka', 'wp-cpt-sidrene-cijene' ); ?></h1>

			<div class="cptsc-panel">
				<h2><?php esc_html_e( '1. Priprema datoteke', 'wp-cpt-sidrene-cijene' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'CSV stupci (redoslijed nije bitan, header mora postojati):', 'wp-cpt-sidrene-cijene' ); ?>
					<br><code>object_id, sifra, sidrena_cijena, sidreni_datum, sidrena_grupa</code>
					<br><?php esc_html_e( 'Prazna sidrena cijena = preskoči redak (ništa se ne mijenja, ništa se ne briše). Za namjerno brisanje sidrenog podatka upišite OBRISANO u stupac cijene (uz potvrdu prepisivanja za potvrđene podatke).', 'wp-cpt-sidrene-cijene' ); ?>
				</p>
				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'cptsc_import_upload' ); ?>
					<p>
						<input type="file" name="cptsc_csv" accept=".csv,text/csv" required>
						<button class="button button-primary" type="submit" name="cptsc_do_upload" value="1"><?php esc_html_e( 'Preuzmi i provjeri', 'wp-cpt-sidrene-cijene' ); ?></button>
					</p>
				</form>
				<?php if ( isset( $this->upload_error ) ) : ?>
					<div class="notice notice-error"><p><?php echo esc_html( $this->upload_error ); ?></p></div>
				<?php endif; ?>
			</div>

			<?php if ( $preview && ! is_wp_error( $preview ) ) : ?>
				<div class="cptsc-panel">
					<h2><?php esc_html_e( '2. Probna provjera (dry run)', 'wp-cpt-sidrene-cijene' ); ?></h2>
					<table class="widefat striped cptsc-table">
						<tbody>
							<tr><th><?php esc_html_e( 'Redaka u datoteci', 'wp-cpt-sidrene-cijene' ); ?></th><td><?php echo esc_html( number_format_i18n( $preview['total'] ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Podudarno (object ID)', 'wp-cpt-sidrene-cijene' ); ?></th><td><?php echo esc_html( number_format_i18n( $preview['matched_id'] ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Podudarno (šifra)', 'wp-cpt-sidrene-cijene' ); ?></th><td><?php echo esc_html( number_format_i18n( $preview['matched_code'] ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Nije pronađeno', 'wp-cpt-sidrene-cijene' ); ?></th><td><?php echo esc_html( number_format_i18n( $preview['unmatched'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Sukobi (postojeći potvrđeni podatak)', 'wp-cpt-sidrene-cijene' ); ?></th><td><?php echo esc_html( number_format_i18n( $preview['conflicts'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Za eksplicitno brisanje (OBRISANO)', 'wp-cpt-sidrene-cijene' ); ?></th><td><?php echo esc_html( number_format_i18n( $preview['to_clear'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Preskočeno (prazna cijena — bez izmjene)', 'wp-cpt-sidrene-cijene' ); ?></th><td><?php echo esc_html( number_format_i18n( $preview['skipped'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Neispravni redci', 'wp-cpt-sidrene-cijene' ); ?></th><td><?php echo esc_html( number_format_i18n( $preview['invalid'] ) ); ?></td></tr>
						</tbody>
					</table>

					<?php if ( ! empty( $preview['sample'] ) ) : ?>
						<h3><?php esc_html_e( 'Prvih redaka', 'wp-cpt-sidrene-cijene' ); ?></h3>
						<table class="widefat striped cptsc-table">
							<thead><tr><th>object_id</th><th><?php esc_html_e( 'šifra', 'wp-cpt-sidrene-cijene' ); ?></th><th><?php esc_html_e( 'sidrena cijena', 'wp-cpt-sidrene-cijene' ); ?></th><th><?php esc_html_e( 'datum', 'wp-cpt-sidrene-cijene' ); ?></th><th><?php esc_html_e( 'status', 'wp-cpt-sidrene-cijene' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $preview['sample'] as $s ) : ?>
								<tr>
									<td><?php echo esc_html( $s['object_id'] ); ?></td>
									<td><?php echo esc_html( $s['code'] ); ?></td>
									<td><?php echo esc_html( $s['price'] ); ?></td>
									<td><?php echo esc_html( $s['date'] ); ?></td>
									<td><?php echo esc_html( $s['status'] ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<form method="post">
						<?php wp_nonce_field( 'cptsc_import_start' ); ?>
						<label class="cptsc-check">
							<input type="checkbox" name="overwrite" value="1">
							<?php esc_html_e( 'Prepiši postojeće potvrđene sidrene podatke (bilježi se u povijest)', 'wp-cpt-sidrene-cijene' ); ?>
						</label>
						<p>
							<button class="button button-primary" type="submit" name="cptsc_start_import" value="1" <?php disabled( 0 === $preview['total'] ); ?>>
								<?php esc_html_e( 'Pokreni uvoz', 'wp-cpt-sidrene-cijene' ); ?>
							</button>
							<button class="button" type="submit" name="cptsc_cancel_import" value="1"><?php esc_html_e( 'Odustani', 'wp-cpt-sidrene-cijene' ); ?></button>
						</p>
					</form>
				</div>
			<?php endif; ?>

			<?php if ( $active_job ) : ?>
				<div class="cptsc-panel cptsc-job-panel" data-job="<?php echo (int) $active_job['job_id']; ?>">
					<h2><?php esc_html_e( '3. Uvoz u tijeku', 'wp-cpt-sidrene-cijene' ); ?></h2>
					<p class="cptsc-job-progress">
						<?php echo esc_html( sprintf( '%d / %d', (int) $active_job['processed'], (int) $active_job['total'] ) ); ?>
					</p>
					<div class="cptsc-progress"><div class="cptsc-progress-bar" style="width:<?php echo $active_job['total'] > 0 ? esc_attr( round( 100 * $active_job['processed'] / max( 1, $active_job['total'] ) ) ) : 0; ?>%"></div></div>
					<p class="description cptsc-job-status"><?php echo esc_html( Dashboard::status_label( $active_job['status'] ) ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle upload via form POST.
	 */
	public function handle_form_upload() {
		if ( ! isset( $_POST['cptsc_do_upload'], $_POST['_wpnonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cptsc_import_upload' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->store_upload();
	}

	/**
	 * AJAX upload handler.
	 */
	public function handle_upload() {
		if ( empty( $_FILES['cptsc_csv'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Nema datoteke.', 'wp-cpt-sidrene-cijene' ) ) );
		}
		$this->store_upload_ajax();
	}

	/**
	 * Non-AJAX upload storage.
	 */
	private function store_upload() {
		if ( empty( $_FILES['cptsc_csv'] ) || ! isset( $_FILES['cptsc_csv']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['cptsc_csv']['error'] ) {
			$this->upload_error = __( 'Datoteka nije ispravno prenesena.', 'wp-cpt-sidrene-cijene' );
			return;
		}
		$path = $this->sanitize_upload( $_FILES['cptsc_csv'] );
		if ( is_wp_error( $path ) ) {
			$this->upload_error = $path->get_error_message();
			return;
		}
		update_option( self::STAGED, array( 'path' => $path, 'time' => time() ), false );
	}

	/**
	 * AJAX upload storage.
	 */
	private function store_upload_ajax() {
		$file = $_FILES['cptsc_csv']; // phpcs:ignore
		if ( empty( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			wp_send_json_error( array( 'message' => __( 'Prijenos datoteke nije uspio.', 'wp-cpt-sidrene-cijene' ) ) );
		}
		$path = $this->sanitize_upload( $file );
		if ( is_wp_error( $path ) ) {
			wp_send_json_error( array( 'message' => $path->get_error_message() ) );
		}
		update_option( self::STAGED, array( 'path' => $path, 'time' => time() ), false );
		$preview = $this->preview( $path );
		if ( is_wp_error( $preview ) ) {
			wp_send_json_error( array( 'message' => $preview->get_error_message() ) );
		}
		wp_send_json_success( $preview );
	}

	/**
	 * Validate + move upload into private temp storage.
	 *
	 * @param array $file $_FILES entry.
	 * @return string|\WP_Error
	 */
	private function sanitize_upload( $file ) {
		if ( ! isset( $file['size'] ) || (int) $file['size'] > 32 * MB_IN_BYTES ) {
			return new \WP_Error( 'cptsc_size', __( 'Datoteka je veća od 32 MB.', 'wp-cpt-sidrene-cijene' ) );
		}
		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			return new \WP_Error( 'cptsc_ext', __( 'Dozvoljene su samo .csv datoteke.', 'wp-cpt-sidrene-cijene' ) );
		}
		$check = function_exists( 'wp_check_filetype_and_ext' ) ? wp_check_filetype_and_ext( $file['tmp_name'], $name ) : array( 'ext' => 'csv' );
		$mime  = isset( $file['type'] ) ? (string) $file['type'] : '';
		$allowed_mime = array( 'text/csv', 'text/plain', 'application/csv', 'application/octet-stream', 'application/vnd.ms-excel', '' );
		if ( ! empty( $mime ) && ! in_array( $mime, $allowed_mime, true ) ) {
			return new \WP_Error( 'cptsc_mime', __( 'Neispravna vrsta datoteke.', 'wp-cpt-sidrene-cijene' ) );
		}
		$dir = \CPTSC\Feed\Archive::tmp_dir();
		if ( ! \CPTSC\Feed\Archive::ensure_dir( $dir ) ) {
			return new \WP_Error( 'cptsc_dir', __( 'Privremena mapa nije dostupna.', 'wp-cpt-sidrene-cijene' ) );
		}
		$target = trailingslashit( $dir ) . 'import-' . wp_generate_password( 16, false, false ) . '.csv';
		$moved  = @move_uploaded_file( $file['tmp_name'], $target ); // phpcs:ignore
		if ( ! $moved ) {
			// Fallback for non-upload contexts (CLI/tests).
			if ( ! @copy( $file['tmp_name'], $target ) ) { // phpcs:ignore
				return new \WP_Error( 'cptsc_move', __( 'Datoteku nije moguće spremiti.', 'wp-cpt-sidrene-cijene' ) );
			}
		}
		@chmod( $target, 0644 ); // phpcs:ignore
		return $target;
	}

	/**
	 * Dry-run preview.
	 *
	 * @param string $path Path.
	 * @return array|\WP_Error
	 */
	public function preview( $path ) {
		if ( ! is_readable( $path ) ) {
			return new \WP_Error( 'cptsc_missing', __( 'Privremena datoteka više nije dostupna. Prenesite je ponovno.', 'wp-cpt-sidrene-cijene' ) );
		}
		$fh = fopen( $path, 'rb' );
		if ( ! $fh ) {
			return new \WP_Error( 'cptsc_open', __( 'Datoteka se ne može otvoriti.', 'wp-cpt-sidrene-cijene' ) );
		}
		$header = fgetcsv( $fh, 0, ';' );
		if ( ! $header || count( $header ) < 2 ) {
			rewind( $fh );
			$header = fgetcsv( $fh, 0, ',' );
		}
		if ( ! $header ) {
			fclose( $fh );
			return new \WP_Error( 'cptsc_header', __( 'CSV nema header redak.', 'wp-cpt-sidrene-cijene' ) );
		}
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$map       = $this->map_header( array_map( 'strval', $header ) );
		if ( false === $map['price'] ) {
			fclose( $fh );
			return new \WP_Error( 'cptsc_cols', __( 'Nije pronađen stupac sidrene cijene (sidrena_cijena / anchor_price).', 'wp-cpt-sidrene-cijene' ) );
		}

		$total = $matched_id = $matched_code = $unmatched = $conflicts = $invalid = $skipped = $to_clear = 0;
		$sample = array();
		$code_index = $this->unique_code_index();

		while ( ( $line = fgetcsv( $fh, 0, ';' ) ) !== false ) {
			if ( 1 === count( $line ) && ( null === $line[0] || '' === trim( (string) $line[0] ) ) ) {
				continue;
			}
			$total++;
			$row = $this->parse_line( $line, $map );
			$status = $this->classify( $row, $code_index );
			if ( 'skipped' === $status['status'] ) {
				$skipped++;
			} elseif ( ! empty( $row['clear'] ) && in_array( $status['status'], array( 'matched_id', 'matched_code' ), true ) ) {
				$to_clear++;
			} else {
				switch ( $status['status'] ) {
					case 'matched_id':
						$matched_id++;
						break;
					case 'matched_code':
						$matched_code++;
						break;
					case 'unmatched':
						$unmatched++;
						break;
					case 'conflict':
						$conflicts++;
						break;
					default:
						$invalid++;
				}
			}
			if ( count( $sample ) < 10 ) {
				$sample[] = array(
					'object_id' => (string) $row['object_id'],
					'code'      => (string) $row['code'],
					'price'     => ! empty( $row['clear'] ) ? 'OBRISANO' : (string) $row['price'],
					'date'      => (string) $row['date'],
					'status'    => $status['label'],
				);
			}
		}
		fclose( $fh );

		return array(
			'total'        => $total,
			'matched_id'   => $matched_id,
			'matched_code' => $matched_code,
			'unmatched'    => $unmatched,
			'conflicts'    => $conflicts,
			'skipped'      => $skipped,
			'to_clear'     => $to_clear,
			'invalid'      => $invalid,
			'sample'       => $sample,
		);
	}

	/**
	 * Start import job (form POST).
	 */
	public function handle_start() {
		if ( isset( $_POST['cptsc_cancel_import'] ) ) {
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cptsc_import_start' ) ) {
				$staged = get_option( self::STAGED, null );
				if ( is_array( $staged ) && is_readable( $staged['path'] ) ) {
					@unlink( $staged['path'] ); // phpcs:ignore
				}
				delete_option( self::STAGED );
			}
			return;
		}
		if ( ! isset( $_POST['cptsc_start_import'], $_POST['_wpnonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cptsc_import_start' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$staged = get_option( self::STAGED, null );
		if ( ! is_array( $staged ) || ! is_readable( $staged['path'] ) ) {
			return;
		}
		$job_id = \CPTSC\Jobs\Manager::enqueue(
			'CSV_IMPORT',
			array(
				'path'      => $staged['path'],
				'overwrite' => ! empty( $_POST['overwrite'] ),
			),
			true
		);
		set_transient( 'cptsc_import_job', $job_id, HOUR_IN_SECONDS );
	}

	/**
	 * AJAX start.
	 */
	public function handle_start_ajax() {
		$this->handle_start();
		$job_id = (int) get_transient( 'cptsc_import_job' );
		wp_send_json_success( array( 'job_id' => $job_id ) );
	}

	/**
	 * Active import job for UI.
	 *
	 * @return array|null
	 */
	private function active_job() {
		$job_id = (int) get_transient( 'cptsc_import_job' );
		if ( ! $job_id ) {
			return null;
		}
		$job = \CPTSC\Jobs\Manager::get( $job_id );
		if ( ! $job || in_array( $job['status'], array( 'completed', 'failed', 'cancelled' ), true ) ) {
			if ( $job && 'completed' === $job['status'] ) {
				delete_transient( 'cptsc_import_job' );
			}
			return null;
		}
		return $job;
	}

	/**
	 * Job batch processing (called by Worker).
	 *
	 * @param array $job      Job.
	 * @param array $payload  Payload.
	 * @param int   $deadline Deadline.
	 * @return bool Finished.
	 * @throws \RuntimeException On file error.
	 */
	public function process_job( array $job, array $payload, $deadline ) {
		global $wpdb;
		$path = isset( $payload['path'] ) ? (string) $payload['path'] : '';
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( 'Import datoteka nije dostupna.' );
		}
		$overwrite = ! empty( $payload['overwrite'] );
		$fh        = fopen( $path, 'rb' );
		if ( ! $fh ) {
			throw new \RuntimeException( 'Import datoteka se ne može otvoriti.' );
		}

		$header = fgetcsv( $fh, 0, ';' );
		if ( ! $header || count( $header ) < 2 ) {
			rewind( $fh );
			$header = fgetcsv( $fh, 0, ',' );
		}
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$map       = $this->map_header( array_map( 'strval', (array) $header ) );

		// Seek to cursor row (row number after header).
		$cursor = (int) $job['cursor'];
		$seeked = 0;
		while ( $seeked < $cursor && ( $discard = fgetcsv( $fh, 0, ';' ) ) !== false ) {
			$seeked++;
		}

		$code_index   = $this->unique_code_index();
		$items        = Database::instance()->table( 'items' );
		$batch        = (int) apply_filters( 'cptsc_import_batch', 200 );
		$processed    = (int) $job['processed'];
		$failed       = (int) $job['failed'];
		$rows_done    = $cursor;
		$user         = get_current_user_id();

		for ( $i = 0; $i < $batch; $i++ ) {
			$line = fgetcsv( $fh, 0, ';' );
			if ( $line === false ) {
				fclose( $fh );
				// Finished.
				$path_to_delete = $path;
				update_option( self::STAGED, '', false );
				delete_option( self::STAGED );
				@unlink( $path_to_delete ); // phpcs:ignore
				\CPTSC\Jobs\Manager::progress( $job['job_id'], $rows_done, $processed, null, $failed );
				return true;
			}
			if ( 1 === count( $line ) && ( null === $line[0] || '' === trim( (string) $line[0] ) ) ) {
				$rows_done++;
				continue;
			}
			$row   = $this->parse_line( $line, $map );
			$status = $this->classify( $row, $code_index );
			if ( 'skipped' === $status['status'] ) {
				// Blank price: row handled as explicit no-op — never deletes, never fails.
				$rows_done++;
				continue;
			}
			if ( in_array( $status['status'], array( 'matched_id', 'matched_code' ), true ) && ! empty( $status['item_id'] ) ) {
				$this->apply_anchor( (int) $status['item_id'], $row, $overwrite, $user );
				$processed++;
			} elseif ( 'conflict' === $status['status'] && $overwrite && ! empty( $status['item_id'] ) ) {
				$this->apply_anchor( (int) $status['item_id'], $row, true, $user );
				$processed++;
			} else {
				$failed++;
			}
			$rows_done++;
			if ( time() >= $deadline ) {
				break;
			}
		}
		fclose( $fh );
		\CPTSC\Jobs\Manager::progress( $job['job_id'], $rows_done, $processed, null, $failed );
		return false;
	}

	/**
	 * Apply one anchor row.
	 *
	 * @param int    $item_id   Item row id.
	 * @param array  $row       Parsed row.
	 * @param bool   $overwrite  Allow overwrite.
	 * @param int    $user      User id.
	 */
	private function apply_anchor( $item_id, array $row, $overwrite, $user ) {
		global $wpdb;
		$items = Database::instance()->table( 'items' );
		$item  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$items} WHERE id = %d", $item_id ), ARRAY_A );
		if ( ! $item ) {
			return;
		}
		// Defense in depth: blank price without the explicit marker never writes.
		if ( empty( $row['clear'] ) && ( null === $row['price'] || '' === $row['price'] ) ) {
			return;
		}
		// Explicit OBRISANO marker: deliberate anchor removal (audited).
		if ( ! empty( $row['clear'] ) ) {
			$had_anchor = null !== $item['anchor_price'] || null !== $item['anchor_date'] || ! empty( $item['anchor_verified'] );
			if ( ! $had_anchor ) {
				return; // Nothing to clear.
			}
			$already_verified = ! empty( $item['anchor_verified'] ) && null !== $item['anchor_price'];
			if ( $already_verified && ! $overwrite ) {
				return; // Verified anchor requires overwrite permission to clear.
			}
			$wpdb->update(
				$items,
				array(
					'anchor_price'    => null,
					'anchor_date'     => null,
					'anchor_verified' => 0,
					'anchor_source'   => 'csv_import',
					'changed_at'      => current_time( 'mysql' ),
					'feed_dirty'      => 1,
				),
				array( 'id' => $item_id )
			);
			Audit::instance()->log(
				(int) $item['object_id'],
				'ANCHOR_CHANGED',
				wp_json_encode( array( $item['anchor_price'], $item['anchor_date'] ) ),
				wp_json_encode( array( null, null ) ),
				'csv_import',
				$user
			);
			do_action( 'cptsc_csv_import_applied', (int) $item['object_id'], $row );
			return;
		}
		$already_verified = ! empty( $item['anchor_verified'] ) && null !== $item['anchor_price'];
		$price_changed    = ! Money::eq( (string) $item['anchor_price'], $row['price'] );
		$date_changed     = (string) $item['anchor_date'] !== (string) $row['date'];

		if ( ( $already_verified && ( $price_changed || $date_changed ) && ! $overwrite ) ) {
			return; // Conflict without overwrite permission.
		}
		if ( $already_verified && ! $price_changed && ! $date_changed ) {
			return; // No-op.
		}

		$group = $row['group'];
		if ( ! $group ) {
			$group = $item['anchor_group'] ? $item['anchor_group'] : Settings::get( 'default_anchor_group', 'general_2026' );
		}
		if ( $group && ! isset( Groups::all()[ $group ] ) ) {
			$group = Settings::get( 'default_anchor_group', 'general_2026' );
		}

		$wpdb->update(
			$items,
			array(
				'anchor_price'    => $row['price'],
				'anchor_date'     => $row['date'],
				'anchor_group'    => $group,
				'anchor_source'   => 'csv_import',
				'anchor_verified' => 1,
				'changed_at'      => current_time( 'mysql' ),
				'feed_dirty'      => 1,
			),
			array( 'id' => $item_id )
		);
		Audit::instance()->log(
			(int) $item['object_id'],
			$already_verified ? 'ANCHOR_CHANGED' : 'ANCHOR_SET',
			wp_json_encode( array( $item['anchor_price'], $item['anchor_date'] ) ),
			wp_json_encode( array( $row['price'], $row['date'] ) ),
			'csv_import',
			$user
		);
		do_action( 'cptsc_csv_import_applied', (int) $item['object_id'], $row );
	}

	/**
	 * Map header names to column indexes.
	 *
	 * @param string[] $header Header.
	 * @return array
	 */
	private function map_header( array $header ) {
		$aliases = array(
			'object_id' => array( 'object_id', 'post_id', 'id', 'objekt_id' ),
			'code'      => array( 'sifra', 'šifra', 'code', 'sku' ),
			'price'     => array( 'sidrena_cijena', 'anchor_price', 'dodatna_cijena', 'cijena' ),
			'date'      => array( 'sidreni_datum', 'anchor_date', 'datum' ),
			'group'     => array( 'sidrena_grupa', 'anchor_group', 'grupa' ),
		);
		$map = array(
			'object_id' => false,
			'code'      => false,
			'price'     => false,
			'date'      => false,
			'group'     => false,
		);
		foreach ( $header as $i => $name ) {
			$clean = strtolower( trim( (string) $name ) );
			foreach ( $aliases as $field => $list ) {
				if ( in_array( $clean, $list, true ) && false === $map[ $field ] ) {
					$map[ $field ] = (int) $i;
				}
			}
		}
		return $map;
	}

	/**
	 * Parse one CSV line.
	 *
	 * @param string[] $line Line.
	 * @param array    $map  Map.
	 * @return array
	 */
	private function parse_line( array $line, array $map ) {
		$get = function ( $field ) use ( $line, $map ) {
			$idx = $map[ $field ];
			return false === $idx || ! isset( $line[ $idx ] ) ? null : trim( (string) $line[ $idx ] );
		};
		$object_id = $get( 'object_id' );
		$price_raw = $get( 'price' );
		$date_raw  = $get( 'date' );
		// Explicit deletion marker (deliberate intent) — distinct from blank.
		$is_clear  = null !== $price_raw && in_array( strtoupper( trim( $price_raw ) ), array( 'OBRISANO', 'OBRISI', 'CLEAR' ), true );
		$price     = $is_clear ? null : Money::to_string( $price_raw );
		$date      = null;
		if ( $date_raw ) {
			if ( \CPTSC\Dates::is_iso_date( $date_raw ) ) {
				$date = $date_raw;
			} elseif ( preg_match( '#^(\d{1,2})[./](\d{1,2})[./](\d{4})\.?$#', $date_raw, $m ) ) {
				$date = sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1] );
			}
		}
		return array(
			'object_id' => null !== $object_id && '' !== $object_id && ctype_digit( (string) $object_id ) ? (int) $object_id : null,
			'code'      => $get( 'code' ),
			'price'     => $price,
			'date'      => $date,
			'group'     => $get( 'group' ) ? sanitize_key( (string) $get( 'group' ) ) : null,
			'clear'     => $is_clear,
		);
	}

	/**
	 * Unique code → item id index (only codes appearing exactly once).
	 *
	 * @return array
	 */
	private function unique_code_index() {
		global $wpdb;
		$items = Database::instance()->table( 'items' );
		$index = array();
		// Codes appearing exactly once (portable, no window functions).
		$rows = $wpdb->get_results(
			"SELECT MIN(id) AS id, code FROM {$items} WHERE code IS NOT NULL AND code <> '' GROUP BY code HAVING COUNT(*) = 1",
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$index[ (string) $row['code'] ] = (int) $row['id'];
		}
		return $index;
	}

	/**
	 * Classify a parsed row.
	 *
	 * @param array $row        Row.
	 * @param array $code_index Unique codes.
	 * @return array {status, label, item_id}
	 */
	private function classify( array $row, array $code_index ) {
		$is_clear = ! empty( $row['clear'] );
		// Blank price (no marker): explicit no-op — never a delete, never a failure.
		if ( ! $is_clear && null === $row['price'] ) {
			return array( 'status' => 'skipped', 'label' => __( 'preskočeno — prazna cijena', 'wp-cpt-sidrene-cijene' ), 'item_id' => 0 );
		}
		// Price present but date missing (clear rows don't need a date).
		if ( ! $is_clear && null === $row['date'] ) {
			return array( 'status' => 'invalid', 'label' => __( 'neispravan redak', 'wp-cpt-sidrene-cijene' ), 'item_id' => 0 );
		}
		global $wpdb;
		$items = Database::instance()->table( 'items' );

		if ( null !== $row['object_id'] ) {
			$item = $wpdb->get_row(
				$wpdb->prepare( "SELECT id, anchor_price, anchor_date, anchor_verified FROM {$items} WHERE object_id = %d", $row['object_id'] ),
				ARRAY_A
			);
			if ( $item ) {
				$verified = ! empty( $item['anchor_verified'] ) && null !== $item['anchor_price'];
				if ( $is_clear ) {
					// Clearing a verified anchor needs overwrite permission (shown as conflict).
					if ( $verified ) {
						return array( 'status' => 'conflict', 'label' => __( 'sukob (brisanje potvrđenog)', 'wp-cpt-sidrene-cijene' ), 'item_id' => (int) $item['id'] );
					}
					return array( 'status' => 'matched_id', 'label' => 'object_id', 'item_id' => (int) $item['id'] );
				}
				$differs = ! Money::eq( (string) $item['anchor_price'], $row['price'] ) || (string) $item['anchor_date'] !== $row['date'];
				if ( $verified && $differs ) {
					return array( 'status' => 'conflict', 'label' => __( 'sukob', 'wp-cpt-sidrene-cijene' ), 'item_id' => (int) $item['id'] );
				}
				return array( 'status' => 'matched_id', 'label' => 'object_id', 'item_id' => (int) $item['id'] );
			}
		}

		if ( $row['code'] && isset( $code_index[ $row['code'] ] ) ) {
			$item_id = $code_index[ $row['code'] ];
			$item    = $wpdb->get_row(
				$wpdb->prepare( "SELECT id, anchor_price, anchor_date, anchor_verified FROM {$items} WHERE id = %d", $item_id ),
				ARRAY_A
			);
			if ( $item ) {
				$verified = ! empty( $item['anchor_verified'] ) && null !== $item['anchor_price'];
				if ( $is_clear ) {
					if ( $verified ) {
						return array( 'status' => 'conflict', 'label' => __( 'sukob (brisanje potvrđenog)', 'wp-cpt-sidrene-cijene' ), 'item_id' => (int) $item['id'] );
					}
					return array( 'status' => 'matched_code', 'label' => __( 'šifra', 'wp-cpt-sidrene-cijene' ), 'item_id' => $item_id );
				}
				$differs = ! Money::eq( (string) $item['anchor_price'], $row['price'] ) || (string) $item['anchor_date'] !== $row['date'];
				if ( $verified && $differs ) {
					return array( 'status' => 'conflict', 'label' => __( 'sukob', 'wp-cpt-sidrene-cijene' ), 'item_id' => (int) $item['id'] );
				}
			}
			return array( 'status' => 'matched_code', 'label' => __( 'šifra', 'wp-cpt-sidrene-cijene' ), 'item_id' => $item_id );
		}

		return array( 'status' => 'unmatched', 'label' => __( 'nije pronađeno', 'wp-cpt-sidrene-cijene' ), 'item_id' => 0 );
	}
}

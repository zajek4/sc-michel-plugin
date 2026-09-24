<?php
/**
 * Diagnostics: versions, health, storage, cron, copyable report (no secrets).
 *
 * @package CPTSC
 */

namespace CPTSC\Admin;

use CPTSC\Catalog\Queue as ItemQueue;
use CPTSC\Feed\Archive;
use CPTSC\Jobs\Cron;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Diagnostics page.
 */
final class Diagnostics {

	/**
	 * Render.
	 */
	public function render() {
		\CPTSC\Jobs\Manager::watchdog( 600 );
		$report = $this->report();
		?>
		<div class="wrap cptsc-wrap">
			<h1><?php esc_html_e( 'Dijagnostika', 'wp-cpt-sidrene-cijene' ); ?></h1>

			<p>
				<button type="button" class="button button-primary" id="cptsc-copy-diag"><?php esc_html_e( 'Kopiraj dijagnostiku', 'wp-cpt-sidrene-cijene' ); ?></button>
			</p>
			<pre id="cptsc-diag-text" class="cptsc-diag"><?php echo esc_html( $report['text'] ); ?></pre>

			<table class="widefat striped cptsc-table">
				<tbody>
					<?php foreach ( $report['rows'] as $label => $value ) : ?>
						<tr>
							<th style="width:36%"><?php echo esc_html( $label ); ?></th>
							<td><?php echo esc_html( $value ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Automatizacija', 'wp-cpt-sidrene-cijene' ); ?></h2>
			<table class="widefat striped cptsc-table">
				<tbody>
					<?php foreach ( $report['cron_rows'] as $label => $value ) : ?>
						<tr>
							<th style="width:36%"><?php echo esc_html( $label ); ?></th>
							<td><?php echo esc_html( $value ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Preporučena server cron naredba', 'wp-cpt-sidrene-cijene' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Za produkcijske sustave preporučujemo pravi server cron umjesto posjetiteljskog WP-Crona:', 'wp-cpt-sidrene-cijene' ); ?></p>
			<pre class="cptsc-diag"><?php echo esc_html( '*/5 * * * * curl -s "' . $report['cron_url_raw'] . '" >/dev/null 2>&1' ); ?></pre>
			<p class="description"><?php esc_html_e( 'Token je vidljiv samo ovdje (za vas) i ne prikazuje se javno. Kopirana dijagnostika token ne sadrži.', 'wp-cpt-sidrene-cijene' ); ?></p>

			<h2><?php esc_html_e( 'Aktivne obrade', 'wp-cpt-sidrene-cijene' ); ?></h2>
			<?php
			$jobs = \CPTSC\Jobs\Manager::active( 20 );
			if ( empty( $jobs ) ) {
				echo '<p>' . esc_html__( 'Nema aktivnih obrada.', 'wp-cpt-sidrene-cijene' ) . '</p>';
			} else {
				?>
				<table class="widefat striped cptsc-table">
					<thead><tr><th>ID</th><th><?php esc_html_e( 'Vrsta', 'wp-cpt-sidrene-cijene' ); ?></th><th><?php esc_html_e( 'Status', 'wp-cpt-sidrene-cijene' ); ?></th><th><?php esc_html_e( 'Napredak', 'wp-cpt-sidrene-cijene' ); ?></th><th><?php esc_html_e( 'Heartbeat', 'wp-cpt-sidrene-cijene' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $jobs as $job ) : ?>
						<tr>
							<td><?php echo (int) $job['job_id']; ?></td>
							<td><code><?php echo esc_html( $job['type'] ); ?></code></td>
							<td><?php echo esc_html( Dashboard::status_label( $job['status'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $job['processed'] ) . ' / ' . number_format_i18n( $job['total'] ) ); ?></td>
							<td><?php echo esc_html( $job['heartbeat_at'] ? substr( $job['heartbeat_at'], 11, 5 ) : '—' ); ?></td>
							<td>
								<?php if ( in_array( $job['status'], array( 'stalled', 'failed', 'paused' ), true ) ) : ?>
									<p class="description"><?php esc_html_e( 'Obrada je prekinuta. Možete sigurno nastaviti.', 'wp-cpt-sidrene-cijene' ); ?></p>
									<button type="button" class="button cptsc-resume-job" data-job="<?php echo (int) $job['job_id']; ?>"><?php esc_html_e( 'Nastavi', 'wp-cpt-sidrene-cijene' ); ?></button>
								<?php endif; ?>
								<?php if ( ! empty( $job['last_error'] ) ) : ?>
									<p class="description cptsc-err"><?php echo esc_html( mb_substr( $job['last_error'], 0, 300 ) ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php
			}
			?>
		</div>
		<?php
	}

	/**
	 * Build report.
	 *
	 * @return array {rows, cron_rows, text, cron_url}
	 */
	private function report() {
		$counts  = \CPTSC\Catalog\Index::counts();
		$health  = Cron::health();
		$latest  = Archive::latest_published();
		$uploads = wp_upload_dir();
		$storage_ok = ! empty( $uploads['basedir'] ) && is_writable( $uploads['basedir'] );
		$token   = Settings::cron_token();
		$cron_url = Cron::server_cron_url();

		$rows = array(
			__( 'Inačica plugina', 'wp-cpt-sidrene-cijene' ) => CPTSC_VERSION,
			__( 'Inačica baze', 'wp-cpt-sidrene-cijene' )    => get_option( 'cptsc_db_version', '—' ),
			'WordPress'                                       => get_bloginfo( 'version' ),
			'PHP'                                             => PHP_VERSION,
			'memory_limit'                                    => (string) ini_get( 'memory_limit' ),
			__( 'Odabrani CPT-ovi', 'wp-cpt-sidrene-cijene' ) => implode( ', ', (array) Settings::get( 'post_types', array() ) ),
			__( 'Vrsta sadržaja', 'wp-cpt-sidrene-cijene' )   => Settings::get( 'item_type', '—' ),
			__( 'Redci indeksa', 'wp-cpt-sidrene-cijene' )    => number_format_i18n( $counts['total'] ),
			__( 'Queue na čekanju', 'wp-cpt-sidrene-cijene' ) => number_format_i18n( ItemQueue::pending_count() ),
			__( 'Queue neuspješni', 'wp-cpt-sidrene-cijene' ) => number_format_i18n( ItemQueue::failed_count() ),
			__( 'Zadnji worker', 'wp-cpt-sidrene-cijene' )    => $health['last_worker'] ? date( 'd.m.Y. H:i', $health['last_worker'] ) : '—',
			__( 'Heartbeat', 'wp-cpt-sidrene-cijene' )        => $health['last_heartbeat'] ? date( 'd.m.Y. H:i', $health['last_heartbeat'] ) : '—',
			__( 'Server cron heartbeat', 'wp-cpt-sidrene-cijene' ) => $health['last_server_cron'] ? date( 'd.m.Y. H:i', $health['last_server_cron'] ) : __( 'nije zabilježen', 'wp-cpt-sidrene-cijene' ),
			'DISABLE_WP_CRON'                                 => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'da' : 'ne',
			__( 'Zadnji cjenik', 'wp-cpt-sidrene-cijene' )    => $latest ? $latest['filename'] . ' (' . $latest['row_count'] . ')' : '—',
			__( 'Fingerprint', 'wp-cpt-sidrene-cijene' )      => $latest ? substr( (string) $latest['fingerprint'], 0, 24 ) . '…' : '—',
			__( 'Spremište zapisivo', 'wp-cpt-sidrene-cijene' ) => $storage_ok ? __( 'da', 'wp-cpt-sidrene-cijene' ) : __( 'NE', 'wp-cpt-sidrene-cijene' ),
			'ACF'                                             => \CPTSC\Integrations\ACF::available() ? __( 'aktivan', 'wp-cpt-sidrene-cijene' ) : '—',
			'WPML'                                            => \CPTSC\Integrations\WPML::available() ? __( 'aktivan', 'wp-cpt-sidrene-cijene' ) : '—',
			'Polylang'                                        => \CPTSC\Integrations\Polylang::available() ? __( 'aktivan', 'wp-cpt-sidrene-cijene' ) : '—',
			__( 'Audit zapisi', 'wp-cpt-sidrene-cijene' )     => number_format_i18n( \CPTSC\Anchor\Audit::count() ),
		);

		$cron_rows = array(
			__( 'WP-Cron redovi', 'wp-cpt-sidrene-cijene' ) => sprintf(
				'queue=%s, heartbeat=%s, feed=%s',
				$health['queue_event'] ? date( 'd.m. H:i', $health['queue_event'] ) : '—',
				$health['heartbeat_event'] ? date( 'd.m. H:i', $health['heartbeat_event'] ) : '—',
				$health['feed_event'] ? date( 'd.m. H:i', $health['feed_event'] ) : '—'
			),
			__( 'Zadnja server cron izvedba', 'wp-cpt-sidrene-cijene' ) => $health['last_server_cron'] ? date( 'd.m. H:i', $health['last_server_cron'] ) : __( 'nije zabilježen', 'wp-cpt-sidrene-cijene' ),
			__( 'Zadnja automatska objava', 'wp-cpt-sidrene-cijene' )   => $health['last_feed_publish'] ? date( 'd.m. H:i', $health['last_feed_publish'] ) : '—',
		);

		$lines = array();
		foreach ( $rows as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}
		foreach ( $cron_rows as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}

		return array(
			'rows'      => $rows,
			'cron_rows' => $cron_rows,
			'text'      => implode( "\n", $lines ),
			'cron_url'  => str_replace( $token, '[TOKEN]', $cron_url ), // Redacted for copyable diagnostics.
			'cron_url_raw' => $cron_url, // Shown only on this admin screen for copy into server cron.
		);
	}
}

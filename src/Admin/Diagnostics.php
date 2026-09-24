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

			<?php if ( ! empty( $report['advice'] ) ) : ?>
				<h2><?php esc_html_e( 'Preporuke', 'wp-cpt-sidrene-cijene' ); ?></h2>
				<div class="notice notice-warning"><ul>
					<?php foreach ( $report['advice'] as $a ) : ?>
						<li><?php echo esc_html( $a ); ?></li>
					<?php endforeach; ?>
				</ul></div>
			<?php endif; ?>

			<?php if ( ItemQueue::failed_count() > 0 ) : ?>
				<form method="post" style="margin:8px 0">
					<?php wp_nonce_field( 'cptsc_queue_retry' ); ?>
					<button class="button" name="cptsc_retry_failed" value="1" type="submit"><?php esc_html_e( 'Pokušaj ponovno za neuspješne stavke', 'wp-cpt-sidrene-cijene' ); ?></button>
				</form>
				<?php
				if ( isset( $_POST['cptsc_retry_failed'], $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cptsc_queue_retry' ) && current_user_can( 'manage_options' ) ) {
					$n = ItemQueue::retry_failed();
					echo '<div class="notice notice-success"><p>' . esc_html( sprintf( /* translators: %d */ __( 'Ponovno je na čekanje stavljeno stavki: %d.', 'wp-cpt-sidrene-cijene' ), $n ) ) . '</p></div>';
				}
				?>
			<?php endif; ?>

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

		// Frontend selector state.
		$fe_state   = (string) Settings::get( 'frontend.state', 'auto_disabled' );
		$selector   = (string) Settings::get( 'frontend.selector', '' );
		$sel_ok     = '' !== $selector && \CPTSC\Frontend\Inspector::is_safe_selector( $selector );

		// Shortcode registration + renderer smoke test on a real anchored item.
		$sc_registered = shortcode_exists( 'sidrena_cijena' );
		$sample_oid    = $this->sample_object_id();
		$sc_smoke      = $sample_oid ? ( '' !== \CPTSC\Frontend\Renderer::render( (int) $sample_oid, 'shortcode' ) ) : null;

		// Canonical resolution probe (WPML/Polylang translation → default-language id).
		$canonical_ok = null;
		if ( $sample_oid && \CPTSC\Integrations\Multilingual::active() ) {
			$canonical_ok = (int) \CPTSC\Integrations\Multilingual::canonical_post_id( (int) $sample_oid ) === (int) $sample_oid
				|| \CPTSC\Integrations\Multilingual::canonical_post_id( (int) $sample_oid ) > 0;
		}

		// Public route: rewrite rule registered + current file on disk.
		$rewrite_rules = get_option( 'rewrite_rules' );
		$route_rule    = is_array( $rewrite_rules ) && ( isset( $rewrite_rules['cjenik/aktualni\\.csv$'] ) || (bool) preg_grep( '#cjenik/aktualni#', array_keys( $rewrite_rules ) ) );
		$feed_file     = $latest ? trailingslashit( Archive::channel_dir( (int) $latest['channel_id'] ) ) . Archive::current_filename( (int) $latest['channel_id'] ) : '';
		$feed_exists   = $feed_file && file_exists( $feed_file );

		// Actionable advice (heartbeat-gated DISABLE_WP_CRON check per reference §44).
		$advice = array();
		if ( $health['wp_cron_disabled'] && empty( $health['last_server_cron'] ) ) {
			$advice[] = __( 'DISABLE_WP_CRON je uključen, a server cron još nije zabilježen — zakazanu objavu ništa neće pokrenuti. Dodajte donju naredbu u server cron ili isključite DISABLE_WP_CRON.', 'wp-cpt-sidrene-cijene' );
		}
		if ( ! $sel_ok && 'auto_verified' === $fe_state ) {
			$advice[] = __( 'Automatski prikaz je uključen (AUTO_VERIFIED), ali CSS selector nedostaje ili nije ispravan — injekcija se neće izvoditi.', 'wp-cpt-sidrene-cijene' );
		}
		if ( $latest && ! $feed_exists ) {
			$advice[] = __( 'Posljednja zabilježena objava postoji u bazi, ali aktualna CSV datoteka nije pronađena na disku — pokrenite ponovnu objavu.', 'wp-cpt-sidrene-cijene' );
		}
		if ( ItemQueue::failed_count() > 0 ) {
			$advice[] = sprintf(
				/* translators: %d: failed queue rows */
				__( 'Queue ima %d neuspješnih redaka — provjerite odgovaraju li izvorne ključeve potvrđenom mapiranju ili pokrenite ponovni pokušaj.', 'wp-cpt-sidrene-cijene' ),
				ItemQueue::failed_count()
			);
		}
		// Feed reconciliation (DB vs disk) — lightweight, diagnostics-only.
		$reconcile = \CPTSC\Feed\Archive::reconcile();
		if ( ! empty( $reconcile['missing_current'] ) ) {
			$advice[] = sprintf(
				/* translators: %s: filenames */
				__( 'Nedostaju aktualne CSV datoteke: %s — pokrenite objavu cjenika.', 'wp-cpt-sidrene-cijene' ),
				implode( ', ', $reconcile['missing_current'] )
			);
		}
		if ( ! empty( $reconcile['fingerprint_mismatch'] ) ) {
			$advice[] = sprintf(
				/* translators: %s: filenames */
				__( 'Fingerprint aktualnih datoteka ne odgovara zadnjoj objavljenoj verziji: %s — pokrenite objavu radi usklađivanja.', 'wp-cpt-sidrene-cijene' ),
				implode( ', ', $reconcile['fingerprint_mismatch'] )
			);
		}
		if ( ! empty( $reconcile['orphan_tmp'] ) ) {
			$advice[] = sprintf(
				/* translators: %d: count */
				_n( 'Pronađena je %d napuštena privremena datoteka — očistit će se automatski.', 'Pronađeno je %d napuštenih privremenih datoteka — očistit će se automatski.', (int) $reconcile['orphan_tmp'], 'wp-cpt-sidrene-cijene' ),
				(int) $reconcile['orphan_tmp']
			);
		}

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
			__( 'Frontend prikaz', 'wp-cpt-sidrene-cijene' )   => $fe_state . ( $sel_ok ? ' (' . $selector . ' — OK)' : ( '' !== $selector ? ' (' . $selector . ' — neispravan)' : '' ) ),
			__( 'Shortcode [sidrena_cijena]', 'wp-cpt-sidrene-cijene' ) => $sc_registered
				? ( ( null === $sc_smoke ) ? __( 'registriran (nema uzorak s sidrenom cijenom)', 'wp-cpt-sidrene-cijene' ) : ( $sc_smoke ? __( 'registriran — prikaz radi', 'wp-cpt-sidrene-cijene' ) : __( 'registriran — PRIKAZ NE RADI', 'wp-cpt-sidrene-cijene' ) ) )
				: __( 'NIJE REGISTRIRAN', 'wp-cpt-sidrene-cijene' ),
			__( 'Kanonski ID (višejezičnost)', 'wp-cpt-sidrene-cijene' ) => null === $canonical_ok
				? ( \CPTSC\Integrations\Multilingual::active() ? __( 'nema uzorak', 'wp-cpt-sidrene-cijene' ) : '—' )
				: ( $canonical_ok ? __( 'OK', 'wp-cpt-sidrene-cijene' ) : __( 'PROVJERITI', 'wp-cpt-sidrene-cijene' ) ),
			__( 'Javna ruta cjenik/aktualni.csv', 'wp-cpt-sidrene-cijene' ) => sprintf(
				'%s / %s',
				$route_rule ? __( 'rewrite aktivan', 'wp-cpt-sidrene-cijene' ) : __( 'rewrite NEDOSTAJE (spremite permalinks)', 'wp-cpt-sidrene-cijene' ),
				$latest ? ( $feed_exists ? __( 'datoteka postoji', 'wp-cpt-sidrene-cijene' ) : __( 'datoteka NEDOSTAJE', 'wp-cpt-sidrene-cijene' ) ) : __( 'još nije objavljeno', 'wp-cpt-sidrene-cijene' )
			),
		);

		$cron_rows = array(
			__( 'WP-Cron redovi', 'wp-cpt-sidrene-cijene' ) => sprintf(
				'queue=%s, heartbeat=%s, feed=%s, retry=%s',
				$health['queue_event'] ? date( 'd.m. H:i', $health['queue_event'] ) : '—',
				$health['heartbeat_event'] ? date( 'd.m. H:i', $health['heartbeat_event'] ) : '—',
				$health['feed_event'] ? date( 'd.m. H:i', $health['feed_event'] ) : '—',
				! empty( $health['feed_retry_event'] ) ? date( 'd.m. H:i', $health['feed_retry_event'] ) : '—'
			),
			__( 'Feed retry pokušaji (max 3)', 'wp-cpt-sidrene-cijene' ) => (string) (int) ( $health['feed_retry_attempts'] ?? 0 ),
			__( 'Zadnja server cron izvedba', 'wp-cpt-sidrene-cijene' ) => $health['last_server_cron'] ? date( 'd.m. H:i', $health['last_server_cron'] ) : __( 'nije zabilježen', 'wp-cpt-sidrene-cijene' ),
			__( 'Zadnja automatska objava', 'wp-cpt-sidrene-cijene' )   => $health['last_feed_publish'] ? date( 'd.m. H:i', $health['last_feed_publish'] ) : '—',
			__( 'Queue-drain zaštita', 'wp-cpt-sidrene-cijene' )         => \CPTSC\Jobs\Cron::feed_busy() ? __( 'aktivna (katalog se obrađuje — objava pričeka)', 'wp-cpt-sidrene-cijene' ) : __( 'mirno stanje', 'wp-cpt-sidrene-cijene' ),
		);

		$lines = array();
		foreach ( $rows as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}
		foreach ( $cron_rows as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}
		foreach ( $advice as $a ) {
			$lines[] = __( 'PREPORUKA', 'wp-cpt-sidrene-cijene' ) . ': ' . $a;
		}

		return array(
			'rows'      => $rows,
			'cron_rows' => $cron_rows,
			'advice'    => $advice,
			'text'      => implode( "\n", $lines ),
			'cron_url'  => str_replace( $token, '[TOKEN]', $cron_url ), // Redacted for copyable diagnostics.
			'cron_url_raw' => $cron_url, // Shown only on this admin screen for copy into server cron.
		);
	}

	/**
	 * Smallest indexed object that has a renderable anchor (smoke-test sample).
	 *
	 * @return int object_id or 0.
	 */
	private function sample_object_id() {
		global $wpdb;
		$items = \CPTSC\Database::instance()->table( 'items' );
		$oid = $wpdb->get_var(
			"SELECT object_id FROM {$items}
			 WHERE anchor_price IS NOT NULL AND anchor_date IS NOT NULL
			   AND validation_level < 3
			 ORDER BY id ASC LIMIT 1"
		);
		return (int) $oid;
	}
}

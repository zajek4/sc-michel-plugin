<?php
/**
 * Dashboard (post-setup overview).
 *
 * @package CPTSC
 */

namespace CPTSC\Admin;

use CPTSC\Feed\Archive;
use CPTSC\Feed\Channel;
use CPTSC\Jobs\Cron;
use CPTSC\Catalog\Queue as ItemQueue;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard page.
 */
final class Dashboard {

	/**
	 * Render.
	 */
	public function render() {
		$counts  = \CPTSC\Catalog\Index::counts();
		$latest  = Archive::latest_published();
		$pending = ItemQueue::pending_count();
		$failed  = ItemQueue::failed_count();
		$item_type = Settings::get( 'item_type', 'product' );
		$label = 'service' === $item_type ? __( 'Usluge', 'wp-cpt-sidrene-cijene' ) : __( 'Proizvodi', 'wp-cpt-sidrene-cijene' );
		?>
		<div class="wrap cptsc-wrap">
			<h1><?php esc_html_e( 'Sidrene cijene — Pregled', 'wp-cpt-sidrene-cijene' ); ?></h1>

			<div class="cptsc-cards">
				<div class="cptsc-card">
					<span class="cptsc-card-label"><?php esc_html_e( 'Sustav', 'wp-cpt-sidrene-cijene' ); ?></span>
					<strong class="cptsc-ok"><?php esc_html_e( 'Aktivan', 'wp-cpt-sidrene-cijene' ); ?></strong>
				</div>
				<div class="cptsc-card">
					<span class="cptsc-card-label"><?php echo esc_html( $label ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $counts['total'] ) ); ?></strong>
				</div>
				<div class="cptsc-card">
					<span class="cptsc-card-label"><?php esc_html_e( 'Spremno', 'wp-cpt-sidrene-cijene' ); ?></span>
					<strong class="cptsc-ok"><?php echo esc_html( number_format_i18n( $counts['ready'] ) ); ?></strong>
				</div>
				<div class="cptsc-card">
					<span class="cptsc-card-label"><?php esc_html_e( 'Za provjeru', 'wp-cpt-sidrene-cijene' ); ?></span>
					<strong class="cptsc-warn"><?php echo esc_html( number_format_i18n( $counts['review'] ) ); ?></strong>
				</div>
				<div class="cptsc-card">
					<span class="cptsc-card-label"><?php esc_html_e( 'Blokirano', 'wp-cpt-sidrene-cijene' ); ?></span>
					<strong class="cptsc-err"><?php echo esc_html( number_format_i18n( $counts['blocked'] ) ); ?></strong>
				</div>
				<div class="cptsc-card">
					<span class="cptsc-card-label"><?php esc_html_e( 'Zadnji cjenik', 'wp-cpt-sidrene-cijene' ); ?></span>
					<strong><?php echo esc_html( $latest ? \CPTSC\Dates::format_hr_date( substr( (string) $latest['published_at'], 0, 10 ) ) . ' ' . substr( (string) $latest['published_at'], 11, 5 ) : '—' ); ?></strong>
				</div>
				<div class="cptsc-card">
					<span class="cptsc-card-label"><?php esc_html_e( 'Arhiva', 'wp-cpt-sidrene-cijene' ); ?></span>
					<strong><?php echo esc_html( sprintf( /* translators: %d days */ __( 'Aktivna (%d dana)', 'wp-cpt-sidrene-cijene' ), Archive::retention_days() ) ); ?></strong>
				</div>
				<div class="cptsc-card">
					<span class="cptsc-card-label"><?php esc_html_e( 'Queue', 'wp-cpt-sidrene-cijene' ); ?></span>
					<strong class="<?php echo ( $failed > 0 ) ? 'cptsc-err' : 'cptsc-ok'; ?>">
						<?php
						if ( $failed > 0 ) {
							echo esc_html( sprintf( /* translators: 1: pending, 2: failed */ __( '%1$d u obradi, %2$d neuspješno', 'wp-cpt-sidrene-cijene' ), $pending, $failed ) );
						} elseif ( $pending > 0 ) {
							echo esc_html( sprintf( /* translators: %d pending */ __( '%d u obradi', 'wp-cpt-sidrene-cijene' ), $pending ) );
						} else {
							esc_html_e( 'normalan', 'wp-cpt-sidrene-cijene' );
						}
						?>
					</strong>
				</div>
			</div>

			<?php if ( $counts['review'] > 0 || $counts['blocked'] > 0 ) : ?>
				<div class="cptsc-notice cptsc-notice-warn">
					<p>
						<?php esc_html_e( 'Postavke stavki koje trebaju pažnju:', 'wp-cpt-sidrene-cijene' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=cptsc-catalog&filter=blocked' ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d */ __( 'Blokirano (%d)', 'wp-cpt-sidrene-cijene' ), $counts['blocked'] ) ); ?></a> ·
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=cptsc-catalog&filter=review' ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d */ __( 'Za provjeru (%d)', 'wp-cpt-sidrene-cijene' ), $counts['review'] ) ); ?></a> ·
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=cptsc-catalog&filter=missing_anchor' ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d */ __( 'Bez sidrene cijene (%d)', 'wp-cpt-sidrene-cijene' ), $counts['missing_anchor'] ) ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<div class="cptsc-quicklinks">
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=cptsc-feed' ) ); ?>"><?php esc_html_e( 'Digitalni cjenik', 'wp-cpt-sidrene-cijene' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=cptsc-import' ) ); ?>"><?php esc_html_e( 'Uvoz sidrenih podataka', 'wp-cpt-sidrene-cijene' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=cptsc-setup' ) ); ?>"><?php esc_html_e( 'Postavljanje', 'wp-cpt-sidrene-cijene' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=cptsc-diagnostics' ) ); ?>"><?php esc_html_e( 'Dijagnostika', 'wp-cpt-sidrene-cijene' ); ?></a>
			</div>

			<?php $this->jobs_box(); ?>
			<?php
			$health = Cron::health();
			if ( $health['wp_cron_disabled'] && ! $health['last_server_cron'] ) :
				?>
				<div class="cptsc-notice cptsc-notice-warn">
					<p><?php esc_html_e( 'Automatska obrada trenutačno nije potvrđena. Provjerite automatizaciju u Dijagnostici.', 'wp-cpt-sidrene-cijene' ); ?></p>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=cptsc-diagnostics' ) ); ?>"><?php esc_html_e( 'Otvori dijagnostiku', 'wp-cpt-sidrene-cijene' ); ?></a></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Active jobs box.
	 */
	private function jobs_box() {
		$jobs = \CPTSC\Jobs\Manager::active( 5 );
		if ( empty( $jobs ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Aktivne obrade', 'wp-cpt-sidrene-cijene' ); ?></h2>
		<table class="widefat striped cptsc-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Vrsta', 'wp-cpt-sidrene-cijene' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wp-cpt-sidrene-cijene' ); ?></th>
					<th><?php esc_html_e( 'Napredak', 'wp-cpt-sidrene-cijene' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $jobs as $job ) : ?>
				<tr>
					<td><code><?php echo esc_html( $job['type'] ); ?></code></td>
					<td><?php echo esc_html( self::status_label( $job['status'] ) ); ?></td>
					<td>
						<?php if ( $job['total'] > 0 ) : ?>
							<?php echo esc_html( number_format_i18n( $job['processed'] ) . ' / ' . number_format_i18n( $job['total'] ) ); ?>
						<?php else : ?>
							<?php echo esc_html( number_format_i18n( $job['processed'] ) ); ?>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( 'stalled' === $job['status'] ) : ?>
							<p class="description"><?php esc_html_e( 'Obrada je prekinuta. Možete sigurno nastaviti.', 'wp-cpt-sidrene-cijene' ); ?></p>
							<button type="button" class="button cptsc-resume-job" data-job="<?php echo esc_attr( $job['job_id'] ); ?>"><?php esc_html_e( 'Nastavi', 'wp-cpt-sidrene-cijene' ); ?></button>
						<?php endif; ?>
						<?php if ( ! empty( $job['last_error'] ) ) : ?>
							<p class="description cptsc-err"><?php echo esc_html( mb_substr( $job['last_error'], 0, 200 ) ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Status label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$map = array(
			'queued'           => __( 'Čeka', 'wp-cpt-sidrene-cijene' ),
			'running'          => __( 'U tijeku', 'wp-cpt-sidrene-cijene' ),
			'waiting_for_lock' => __( 'Čeka na obradu', 'wp-cpt-sidrene-cijene' ),
			'paused'           => __( 'Pauzirano', 'wp-cpt-sidrene-cijene' ),
			'completed'        => __( 'Završeno', 'wp-cpt-sidrene-cijene' ),
			'failed'           => __( 'Neuspješno', 'wp-cpt-sidrene-cijene' ),
			'cancelled'        => __( 'Otkazano', 'wp-cpt-sidrene-cijene' ),
			'stalled'          => __( 'Prekinuto', 'wp-cpt-sidrene-cijene' ),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : $status;
	}
}

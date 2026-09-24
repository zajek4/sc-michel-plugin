<?php
/**
 * Digital price list admin: publish controls, channels, preview.
 *
 * @package CPTSC
 */

namespace CPTSC\Admin;

use CPTSC\Feed\Archive;
use CPTSC\Feed\Channel;
use CPTSC\Feed\Generator;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed admin page.
 */
final class Feed {

	/**
	 * Flash message.
	 *
	 * @var string|null
	 */
	private $message = null;

	/**
	 * Render.
	 */
	public function render() {
		$this->handle_actions();

		$latest   = Archive::latest_published();
		$channels = Channel::all();
		$enabled  = Settings::get( 'feed.enabled', false );
		$item_type = Settings::get( 'item_type', 'product' );
		$default  = Channel::default_channel();
		?>
		<div class="wrap cptsc-wrap">
			<h1><?php esc_html_e( 'Digitalni cjenik', 'wp-cpt-sidrene-cijene' ); ?></h1>

			<div class="cptsc-cards">
				<div class="cptsc-card">
					<span class="cptsc-card-label"><?php esc_html_e( 'Objava cjenika', 'wp-cpt-sidrene-cijene' ); ?></span>
					<strong class="<?php echo $enabled ? 'cptsc-ok' : 'cptsc-warn'; ?>"><?php echo $enabled ? esc_html__( 'Uključena', 'wp-cpt-sidrene-cijene' ) : esc_html__( 'Isključena', 'wp-cpt-sidrene-cijene' ); ?></strong>
				</div>
				<div class="cptsc-card">
					<span class="cptsc-card-label"><?php esc_html_e( 'Zadnja objava', 'wp-cpt-sidrene-cijene' ); ?></span>
					<strong><?php echo esc_html( $latest ? \CPTSC\Dates::format_hr_date( substr( (string) $latest['published_at'], 0, 10 ) ) . ' ' . substr( (string) $latest['published_at'], 11, 5 ) : '—' ); ?></strong>
				</div>
				<div class="cptsc-card">
					<span class="cptsc-card-label"><?php esc_html_e( 'Redaka', 'wp-cpt-sidrene-cijene' ); ?></span>
					<strong><?php echo esc_html( $latest ? number_format_i18n( (int) $latest['row_count'] ) : '—' ); ?></strong>
				</div>
				<div class="cptsc-card">
					<span class="cptsc-card-label"><?php esc_html_e( 'Javni CSV', 'wp-cpt-sidrene-cijene' ); ?></span>
					<strong><a href="<?php echo esc_url( Archive::current_url( $default ? (int) $default['id'] : 0 ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'aktualni.csv', 'wp-cpt-sidrene-cijene' ); ?></a></strong>
				</div>
			</div>

			<form method="post" class="cptsc-panel">
				<?php wp_nonce_field( 'cptsc_feed_settings' ); ?>
				<h2><?php esc_html_e( 'Postavke objave', 'wp-cpt-sidrene-cijene' ); ?></h2>
				<label class="cptsc-check">
					<input type="checkbox" name="feed_enabled" value="1" <?php checked( $enabled ); ?>>
					<?php esc_html_e( 'Omogući javnu objavu cjenika (CSV + HTML)', 'wp-cpt-sidrene-cijene' ); ?>
				</label>
				<label class="cptsc-check">
					<input type="checkbox" name="feed_auto" value="1" <?php checked( Settings::get( 'feed.auto_publish', true ) ); ?>>
					<?php echo 'service' === $item_type ? esc_html__( 'Automatska objava kod promjene cijene (najkasnije do 8:00)', 'wp-cpt-sidrene-cijene' ) : esc_html__( 'Dnevna automatska objava radnim danom do 8:00', 'wp-cpt-sidrene-cijene' ); ?>
				</label>
				<label class="cptsc-check">
					<input type="checkbox" name="feed_html" value="1" <?php checked( Settings::get( 'feed.html_enabled', true ) ); ?>>
					<?php esc_html_e( 'Javna HTML stranica cjenika (pretraga i straničenje)', 'wp-cpt-sidrene-cijene' ); ?>
				</label>
				<p>
					<label><?php esc_html_e( 'Čuvanje arhive (dana)', 'wp-cpt-sidrene-cijene' ); ?>
						<input type="number" name="retention" min="30" max="365" value="<?php echo esc_attr( (int) Settings::get( 'feed.retention_days', 40 ) ); ?>">
					</label>
					<span class="description"><?php esc_html_e( 'Najmanje 30 dana (zakonski minimum).', 'wp-cpt-sidrene-cijene' ); ?></span>
				</p>
				<p>
					<button class="button button-primary" name="cptsc_feed_save" value="1" type="submit"><?php esc_html_e( 'Spremi', 'wp-cpt-sidrene-cijene' ); ?></button>
					<button class="button" name="cptsc_feed_publish" value="1" type="submit" <?php disabled( ! $enabled ); ?>><?php esc_html_e( 'Objavi odmah', 'wp-cpt-sidrene-cijene' ); ?></button>
				</p>
				<?php if ( isset( $this->message ) ) : ?>
					<div class="notice notice-success"><p><?php echo esc_html( $this->message ); ?></p></div>
				<?php endif; ?>
			</form>

			<h2><?php esc_html_e( 'Lokacije (kanali)', 'wp-cpt-sidrene-cijene' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Za svaku lokaciju objavljuje se zasebna datoteka cjenika. Web trgovina ima vlastitu datoteku.', 'wp-cpt-sidrene-cijene' ); ?></p>
			<table class="widefat striped cptsc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Naziv', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Oblik', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Adresa', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Oznaka', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Br. pohrane', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'CSV', 'wp-cpt-sidrene-cijene' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $channels as $ch ) : ?>
					<tr>
						<td><?php echo esc_html( $ch['name'] ); ?><?php echo $ch['is_default'] ? ' <span class="description">(' . esc_html__( 'glavni', 'wp-cpt-sidrene-cijene' ) . ')</span>' : ''; ?></td>
						<td><?php echo esc_html( $ch['object_kind'] ); ?></td>
						<td><?php echo esc_html( $ch['address'] ); ?></td>
						<td><?php echo esc_html( $ch['object_code'] ); ?></td>
						<td><?php echo esc_html( $ch['storage_sequence'] ); ?></td>
						<td><?php echo $ch['enabled'] ? esc_html__( 'Aktivan', 'wp-cpt-sidrene-cijene' ) : esc_html__( 'Neaktivan', 'wp-cpt-sidrene-cijene' ); ?></td>
						<td><a href="<?php echo esc_url( Archive::current_url( $ch['id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Otvori', 'wp-cpt-sidrene-cijene' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=cptsc-settings#channels' ) ); ?>"><?php esc_html_e( 'Upravljaj lokacijama', 'wp-cpt-sidrene-cijene' ); ?></a></p>

			<?php if ( $latest ) : ?>
				<h2><?php esc_html_e( 'Zadnja verzija', 'wp-cpt-sidrene-cijene' ); ?></h2>
				<table class="widefat striped cptsc-table">
					<tbody>
						<tr><th><?php esc_html_e( 'Datoteka', 'wp-cpt-sidrene-cijene' ); ?></th><td><code><?php echo esc_html( $latest['filename'] ); ?></code></td></tr>
						<tr><th><?php esc_html_e( 'Fingerprint (SHA-256)', 'wp-cpt-sidrene-cijene' ); ?></th><td><code><?php echo esc_html( substr( $latest['fingerprint'], 0, 32 ) ); ?>…</code></td></tr>
						<tr><th><?php esc_html_e( 'Upozorenja', 'wp-cpt-sidrene-cijene' ); ?></th><td><?php echo esc_html( $latest['validation_warnings'] ? implode( ', ', (array) json_decode( $latest['validation_warnings'], true ) ) : '—' ); ?></td></tr>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle POST actions.
	 */
	private function handle_actions() {
		if ( ! isset( $_POST['cptsc_feed_save'], $_POST['_wpnonce'] ) && ! isset( $_POST['cptsc_feed_publish'], $_POST['_wpnonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cptsc_feed_settings' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_POST['cptsc_feed_save'] ) ) {
			$retention = isset( $_POST['retention'] ) ? max( 30, min( 365, (int) $_POST['retention'] ) ) : 40;
			Settings::update(
				array(
					'feed' => array(
						'enabled'       => ! empty( $_POST['feed_enabled'] ),
						'auto_publish'  => ! empty( $_POST['feed_auto'] ),
						'html_enabled'  => ! empty( $_POST['feed_html'] ),
						'retention_days' => $retention,
					),
				)
			);
			$this->message = __( 'Postavke cjenika su spremljene.', 'wp-cpt-sidrene-cijene' );
		}
		if ( isset( $_POST['cptsc_feed_publish'] ) ) {
			$job_id = \CPTSC\Jobs\Manager::enqueue( 'FEED_GENERATION', array( 'force' => false ), true );
			$this->message = sprintf(
				/* translators: %d job id */
				__( 'Objava cjenika je pokrenuta (obrada #%d). Status vidite na dnu stranice.', 'wp-cpt-sidrene-cijene' ),
				$job_id
			);
		}
	}

	/**
	 * Archive submenu page.
	 */
	public function render_archive_page() {
		$default  = Channel::default_channel();
		$versions = $default ? Archive::versions( $default['id'], 200 ) : array();
		?>
		<div class="wrap cptsc-wrap">
			<h1><?php esc_html_e( 'Arhiva cjenika', 'wp-cpt-sidrene-cijene' ); ?></h1>
			<p class="description">
				<?php echo esc_html( sprintf( /* translators: %d days */ __( 'Objavljene datoteke čuvaju se najmanje %d dana (zakonski minimum).', 'wp-cpt-sidrene-cijene' ), Archive::retention_days() ) ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( home_url( '/cjenik/arhiva/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Otvori javnu arhivu', 'wp-cpt-sidrene-cijene' ); ?></a>
				<a class="button" href="<?php echo esc_url( Archive::current_url( $default ? (int) $default['id'] : 0 ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Aktualni CSV', 'wp-cpt-sidrene-cijene' ); ?></a>
			</p>
			<table class="widefat striped cptsc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Objavljeno', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Redaka', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Datoteka', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Fingerprint', 'wp-cpt-sidrene-cijene' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $versions ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'Još nema objavljenih verzija.', 'wp-cpt-sidrene-cijene' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $versions as $v ) : ?>
						<tr>
							<td><?php echo esc_html( \CPTSC\Dates::format_hr_date( substr( (string) $v['published_at'], 0, 10 ) ) . ' ' . substr( (string) $v['published_at'], 11, 5 ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $v['row_count'] ) ); ?></td>
							<td><a href="<?php echo esc_url( home_url( '/cjenik/arhiva/' . rawurlencode( $v['filename'] ) ) ); ?>"><?php esc_html_e( 'Preuzmi', 'wp-cpt-sidrene-cijene' ); ?></a></td>
							<td><code><?php echo esc_html( substr( (string) $v['fingerprint'], 0, 16 ) ); ?>…</code></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			<h2><?php esc_html_e( 'Održavanje', 'wp-cpt-sidrene-cijene' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'cptsc_archive_maint' ); ?>
				<button class="button" name="cptsc_cleanup" value="1" type="submit"><?php esc_html_e( 'Pokreni čišćenje arhive', 'wp-cpt-sidrene-cijene' ); ?></button>
			</form>
			<?php
			if ( isset( $_POST['cptsc_cleanup'], $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cptsc_archive_maint' ) && current_user_can( 'manage_options' ) ) {
				\CPTSC\Jobs\Manager::enqueue( 'ARCHIVE_CLEANUP', array(), false );
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Čišćenje arhive je pokrenuto.', 'wp-cpt-sidrene-cijene' ) . '</p></div>';
			}
			?>
		</div>
		<?php
	}
}

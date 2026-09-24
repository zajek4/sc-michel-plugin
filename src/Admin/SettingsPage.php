<?php
/**
 * Settings: general, channels, maintenance (A/B/C/D resets), uninstall option.
 *
 * @package CPTSC
 */

namespace CPTSC\Admin;

use CPTSC\Feed\Channel;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page.
 */
final class SettingsPage {

	/**
	 * Render.
	 */
	public function render() {
		$this->handle_actions();
		?>
		<div class="wrap cptsc-wrap">
			<h1><?php esc_html_e( 'Postavke', 'wp-cpt-sidrene-cijene' ); ?></h1>

			<form method="post" class="cptsc-panel">
				<?php wp_nonce_field( 'cptsc_settings_save' ); ?>
				<h2><?php esc_html_e( 'Opće', 'wp-cpt-sidrene-cijene' ); ?></h2>
				<p>
					<label><?php esc_html_e( 'Valuta (simbol)', 'wp-cpt-sidrene-cijene' ); ?>
						<input type="text" name="currency_symbol" value="<?php echo esc_attr( Settings::get( 'currency_symbol', '€' ) ); ?>" maxlength="6">
					</label>
				</p>
				<p>
					<label><?php esc_html_e( 'Zadana sidrena grupa', 'wp-cpt-sidrene-cijene' ); ?>
						<select name="default_anchor_group">
							<?php foreach ( \CPTSC\Anchor\Groups::options() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( Settings::get( 'default_anchor_group', 'general_2026' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</p>
				<p>
					<label><?php esc_html_e( 'Dostupnost (pravilo)', 'wp-cpt-sidrene-cijene' ); ?>
						<select name="availability_policy">
							<option value="not_configured" <?php selected( Settings::get( 'availability_policy', 'not_configured' ), 'not_configured' ); ?>><?php esc_html_e( 'Nije postavljeno (traži se konfiguracija)', 'wp-cpt-sidrene-cijene' ); ?></option>
							<option value="mapped" <?php selected( Settings::get( 'availability_policy', 'not_configured' ), 'mapped' ); ?>><?php esc_html_e( 'Čitam iz mapiranog izvora', 'wp-cpt-sidrene-cijene' ); ?></option>
							<option value="published_means_available" <?php selected( Settings::get( 'availability_policy', 'not_configured' ), 'published_means_available' ); ?>><?php esc_html_e( 'Objavljeni artikli su dostupni (potvrda administratora)', 'wp-cpt-sidrene-cijene' ); ?></option>
						</select>
					</label>
				</p>
				<p>
					<label><?php esc_html_e( 'Posebni oblici prodaje', 'wp-cpt-sidrene-cijene' ); ?>
						<select name="special_sale_mode">
							<option value="not_configured" <?php selected( Settings::get( 'special_sale_mode', 'not_configured' ), 'not_configured' ); ?>><?php esc_html_e( 'Nije postavljeno', 'wp-cpt-sidrene-cijene' ); ?></option>
							<option value="mapped" <?php selected( Settings::get( 'special_sale_mode', 'not_configured' ), 'mapped' ); ?>><?php esc_html_e( 'Mapiram polja posebnog oblika prodaje', 'wp-cpt-sidrene-cijene' ); ?></option>
							<option value="constant_false" <?php selected( Settings::get( 'special_sale_mode', 'not_configured' ), 'constant_false' ); ?>><?php esc_html_e( 'Ne koristimo posebne oblike prodaje (uvijek "ne")', 'wp-cpt-sidrene-cijene' ); ?></option>
						</select>
					</label>
				</p>
				<p><button class="button button-primary" type="submit" name="cptsc_save_general" value="1"><?php esc_html_e( 'Spremi', 'wp-cpt-sidrene-cijene' ); ?></button></p>
			</form>

			<form method="post" class="cptsc-panel">
				<?php wp_nonce_field( 'cptsc_channels' ); ?>
				<h2 id="channels"><?php esc_html_e( 'Lokacije (kanali)', 'wp-cpt-sidrene-cijene' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Posebna datoteka cjenika po lokaciji + zasebna datoteka za web trgovinu.', 'wp-cpt-sidrene-cijene' ); ?></p>
				<table class="widefat striped cptsc-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Naziv', 'wp-cpt-sidrene-cijene' ); ?></th>
							<th><?php esc_html_e( 'Oblik objekta', 'wp-cpt-sidrene-cijene' ); ?></th>
							<th><?php esc_html_e( 'Adresa', 'wp-cpt-sidrene-cijene' ); ?></th>
							<th><?php esc_html_e( 'Oznaka', 'wp-cpt-sidrene-cijene' ); ?></th>
							<th><?php esc_html_e( 'Vrsta', 'wp-cpt-sidrene-cijene' ); ?></th>
							<th><?php esc_html_e( 'Aktivan', 'wp-cpt-sidrene-cijene' ); ?></th>
							<th><?php esc_html_e( 'Glavni', 'wp-cpt-sidrene-cijene' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( Channel::all() as $i => $ch ) : ?>
						<tr>
							<td><input type="text" name="ch[<?php echo (int) $ch['id']; ?>][name]" value="<?php echo esc_attr( $ch['name'] ); ?>"></td>
							<td><input type="text" name="ch[<?php echo (int) $ch['id']; ?>][object_kind]" value="<?php echo esc_attr( $ch['object_kind'] ); ?>"></td>
							<td><input type="text" name="ch[<?php echo (int) $ch['id']; ?>][address]" value="<?php echo esc_attr( $ch['address'] ); ?>"></td>
							<td><input type="text" name="ch[<?php echo (int) $ch['id']; ?>][object_code]" value="<?php echo esc_attr( $ch['object_code'] ); ?>"></td>
							<td>
								<select name="ch[<?php echo (int) $ch['id']; ?>][type]">
									<option value="web" <?php selected( $ch['type'], 'web' ); ?>><?php esc_html_e( 'Web / glavni', 'wp-cpt-sidrene-cijene' ); ?></option>
									<option value="location" <?php selected( $ch['type'], 'location' ); ?>><?php esc_html_e( 'Lokacija', 'wp-cpt-sidrene-cijene' ); ?></option>
									<option value="webshop" <?php selected( $ch['type'], 'webshop' ); ?>><?php esc_html_e( 'Web trgovina', 'wp-cpt-sidrene-cijene' ); ?></option>
								</select>
							</td>
							<td><input type="checkbox" name="ch[<?php echo (int) $ch['id']; ?>][enabled]" value="1" <?php checked( $ch['enabled'] ); ?>></td>
							<td><input type="checkbox" name="ch[<?php echo (int) $ch['id']; ?>][is_default]" value="1" <?php checked( $ch['is_default'] ); ?>></td>
							<td>
								<?php if ( ! $ch['is_default'] ) : ?>
									<button class="button" type="submit" name="ch_delete" value="<?php echo (int) $ch['id']; ?>" onclick="return confirm('<?php echo esc_js__( 'Obrisati ovu lokaciju?', 'wp-cpt-sidrene-cijene' ); ?>');"><?php esc_html_e( 'Obriši', 'wp-cpt-sidrene-cijene' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<td><input type="text" name="ch_new[name]" placeholder="<?php esc_attr_e( 'Nova lokacija…', 'wp-cpt-sidrene-cijene' ); ?>"></td>
						<td><input type="text" name="ch_new[object_kind]" placeholder="prodavaonica"></td>
						<td><input type="text" name="ch_new[address]" placeholder="Ilica 150 Zagreb"></td>
						<td><input type="text" name="ch_new[object_code]" placeholder="P-02"></td>
						<td>
							<select name="ch_new[type]">
								<option value="location"><?php esc_html_e( 'Lokacija', 'wp-cpt-sidrene-cijene' ); ?></option>
								<option value="webshop"><?php esc_html_e( 'Web trgovina', 'wp-cpt-sidrene-cijene' ); ?></option>
							</select>
						</td>
						<td><input type="checkbox" name="ch_new[enabled]" value="1" checked></td>
						<td></td>
						<td><button class="button" type="submit" name="ch_add" value="1"><?php esc_html_e( 'Dodaj', 'wp-cpt-sidrene-cijene' ); ?></button></td>
					</tr>
					</tbody>
				</table>
				<p><button class="button button-primary" type="submit" name="cptsc_save_channels" value="1"><?php esc_html_e( 'Spremi lokacije', 'wp-cpt-sidrene-cijene' ); ?></button></p>
			</form>

			<form method="post" class="cptsc-panel">
				<?php wp_nonce_field( 'cptsc_uninstall_opt' ); ?>
				<h2><?php esc_html_e( 'Deinstalacija', 'wp-cpt-sidrene-cijene' ); ?></h2>
				<label class="cptsc-check">
					<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( Settings::get( 'delete_data_on_uninstall', false ) ); ?>>
					<?php esc_html_e( 'Obriši sve podatke plugina pri deinstalaciji', 'wp-cpt-sidrene-cijene' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Podrazumijevano se podaci (uključujući audit i sidrene podatke) NE brišu pri deinstalaciji.', 'wp-cpt-sidrene-cijene' ); ?></p>
				<p><button class="button" type="submit" name="cptsc_save_uninstall" value="1"><?php esc_html_e( 'Spremi', 'wp-cpt-sidrene-cijene' ); ?></button></p>
			</form>

			<div class="cptsc-panel cptsc-danger">
				<h2><?php esc_html_e( 'Održavanje', 'wp-cpt-sidrene-cijene' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Odvojene radnje — jasno je što svaka radi:', 'wp-cpt-sidrene-cijene' ); ?></p>
				<form method="post" style="display:inline">
					<?php wp_nonce_field( 'cptsc_maint' ); ?>
					<button class="button" name="maint" value="rediscover" type="submit"><?php esc_html_e( 'A) Ponovno analiziraj izvore', 'wp-cpt-sidrene-cijene' ); ?></button>
					<button class="button" name="maint" value="reindex" type="submit"><?php esc_html_e( 'B) Ponovno izgradi indeks', 'wp-cpt-sidrene-cijene' ); ?></button>
					<button class="button" name="maint" value="revalidate" type="submit"><?php esc_html_e( 'C) Ponovno validiraj katalog', 'wp-cpt-sidrene-cijene' ); ?></button>
				</form>
				<form method="post" style="display:inline" onsubmit="return cptscConfirmReset(event);">
					<?php wp_nonce_field( 'cptsc_full_reset' ); ?>
					<input type="hidden" name="maint" value="full_reset">
					<button class="button button-secondary cptsc-danger-btn" type="submit"><?php esc_html_e( 'D) Potpuni reset plugina', 'wp-cpt-sidrene-cijene' ); ?></button>
					<p class="description"><?php esc_html_e( 'D) briše indeks, sidrene podatke, uvoze, audit i cjenikove. Traži potvrdu.', 'wp-cpt-sidrene-cijene' ); ?></p>
				</form>
			</div>

			<div class="cptsc-panel">
				<h2><?php esc_html_e( 'Testni način', 'wp-cpt-sidrene-cijene' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Provjera mapiranja, normalizacije, sidrenih pravila, frontend prikaza i CSV predloška — bez ikakvog utjecaja na produkcijske podatke.', 'wp-cpt-sidrene-cijene' ); ?></p>
				<div id="cptsc-test-result"></div>
				<button class="button" type="button" id="cptsc-run-test"><?php esc_html_e( 'Pokreni test (5 stavki)', 'wp-cpt-sidrene-cijene' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle POST.
	 */
	private function handle_actions() {
		if ( empty( $_POST ) || ! isset( $_POST['_wpnonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );

		if ( isset( $_POST['cptsc_save_general'] ) && wp_verify_nonce( $nonce, 'cptsc_settings_save' ) ) {
			Settings::update(
				array(
					'currency_symbol'      => isset( $_POST['currency_symbol'] ) ? sanitize_text_field( wp_unslash( $_POST['currency_symbol'] ) ) : '€',
					'default_anchor_group' => isset( $_POST['default_anchor_group'] ) ? sanitize_key( wp_unslash( $_POST['default_anchor_group'] ) ) : 'general_2026',
					'availability_policy'  => isset( $_POST['availability_policy'] ) ? sanitize_key( wp_unslash( $_POST['availability_policy'] ) ) : 'not_configured',
					'special_sale_mode'    => isset( $_POST['special_sale_mode'] ) ? sanitize_key( wp_unslash( $_POST['special_sale_mode'] ) ) : 'not_configured',
				)
			);
			\CPTSC\Jobs\Manager::enqueue( 'REVALIDATE', array(), true );
			$this->flash( __( 'Postavke su spremljene. Katalog se ponovno provjerava.', 'wp-cpt-sidrene-cijene' ) );
			return;
		}

		if ( isset( $_POST['cptsc_save_channels'] ) && wp_verify_nonce( $nonce, 'cptsc_channels' ) ) {
			$channels = isset( $_POST['ch'] ) && is_array( $_POST['ch'] ) ? wp_unslash( $_POST['ch'] ) : array(); // phpcs:ignore
			$default_id = 0;
			foreach ( $channels as $id => $data ) {
				$is_default = ! empty( $data['is_default'] );
				Channel::save( $data, (int) $id );
				if ( $is_default ) {
					$default_id = (int) $id;
				}
			}
			if ( $default_id ) {
				foreach ( Channel::all() as $ch ) {
					global $wpdb;
					$wpdb->update(
						\CPTSC\Database::instance()->table( 'channels' ),
						array( 'is_default' => ( $ch['id'] === $default_id ) ? 1 : 0 ),
						array( 'id' => $ch['id'] )
					);
				}
			}
			if ( isset( $_POST['ch_add'] ) && ! empty( $_POST['ch_new']['name'] ) ) {
				Channel::save( wp_unslash( $_POST['ch_new'] ) ); // phpcs:ignore
			}
			if ( isset( $_POST['ch_delete'] ) ) {
				Channel::delete( (int) $_POST['ch_delete'] );
			}
			$this->flash( __( 'Lokacije su spremljene.', 'wp-cpt-sidrene-cijene' ) );
			return;
		}

		if ( isset( $_POST['cptsc_save_uninstall'] ) && wp_verify_nonce( $nonce, 'cptsc_uninstall_opt' ) ) {
			Settings::set( 'delete_data_on_uninstall', ! empty( $_POST['delete_data_on_uninstall'] ) );
			$this->flash( __( 'Postavka deinstalacije je spremljena.', 'wp-cpt-sidrene-cijene' ) );
			return;
		}

		if ( isset( $_POST['maint'] ) && wp_verify_nonce( $nonce, 'cptsc_maint' ) ) {
			$op = sanitize_key( wp_unslash( $_POST['maint'] ) );
			if ( 'rediscover' === $op ) {
				\CPTSC\Jobs\Manager::enqueue( 'DISCOVERY', array(), true );
				$this->flash( __( 'Ponovna analiza izvora je pokrenuta.', 'wp-cpt-sidrene-cijene' ) );
			} elseif ( 'reindex' === $op ) {
				\CPTSC\Jobs\Manager::enqueue( 'FULL_REINDEX', array(), true );
				$this->flash( __( 'Izgradnja indeksa je pokrenuta. Sidreni podaci ostaju netaknuti.', 'wp-cpt-sidrene-cijene' ) );
			} elseif ( 'revalidate' === $op ) {
				\CPTSC\Jobs\Manager::enqueue( 'REVALIDATE', array(), true );
				$this->flash( __( 'Ponovna provjera kataloga je pokrenuta.', 'wp-cpt-sidrene-cijene' ) );
			}
			return;
		}

		if ( isset( $_POST['maint'], $_POST['cptsc_confirm'] ) && wp_verify_nonce( $nonce, 'cptsc_full_reset' ) ) {
			if ( 'RESET' === strtoupper( sanitize_text_field( wp_unslash( $_POST['cptsc_confirm'] ) ) ) ) {
				self::full_reset();
				wp_safe_redirect( admin_url( 'admin.php?page=cptsc-setup&reset=1' ) );
				exit;
			}
			$this->flash( __( 'Potvrda nije ispravna — ništa nije obrisano.', 'wp-cpt-sidrene-cijene' ), 'error' );
		}
	}

	/**
	 * Flash message.
	 *
	 * @param string $message Message.
	 * @param string $type    Type.
	 */
	private function flash( $message, $type = 'success' ) {
		set_transient( 'cptsc_flash', array( $message, $type ), 60 );
	}

	/**
	 * Full reset (D): deletes index, anchors, audit, feeds, files, setup state.
	 */
	public static function full_reset() {
		global $wpdb;
		$db = \CPTSC\Database::instance();
		foreach ( $db->tables() as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . $table ); // phpcs:ignore
		}
		delete_option( Settings::DISCOVERY_OPTION );
		delete_option( Settings::OPTION );
		// Remove feed files.
		$base = \CPTSC\Feed\Archive::base_dir();
		if ( is_dir( $base ) ) {
			self::rrmdir( $base );
		}
		$tmp = \CPTSC\Feed\Archive::tmp_dir();
		if ( is_dir( $tmp ) ) {
			self::rrmdir( $tmp );
		}
		\CPTSC\Jobs\Cron::clear_scheduled();
		Settings::install_defaults();
		do_action( 'cptsc_full_reset' );
	}

	/**
	 * Recursive dir delete inside uploads/cptsc only.
	 *
	 * @param string $dir Dir.
	 */
	private static function rrmdir( $dir ) {
		$real = realpath( $dir );
		$allowed = realpath( trailingslashit( wp_upload_dir()['basedir'] ) . 'cptsc' );
		if ( ! $real || ! $allowed || 0 !== strpos( $real, $allowed ) ) {
			return;
		}
		foreach ( array_diff( scandir( $real ), array( '.', '..' ) ) as $item ) {
			$path = $real . '/' . $item;
			if ( is_dir( $path ) ) {
				self::rrmdir( $path );
			} else {
				@unlink( $path ); // phpcs:ignore
			}
		}
		@rmdir( $real ); // phpcs:ignore
	}
}

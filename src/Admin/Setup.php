<?php
/**
 * Setup wizard: 12 resumable steps, friendly copy, AJAX-driven where helpful.
 *
 * @package CPTSC
 */

namespace CPTSC\Admin;

use CPTSC\Anchor\Groups;
use CPTSC\Anchor\Rules;
use CPTSC\Catalog\Queue as ItemQueue;
use CPTSC\Discovery\FieldScorer;
use CPTSC\Feed\Archive;
use CPTSC\Feed\Channel;
use CPTSC\Jobs\Cron;
use CPTSC\Jobs\Manager as JobsManager;
use CPTSC\Mapping\SourceProfile;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Setup wizard.
 */
final class Setup {

	/**
	 * Step count.
	 */
	const STEPS = 12;

	/**
	 * Hooks (wizard AJAX).
	 */
	public function hooks() {
		add_action( 'wp_ajax_cptsc_wizard_next', array( $this, 'ajax_next' ) );
		add_action( 'wp_ajax_cptsc_wizard_back', array( $this, 'ajax_back' ) );
		add_action( 'wp_ajax_cptsc_wizard_save_step', array( $this, 'ajax_save_step' ) );
		add_action( 'wp_ajax_cptsc_wizard_activate', array( $this, 'ajax_activate' ) );
	}

	/**
	 * Render wizard page.
	 */
	public static function render_page() {
		$setup = new self();
		$setup->render();
	}

	/**
	 * Render current step.
	 */
	public function render() {
		$step  = (int) Settings::get( 'setup_step', 1 );
		$step  = max( 1, min( self::STEPS, $step ) );
		$state = Settings::get( 'setup_state', 'NOT_STARTED' );
		?>
		<div class="wrap cptsc-wrap cptsc-setup">
			<div class="cptsc-setup-header">
				<h1><?php esc_html_e( 'Postavljanje — Sidrene cijene', 'wp-cpt-sidrene-cijene' ); ?></h1>
				<div class="cptsc-steps" aria-hidden="true">
					<?php for ( $i = 1; $i <= self::STEPS; $i++ ) : ?>
						<span class="cptsc-step-dot <?php echo $i < $step ? 'is-done' : ( $i === $step ? 'is-current' : '' ); ?>" title="<?php echo esc_attr( $i ); ?>"></span>
					<?php endfor; ?>
				</div>
			</div>
			<div id="cptsc-wizard-body" class="cptsc-wizard-body" data-step="<?php echo (int) $step; ?>">
				<?php $this->render_step( $step ); ?>
			</div>
			<div class="cptsc-wizard-nav">
				<?php if ( $step > 1 ) : ?>
					<button type="button" class="button" id="cptsc-wizard-back"><?php esc_html_e( 'Natrag', 'wp-cpt-sidrene-cijene' ); ?></button>
				<?php endif; ?>
				<?php if ( $step < self::STEPS ) : ?>
					<button type="button" class="button button-primary" id="cptsc-wizard-next"><?php esc_html_e( 'Nastavi', 'wp-cpt-sidrene-cijene' ); ?></button>
				<?php elseif ( 'ACTIVE' !== $state ) : ?>
					<button type="button" class="button button-primary" id="cptsc-wizard-activate"><?php esc_html_e( 'Aktiviraj', 'wp-cpt-sidrene-cijene' ); ?></button>
				<?php else : ?>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::SLUG ) ); ?>"><?php esc_html_e( 'Na pregled', 'wp-cpt-sidrene-cijene' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one step.
	 *
	 * @param int $step Step.
	 */
	private function render_step( $step ) {
		switch ( $step ) {
			case 1:
				$this->step_welcome();
				break;
			case 2:
				$this->step_type();
				break;
			case 3:
				$this->step_sources();
				break;
			case 4:
				$this->step_discovery();
				break;
			case 5:
				$this->step_mapping_price();
				break;
			case 6:
				$this->step_mapping_other();
				break;
			case 7:
				$this->step_taxonomies();
				break;
			case 8:
				$this->step_anchors();
				break;
			case 9:
				$this->step_frontend();
				break;
			case 10:
				$this->step_feed();
				break;
			case 11:
				$this->step_preflight();
				break;
			case 12:
				$this->step_activate();
				break;
		}
	}

	/* ------------------------------------------------------------- steps */

	private function step_welcome() {
		?>
		<h2><?php esc_html_e( 'Dobrodošli', 'wp-cpt-sidrene-cijene' ); ?></h2>
		<p><?php esc_html_e( 'Ovaj će vas vodič kroz nekoliko jednostavnih koraka pripremiti za prikaz dodatne (sidrene) cijene i digitalni cjenik prema važećim propisima.', 'wp-cpt-sidrene-cijene' ); ?></p>
		<p><?php esc_html_e( 'Trebate odrediti gdje se nalaze cijene vaših proizvoda ili usluga, potvrditi prijedloge i aktivirati prikaz. Sve možete naknadno promijeniti u postavkama.', 'wp-cpt-sidrene-cijene' ); ?></p>
		<?php
	}

	private function step_type() {
		$type = Settings::get( 'item_type', '' );
		?>
		<h2><?php esc_html_e( 'Vrsta sadržaja', 'wp-cpt-sidrene-cijene' ); ?></h2>
		<p><?php esc_html_e( 'Što ovaj web prvenstveno nudi?', 'wp-cpt-sidrene-cijene' ); ?></p>
		<label class="cptsc-choice">
			<input type="radio" name="item_type" value="product" <?php checked( $type, 'product' ); ?>>
			<strong><?php esc_html_e( 'Proizvodi', 'wp-cpt-sidrene-cijene' ); ?></strong>
			<span><?php esc_html_e( 'Katalog proizvoda s cijenama, šiframa, markama…', 'wp-cpt-sidrene-cijene' ); ?></span>
		</label>
		<label class="cptsc-choice">
			<input type="radio" name="item_type" value="service" <?php checked( $type, 'service' ); ?>>
			<strong><?php esc_html_e( 'Usluge', 'wp-cpt-sidrene-cijene' ); ?></strong>
			<span><?php esc_html_e( 'Cjenik usluga — naziv, cijena, posebni oblici prodaje.', 'wp-cpt-sidrene-cijene' ); ?></span>
		</label>
		<?php
	}

	private function step_sources() {
		$selected = (array) Settings::get( 'post_types', array() );
		$all      = get_post_types( array( 'public' => true ), 'objects' );
		unset( $all['attachment'], $all['page'], $all['post'] );
		?>
		<h2><?php esc_html_e( 'Odabir CPT-ova', 'wp-cpt-sidrene-cijene' ); ?></h2>
		<p><?php esc_html_e( 'Odaberite jedan ili više tipova sadržaja u kojima se vode vaši proizvodi/usluge.', 'wp-cpt-sidrene-cijene' ); ?></p>
		<div class="cptsc-checklist">
			<?php if ( empty( $all ) ) : ?>
				<p><?php esc_html_e( 'Na ovom webu nema pronađenih CPT-ova osim standardnih.', 'wp-cpt-sidrene-cijene' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $all as $pt ) : ?>
				<label class="cptsc-check">
					<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $selected, true ) ); ?>>
					<?php echo esc_html( $pt->label . ' (' . $pt->name . ')' ); ?>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function step_discovery() {
		$types = (array) Settings::get( 'post_types', array() );
		?>
		<h2><?php esc_html_e( 'Analiza podataka', 'wp-cpt-sidrene-cijene' ); ?></h2>
		<p><?php esc_html_e( 'Plugin će pročitati reprezentativni uzorak stavki i predložiti gdje se nalaze cijene i ostali podaci.', 'wp-cpt-sidrene-cijene' ); ?></p>
		<div id="cptsc-discovery-results">
			<?php foreach ( $types as $pt ) : ?>
				<div class="cptsc-disc-box" data-pt="<?php echo esc_attr( $pt ); ?>">
					<h3><?php echo esc_html( $pt ); ?></h3>
					<p class="description"><?php esc_html_e( 'Čekam analizu…', 'wp-cpt-sidrene-cijene' ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>
		<p><button type="button" class="button button-primary" id="cptsc-run-discovery"><?php esc_html_e( 'Pokreni analizu', 'wp-cpt-sidrene-cijene' ); ?></button></p>
		<?php
	}

	private function step_mapping_price() {
		$types   = (array) Settings::get( 'post_types', array() );
		$draft   = Settings::get( 'wizard', array() );
		?>
		<h2><?php esc_html_e( 'Mapiranje aktualne cijene', 'wp-cpt-sidrene-cijene' ); ?></h2>
		<p><?php esc_html_e( 'Potvrdite odakle čitamo aktualnu cijenu. Uvijek pogledajte primjere prije potvrde.', 'wp-cpt-sidrene-cijene' ); ?></p>
		<?php foreach ( $types as $pt ) : ?>
			<div class="cptsc-map-box" data-pt="<?php echo esc_attr( $pt ); ?>">
				<h3><?php echo esc_html( $pt ); ?></h3>
				<?php
				$discovery = Settings::discovery( $pt );
				$cands     = isset( $discovery['price_candidates']['price'] ) ? $discovery['price_candidates']['price'] : array();
				$current   = isset( $draft['mapping'][ $pt ]['current_price'] ) ? $draft['mapping'][ $pt ]['current_price'] : null;
				?>
				<?php if ( empty( $cands ) ) : ?>
					<p class="description"><?php esc_html_e( 'Nema automatskog prijedloga. Odaberite izvor ručno.', 'wp-cpt-sidrene-cijene' ); ?></p>
				<?php else : ?>
					<?php foreach ( $cands as $i => $cand ) : ?>
						<label class="cptsc-candidate <?php echo 0 === $i ? 'is-top' : ''; ?>">
							<input type="radio" name="cp_<?php echo esc_attr( $pt ); ?>" value="<?php echo esc_attr( wp_json_encode( $cand ) ); ?>" <?php checked( $current && isset( $current['key'] ) && $current['key'] === $cand['key'] && $current['source'] === $cand['source'] ); ?><?php checked( ! $current && 0 === $i ); ?>>
							<span class="cptsc-cand-title">
								<strong><?php echo esc_html( $cand['display'] ); ?></strong>
								<em><?php
									$labels = array(
										'high'   => __( 'Visoka pouzdanost', 'wp-cpt-sidrene-cijene' ),
										'medium' => __( 'Srednja pouzdanost', 'wp-cpt-sidrene-cijene' ),
										'low'    => __( 'Niska pouzdanost', 'wp-cpt-sidrene-cijene' ),
									);
									echo esc_html( isset( $labels[ $cand['confidence'] ] ) ? $labels[ $cand['confidence'] ] : $cand['confidence'] );
								?></em>
							</span>
							<span class="cptsc-cand-samples">
								<?php echo esc_html( implode( '   ', array_slice( (array) $cand['samples'], 0, 3 ) ) ); ?>
							</span>
						</label>
					<?php endforeach; ?>
				<?php endif; ?>
				<details class="cptsc-manual-map">
					<summary><?php esc_html_e( 'Odaberi drugo (ručno)', 'wp-cpt-sidrene-cijene' ); ?></summary>
					<p>
						<select name="manual_source_<?php echo esc_attr( $pt ); ?>" class="cptsc-manual-source">
							<option value=""><?php esc_html_e( '— izvor —', 'wp-cpt-sidrene-cijene' ); ?></option>
							<optgroup label="ACF">
								<?php foreach ( ( isset( $discovery['acf'] ) ? $discovery['acf'] : array() ) as $f ) : ?>
									<?php if ( ! empty( $f['scalar'] ) ) : ?>
										<option value="acf" data-key="<?php echo esc_attr( $f['name'] ); ?>"><?php echo esc_html( $f['label'] ? $f['label'] : $f['name'] ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</optgroup>
							<optgroup label="Meta">
								<?php foreach ( ( isset( $discovery['meta'] ) ? $discovery['meta'] : array() ) as $f ) : ?>
									<option value="meta" data-key="<?php echo esc_attr( $f['key'] ); ?>"><?php echo esc_html( $f['key'] ); ?></option>
								<?php endforeach; ?>
							</optgroup>
							<optgroup label="Osnovna polja">
								<?php foreach ( ( isset( $discovery['core'] ) ? $discovery['core'] : array() ) as $f ) : ?>
									<option value="core" data-key="<?php echo esc_attr( $f['key'] ); ?>"><?php echo esc_html( $f['label'] ); ?></option>
								<?php endforeach; ?>
							</optgroup>
							<option value="constant"><?php esc_html_e( 'Fiksna vrijednost…', 'wp-cpt-sidrene-cijene' ); ?></option>
						</select>
						<input type="text" name="manual_key_<?php echo esc_attr( $pt ); ?>" placeholder="<?php esc_attr_e( 'ključ / vrijednost', 'wp-cpt-sidrene-cijene' ); ?>">
					</p>
				</details>
			</div>
		<?php endforeach; ?>
		<?php
	}

	private function step_mapping_other() {
		$types     = (array) Settings::get( 'post_types', array() );
		$draft     = Settings::get( 'wizard', array() );
		$item_type = Settings::get( 'item_type', 'product' );
		?>
		<h2><?php esc_html_e( 'Ostali podaci', 'wp-cpt-sidrene-cijene' ); ?></h2>

		<h3><?php esc_html_e( 'Posebni oblici prodaje', 'wp-cpt-sidrene-cijene' ); ?></h3>
		<label class="cptsc-check"><input type="radio" name="special_sale_mode" value="constant_false" <?php checked( Settings::get( 'special_sale_mode', 'not_configured' ), 'constant_false' ); ?>> <?php esc_html_e( 'Ne koristimo posebne oblike prodaje', 'wp-cpt-sidrene-cijene' ); ?></label>
		<label class="cptsc-check"><input type="radio" name="special_sale_mode" value="mapped" <?php checked( Settings::get( 'special_sale_mode', 'not_configured' ), 'mapped' ); ?>> <?php esc_html_e( 'Da — mapiram polja (posebni oblik + naziv)', 'wp-cpt-sidrene-cijene' ); ?></label>

		<?php if ( 'product' === $item_type ) : ?>
			<h3><?php esc_html_e( 'Dostupnost', 'wp-cpt-sidrene-cijene' ); ?></h3>
			<label class="cptsc-check"><input type="radio" name="availability_policy" value="not_configured" <?php checked( Settings::get( 'availability_policy', 'not_configured' ), 'not_configured' ); ?>> <?php esc_html_e( 'Mapiram iz izvora', 'wp-cpt-sidrene-cijene' ); ?></label>
			<label class="cptsc-check"><input type="radio" name="availability_policy" value="mapped" <?php checked( Settings::get( 'availability_policy', 'not_configured' ), 'mapped' ); ?>> <?php esc_html_e( 'Čitam iz mapiranog polja', 'wp-cpt-sidrene-cijene' ); ?></label>
			<label class="cptsc-check"><input type="radio" name="availability_policy" value="published_means_available" <?php checked( Settings::get( 'availability_policy', 'not_configured' ), 'published_means_available' ); ?>> <?php esc_html_e( 'Potvrđujem: svi objavljeni artikli smatraju se dostupnima', 'wp-cpt-sidrene-cijene' ); ?></label>

			<h3><?php esc_html_e( 'Koja polja se primjenjuju?', 'wp-cpt-sidrene-cijene' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Eksplicitnom odlukom izbjegavamo beskrajna upozorenja za nepostojeća polja.', 'wp-cpt-sidrene-cijene' ); ?></p>
			<?php foreach ( array( 'brand' => __( 'Marka', 'wp-cpt-sidrene-cijene' ), 'unit' => __( 'Jedinica mjere', 'wp-cpt-sidrene-cijene' ), 'unit_price' => __( 'Cijena jedinice mjere', 'wp-cpt-sidrene-cijene' ), 'barcode' => __( 'Barkod', 'wp-cpt-sidrene-cijene' ) ) as $field => $lbl ) : ?>
				<label class="cptsc-inline-select">
					<?php echo esc_html( $lbl ); ?>:
					<select name="applicability[<?php echo esc_attr( $field ); ?>]">
						<option value="applicable" <?php selected( Settings::get( 'applicability.' . $field, 'applicable' ), 'applicable' ); ?>><?php esc_html_e( 'primjenjivo', 'wp-cpt-sidrene-cijene' ); ?></option>
						<option value="not_applicable" <?php selected( Settings::get( 'applicability.' . $field, 'applicable' ), 'not_applicable' ); ?>><?php esc_html_e( 'nije primjenjivo', 'wp-cpt-sidrene-cijene' ); ?></option>
					</select>
				</label>
			<?php endforeach; ?>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Dodatna polja po tipu sadržaja', 'wp-cpt-sidrene-cijene' ); ?></h3>
		<?php foreach ( $types as $pt ) : ?>
			<?php
			$discovery = Settings::discovery( $pt );
			$defaults  = isset( $draft['mapping'][ $pt ] ) ? $draft['mapping'][ $pt ] : array();
			?>
			<div class="cptsc-map-box" data-pt="<?php echo esc_attr( $pt ); ?>">
				<h4><?php echo esc_html( $pt ); ?></h4>
				<p>
					<label><?php esc_html_e( 'Šifra', 'wp-cpt-sidrene-cijene' ); ?>
						<?php $this->field_select( 'code', $pt, $discovery, isset( $defaults['code'] ) ? $defaults['code'] : null ); ?>
					</label>
				</p>
				<?php if ( 'product' === $item_type ) : ?>
					<p>
						<label><?php esc_html_e( 'Marka', 'wp-cpt-sidrene-cijene' ); ?>
							<?php $this->field_select( 'brand', $pt, $discovery, isset( $defaults['brand'] ) ? $defaults['brand'] : null, true ); ?>
						</label>
					</p>
					<p>
						<label><?php esc_html_e( 'Jedinica mjere', 'wp-cpt-sidrene-cijene' ); ?>
							<?php $this->field_select( 'unit', $pt, $discovery, isset( $defaults['unit'] ) ? $defaults['unit'] : null, true, true ); ?>
						</label>
						<input type="text" name="unit_const_<?php echo esc_attr( $pt ); ?>" placeholder="<?php esc_attr_e( 'npr. kom', 'wp-cpt-sidrene-cijene' ); ?>" value="<?php echo esc_attr( isset( $defaults['unit']['value'] ) ? $defaults['unit']['value'] : '' ); ?>">
					</p>
					<p>
						<label><?php esc_html_e( 'Barkod', 'wp-cpt-sidrene-cijene' ); ?>
							<?php $this->field_select( 'barcode', $pt, $discovery, isset( $defaults['barcode'] ) ? $defaults['barcode'] : null, true ); ?>
						</label>
					</p>
					<p>
						<label><?php esc_html_e( 'Cijena jedinice mjere', 'wp-cpt-sidrene-cijene' ); ?>
							<?php $this->field_select( 'unit_price', $pt, $discovery, isset( $defaults['unit_price'] ) ? $defaults['unit_price'] : null, true ); ?>
						</label>
					</p>
				<?php endif; ?>
				<p>
					<label><?php esc_html_e( 'Redovna cijena', 'wp-cpt-sidrene-cijene' ); ?>
						<?php $this->field_select( 'regular_price', $pt, $discovery, isset( $defaults['regular_price'] ) ? $defaults['regular_price'] : null, true ); ?>
					</label>
				</p>
				<p>
					<label><?php esc_html_e( 'Snižena / akcijska cijena', 'wp-cpt-sidrene-cijene' ); ?>
						<?php $this->field_select( 'sale_price', $pt, $discovery, isset( $defaults['sale_price'] ) ? $defaults['sale_price'] : null, true ); ?>
					</label>
				</p>
				<p>
					<label><?php esc_html_e( 'Naziv posebnog oblika prodaje', 'wp-cpt-sidrene-cijene' ); ?>
						<?php $this->field_select( 'special_sale_name', $pt, $discovery, isset( $defaults['special_sale_name'] ) ? $defaults['special_sale_name'] : null, true ); ?>
					</label>
				</p>
				<p>
					<label><?php esc_html_e( 'Zastavica posebnog oblika prodaje', 'wp-cpt-sidrene-cijene' ); ?>
						<?php $this->field_select( 'special_sale', $pt, $discovery, isset( $defaults['special_sale'] ) ? $defaults['special_sale'] : null, true ); ?>
					</label>
				</p>
				<?php if ( 'mapped' === Settings::get( 'availability_policy', 'not_configured' ) ) : ?>
					<p>
						<label><?php esc_html_e( 'Dostupnost', 'wp-cpt-sidrene-cijene' ); ?>
							<?php $this->field_select( 'availability', $pt, $discovery, isset( $defaults['availability'] ) ? $defaults['availability'] : null, true ); ?>
						</label>
					</p>
				<?php endif; ?>
				<details>
					<summary><?php esc_html_e( 'Povijesna polja sidrene cijene (ako postoje)', 'wp-cpt-sidrene-cijene' ); ?></summary>
					<p class="description"><?php esc_html_e( 'Ako već imate zabilježenu cijenu s referentnog datuma u polju — mapirajte je ovdje. U suprotnom sidrene cijene uvozite CSV-om ili unesite ručno.', 'wp-cpt-sidrene-cijene' ); ?></p>
					<p>
						<label><?php esc_html_e( 'Povijesna cijena', 'wp-cpt-sidrene-cijene' ); ?>
							<?php $this->field_select( 'anchor_historical_price', $pt, $discovery, isset( $defaults['anchor_historical_price'] ) ? $defaults['anchor_historical_price'] : null, true ); ?>
						</label>
					</p>
					<p>
						<label><?php esc_html_e( 'Datum povijesne cijene', 'wp-cpt-sidrene-cijene' ); ?>
							<?php $this->field_select( 'anchor_historical_date', $pt, $discovery, isset( $defaults['anchor_historical_date'] ) ? $defaults['anchor_historical_date'] : null, true ); ?>
						</label>
					</p>
				</details>
			</div>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Reusable field select.
	 *
	 * @param string      $field     Field.
	 * @param string      $pt        Post type.
	 * @param array       $discovery Discovery.
	 * @param array|null  $current   Current spec.
	 * @param bool        $allow_na  Allow not-applicable.
	 * @param bool        $allow_const Allow constant.
	 */
	private function field_select( $field, $pt, array $discovery, $current, $allow_na = false, $allow_const = false ) {
		$name = 'field_' . $field . '_' . $pt;
		$val  = '';
		if ( is_array( $current ) ) {
			if ( isset( $current['source'] ) && in_array( $current['source'], array( 'acf', 'meta', 'core', 'taxonomy' ), true ) ) {
				$val = $current['source'] . '|' . ( isset( $current['key'] ) ? $current['key'] : '' );
			} elseif ( 'constant' === $current['source'] ) {
				$val = 'constant';
			} elseif ( 'manual' === $current['source'] ) {
				$val = 'manual';
			} elseif ( 'na' === $current['source'] ) {
				$val = 'na';
			}
		}
		?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<option value=""><?php esc_html_e( '— nije postavljeno —', 'wp-cpt-sidrene-cijene' ); ?></option>
			<?php foreach ( ( isset( $discovery['acf'] ) ? $discovery['acf'] : array() ) as $f ) : ?>
				<?php if ( ! empty( $f['scalar'] ) ) : ?>
					<option value="<?php echo esc_attr( 'acf|' . $f['name'] ); ?>" <?php selected( $val, 'acf|' . $f['name'] ); ?>><?php echo esc_html( 'ACF → ' . ( $f['label'] ? $f['label'] : $f['name'] ) ); ?></option>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php foreach ( ( isset( $discovery['meta'] ) ? $discovery['meta'] : array() ) as $f ) : ?>
				<option value="<?php echo esc_attr( 'meta|' . $f['key'] ); ?>" <?php selected( $val, 'meta|' . $f['key'] ); ?>><?php echo esc_html( 'Meta → ' . $f['key'] ); ?></option>
			<?php endforeach; ?>
			<?php foreach ( ( isset( $discovery['registered'] ) ? $discovery['registered'] : array() ) as $f ) : ?>
				<option value="<?php echo esc_attr( 'meta|' . $f['key'] ); ?>" <?php selected( $val, 'meta|' . $f['key'] ); ?>><?php echo esc_html( 'Meta (registrirano) → ' . $f['key'] ); ?></option>
			<?php endforeach; ?>
			<?php foreach ( ( isset( $discovery['taxonomies'] ) ? $discovery['taxonomies'] : array() ) as $t ) : ?>
				<option value="<?php echo esc_attr( 'taxonomy|' . $t['taxonomy'] ); ?>" <?php selected( $val, 'taxonomy|' . $t['taxonomy'] ); ?>><?php echo esc_html( 'Taxonomija → ' . $t['label'] ); ?></option>
			<?php endforeach; ?>
			<?php if ( $allow_const ) : ?>
				<option value="constant" <?php selected( $val, 'constant' ); ?>><?php esc_html_e( 'Fiksna vrijednost', 'wp-cpt-sidrene-cijene' ); ?></option>
			<?php endif; ?>
			<?php if ( $allow_na ) : ?>
				<option value="na" <?php selected( $val, 'na' ); ?>><?php esc_html_e( 'Nije primjenjivo', 'wp-cpt-sidrene-cijene' ); ?></option>
			<?php endif; ?>
		</select>
		<?php
	}

	private function step_taxonomies() {
		$types = (array) Settings::get( 'post_types', array() );
		$rules = Rules::all();
		$rule_map = array();
		foreach ( $rules as $r ) {
			$rule_map[ $r['taxonomy'] . '_' . $r['term_id'] ] = $r;
		}
		?>
		<h2><?php esc_html_e( 'Taxonomije i sidrene skupine', 'wp-cpt-sidrene-cijene' ); ?></h2>
		<p><?php esc_html_e( 'Odredite koja kategorija pripada kojoj sidrenoj skupini. Plugin nikad sam ne klasificira nejasne kategorije.', 'wp-cpt-sidrene-cijene' ); ?></p>

		<p>
			<label><?php esc_html_e( 'Zadana skupina (za stavke bez pravila)', 'wp-cpt-sidrene-cijene' ); ?>
				<select name="default_anchor_group">
					<?php foreach ( Groups::options() as $key => $lbl ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( Settings::get( 'default_anchor_group', 'general_2026' ), $key ); ?>><?php echo esc_html( $lbl ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</p>

		<div id="cptsc-term-rules">
			<?php
			$any = false;
			foreach ( $types as $pt ) {
				foreach ( get_object_taxonomies( $pt, 'objects' ) as $tax ) {
					$terms = get_terms(
						array(
							'taxonomy'   => $tax->name,
							'hide_empty' => false,
						)
					);
					if ( is_wp_error( $terms ) || empty( $terms ) ) {
						continue;
					}
					$any = true;
					echo '<div class="cptsc-tax-box"><h3>' . esc_html( $tax->label . ' (' . $tax->name . ')' ) . '</h3><ul>';
					foreach ( $terms as $term ) {
						$key   = $tax->name . '_' . $term->term_id;
						$rule  = isset( $rule_map[ $key ] ) ? $rule_map[ $key ] : null;
						$depth = Rules::depth_of( $term->term_id, $tax->name );
						echo '<li style="margin-left:' . esc_attr( $depth * 18 ) . 'px">';
						echo '<label class="cptsc-check"><input type="checkbox" class="cptsc-term-check" data-taxonomy="' . esc_attr( $tax->name ) . '" data-term="' . esc_attr( $term->term_id ) . '" ' . checked( (bool) $rule, true, false ) . '>';
						echo esc_html( $term->name );
						echo '</label> ';
						echo '<select class="cptsc-term-group" data-taxonomy="' . esc_attr( $tax->name ) . '" data-term="' . esc_attr( $term->term_id ) . '" ' . disabled( ! $rule, true, false ) . '>';
						foreach ( Groups::options() as $gkey => $glbl ) {
							echo '<option value="' . esc_attr( $gkey ) . '" ' . selected( $rule ? $rule['anchor_group'] : Settings::get( 'default_anchor_group', 'general_2026' ), $gkey, false ) . '>' . esc_html( $glbl ) . '</option>';
						}
						echo '</select> ';
						echo '<label class="cptsc-inline"><input type="checkbox" class="cptsc-term-desc" data-taxonomy="' . esc_attr( $tax->name ) . '" data-term="' . esc_attr( $term->term_id ) . '" ' . checked( $rule ? (bool) $rule['include_descendants'] : true, true, false ) . '> ' . esc_html__( 'uključi sve podkategorije', 'wp-cpt-sidrene-cijene' ) . '</label>';
						echo '</li>';
					}
					echo '</ul></div>';
				}
			}
			if ( ! $any ) {
				echo '<p>' . esc_html__( 'Nema pronađenih taxonomija za odabrane CPT-ove. Možete koristiti zadano pravilo.', 'wp-cpt-sidrene-cijene' ) . '</p>';
			}
			?>
		</div>
		<p id="cptsc-rules-status" class="description"></p>
		<?php
	}

	private function step_anchors() {
		$counts = \CPTSC\Catalog\Index::counts();
		?>
		<h2><?php esc_html_e( 'Sidrene cijene', 'wp-cpt-sidrene-cijene' ); ?></h2>
		<p><?php esc_html_e( 'Sidrena cijena je zaštićen povijesni podatak. Plugin je nikad ne izmišlja — bez dokaza ostaje prazna i označena kao nedostajuća.', 'wp-cpt-sidrene-cijene' ); ?></p>

		<div class="cptsc-cards">
			<div class="cptsc-card"><span class="cptsc-card-label"><?php esc_html_e( 'Stavki u indeksu', 'wp-cpt-sidrene-cijene' ); ?></span><strong><?php echo esc_html( number_format_i18n( $counts['total'] ) ); ?></strong></div>
			<div class="cptsc-card"><span class="cptsc-card-label"><?php esc_html_e( 'Bez sidrene cijene', 'wp-cpt-sidrene-cijene' ); ?></span><strong class="cptsc-warn"><?php echo esc_html( number_format_i18n( $counts['missing_anchor'] ) ); ?></strong></div>
		</div>

		<div class="cptsc-notice">
			<p><strong><?php esc_html_e( 'Načini unosa sidrenih cijena:', 'wp-cpt-sidrene-cijene' ); ?></strong></p>
			<ol>
				<li><?php esc_html_e( 'CSV uvoz (preporučeno za masovni prijenos) — u izborniku Uvoz.', 'wp-cpt-sidrene-cijene' ); ?></li>
				<li><?php esc_html_e( 'Ručni unos u metaboxu svake stavke.', 'wp-cpt-sidrene-cijene' ); ?></li>
				<li><?php esc_html_e( 'Povijesno polje (ako ste ga mapirali u prošlom koraku).', 'wp-cpt-sidrene-cijene' ); ?></li>
				<li><?php esc_html_e( 'Automatski zapis prvog uvrštenja za nove stavke nakon referentnog datuma.', 'wp-cpt-sidrene-cijene' ); ?></li>
			</ol>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=cptsc-import' ) ); ?>"><?php esc_html_e( 'Otvori CSV uvoz', 'wp-cpt-sidrene-cijene' ); ?></a>
			</p>
		</div>

		<label class="cptsc-check">
			<input type="checkbox" name="anchor_rules_confirmed" value="1" <?php checked( Settings::get( 'anchor_rules_confirmed', false ) ); ?>>
			<?php esc_html_e( 'Potvrđujem sidrene grupe i početne sidrene podatke (nastavit ću doradu uvozom ako treba)', 'wp-cpt-sidrene-cijene' ); ?>
		</label>
		<?php
	}

	private function step_frontend() {
		$frontend = Settings::get( 'frontend', array() );
		$state    = isset( $frontend['state'] ) ? $frontend['state'] : 'auto_disabled';
		$sample   = isset( $frontend['sample_post_id'] ) ? (int) $frontend['sample_post_id'] : 0;
		if ( ! $sample ) {
			$ids = get_posts(
				array(
					'post_type'   => (array) Settings::get( 'post_types', array() ),
					'post_status' => 'publish',
					'numberposts' => 1,
					'fields'      => 'ids',
				)
			);
			$sample = ! empty( $ids ) ? (int) $ids[0] : 0;
		}
		?>
		<h2><?php esc_html_e( 'Prikaz na stranicama', 'wp-cpt-sidrene-cijene' ); ?></h2>

		<h3><?php esc_html_e( 'A) Automatski prikaz', 'wp-cpt-sidrene-cijene' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Pronalazimo mjesto aktualne cijene na stranici stavke i uz nju prikazujemo sidrenu cijenu. Ništa se ne objavljuje dok mjesto ne potvrdite.', 'wp-cpt-sidrene-cijene' ); ?></p>

		<div class="cptsc-inspector">
			<p>
				<button type="button" class="button" id="cptsc-inspect" data-post="<?php echo esc_attr( $sample ); ?>" <?php disabled( ! $sample ); ?>>
					<?php esc_html_e( 'Pronađi mjesto cijene (uzorak)', 'wp-cpt-sidrene-cijene' ); ?>
				</button>
				<?php if ( ! $sample ) : ?>
					<span class="description"><?php esc_html_e( 'Dodajte prvo objavljenu stavku.', 'wp-cpt-sidrene-cijene' ); ?></span>
				<?php endif; ?>
			</p>
			<div id="cptsc-inspect-result"></div>
			<p>
				<strong><?php esc_html_e( 'Status:', 'wp-cpt-sidrene-cijene' ); ?></strong>
				<?php
				$state_labels = array(
					'auto_unverified' => __( 'Čeka potvrdu', 'wp-cpt-sidrene-cijene' ),
					'auto_verified'   => __( 'Potvrđeno — aktivno', 'wp-cpt-sidrene-cijene' ),
					'auto_disabled'   => __( 'Isključeno', 'wp-cpt-sidrene-cijene' ),
				);
				echo esc_html( isset( $state_labels[ $state ] ) ? $state_labels[ $state ] : $state );
				?>
			</p>
			<label class="cptsc-check">
				<input type="checkbox" name="auto_disabled" value="1" <?php checked( 'auto_disabled', $state ); ?>>
				<?php esc_html_e( 'Isključi automatski prikaz (koristit ću shortcode)', 'wp-cpt-sidrene-cijene' ); ?>
			</label>
		</div>

		<h3><?php esc_html_e( 'B) Shortcode (pouzdani način)', 'wp-cpt-sidrene-cijene' ); ?></h3>
		<p><code>[sidrena_cijena]</code> &nbsp; <code>[sidrena_cijena id="123"]</code></p>
		<p class="description"><?php esc_html_e( 'Radi u Elementor shortcode widgetu, Gutenberg shortcode bloku i klasičnom sadržaju. Isti prikaz kao automatski način.', 'wp-cpt-sidrene-cijene' ); ?></p>
		<?php
	}

	private function step_feed() {
		$multi = Settings::get( 'multi_location', false );
		?>
		<h2><?php esc_html_e( 'Podaci za digitalni cjenik', 'wp-cpt-sidrene-cijene' ); ?></h2>

		<fieldset>
			<legend><?php esc_html_e( 'Ima li poslovni subjekt više lokacija za koje je potrebno objavljivati zasebne cjenike?', 'wp-cpt-sidrene-cijene' ); ?></legend>
			<label class="cptsc-check"><input type="radio" name="multi_location" value="0" <?php checked( $multi, false ); ?>> <?php esc_html_e( 'Ne — jedan glavni cjenik', 'wp-cpt-sidrene-cijene' ); ?></label>
			<label class="cptsc-check"><input type="radio" name="multi_location" value="1" <?php checked( $multi, true ); ?>> <?php esc_html_e( 'Da — više lokacija', 'wp-cpt-sidrene-cijene' ); ?></label>
		</fieldset>

		<h3><?php esc_html_e( 'Glavni kanal', 'wp-cpt-sidrene-cijene' ); ?></h3>
		<?php
		$ch = Channel::default_channel();
		if ( $ch ) :
			?>
			<p>
				<label><?php esc_html_e( 'Oblik objekta', 'wp-cpt-sidrene-cijene' ); ?>
					<input type="text" name="ch_kind" value="<?php echo esc_attr( $ch['object_kind'] ); ?>" placeholder="prodavaonica / usluzni_objekt / webshop">
				</label>
			</p>
			<p>
				<label><?php esc_html_e( 'Adresa', 'wp-cpt-sidrene-cijene' ); ?>
					<input type="text" name="ch_address" value="<?php echo esc_attr( $ch['address'] ); ?>" placeholder="Ilica 150 Zagreb">
				</label>
			</p>
			<p>
				<label><?php esc_html_e( 'Oznaka objekta', 'wp-cpt-sidrene-cijene' ); ?>
					<input type="text" name="ch_code" value="<?php echo esc_attr( $ch['object_code'] ); ?>" placeholder="P-01">
				</label>
			</p>
			<p class="description">
				<?php
				$example = \CPTSC\Feed\Generator::build_filename( $ch, max( 1, (int) $ch['storage_sequence'] + 1 ) );
				/* translators: %s example filename */
				echo esc_html( sprintf( __( 'Primjer naziva datoteke: %s', 'wp-cpt-sidrene-cijene' ), $example ) );
				?>
			</p>
		<?php endif; ?>

		<label class="cptsc-check">
			<input type="checkbox" name="feed_enabled" value="1" <?php checked( Settings::get( 'feed.enabled', false ) ); ?>>
			<?php esc_html_e( 'Objavi javni CSV cjenik i HTML stranicu', 'wp-cpt-sidrene-cijene' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'CSV je dostupan bez prijave, bez JavaScripta. Prethodne verzije ostaju u arhivi najmanje 30 dana.', 'wp-cpt-sidrene-cijene' ); ?></p>
		<?php
	}

	private function step_preflight() {
		// Ensure initial index exists: launch job if empty.
		$counts = \CPTSC\Catalog\Index::counts();
		if ( 0 === $counts['total'] && \CPTSC\Mapping\SourceProfile::confirmed() ) {
			JobsManager::enqueue( 'FULL_REINDEX', array(), true );
			$counts['total'] = -1; // Marker: building.
		}
		?>
		<h2><?php esc_html_e( 'Provjera prije aktivacije', 'wp-cpt-sidrene-cijene' ); ?></h2>

		<?php if ( -1 === $counts['total'] ) : ?>
			<p class="cptsc-preflight-building">
				<span class="spinner is-active"></span>
				<?php esc_html_e( 'Početni indeks se gradi u pozadini. Osvježite stranicu za trenutak…', 'wp-cpt-sidrene-cijene' ); ?>
			</p>
			<p><button type="button" class="button" onclick="location.reload();"><?php esc_html_e( 'Osvježi', 'wp-cpt-sidrene-cijene' ); ?></button></p>
		<?php else : ?>
			<div class="cptsc-preflight-grid">
				<a class="cptsc-pf-card cptsc-ok" href="<?php echo esc_url( admin_url( 'admin.php?page=cptsc-catalog&filter=ready' ) ); ?>">
					<strong><?php esc_html_e( 'SPREMNO', 'wp-cpt-sidrene-cijene' ); ?></strong>
					<span><?php echo esc_html( number_format_i18n( $counts['ready'] ) ); ?></span>
				</a>
				<a class="cptsc-pf-card cptsc-warn" href="<?php echo esc_url( admin_url( 'admin.php?page=cptsc-catalog&filter=review' ) ); ?>">
					<strong><?php esc_html_e( 'ZA PROVJERU', 'wp-cpt-sidrene-cijene' ); ?></strong>
					<span><?php echo esc_html( number_format_i18n( $counts['review'] ) ); ?></span>
				</a>
				<a class="cptsc-pf-card cptsc-err" href="<?php echo esc_url( admin_url( 'admin.php?page=cptsc-catalog&filter=blocked' ) ); ?>">
					<strong><?php esc_html_e( 'BLOKIRANO', 'wp-cpt-sidrene-cijene' ); ?></strong>
					<span><?php echo esc_html( number_format_i18n( $counts['blocked'] ) ); ?></span>
				</a>
			</div>
			<?php if ( $counts['blocked'] > 0 ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Kliknite na Blokirano i riješite stavke prije produkcijske objave cjenika.', 'wp-cpt-sidrene-cijene' ); ?></p></div>
			<?php endif; ?>
			<label class="cptsc-check">
				<input type="checkbox" name="preflight_ack" value="1" <?php checked( Settings::get( 'preflight_ack', false ) ); ?>>
				<?php esc_html_e( 'Pregledao/la sam rezultate provjere', 'wp-cpt-sidrene-cijene' ); ?>
			</label>
		<?php endif; ?>
		<?php
	}

	private function step_activate() {
		$ready = $this->activation_checklist();
		$all_ok = ! in_array( false, $ready, true );
		?>
		<h2><?php esc_html_e( 'Aktivacija', 'wp-cpt-sidrene-cijene' ); ?></h2>
		<ul class="cptsc-checklist-big">
			<?php foreach ( $ready as $key => $ok ) : ?>
				<li class="<?php echo $ok ? 'is-ok' : 'is-not'; ?>">
					<?php
					$labels = array(
						'sources'   => __( 'CPT izvori potvrđeni', 'wp-cpt-sidrene-cijene' ),
						'price'     => __( 'Aktualna cijena mapirana', 'wp-cpt-sidrene-cijene' ),
						'rules'     => __( 'Sidrene grupe konfigurirane', 'wp-cpt-sidrene-cijene' ),
						'index'     => __( 'Inicijalni indeks izgrađen', 'wp-cpt-sidrene-cijene' ),
						'preflight' => __( 'Provjera prije aktivacije prihvaćena', 'wp-cpt-sidrene-cijene' ),
						'feed'      => __( 'Postavke digitalnog cjenika dovršene', 'wp-cpt-sidrene-cijene' ),
					);
					echo esc_html( isset( $labels[ $key ] ) ? $labels[ $key ] : $key );
					?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( $all_ok ) : ?>
			<p class="description"><?php esc_html_e( 'Sve je spremno. Aktivacijom plugin preuzima automatsku obradu i (ako je uključeno) javnu objavu cjenika.', 'wp-cpt-sidrene-cijene' ); ?></p>
		<?php else : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'Neke stavke još nisu dovršene. Možete se vratiti natrag i dovršiti ih — ili aktivirati kasnije iz pregleda.', 'wp-cpt-sidrene-cijene' ); ?></p></div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Activation checklist.
	 *
	 * @return array key => bool
	 */
	public function activation_checklist() {
		$profiles = SourceProfile::confirmed();
		$counts   = \CPTSC\Catalog\Index::counts();
		return array(
			'sources'   => ! empty( $profiles ),
			'price'     => ! empty( $profiles ) && null !== $profiles[0]->field( 'current_price' ),
			'rules'     => (bool) Settings::get( 'term_rules_confirmed', false ) && (bool) Settings::get( 'anchor_rules_confirmed', false ),
			// Pre-activation an empty index is normal (initial full reindex runs on activation).
			'index'     => $counts['total'] > 0 || 'ACTIVE' !== (string) Settings::get( 'setup_state', '' ),
			'preflight' => (bool) Settings::get( 'preflight_ack', false ) || $counts['total'] <= 0,
			'feed'      => true, // Optional feature — default single channel exists.
		);
	}

	/* ------------------------------------------------------------ AJAX */

	/**
	 * Save current step data.
	 */
	public function ajax_save_step() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cptsc_admin' ) ) {
			wp_send_json_error( array( 'message' => 'nonce' ), 403 );
		}
		$step = isset( $_POST['step'] ) ? (int) $_POST['step'] : 0;
		$post = wp_unslash( $_POST ); // phpcs:ignore

		switch ( $step ) {
			case 2:
				$type = isset( $post['item_type'] ) ? sanitize_key( $post['item_type'] ) : '';
				if ( ! in_array( $type, array( 'product', 'service' ), true ) ) {
					wp_send_json_error( array( 'message' => __( 'Odaberite vrstu sadržaja.', 'wp-cpt-sidrene-cijene' ) ) );
				}
				Settings::update(
					array(
						'item_type'   => $type,
						'setup_state' => 'SOURCE_SELECTED',
					)
				);
				break;

			case 3:
				$types = isset( $post['post_types'] ) && is_array( $post['post_types'] ) ? array_values( array_filter( array_map( 'sanitize_key', $post['post_types'] ) ) ) : array();
				if ( empty( $types ) ) {
					wp_send_json_error( array( 'message' => __( 'Odaberite barem jedan tip sadržaja.', 'wp-cpt-sidrene-cijene' ) ) );
				}
				Settings::update(
					array(
						'post_types'       => $types,
						'sources_confirmed' => true,
					)
				);
				break;

			case 4:
				Settings::set( 'setup_state', 'DISCOVERY_COMPLETE' );
				break;

			case 5:
				$wizard = Settings::get( 'wizard', array() );
				foreach ( (array) Settings::get( 'post_types', array() ) as $pt ) {
					$spec = $this->extract_price_spec( $pt, $post );
					if ( ! $spec ) {
						wp_send_json_error( array( 'message' => sprintf( /* translators: %s post type */ __( 'Postavite izvor cijene za "%s".', 'wp-cpt-sidrene-cijene' ), $pt ) ) );
					}
					$wizard['mapping'][ $pt ]['current_price'] = $spec;
				}
				Settings::set( 'wizard', $wizard );
				break;

			case 6:
				$wizard = Settings::get( 'wizard', array() );
				$item_type = Settings::get( 'item_type', 'product' );
				foreach ( (array) Settings::get( 'post_types', array() ) as $pt ) {
					foreach ( $this->other_fields( $item_type ) as $field ) {
						$sel = isset( $post[ 'field_' . $field . '_' . $pt ] ) ? (string) $post[ 'field_' . $field . '_' . $pt ] : '';
						$wizard['mapping'][ $pt ][ $field ] = $this->parse_field_select( $sel, $field, $pt, $post );
					}
					// Unit constant.
					if ( isset( $post[ 'unit_const_' . $pt ] ) && '' !== $post[ 'unit_const_' . $pt ] ) {
						$wizard['mapping'][ $pt ]['unit'] = array(
							'source' => 'constant',
							'value'  => sanitize_text_field( $post[ 'unit_const_' . $pt ] ),
						);
					}
				}
				Settings::set( 'wizard', $wizard );
				Settings::update(
					array(
						'special_sale_mode'    => isset( $post['special_sale_mode'] ) ? sanitize_key( $post['special_sale_mode'] ) : 'constant_false',
						'availability_policy'  => isset( $post['availability_policy'] ) ? sanitize_key( $post['availability_policy'] ) : Settings::get( 'availability_policy', 'not_configured' ),
						'applicability'        => isset( $post['applicability'] ) && is_array( $post['applicability'] ) ? array_map( 'sanitize_key', $post['applicability'] ) : Settings::get( 'applicability', array() ),
						'mapping_confirmed'    => true,
						'setup_state'          => 'MAPPING_CONFIRMED',
					)
				);
				$this->persist_profiles();
				break;

			case 7:
				// Term rules collected client-side → saved via cptsc_save_term_rules; here we only confirm + default group.
				// NOTE: anchor_rules_confirmed is reserved for step 8 (anchor data confirmation) — do NOT set it here.
				if ( isset( $post['default_anchor_group'] ) ) {
					Settings::set( 'default_anchor_group', sanitize_key( $post['default_anchor_group'] ) );
				}
				Settings::update(
					array(
						'term_rules_confirmed' => true,
						'setup_state'          => 'ANCHOR_RULES_CONFIRMED',
					)
				);
				break;

			case 8:
				if ( empty( $post['anchor_rules_confirmed'] ) && ! Settings::get( 'anchor_rules_confirmed', false ) ) {
					wp_send_json_error( array( 'message' => __( 'Potvrdite sidrene podatke.', 'wp-cpt-sidrene-cijene' ) ) );
				}
				Settings::set( 'anchor_rules_confirmed', true );
				break;

			case 9:
				$auto_off = ! empty( $post['auto_disabled'] );
				if ( $auto_off ) {
					Settings::update(
						array(
							'frontend' => array( 'state' => 'auto_disabled' ),
							'setup_state' => 'FRONTEND_CONFIGURED',
						)
					);
				} else {
					$frontend = Settings::get( 'frontend', array() );
					$state    = isset( $frontend['state'] ) ? $frontend['state'] : 'auto_disabled';
					if ( 'auto_verified' !== $state ) {
						// Keep whatever inspector verified; otherwise leave unverified/disabled.
						if ( 'auto_unverified' !== $state ) {
							Settings::set( 'frontend.state', 'auto_unverified' );
						}
					}
					Settings::set( 'setup_state', 'FRONTEND_CONFIGURED' );
				}
				break;

			case 10:
				Settings::update(
					array(
						'multi_location' => ! empty( $post['multi_location'] ),
						'feed'           => array( 'enabled' => ! empty( $post['feed_enabled'] ) ),
						'setup_state'    => 'FRONTEND_CONFIGURED',
					)
				);
				$ch = Channel::default_channel();
				if ( $ch ) {
					Channel::save(
						array(
							'name'        => $ch['name'],
							'type'        => $ch['type'],
							'object_kind' => isset( $post['ch_kind'] ) ? $post['ch_kind'] : $ch['object_kind'],
							'address'     => isset( $post['ch_address'] ) ? $post['ch_address'] : $ch['address'],
							'object_code' => isset( $post['ch_code'] ) ? $post['ch_code'] : $ch['object_code'],
							'enabled'     => 1,
							'is_default'  => 1,
						),
						(int) $ch['id']
					);
				}
				break;

			case 11:
				Settings::set( 'preflight_ack', ! empty( $post['preflight_ack'] ) );
				Settings::set( 'setup_state', 'PREFLIGHT_COMPLETE' );
				break;
		}

		// Advance step.
		$next = min( self::STEPS, $step + 1 );
		Settings::set( 'setup_step', $next );
		wp_send_json_success(
			array(
				'step'  => $next,
				'state' => Settings::get( 'setup_state' ),
			)
		);
	}

	/**
	 * Extract price mapping spec from request.
	 *
	 * @param string $pt   Post type.
	 * @param array  $post Request.
	 * @return array|null
	 */
	private function extract_price_spec( $pt, array $post ) {
		$radio = isset( $post[ 'cp_' . $pt ] ) ? json_decode( (string) $post[ 'cp_' . $pt ], true ) : null;
		if ( is_array( $radio ) && ! empty( $radio['source'] ) && ! empty( $radio['key'] ) ) {
			return array(
				'source' => sanitize_key( $radio['source'] ),
				'key'    => sanitize_text_field( $radio['key'] ),
			);
		}
		$manual_source = isset( $post[ 'manual_source_' . $pt ] ) ? sanitize_key( $post[ 'manual_source_' . $pt ] ) : '';
		$manual_key    = isset( $post[ 'manual_key_' . $pt ] ) ? sanitize_text_field( $post[ 'manual_key_' . $pt ] ) : '';
		if ( 'constant' === $manual_source && '' !== $manual_key ) {
			return array(
				'source' => 'constant',
				'value'  => $manual_key,
			);
		}
		if ( in_array( $manual_source, array( 'acf', 'meta', 'core', 'taxonomy' ), true ) && '' !== $manual_key ) {
			return array(
				'source' => $manual_source,
				'key'    => $manual_key,
			);
		}
		return null;
	}

	/**
	 * Other mapped fields for step 6.
	 *
	 * @param string $item_type Type.
	 * @return string[]
	 */
	private function other_fields( $item_type ) {
		$fields = array( 'code', 'regular_price', 'sale_price', 'special_sale', 'special_sale_name' );
		if ( 'product' === $item_type ) {
			$fields = array_merge( $fields, array( 'brand', 'unit', 'unit_price', 'barcode' ) );
		}
		$fields[] = 'availability';
		$fields[] = 'anchor_historical_price';
		$fields[] = 'anchor_historical_date';
		return $fields;
	}

	/**
	 * Parse "source|key" select value.
	 *
	 * @param string $sel   Value.
	 * @param string $field Field.
	 * @param string $pt    Post type.
	 * @param array  $post  Request.
	 * @return array|null
	 */
	private function parse_field_select( $sel, $field, $pt, array $post ) {
		if ( '' === $sel ) {
			return null;
		}
		if ( 'na' === $sel ) {
			return array( 'source' => 'na' );
		}
		if ( 'constant' === $sel ) {
			return array( 'source' => 'constant', 'value' => '' );
		}
		if ( false !== strpos( $sel, '|' ) ) {
			list( $source, $key ) = explode( '|', $sel, 2 );
			return array(
				'source' => sanitize_key( $source ),
				'key'    => sanitize_text_field( $key ),
			);
		}
		return null;
	}

	/**
	 * Persist confirmed source profiles from wizard mapping.
	 */
	private function persist_profiles() {
		$wizard    = Settings::get( 'wizard', array() );
		$item_type = Settings::get( 'item_type', 'product' );
		foreach ( (array) Settings::get( 'post_types', array() ) as $pt ) {
			$mapping = isset( $wizard['mapping'][ $pt ] ) ? $wizard['mapping'][ $pt ] : array();
			$mapping = array_filter( $mapping );
			if ( empty( $mapping['current_price'] ) ) {
				continue;
			}
			$profile            = SourceProfile::find( $pt );
			if ( ! $profile ) {
				$profile = new SourceProfile();
				$profile->post_type = $pt;
			}
			$profile->item_type = $item_type;
			$profile->fields    = $mapping;
			$profile->status    = 'confirmed';
			$profile->flags     = array(
				'availability_policy' => Settings::get( 'availability_policy', 'not_configured' ),
				'special_sale_mode'   => Settings::get( 'special_sale_mode', 'constant_false' ),
				'applicability'       => Settings::get( 'applicability', array() ),
			);
			SourceProfile::save( $profile );
		}
		// Keep flags in sync for all confirmed profiles.
		foreach ( SourceProfile::confirmed() as $profile ) {
			$profile->flags = array(
				'availability_policy' => Settings::get( 'availability_policy', 'not_configured' ),
				'special_sale_mode'   => Settings::get( 'special_sale_mode', 'constant_false' ),
				'applicability'       => Settings::get( 'applicability', array() ),
			);
			SourceProfile::save( $profile );
		}
	}

	/**
	 * Wizard back.
	 */
	public function ajax_back() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cptsc_admin' ) ) {
			wp_send_json_error( array( 'message' => 'nonce' ), 403 );
		}
		$step = isset( $_POST['step'] ) ? (int) $_POST['step'] : 1;
		$prev = max( 1, $step - 1 );
		Settings::set( 'setup_step', $prev );
		wp_send_json_success( array( 'step' => $prev ) );
	}

	/**
	 * Wizard next — save current + load next step HTML.
	 */
	public function ajax_next() {
		// Step save happens first via cptsc_wizard_save_step; this returns next HTML.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cptsc_admin' ) ) {
			wp_send_json_error( array( 'message' => 'nonce' ), 403 );
		}
		$step = isset( $_POST['step'] ) ? (int) $_POST['step'] : 1;
		ob_start();
		$this->render_step( min( self::STEPS, $step ) );
		wp_send_json_success(
			array(
				'step' => min( self::STEPS, $step ),
				'html' => ob_get_clean(),
			)
		);
	}

	/**
	 * Final activation.
	 */
	public function ajax_activate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cptsc_admin' ) ) {
			wp_send_json_error( array( 'message' => 'nonce' ), 403 );
		}
		$profiles = SourceProfile::confirmed();
		if ( empty( $profiles ) ) {
			wp_send_json_error( array( 'message' => __( 'Mapiranje nije potvrđeno.', 'wp-cpt-sidrene-cijene' ) ) );
		}
		Channel::ensure_default();
		Settings::update(
			array(
				'setup_state' => 'ACTIVE',
				'setup_step'  => self::STEPS,
				'version'     => CPTSC_VERSION,
			)
		);
		Cron::schedule_defaults();
		\CPTSC\Catalog\Queue::schedule_tick();
		JobsManager::enqueue( 'FULL_REINDEX', array(), true );
		do_action( 'cptsc_activated_production' );
		wp_send_json_success( array( 'redirect' => admin_url( 'admin.php?page=' . Admin::SLUG ) ) );
	}
}

<?php
/**
 * "Sidrena cijena" metabox on selected CPTs.
 *
 * @package CPTSC
 */

namespace CPTSC\Admin;

use CPTSC\Anchor\Audit;
use CPTSC\Anchor\Groups;
use CPTSC\Money;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Item metabox.
 */
final class Metabox {

	/**
	 * Box id.
	 */
	const BOX = 'cptsc_anchor_box';

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post', array( $this, 'save' ), 30, 2 );
	}

	/**
	 * Register box on selected CPTs.
	 */
	public function register() {
		foreach ( Settings::get( 'post_types', array() ) as $pt ) {
			add_meta_box(
				self::BOX,
				__( 'Sidrena cijena', 'wp-cpt-sidrene-cijene' ),
				array( $this, 'render' ),
				$pt,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render( $post ) {
		$item = \CPTSC\Catalog\Index::get_by_object( $post->ID );
		$row  = $item ? $item->data : null;
		?>
		<?php if ( ! $row ) : ?>
			<p class="description"><?php esc_html_e( 'Stavka još nije obrađena. Indeks se osvježava automatski u pozadini.', 'wp-cpt-sidrene-cijene' ); ?></p>
		<?php else : ?>
			<p>
				<strong><?php esc_html_e( 'Aktualna cijena:', 'wp-cpt-sidrene-cijene' ); ?></strong>
				<?php echo esc_html( null !== $row['current_price'] && '' !== $row['current_price'] ? Money::format_hr( $row['current_price'] ) . ' ' . Settings::get( 'currency_symbol', '€' ) : '—' ); ?>
				<br>
				<span class="description">
					<?php
					$source_label = '—';
					$profile      = \CPTSC\Mapping\SourceProfile::find( $row['post_type'] );
					if ( $profile ) {
						$spec = $profile->field( 'current_price' );
						if ( $spec && isset( $spec['key'] ) ) {
							$source_label = \CPTSC\Discovery\FieldScorer::display_label( $spec['source'], $spec['key'] );
						} elseif ( $spec ) {
							$source_label = \CPTSC\Discovery\FieldScorer::display_label( $spec['source'], isset( $spec['value'] ) ? (string) $spec['value'] : '' );
						}
					}
					echo esc_html( __( 'Izvor:', 'wp-cpt-sidrene-cijene' ) . ' ' . $source_label );
					?>
				</span>
			</p>
			<hr>
			<p>
				<strong><?php esc_html_e( 'Sidrena skupina:', 'wp-cpt-sidrene-cijene' ); ?></strong>
				<?php echo esc_html( $row['anchor_group'] ? Groups::label( $row['anchor_group'] ) : '—' ); ?>
				<?php if ( ! empty( $row['group_override'] ) ) : ?>
					<em><?php esc_html_e( '(ručno postavljena)', 'wp-cpt-sidrene-cijene' ); ?></em>
				<?php endif; ?>
				<br>
				<strong><?php esc_html_e( 'Sidreni datum:', 'wp-cpt-sidrene-cijene' ); ?></strong>
				<?php echo esc_html( $row['anchor_date'] ? \CPTSC\Dates::format_hr_date( $row['anchor_date'] ) : '—' ); ?>
				<br>
				<strong><?php esc_html_e( 'Sidrena cijena:', 'wp-cpt-sidrene-cijene' ); ?></strong>
				<?php echo esc_html( null !== $row['anchor_price'] && '' !== $row['anchor_price'] ? Money::format_hr( $row['anchor_price'] ) . ' ' . Settings::get( 'currency_symbol', '€' ) : __( 'nema', 'wp-cpt-sidrene-cijene' ) ); ?>
				<br>
				<strong><?php esc_html_e( 'Izvor:', 'wp-cpt-sidrene-cijene' ); ?></strong>
				<?php echo esc_html( $row['anchor_source'] ? $row['anchor_source'] : '—' ); ?>
				<br>
				<strong><?php esc_html_e( 'Status:', 'wp-cpt-sidrene-cijene' ); ?></strong>
				<?php
				if ( ! empty( $row['anchor_verified'] ) ) {
					echo esc_html__( 'Potvrđeno (zaštićeno)', 'wp-cpt-sidrene-cijene' );
				} elseif ( null !== $row['anchor_price'] && '' !== $row['anchor_price'] ) {
					echo esc_html__( 'Nepotvrđeno', 'wp-cpt-sidrene-cijene' );
				} else {
					echo esc_html__( 'Nedostaje sidrena cijena', 'wp-cpt-sidrene-cijene' );
				}
				?>
			</p>

			<?php if ( (int) $row['issue_mask'] > 0 ) : ?>
				<ul class="cptsc-metabox-issues">
					<?php foreach ( \CPTSC\Issues::describe( (int) $row['issue_mask'] ) as $issue ) : ?>
						<li><?php echo esc_html( $issue ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<details>
				<summary><?php esc_html_e( 'Ručni unos / izmjena sidrene cijene', 'wp-cpt-sidrene-cijene' ); ?></summary>
				<?php if ( ! empty( $row['anchor_verified'] ) ) : ?>
					<p class="description"><?php esc_html_e( 'Potvrđeni podatak se ne mijenja automatski. Izmjena se bilježi u povijesti.', 'wp-cpt-sidrene-cijene' ); ?></p>
				<?php endif; ?>
				<?php wp_nonce_field( 'cptsc_metabox_' . $post->ID ); ?>
				<p>
					<label><?php esc_html_e( 'Sidrena cijena', 'wp-cpt-sidrene-cijene' ); ?>
						<input type="text" name="cptsc_anchor_price" value="<?php echo esc_attr( null !== $row['anchor_price'] && '' !== $row['anchor_price'] ? Money::format_hr( $row['anchor_price'] ) : '' ); ?>" class="widefat">
					</label>
				</p>
				<p>
					<label><?php esc_html_e( 'Sidreni datum', 'wp-cpt-sidrene-cijene' ); ?>
						<input type="date" name="cptsc_anchor_date" value="<?php echo esc_attr( $row['anchor_date'] ? $row['anchor_date'] : '' ); ?>" class="widefat" max="<?php echo esc_attr( cptsc_metabox_today() ); ?>">
					</label>
				</p>
				<p>
					<label><?php esc_html_e( 'Sidrena skupina', 'wp-cpt-sidrene-cijene' ); ?>
						<select name="cptsc_anchor_group" class="widefat">
							<option value=""><?php esc_html_e( '(bez promjene)', 'wp-cpt-sidrene-cijene' ); ?></option>
							<?php foreach ( Groups::options() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row['anchor_group'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</p>
				<p>
					<label class="cptsc-check">
						<input type="checkbox" name="cptsc_verify" value="1">
						<?php esc_html_e( 'Potvrdi sidreni podatak (zaštićena povijesna vrijednost)', 'wp-cpt-sidrene-cijene' ); ?>
					</label>
				</p>
				<p>
					<button class="button button-primary" type="submit" name="cptsc_save_anchor" value="1"><?php esc_html_e( 'Spremi sidreni podatak', 'wp-cpt-sidrene-cijene' ); ?></button>
				</p>
			</details>

			<?php
			$audit = Audit::instance()->for_object( $post->ID, 5 );
			if ( $audit ) :
				?>
				<details>
					<summary><?php esc_html_e( 'Povijest izmjena', 'wp-cpt-sidrene-cijene' ); ?></summary>
					<ul class="cptsc-audit-list">
						<?php foreach ( $audit as $a ) : ?>
							<li>
								<strong><?php echo esc_html( $a['event'] ); ?></strong>
								— <?php echo esc_html( substr( (string) $a['created_at'], 0, 16 ) ); ?>
								<?php if ( $a['old_value'] || $a['new_value'] ) : ?>
									<br><small><?php echo esc_html( mb_substr( (string) $a['old_value'], 0, 60 ) ); ?> → <?php echo esc_html( mb_substr( (string) $a['new_value'], 0, 60 ) ); ?></small>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Save manual anchor data (explicit action only).
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post.
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['cptsc_save_anchor'] ) ) {
			return;
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cptsc_metabox_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		global $wpdb;
		$table = \CPTSC\Database::instance()->table( 'items' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE object_id = %d", (int) $post_id ), ARRAY_A );
		if ( ! $row ) {
			return;
		}

		$update = array();
		$user   = get_current_user_id();

		// Price.
		if ( isset( $_POST['cptsc_anchor_price'] ) ) {
			$raw    = sanitize_text_field( wp_unslash( $_POST['cptsc_anchor_price'] ) );
			$price  = '' === $raw ? null : Money::to_string( $raw );
			if ( '' !== $raw && null === $price ) {
				add_filter( 'redirect_post_location', function ( $loc ) { // phpcs:ignore
					return add_query_arg( 'cptsc_err', 'price', $loc );
				} );
				return;
			}
			if ( (string) $price !== (string) $row['anchor_price'] ) {
				Audit::instance()->log( $post_id, null !== $price ? ( empty( $row['anchor_price'] ) ? 'ANCHOR_SET' : 'ANCHOR_CHANGED' ) : 'ANCHOR_CHANGED', (string) $row['anchor_price'], (string) $price, 'manual', $user );
				$update['anchor_price'] = $price;
				if ( null === $price ) {
					// Explicit clear → unverify so the system does not treat it as confirmed history.
					$update['anchor_verified'] = 0;
					$update['anchor_override'] = 0;
					$update['anchor_source']   = null;
					$update['anchor_date']     = null;
				}
			}
		}

		// Date.
		if ( isset( $_POST['cptsc_anchor_date'] ) ) {
			$date = sanitize_text_field( wp_unslash( $_POST['cptsc_anchor_date'] ) );
			$date = '' === $date ? null : $date;
			if ( $date && ! \CPTSC\Dates::is_iso_date( $date ) ) {
				$date = null;
			}
			if ( $date !== $row['anchor_date'] ) {
				Audit::instance()->log( $post_id, 'ANCHOR_DATE_CHANGED', (string) $row['anchor_date'], (string) $date, 'manual', $user );
				$update['anchor_date'] = $date;
			}
		}

		// Group override.
		if ( isset( $_POST['cptsc_anchor_group'] ) && '' !== $_POST['cptsc_anchor_group'] ) {
			$group = sanitize_key( wp_unslash( $_POST['cptsc_anchor_group'] ) );
			if ( isset( Groups::all()[ $group ] ) && $group !== $row['group_override'] ) {
				Audit::instance()->log( $post_id, 'ANCHOR_GROUP_OVERRIDE', (string) $row['group_override'], $group, 'manual', $user );
				$update['group_override'] = $group;
				$update['anchor_group']   = $group;
			}
		}

		// Verify.
		if ( ! empty( $_POST['cptsc_verify'] ) && ! empty( $update['anchor_price'] ) && null !== $update['anchor_price'] ) {
			if ( empty( $row['anchor_verified'] ) ) {
				Audit::instance()->log( $post_id, 'ANCHOR_CHANGED', '', (string) $update['anchor_price'], 'manual', $user );
			}
			$update['anchor_verified'] = 1;
			$update['anchor_override'] = 1;
			$update['anchor_source']   = 'manual';
		}

		if ( $update ) {
			$update['changed_at'] = current_time( 'mysql' );
			$wpdb->update( $table, $update, array( 'id' => (int) $row['id'] ) );
			// Recompute hash/dirty flags via builder pass (anchor stays as stored — verified path).
			\CPTSC\Catalog\Builder::index_post( (int) $post_id );
		}
	}
}

/**
 * Today helper for metabox max date attribute.
 *
 * @return string
 */
function cptsc_metabox_today() {
	return \CPTSC\Dates::today();
}

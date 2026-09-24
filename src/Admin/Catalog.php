<?php
/**
 * Central catalog table with server-side paging, filters and search.
 *
 * @package CPTSC
 */

namespace CPTSC\Admin;

use CPTSC\Issues;
use CPTSC\Money;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catalog admin list.
 */
final class Catalog {

	/**
	 * Render.
	 */
	public function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only listing.
		$filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable

		$result = \CPTSC\Catalog\Index::query(
			array(
				'filter'   => $filter,
				'search'   => $search,
				'page'     => $paged,
				'per_page' => 20,
			)
		);

		$item_type = Settings::get( 'item_type', 'product' );
		$label     = 'service' === $item_type ? __( 'Usluge', 'wp-cpt-sidrene-cijene' ) : __( 'Proizvodi', 'wp-cpt-sidrene-cijene' );

		$filters = array(
			''                => __( 'Sve', 'wp-cpt-sidrene-cijene' ),
			'ready'           => __( 'Spremno', 'wp-cpt-sidrene-cijene' ),
			'review'          => __( 'Za provjeru', 'wp-cpt-sidrene-cijene' ),
			'blocked'         => __( 'Blokirano', 'wp-cpt-sidrene-cijene' ),
			'missing_anchor'  => __( 'Bez sidrene cijene', 'wp-cpt-sidrene-cijene' ),
			'missing_barcode' => __( 'Bez barkoda', 'wp-cpt-sidrene-cijene' ),
			'missing_brand'   => __( 'Bez marke', 'wp-cpt-sidrene-cijene' ),
			'group_conflict'  => __( 'Konflikt sidrene grupe', 'wp-cpt-sidrene-cijene' ),
			'new_items'       => __( 'Novi itemi', 'wp-cpt-sidrene-cijene' ),
			'excluded'        => __( 'Isključeno', 'wp-cpt-sidrene-cijene' ),
		);
		?>
		<div class="wrap cptsc-wrap">
			<h1><?php echo esc_html( $label ); ?></h1>

			<form method="get" class="cptsc-filters">
				<input type="hidden" name="page" value="cptsc-catalog">
				<select name="filter">
					<?php foreach ( $filters as $key => $lbl ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter, $key ); ?>><?php echo esc_html( $lbl ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Naziv, šifra, barkod, marka…', 'wp-cpt-sidrene-cijene' ); ?>">
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Primijeni', 'wp-cpt-sidrene-cijene' ); ?></button>
				<span class="description"><?php echo esc_html( sprintf( /* translators: %d */ __( 'Ukupno: %d', 'wp-cpt-sidrene-cijene' ), $result['total'] ) ); ?></span>
			</form>

			<table class="widefat striped cptsc-table cptsc-catalog-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Status', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Naziv', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Šifra', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'CPT', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Aktualna cijena', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Sidrena cijena', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Sidreni datum', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Sidrena grupa', 'wp-cpt-sidrene-cijene' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $result['rows'] ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'Nema stavki.', 'wp-cpt-sidrene-cijene' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $result['rows'] as $item ) : ?>
						<?php $row = $item->data; ?>
						<tr class="cptsc-level-<?php echo esc_attr( $row['validation_level'] ); ?>">
							<td>
								<span class="cptsc-badge cptsc-badge-<?php echo esc_attr( (int) $row['validation_level'] ); ?>">
									<?php
									$level_labels = array(
										0 => __( 'Spremno', 'wp-cpt-sidrene-cijene' ),
										1 => __( 'Za provjeru', 'wp-cpt-sidrene-cijene' ),
										2 => __( 'Blokirano', 'wp-cpt-sidrene-cijene' ),
										3 => __( 'Isključeno', 'wp-cpt-sidrene-cijene' ),
									);
									$ll = (int) $row['validation_level'];
									echo esc_html( isset( $level_labels[ $ll ] ) ? $level_labels[ $ll ] : $ll );
									?>
								</span>
								<?php if ( (int) $row['issue_mask'] > 0 ) : ?>
									<div class="cptsc-issues">
										<?php foreach ( array_slice( Issues::describe( (int) $row['issue_mask'] ), 0, 3 ) as $issue ) : ?>
											<span class="cptsc-issue" title="<?php echo esc_attr( $issue ); ?>"><?php echo esc_html( $issue ); ?></span>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</td>
							<td>
								<?php
								$edit = get_edit_post_link( (int) $row['object_id'] );
								if ( $edit ) {
									echo '<a href="' . esc_url( $edit ) . '">' . esc_html( $row['name'] ) . '</a>';
								} else {
									echo esc_html( $row['name'] );
								}
								?>
							</td>
							<td><?php echo esc_html( $row['code'] ? $row['code'] : '—' ); ?></td>
							<td><code><?php echo esc_html( $row['post_type'] ); ?></code></td>
							<td><?php echo esc_html( null !== $row['current_price'] && '' !== $row['current_price'] ? Money::format_hr( $row['current_price'] ) . ' ' . Settings::get( 'currency_symbol', '€' ) : '—' ); ?></td>
							<td>
								<?php
								if ( null !== $row['anchor_price'] && '' !== $row['anchor_price'] ) {
									echo esc_html( Money::format_hr( $row['anchor_price'] ) . ' ' . Settings::get( 'currency_symbol', '€' ) );
									if ( empty( $row['anchor_verified'] ) ) {
										echo ' <span class="description">' . esc_html__( '(nepotvrđeno)', 'wp-cpt-sidrene-cijene' ) . '</span>';
									}
								} else {
									echo '—';
								}
								?>
							</td>
							<td><?php echo esc_html( $row['anchor_date'] ? \CPTSC\Dates::format_hr_date( $row['anchor_date'] ) : '—' ); ?></td>
							<td>
								<?php echo esc_html( $row['anchor_group'] ? \CPTSC\Anchor\Groups::label( $row['anchor_group'] ) : '—' ); ?>
								<?php if ( ! empty( $row['group_override'] ) ) : ?>
									<span class="description"><?php esc_html_e( '(ručno)', 'wp-cpt-sidrene-cijene' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php
			if ( $result['pages'] > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $result['page'],
							'total'     => $result['pages'],
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}
}

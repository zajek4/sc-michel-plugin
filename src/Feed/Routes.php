<?php
/**
 * Public routes: HTML price list, stable current CSV, archive listing, archive files.
 * No JavaScript required for machine access. No authentication for published data.
 *
 * @package CPTSC
 */

namespace CPTSC\Feed;

use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-controller for /cjenik/* URLs.
 */
final class Routes {

	/**
	 * Query var keys.
	 */
	const QV = 'cptsc_feed';

	/**
	 * Register rewrite rules + query vars.
	 */
	public static function add_rules() {
		add_rewrite_rule(
			'^cjenik/aktualni\.csv$',
			'index.php?' . self::QV . '=current&channel=0',
			'top'
		);
		add_rewrite_rule(
			'^cjenik/aktualni-(\d+)\.csv$',
			'index.php?' . self::QV . '=current&channel=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^cjenik/arhiva/?$',
			'index.php?' . self::QV . '=archive',
			'top'
		);
		add_rewrite_rule(
			'^cjenik/arhiva/([^/]+\.csv)$',
			'index.php?' . self::QV . '=file&file=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^cjenik/?$',
			'index.php?' . self::QV . '=html',
			'top'
		);
	}

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'init', array( __CLASS__, 'add_rules' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'route' ), 1 );
	}

	/**
	 * Public query vars.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = self::QV;
		$vars[] = 'channel';
		$vars[] = 'file';
		return $vars;
	}

	/**
	 * Dispatch.
	 */
	public function route() {
		$type = get_query_var( self::QV );
		if ( ! $type ) {
			return;
		}
		switch ( $type ) {
			case 'current':
				$this->serve_current( (int) get_query_var( 'channel' ) );
				break;
			case 'file':
				$this->serve_archive_file( (string) get_query_var( 'file' ) );
				break;
			case 'archive':
				$this->render_archive();
				break;
			case 'html':
				$this->render_html();
				break;
		}
	}

	/**
	 * Stable current CSV (default channel reads aktualni.csv).
	 *
	 * @param int $channel_id Channel.
	 */
	private function serve_current( $channel_id ) {
		$default = Channel::default_channel();
		if ( $channel_id <= 0 ) {
			$channel_id = $default ? (int) $default['id'] : 0;
		}
		$filename = Archive::current_filename( $channel_id );
		$dir  = Archive::channel_dir( $channel_id );
		$path = trailingslashit( $dir ) . $filename;
		$this->stream( $path, $filename );
	}

	/**
	 * Archived file download — strict whitelist.
	 *
	 * @param string $filename Filename.
	 */
	private function serve_archive_file( $filename ) {
		$filename = rawurldecode( $filename );
		if ( ! Archive::is_safe_filename( $filename ) ) {
			status_header( 404 );
			exit;
		}
		// Search all channels for this published file.
		foreach ( Channel::all() as $channel ) {
			$path = Archive::archive_path( $filename, $channel['id'] );
			if ( $path && is_readable( $path ) ) {
				// Only serve files that are registered as published versions.
				if ( $this->is_published_filename( $filename, (int) $channel['id'] ) ) {
					$this->stream( $path, $filename );
				}
			}
		}
		status_header( 404 );
		exit;
	}

	/**
	 * Filename registered as published?
	 *
	 * @param string $filename   Name.
	 * @param int    $channel_id Channel.
	 * @return bool
	 */
	private function is_published_filename( $filename, $channel_id ) {
		global $wpdb;
		$table = \CPTSC\Database::instance()->table( 'feed_versions' );
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE channel_id = %d AND filename = %s AND status = 'published'",
				$channel_id,
				$filename
			)
		);
		return $count > 0;
	}

	/**
	 * Stream a CSV to the client without auth.
	 *
	 * @param string $path     Path.
	 * @param string $filename Download name.
	 */
	private function stream( $path, $filename ) {
		if ( ! $path || ! is_readable( $path ) ) {
			status_header( 404 );
			exit;
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . basename( $filename ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * HTML archive listing.
	 */
	private function render_archive() {
		if ( ! Settings::get( 'feed.html_enabled', true ) ) {
			status_header( 404 );
			exit;
		}
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		$channel = Channel::default_channel();
		$versions = $channel ? Archive::versions( $channel['id'], 200 ) : array();
		echo $this->layout( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Arhiva cjenika', 'wp-cpt-sidrene-cijene' ),
			$this->archive_table( $versions, $channel )
		);
		exit;
	}

	/**
	 * Archive table HTML.
	 *
	 * @param array[] $versions Versions.
	 * @param array   $channel  Channel.
	 * @return string
	 */
	private function archive_table( array $versions, $channel ) {
		ob_start();
		?>
		<p>
			<a class="cptsc-csv-link" href="<?php echo esc_url( Archive::current_url( $channel ? (int) $channel['id'] : 0 ) ); ?>"><?php esc_html_e( 'Preuzmi aktualni cjenik (CSV)', 'wp-cpt-sidrene-cijene' ); ?></a>
		</p>
		<table class="cptsc-table cptsc-archive-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Objavljeno', 'wp-cpt-sidrene-cijene' ); ?></th>
					<th><?php esc_html_e( 'Redaka', 'wp-cpt-sidrene-cijene' ); ?></th>
					<th><?php esc_html_e( 'Veličina', 'wp-cpt-sidrene-cijene' ); ?></th>
					<th><?php esc_html_e( 'Datoteka', 'wp-cpt-sidrene-cijene' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $versions ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'Još nema objavljenih verzija.', 'wp-cpt-sidrene-cijene' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $versions as $v ) : ?>
					<tr>
						<td><?php echo esc_html( \CPTSC\Dates::format_hr_date( substr( (string) $v['published_at'], 0, 10 ) ) . ' ' . esc_html( substr( (string) $v['published_at'], 11, 5 ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $v['row_count'] ) ); ?></td>
						<td><?php echo esc_html( size_format( (int) $v['file_size'] ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( home_url( '/cjenik/arhiva/' . rawurlencode( $v['filename'] ) ) ); ?>">
								<?php esc_html_e( 'Preuzmi', 'wp-cpt-sidrene-cijene' ); ?>
							</a>
							<code><?php echo esc_html( $v['filename'] ); ?></code>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
		return ob_get_clean();
	}

	/**
	 * Public HTML price list with search + server-side pagination.
	 */
	private function render_html() {
		if ( ! Settings::get( 'feed.html_enabled', true ) ) {
			status_header( 404 );
			exit;
		}
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per    = 50;

		// Restrict to published, canonical rows for the public list.
		global $wpdb;
		$table = \CPTSC\Database::instance()->table( 'items' );
		$total = 0;
		$rows  = array();
		$where = "validation_level < 3 AND publication_state = 'publish' AND object_id = canonical_object_id";
		$args  = array();
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND (name LIKE %s OR code LIKE %s OR barcode LIKE %s OR brand LIKE %s)';
			$args   = array( $like, $like, $like, $like );
		}
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) : "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$offset = ( $paged - 1 ) * $per;
		$rows   = (array) $wpdb->get_results(
			$args
				? $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY name ASC LIMIT %d OFFSET %d", array_merge( $args, array( $per, $offset ) ) )
				: $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY name ASC LIMIT %d OFFSET %d", $per, $offset ),
			ARRAY_A
		);

		$item_type = Settings::get( 'item_type', 'product' );
		$pages     = (int) ceil( $total / $per );

		echo $this->layout( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Cjenik', 'wp-cpt-sidrene-cijene' ),
			$this->html_list( $rows, $total, $paged, $pages, $search, $item_type )
		);
		exit;
	}

	/**
	 * Public list markup.
	 *
	 * @param array[] $rows      Rows.
	 * @param int     $total     Total.
	 * @param int     $paged     Page.
	 * @param int     $pages     Pages.
	 * @param string  $search    Search.
	 * @param string  $item_type Type.
	 * @return string
	 */
	private function html_list( array $rows, $total, $paged, $pages, $search, $item_type ) {
		ob_start();
		?>
		<form class="cptsc-search" method="get" action="<?php echo esc_url( home_url( '/cjenik/' ) ); ?>">
			<label class="screen-reader-text" for="cptsc-s"><?php esc_html_e( 'Pretraži cjenik', 'wp-cpt-sidrene-cijene' ); ?></label>
			<input type="search" id="cptsc-s" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Naziv, šifra, barkod, marka…', 'wp-cpt-sidrene-cijene' ); ?>">
			<button type="submit"><?php esc_html_e( 'Pretraži', 'wp-cpt-sidrene-cijene' ); ?></button>
			<a class="cptsc-csv-link" href="<?php echo esc_url( Archive::current_url() ); ?>"><?php esc_html_e( 'CSV cjenik', 'wp-cpt-sidrene-cijene' ); ?></a>
			<a class="cptsc-csv-link" href="<?php echo esc_url( home_url( '/cjenik/arhiva/' ) ); ?>"><?php esc_html_e( 'Arhiva', 'wp-cpt-sidrene-cijene' ); ?></a>
		</form>
		<p class="cptsc-total"><?php echo esc_html( sprintf( /* translators: %d count */ __( 'Ukupno: %d', 'wp-cpt-sidrene-cijene' ), $total ) ); ?></p>
		<div class="cptsc-table-wrap">
		<table class="cptsc-table cptsc-price-list">
			<thead>
				<tr>
					<?php if ( 'service' === $item_type ) : ?>
						<th><?php esc_html_e( 'Naziv usluge', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Cijena', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Sidrena cijena', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Datum', 'wp-cpt-sidrene-cijene' ); ?></th>
					<?php else : ?>
						<th><?php esc_html_e( 'Naziv', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Šifra', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Marka', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Cijena', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Sidrena cijena', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Datum', 'wp-cpt-sidrene-cijene' ); ?></th>
						<th><?php esc_html_e( 'Dostupnost', 'wp-cpt-sidrene-cijene' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'Nema rezultata.', 'wp-cpt-sidrene-cijene' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<?php if ( 'service' === $item_type ) : ?>
							<td><?php echo esc_html( $r['name'] ); ?></td>
							<td><?php echo esc_html( \CPTSC\Money::format_hr( $r['current_price'] ) . ' ' . Settings::get( 'currency_symbol', '€' ) ); ?></td>
							<td><?php echo esc_html( null !== $r['anchor_price'] && '' !== $r['anchor_price'] ? \CPTSC\Money::format_hr( $r['anchor_price'] ) . ' ' . Settings::get( 'currency_symbol', '€' ) : '—' ); ?></td>
							<td><?php echo esc_html( $r['anchor_date'] ? \CPTSC\Dates::format_hr_date( $r['anchor_date'] ) : '—' ); ?></td>
						<?php else : ?>
							<td><?php echo esc_html( $r['name'] ); ?></td>
							<td><?php echo esc_html( $r['code'] ? $r['code'] : '—' ); ?></td>
							<td><?php echo esc_html( $r['brand'] ? $r['brand'] : '—' ); ?></td>
							<td><?php echo esc_html( \CPTSC\Money::format_hr( $r['current_price'] ) . ' ' . Settings::get( 'currency_symbol', '€' ) ); ?></td>
							<td><?php echo esc_html( null !== $r['anchor_price'] && '' !== $r['anchor_price'] ? \CPTSC\Money::format_hr( $r['anchor_price'] ) . ' ' . Settings::get( 'currency_symbol', '€' ) : '—' ); ?></td>
							<td><?php echo esc_html( $r['anchor_date'] ? \CPTSC\Dates::format_hr_date( $r['anchor_date'] ) : '—' ); ?></td>
							<td><?php echo esc_html( 'available' === $r['availability'] ? __( 'dostupno', 'wp-cpt-sidrene-cijene' ) : ( 'unavailable' === $r['availability'] ? __( 'nedostupno', 'wp-cpt-sidrene-cijene' ) : '—' ) ); ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		</div>
		<?php
		if ( $pages > 1 ) {
			echo '<nav class="cptsc-pagination">';
			if ( $paged > 1 ) {
				echo '<a href="' . esc_url( add_query_arg( array( 'paged' => $paged - 1, 's' => rawurlencode( $search ) ), home_url( '/cjenik/' ) ) ) . '">&laquo;</a> ';
			}
			for ( $p = max( 1, $paged - 3 ); $p <= min( $pages, $paged + 3 ); $p++ ) {
				if ( $p === $paged ) {
					echo '<span class="current">' . esc_html( $p ) . '</span> ';
				} else {
					echo '<a href="' . esc_url( add_query_arg( array( 'paged' => $p, 's' => rawurlencode( $search ) ), home_url( '/cjenik/' ) ) ) . '">' . esc_html( $p ) . '</a> ';
				}
			}
			if ( $paged < $pages ) {
				echo '<a href="' . esc_url( add_query_arg( array( 'paged' => $paged + 1, 's' => rawurlencode( $search ) ), home_url( '/cjenik/' ) ) ) . '">&raquo;</a>';
			}
			echo '</nav>';
		}
		return ob_get_clean();
	}

	/**
	 * Minimal HTML layout — inherits site fonts/colors.
	 *
	 * @param string $title Title.
	 * @param string $body  Body HTML.
	 * @return string
	 */
	private function layout( $title, $body ) {
		$css = '
		.cptsc-wrap{max-width:1100px;margin:2rem auto;padding:0 1rem;font-family:inherit}
		.cptsc-table{width:100%;border-collapse:collapse}
		.cptsc-table th,.cptsc-table td{padding:.55rem .6rem;border-bottom:1px solid rgba(127,127,127,.25);text-align:left;vertical-align:top}
		.cptsc-search{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin:1rem 0}
		.cptsc-search input[type=search]{flex:1;min-width:200px;padding:.45rem .6rem}
		.cptsc-pagination{display:flex;gap:.4rem;margin:1rem 0}
		.cptsc-csv-link{font-weight:600}
		.cptsc-archive-table code{display:block;font-size:.75rem;opacity:.7;word-break:break-all}
		';
		$home = home_url( '/' );
		return '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . esc_html( $title . ' — ' . get_bloginfo( 'name' ) ) . '</title>'
			. '<style>' . $css . '</style></head><body>'
			. '<div class="cptsc-wrap"><h1>' . esc_html( $title ) . '</h1>' . $body
			. '<p><a href="' . esc_url( $home ) . '">' . esc_html__( 'Natrag na početnu', 'wp-cpt-sidrene-cijene' ) . '</a></p></div></body></html>';
	}
}

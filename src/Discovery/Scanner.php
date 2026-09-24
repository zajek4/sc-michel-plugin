<?php
/**
 * Read-only discovery: samples posts per CPT and aggregates field candidates.
 * Setup/maintenance operation only — never runs on frontend requests.
 *
 * @package CPTSC
 */

namespace CPTSC\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates meta / ACF / taxonomy discovery for one post type.
 */
class Scanner {

	/**
	 * Sample size (expandable).
	 *
	 * @var int
	 */
	private $sample_size;

	/**
	 * Constructor.
	 *
	 * @param int $sample_size Sample size.
	 */
	public function __construct( $sample_size = 0 ) {
		$this->sample_size = $sample_size ? (int) $sample_size : apply_filters( 'cptsc_discovery_sample', 100 );
	}

	/**
	 * Run discovery for a post type. Returns a structured, JSON-safe report.
	 *
	 * @param string $post_type Post type.
	 * @return array
	 */
	public function scan( $post_type ) {
		$post_type = sanitize_key( $post_type );
		$ids       = $this->sample_ids( $post_type );

		$meta_scanner     = new MetaScanner();
		$taxonomy_scanner = new TaxonomyScanner();
		$acf_scanner      = new ACFScanner();
		$scorer           = new FieldScorer();

		$meta_keys     = $meta_scanner->keys_for_posts( $ids );
		$registered    = $meta_scanner->registered_keys( $post_type );
		$taxonomies    = $taxonomy_scanner->taxonomies( $post_type, $ids );
		$acf_fields    = $acf_scanner->fields( $post_type );
		$core_fields   = $this->core_fields( $post_type );

		$price_candidates = $scorer->score(
			array(
				'core'      => $core_fields,
				'meta'      => $meta_keys,
				'registered'=> $registered,
				'acf'       => $acf_fields,
				'taxonomy'  => $taxonomies,
			),
			$ids
		);

		return array(
			'post_type'      => $post_type,
			'sampled'        => count( $ids ),
			'sample_ids'     => $ids,
			'total_estimate' => $this->total_estimate( $post_type ),
			'core'           => $core_fields,
			'meta'           => $meta_keys,
			'registered'     => $registered,
			'acf'            => $acf_fields,
			'taxonomies'     => $taxonomies,
			'price_candidates' => $price_candidates,
			'scanned_at'     => current_time( 'mysql' ),
		);
	}

	/**
	 * Keyset-based sample of post IDs (never OFFSET, never -1).
	 *
	 * @param string $post_type Post type.
	 * @return int[]
	 */
	private function sample_ids( $post_type ) {
		global $wpdb;
		$limit = max( 10, min( 500, (int) $this->sample_size ) );

		// Evenly-ish distributed sample: take latest N (representative enough for mapping).
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft','pending','future','private') ORDER BY ID DESC LIMIT %d",
				$post_type,
				$limit
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Approximate total count.
	 *
	 * @param string $post_type Post type.
	 * @return int
	 */
	private function total_estimate( $post_type ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft','inherit')",
				$post_type
			)
		);
	}

	/**
	 * Core fields offered for mapping.
	 *
	 * @param string $post_type Post type.
	 * @return array[]
	 */
	private function core_fields( $post_type ) {
		$fields = array(
			array(
				'source' => 'core',
				'key'    => 'post_title',
				'label'  => __( 'Naziv (post_title)', 'wp-cpt-sidrene-cijene' ),
				'type'   => 'string',
			),
			array(
				'source' => 'core',
				'key'    => 'post_date',
				'label'  => __( 'Datum objave (post_date)', 'wp-cpt-sidrene-cijene' ),
				'type'   => 'date',
			),
			array(
				'source' => 'core',
				'key'    => 'post_modified',
				'label'  => __( 'Datum izmjene (post_modified)', 'wp-cpt-sidrene-cijene' ),
				'type'   => 'date',
			),
			array(
				'source' => 'core',
				'key'    => 'post_status',
				'label'  => __( 'Status (post_status)', 'wp-cpt-sidrene-cijene' ),
				'type'   => 'string',
			),
			array(
				'source' => 'core',
				'key'    => 'post_name',
				'label'  => __( 'Permalink slug (post_name)', 'wp-cpt-sidrene-cijene' ),
				'type'   => 'string',
			),
			array(
				'source' => 'core',
				'key'    => 'post_parent',
				'label'  => __( 'Nadređeni (post_parent)', 'wp-cpt-sidrene-cijene' ),
				'type'   => 'int',
			),
			array(
				'source' => 'core',
				'key'    => 'post_content',
				'label'  => __( 'Sadržaj (post_content)', 'wp-cpt-sidrene-cijene' ),
				'type'   => 'text',
			),
			array(
				'source' => 'core',
				'key'    => 'post_excerpt',
				'label'  => __( 'Izvadak (post_excerpt)', 'wp-cpt-sidrene-cijene' ),
				'type'   => 'text',
			),
		);
		return $fields;
	}
}

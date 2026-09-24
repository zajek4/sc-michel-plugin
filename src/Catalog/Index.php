<?php
/**
 * Read-side access to the normalized index. All frontend/admin reads go through here —
 * never through full-catalog scans of CPT data.
 *
 * @package CPTSC
 */

namespace CPTSC\Catalog;

use CPTSC\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Index repository.
 */
final class Index {

	/**
	 * Fetch item by original object (post) ID.
	 *
	 * @param int $object_id Post ID.
	 * @return Item|null
	 */
	public static function get_by_object( $object_id ) {
		global $wpdb;
		$table = Database::instance()->table( 'items' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE object_id = %d LIMIT 1", (int) $object_id ),
			ARRAY_A
		);
		return $row ? Item::from_row( $row ) : null;
	}

	/**
	 * Resolve an item for any (possibly translated) post ID via canonical mapping.
	 *
	 * @param int $post_id Post ID.
	 * @return Item|null
	 */
	public static function resolve_for_post( $post_id ) {
		$item = self::get_by_object( $post_id );
		if ( $item ) {
			return $item;
		}
		$canonical = \CPTSC\Integrations\Multilingual::canonical_post_id( $post_id );
		if ( $canonical && $canonical !== (int) $post_id ) {
			return self::get_by_object( $canonical );
		}
		return null;
	}

	/**
	 * Row counts by validation level.
	 *
	 * @return array {total, ready, review, blocked, excluded, missing_anchor}
	 */
	public static function counts() {
		global $wpdb;
		$table = Database::instance()->table( 'items' );
		$row   = $wpdb->get_row(
			"SELECT
				COUNT(*) AS total,
				COALESCE(SUM(validation_level = 0), 0) AS ready,
				COALESCE(SUM(validation_level = 1), 0) AS review,
				COALESCE(SUM(validation_level = 2), 0) AS blocked,
				COALESCE(SUM(validation_level = 3), 0) AS excluded,
				COALESCE(SUM(anchor_price IS NULL AND validation_level < 3), 0) AS missing_anchor
			 FROM {$table}",
			ARRAY_A
		);
		return array(
			'total'         => (int) $row['total'],
			'ready'         => (int) $row['ready'],
			'review'        => (int) $row['review'],
			'blocked'       => (int) $row['blocked'],
			'excluded'      => (int) $row['excluded'],
			'missing_anchor'=> (int) $row['missing_anchor'],
		);
	}

	/**
	 * Count items with a specific issue bit.
	 *
	 * @param int $bit Issue bit.
	 * @return int
	 */
	public static function count_issue( $bit ) {
		global $wpdb;
		$table = Database::instance()->table( 'items' );
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE (issue_mask & %d) > 0 AND validation_level < 3", (int) $bit )
		);
	}

	/**
	 * Admin list query (server-side paging + filters + search).
	 *
	 * @param array $args {filter, search, page, per_page}.
	 * @return array {rows: array[], total: int}
	 */
	public static function query( array $args ) {
		global $wpdb;
		$table   = Database::instance()->table( 'items' );
		$page    = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$per_page = min( 200, max( 10, isset( $args['per_page'] ) ? (int) $args['per_page'] : 20 ) );
		$where   = array( '1=1' );
		$params  = array();

		$filter = isset( $args['filter'] ) ? (string) $args['filter'] : '';
		switch ( $filter ) {
			case 'ready':
				$where[] = 'validation_level = 0';
				break;
			case 'review':
				$where[] = 'validation_level = 1';
				break;
			case 'blocked':
				$where[] = 'validation_level = 2';
				break;
			case 'excluded':
				$where[] = 'validation_level = 3';
				break;
			case 'missing_anchor':
				$where[] = 'anchor_price IS NULL AND validation_level < 3';
				break;
			case 'missing_barcode':
				$where[] = '(issue_mask & ' . \CPTSC\Issues::MISSING_BARCODE . ') > 0';
				break;
			case 'missing_brand':
				$where[] = '(issue_mask & ' . \CPTSC\Issues::MISSING_BRAND . ') > 0';
				break;
			case 'group_conflict':
				$where[] = '(issue_mask & ' . \CPTSC\Issues::ANCHOR_GROUP_CONFLICT . ') > 0';
				break;
			case 'new_items':
				$where[] = 'first_listing_state IN ("captured","requires_confirmation")';
				break;
		}

		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(name LIKE %s OR code LIKE %s OR barcode LIKE %s OR brand LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) );
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
		}
		$offset    = ( $page - 1 ) * $per_page;
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		return array(
			'rows'  => array_map( array( __CLASS__, 'hydrate' ), (array) $rows ),
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
			'page'  => $page,
		);
	}

	/**
	 * Hydrate row into Item.
	 *
	 * @param array $row Row.
	 * @return Item
	 */
	public static function hydrate( $row ) {
		return Item::from_row( $row );
	}

	/**
	 * Rows for feed streaming (keyset pagination, canonical only, published only).
	 *
	 * @param int   $last_id  Last seen ID.
	 * @param int   $limit    Batch size.
	 * @param array $extra_where Extra conditions array(sql, params).
	 * @return array[]
	 */
	public static function feed_batch( $last_id, $limit, array $extra_where = array() ) {
		global $wpdb;
		$table  = Database::instance()->table( 'items' );
		$where  = array( 'id > %d', 'validation_level < 3', 'publication_state = "publish"', 'object_id = canonical_object_id' );
		$params = array( (int) $last_id );
		if ( ! empty( $extra_where[0] ) ) {
			$where[] = $extra_where[0];
			$params  = array_merge( $params, $extra_where[1] );
		}
		$where_sql = implode( ' AND ', $where );
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id ASC LIMIT %d",
				array_merge( $params, array( (int) $limit ) )
			),
			ARRAY_A
		);
	}

	/**
	 * Highest item id (reindex cursor bound).
	 *
	 * @return int
	 */
	public static function max_id() {
		global $wpdb;
		$table = Database::instance()->table( 'items' );
		return (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$table}" );
	}
}

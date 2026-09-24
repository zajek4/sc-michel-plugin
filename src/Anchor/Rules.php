<?php
/**
 * Term → anchor group rules with explicit admin confirmation semantics.
 *
 * @package CPTSC
 */

namespace CPTSC\Anchor;

use CPTSC\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomy rules engine.
 */
final class Rules {

	/**
	 * All rules.
	 *
	 * @return array[]
	 */
	public static function all() {
		global $wpdb;
		$table = Database::instance()->table( 'term_rules' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY priority DESC, id ASC", ARRAY_A );
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'                  => (int) $row['id'],
				'taxonomy'            => (string) $row['taxonomy'],
				'term_id'             => (int) $row['term_id'],
				'anchor_group'        => (string) $row['anchor_group'],
				'include_descendants' => (int) $row['include_descendants'],
				'priority'            => (int) $row['priority'],
			);
		}
		return $out;
	}

	/**
	 * Upsert one rule.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term ID.
	 * @param string $group    Anchor group key.
	 * @param bool   $include_descendants Include descendants.
	 * @param int    $priority Priority (higher wins; default derived from term depth).
	 */
	public static function upsert( $taxonomy, $term_id, $group, $include_descendants = true, $priority = 0 ) {
		global $wpdb;
		$table = Database::instance()->table( 'term_rules' );
		$term  = get_term( (int) $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		if ( $priority <= 0 ) {
			$priority = self::depth_of( (int) $term_id, $taxonomy ) * 10;
		}
		$now = current_time( 'mysql' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (taxonomy, term_id, anchor_group, include_descendants, priority, created_at, updated_at)
				 VALUES (%s, %d, %s, %d, %d, %s, %s)
				 ON DUPLICATE KEY UPDATE anchor_group = VALUES(anchor_group), include_descendants = VALUES(include_descendants), priority = VALUES(priority), updated_at = VALUES(updated_at)",
				$taxonomy,
				(int) $term_id,
				$group,
				$include_descendants ? 1 : 0,
				(int) $priority,
				$now,
				$now
			)
		);
	}

	/**
	 * Delete a rule.
	 *
	 * @param int $id Rule id.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( Database::instance()->table( 'term_rules' ), array( 'id' => (int) $id ) );
	}

	/**
	 * Delete all rules (wizard replace / reset — never touches anchors).
	 */
	public static function delete_all() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Database::instance()->table( 'term_rules' ) );
	}

	/**
	 * Term depth (0 = top level).
	 *
	 * @param int    $term_id  Term.
	 * @param string $taxonomy Taxonomy.
	 * @return int
	 */
	public static function depth_of( $term_id, $taxonomy ) {
		$depth = 0;
		$guard = 0;
		$tid   = (int) $term_id;
		while ( $guard++ < 20 ) {
			$term = get_term( $tid, $taxonomy );
			if ( ! $term || is_wp_error( $term ) || ! $term->parent ) {
				break;
			}
			$depth++;
			$tid = (int) $term->parent;
		}
		return $depth;
	}

	/**
	 * Resolve anchor group for a set of object terms.
	 *
	 * Priority: most specific term rule (deepest/highest priority) per taxonomy;
	 * conflicting results across taxonomies => ANCHOR_GROUP_CONFLICT marker.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $taxonomy  Taxonomy to consider (one of the post's taxonomies).
	 * @param array  $rules     Preloaded rules (optional).
	 * @return array {group: string|null, conflict: bool, term_id: int|null, rule_id: int|null, matched_groups: string[]}
	 */
	public static function resolve_for_post( $post_id, $taxonomy = '', array $rules = null ) {
		if ( null === $rules ) {
			$rules = self::all();
		}
		$relevant = array();
		foreach ( $rules as $rule ) {
			if ( $taxonomy && $rule['taxonomy'] !== $taxonomy ) {
				continue;
			}
			$relevant[] = $rule;
		}
		$empty = array(
			'group'          => null,
			'conflict'       => false,
			'term_id'        => null,
			'rule_id'        => null,
			'matched_groups' => array(),
		);
		if ( empty( $relevant ) ) {
			return $empty;
		}

		$taxonomies = array();
		foreach ( $relevant as $rule ) {
			$taxonomies[ $rule['taxonomy'] ] = true;
		}

		$best_per_tax = array();
		foreach ( array_keys( $taxonomies ) as $tax ) {
			$term_ids = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
				continue;
			}
			$term_ids = array_map( 'intval', $term_ids );
			$best     = null;
			foreach ( $relevant as $rule ) {
				if ( $rule['taxonomy'] !== $tax ) {
					continue;
				}
				$matches = self::rule_matches( $rule, $term_ids, $tax );
				if ( $matches ) {
					// More specific rule wins: higher priority, then deeper term.
					if (
						null === $best
						|| $rule['priority'] > $best['priority']
						|| ( $rule['priority'] === $best['priority'] && self::depth_of( $rule['term_id'], $tax ) > self::depth_of( $best['term_id'], $tax ) )
					) {
						$best = $rule;
					}
				}
			}
			if ( $best ) {
				$best_per_tax[ $tax ] = $best;
			}
		}

		if ( empty( $best_per_tax ) ) {
			return $empty;
		}

		$groups = array();
		foreach ( $best_per_tax as $rule ) {
			$groups[ $rule['anchor_group'] ] = true;
		}
		$matched = array_keys( $groups );
		if ( count( $matched ) > 1 ) {
			return array(
				'group'          => null,
				'conflict'       => true,
				'term_id'        => (int) reset( $best_per_tax )['term_id'],
				'rule_id'        => (int) reset( $best_per_tax )['id'],
				'matched_groups' => $matched,
			);
		}

		$winner = reset( $best_per_tax );
		return array(
			'group'          => $winner['anchor_group'],
			'conflict'       => false,
			'term_id'        => (int) $winner['term_id'],
			'rule_id'        => (int) $winner['id'],
			'matched_groups' => $matched,
		);
	}

	/**
	 * Whether a rule matches the post's terms.
	 *
	 * @param array $rule     Rule.
	 * @param int[] $term_ids Post term ids in that taxonomy.
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	private static function rule_matches( array $rule, array $term_ids, $taxonomy ) {
		$target = (int) $rule['term_id'];
		foreach ( $term_ids as $tid ) {
			if ( $tid === $target ) {
				return true;
			}
			if ( $rule['include_descendants'] && self::is_descendant( $tid, $target, $taxonomy ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is $tid a descendant of $ancestor?
	 *
	 * @param int    $tid      Term.
	 * @param int    $ancestor Ancestor.
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	private static function is_descendant( $tid, $ancestor, $taxonomy ) {
		$guard = 0;
		$cur   = (int) $tid;
		while ( $guard++ < 20 && $cur > 0 ) {
			if ( $cur === (int) $ancestor ) {
				return true;
			}
			$term = get_term( $cur, $taxonomy );
			if ( ! $term || is_wp_error( $term ) || ! $term->parent ) {
				return false;
			}
			$cur = (int) $term->parent;
		}
		return false;
	}
}

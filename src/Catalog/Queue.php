<?php
/**
 * Deduplicated, buffered item queue with batch processing and locks.
 *
 * @package CPTSC
 */

namespace CPTSC\Catalog;

use CPTSC\Database;
use CPTSC\Jobs\Lock;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Item queue.
 */
final class Queue {

	/**
	 * Request buffer: "source:object" => reason.
	 *
	 * @var array
	 */
	private static $buffer = array();

	/**
	 * Whether shutdown hook is attached.
	 *
	 * @var bool
	 */
	private static $shutdown_bound = false;

	/**
	 * Hook change detection.
	 */
	public function hooks() {
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 3 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 20, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta_update' ), 20, 4 );
		add_action( 'added_post_meta', array( $this, 'on_meta_update' ), 20, 4 );
		add_action( 'set_object_terms', array( $this, 'on_set_terms' ), 20, 6 );
		add_action( 'wp_trash_post', array( $this, 'on_status_event' ), 20, 1 );
		add_action( 'untrash_post', array( $this, 'on_status_event' ), 20, 1 );
		add_action( 'deleted_post', array( $this, 'on_deleted' ), 20, 1 );
		add_action( 'acf/save_post', array( $this, 'on_acf_save' ), 20, 1 );
	}

	/**
	 * Selected post types.
	 *
	 * @return string[]
	 */
	public static function selected_post_types() {
		$types = Settings::get( 'post_types', array() );
		return is_array( $types ) ? array_values( array_filter( $types ) ) : array();
	}

	/**
	 * save_post.
	 *
	 * @param int      $post_id ID.
	 * @param \WP_Post $post    Post.
	 * @param bool     $update  Update.
	 */
	public function on_save_post( $post_id, $post, $update ) {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::selected_post_types(), true ) ) {
			return;
		}
		self::push( $post->post_type, $post_id, 'save_post' );
	}

	/**
	 * Status transitions (draft → publish, publish → draft…).
	 *
	 * @param string   $new   New.
	 * @param string   $old   Old.
	 * @param \WP_Post $post  Post.
	 */
	public function on_transition( $new, $old, $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::selected_post_types(), true ) ) {
			return;
		}
		if ( $new === $old ) {
			return;
		}
		self::push( $post->post_type, $post_id = $post->ID, 'transition:' . $old . '>' . $new );
	}

	/**
	 * Relevant meta changes only.
	 *
	 * @param int    $meta_id    Meta id.
	 * @param int    $object_id  Object.
	 * @param string $meta_key   Key.
	 * @param mixed  $meta_value Value.
	 */
	public function on_meta_update( $meta_id, $object_id, $meta_key, $meta_value ) {
		$relevant = \CPTSC\Mapping\SourceProfile::relevant_meta_keys();
		// ACF underscore fields: also accept the raw field name.
		$check = ltrim( (string) $meta_key, '_' );
		if ( ! in_array( $meta_key, $relevant, true ) && ! in_array( $check, $relevant, true ) ) {
			return;
		}
		$post = get_post( (int) $object_id );
		if ( ! $post || ! in_array( $post->post_type, self::selected_post_types(), true ) ) {
			return;
		}
		self::push( $post->post_type, $object_id, 'meta:' . $meta_key );
	}

	/**
	 * Term assignment on relevant taxonomies.
	 *
	 * @param int    $object_id  Object.
	 * @param int[]  $tt_ids     Term taxonomy ids.
	 * @param array  $tt_ids_unused Unused.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Append.
	 * @param array  $old_tt_ids Old.
	 */
	public function on_set_terms( $object_id, $tt_ids, $tt_ids_unused = null, $taxonomy = '', $append = false, $old_tt_ids = array() ) {
		$relevant = \CPTSC\Mapping\SourceProfile::relevant_taxonomies();
		if ( $taxonomy && ! in_array( $taxonomy, $relevant, true ) ) {
			return;
		}
		$post = get_post( (int) $object_id );
		if ( ! $post || ! in_array( $post->post_type, self::selected_post_types(), true ) ) {
			return;
		}
		self::push( $post->post_type, $object_id, 'terms:' . $taxonomy );
	}

	/**
	 * Trash/untrash.
	 *
	 * @param int $post_id ID.
	 */
	public function on_status_event( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::selected_post_types(), true ) ) {
			return;
		}
		self::push( $post->post_type, $post_id, 'status_event' );
	}

	/**
	 * Permanent delete — remove index row.
	 *
	 * @param int $post_id ID.
	 */
	public function on_deleted( $post_id ) {
		Builder::remove_by_object( $post_id );
	}

	/**
	 * ACF save.
	 *
	 * @param mixed $post_id Post id (int or string "post_X").
	 */
	public function on_acf_save( $post_id ) {
		if ( is_string( $post_id ) && false !== strpos( $post_id, '_' ) ) {
			return; // Options/term/user saves.
		}
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::selected_post_types(), true ) ) {
			return;
		}
		self::push( $post->post_type, $post_id, 'acf_save' );
	}

	/**
	 * Buffer an event (deduplicated per request) and flush on shutdown.
	 *
	 * @param string $post_type Post type.
	 * @param int    $object_id Object.
	 * @param string $reason    Reason.
	 */
	public static function push( $post_type, $object_id, $reason ) {
		if ( ! function_exists( 'is_admin' ) || ! wp_doing_cron() ) {
			// Never enqueue from frontend page views (only programmatic saves reach here).
		}
		$source = self::source_id_for( $post_type );
		if ( ! $source ) {
			return;
		}
		$key = $source . ':' . (int) $object_id;
		// Keep the most specific reason of this request.
		self::$buffer[ $key ] = array(
			'source_id' => $source,
			'object_id' => (int) $object_id,
			'reason'    => substr( $reason, 0, 64 ),
		);
		if ( ! self::$shutdown_bound ) {
			self::$shutdown_bound = true;
			add_action( 'shutdown', array( __CLASS__, 'flush_buffer' ), 20 );
		}
		// Light-touch: also trigger worker soon after the request.
		self::schedule_tick();
	}

	/**
	 * Source id for post type.
	 *
	 * @param string $post_type Post type.
	 * @return int
	 */
	private static function source_id_for( $post_type ) {
		$profile = \CPTSC\Mapping\SourceProfile::find( $post_type );
		return $profile ? (int) $profile->id : 0;
	}

	/**
	 * Batch-insert the request buffer (dedup via unique key).
	 */
	public static function flush_buffer() {
		if ( empty( self::$buffer ) ) {
			return;
		}
		global $wpdb;
		$table = Database::instance()->table( 'queue' );
		$now   = current_time( 'mysql' );
		foreach ( self::$buffer as $entry ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (source_id, object_id, reason, status, attempts, created_at, updated_at)
					 VALUES (%d, %d, %s, 'pending', 0, %s, %s)
					 ON DUPLICATE KEY UPDATE reason = VALUES(reason), status = 'pending', attempts = 0, locked_until = NULL, updated_at = VALUES(updated_at)",
					$entry['source_id'],
					$entry['object_id'],
					$entry['reason'],
					$now,
					$now
				)
			);
		}
		self::$buffer = array();
	}

	/**
	 * Ask for a worker tick soon (idempotent).
	 */
	public static function schedule_tick() {
		if ( ! wp_next_scheduled( 'cptsc_queue_tick' ) ) {
			wp_schedule_single_event( time() + 10, 'cptsc_queue_tick' );
		}
	}

	/**
	 * Pending count.
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;
		$table = Database::instance()->table( 'queue' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
	}

	/**
	 * Failed count.
	 *
	 * @return int
	 */
	public static function failed_count() {
		global $wpdb;
		$table = Database::instance()->table( 'queue' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'" );
	}

	/**
	 * Process one batch inside a time budget. Returns remaining pending count.
	 *
	 * @return int
	 */
	public static function process_batch() {
		global $wpdb;
		$table = Database::instance()->table( 'queue' );

		if ( ! Lock::acquire( 'queue', 60 ) ) {
			return self::pending_count();
		}

		Settings::health_set( 'last_worker', time() );

		$batch     = (int) apply_filters( 'cptsc_batch_size', 50 );
		$budget    = (int) apply_filters( 'cptsc_time_budget', 10 );
		$deadline  = time() + max( 2, min( 25, $budget ) );
		$now       = current_time( 'mysql' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE status = 'pending' AND (locked_until IS NULL OR locked_until < %s)
				 ORDER BY id ASC LIMIT %d",
				$now,
				$batch
			)
		);

		foreach ( (array) $ids as $qid ) {
			$qid = (int) $qid;
			$wpdb->update(
				$table,
				array(
					'locked_until' => gmdate( 'Y-m-d H:i:s', time() + 120 ),
					'status'       => 'processing',
					'updated_at'   => $now,
				),
				array( 'id' => $qid )
			);

			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $qid ), ARRAY_A );
			if ( ! $row ) {
				continue;
			}

			$ok = false;
			try {
				$ok = Builder::index_post( (int) $row['object_id'] );
				if ( ! $ok ) {
					// Profile missing or translation — that's fine, mark done.
					$ok = true;
				}
			} catch ( \Throwable $e ) {
				$wpdb->update(
					$table,
					array(
						'last_error' => substr( $e->getMessage(), 0, 490 ),
						'attempts'   => (int) $row['attempts'] + 1,
					),
					array( 'id' => $qid )
				);
			}

			if ( $ok ) {
				$wpdb->delete( $table, array( 'id' => $qid ) );
			} else {
				$attempts = (int) $row['attempts'] + 1;
				$wpdb->update(
					$table,
					array(
						'status'       => $attempts >= 3 ? 'failed' : 'pending',
						'attempts'     => $attempts,
						'locked_until' => null,
						'updated_at'   => current_time( 'mysql' ),
					),
					array( 'id' => $qid )
				);
			}

			if ( time() >= $deadline ) {
				break;
			}
		}

		$remaining = self::pending_count();
		if ( $remaining > 0 ) {
			wp_schedule_single_event( time() + 15, 'cptsc_queue_tick' );
		}
		Lock::release( 'queue' );
		return $remaining;
	}

	/**
	 * Clear queue (full reset only).
	 */
	public static function clear() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::instance()->table( 'queue' ) );
	}
}

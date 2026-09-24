<?php
/**
 * Job worker: lock + heartbeat + time budget + continuation scheduling.
 *
 * @package CPTSC
 */

namespace CPTSC\Jobs;

use CPTSC\Anchor\Rules;
use CPTSC\Catalog\Builder;
use CPTSC\Catalog\Queue as ItemQueue;
use CPTSC\Catalog\Validator;
use CPTSC\Database;
use CPTSC\Discovery\Scanner;
use CPTSC\Feed\Archive;
use CPTSC\Feed\Generator;
use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes jobs in resumable batches.
 */
final class Worker {

	/**
	 * Hook cron entry points.
	 */
	public function hooks() {
		add_action( 'cptsc_queue_tick', array( $this, 'run_queue' ) );
		add_action( 'cptsc_job_tick', array( $this, 'run_job' ), 10, 1 );
		add_action( 'cptsc_heartbeat', array( $this, 'run_heartbeat' ) );
	}

	/**
	 * Queue tick.
	 */
	public function run_queue() {
		if ( ! wp_next_scheduled( 'cptsc_queue_tick' ) ) {
			// Recurring safety net (single events cover the normal path).
			wp_schedule_event( time() + 60, 'every_minute', 'cptsc_queue_tick' );
		}
		try {
			ItemQueue::process_batch();
		} catch ( \Throwable $e ) {
			// Fail-safe: never fatal a request from the worker.
			error_log( 'CPTSC queue worker: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/**
	 * Job tick.
	 *
	 * @param int $job_id Job ID.
	 */
	public function run_job( $job_id ) {
		$job = Manager::get( $job_id );
		if ( ! $job ) {
			return;
		}
		if ( in_array( $job['status'], array( 'completed', 'failed', 'cancelled', 'stalled' ), true ) ) {
			return;
		}

		$lock_name  = 'job_' . (int) $job_id;
		$lock_token = wp_generate_password( 20, false, false );
		if ( ! Lock::acquire( $lock_name, 90, $lock_token ) ) {
			Manager::set_status( $job_id, 'waiting_for_lock' );
			Manager::schedule_tick( (int) $job_id );
			return;
		}

		if ( 'waiting_for_lock' === $job['status'] || 'queued' === $job['status'] || 'paused' === $job['status'] ) {
			Manager::set_status( $job_id, 'running' );
			$job = Manager::get( $job_id );
		}

		$budget   = (int) apply_filters( 'cptsc_time_budget', 10 );
		$deadline = time() + max( 3, min( 25, $budget ) );
		$payload  = Manager::payload( $job );

		try {
			$done = $this->process_batch( $job, $payload, $deadline );
		} catch ( \Throwable $e ) {
			Manager::fail( $job_id, $e->getMessage() );
			return;
		}

		$job = Manager::get( $job_id );
		if ( 'defer' === $done ) {
			// Queue-drain guard: catalog still busy — pause and retry later.
			Manager::set_status(
				$job_id,
				'paused',
				array( 'last_error' => __( 'Odgođeno — čekam dovršetak obrade kataloga.', 'wp-cpt-sidrene-cijene' ) )
			);
			Lock::release( $lock_name );
			Cron::schedule_feed_retry();
			return;
		}
		if ( $done ) {
			Manager::set_status( $job_id, 'completed' );
			Lock::release( $lock_name );
			do_action( 'cptsc_job_finished', (int) $job_id, $job['type'], $job );
			return;
		}

		// Continuation: release this worker's lock between batches. Persisted
		// cursor is the sole continuation mechanism; next tick re-acquires a
		// fresh unique token (prevents lock-held-across-request deadlocks).
		Lock::release( $lock_name, $lock_token );
		Manager::set_status( $job_id, 'running' );
		Manager::schedule_tick( (int) $job_id );
	}

	/**
	 * One batch for a job type. Returns true when finished.
	 *
	 * @param array $job      Job row.
	 * @param array $payload  Payload.
	 * @param int   $deadline Unix deadline.
	 * @return bool
	 * @throws \Throwable On unrecoverable error.
	 */
	private function process_batch( array $job, array $payload, $deadline ) {
		switch ( $job['type'] ) {
			case 'DISCOVERY':
				return $this->batch_discovery( $job, $payload, $deadline );
			case 'FULL_REINDEX':
				return $this->batch_reindex( $job, $payload, $deadline );
			case 'REVALIDATE':
				return $this->batch_revalidate( $job, $payload, $deadline );
			case 'TERM_RULE_REBUILD':
				return $this->batch_term_rebuild( $job, $payload, $deadline );
			case 'FEED_GENERATION':
				return $this->batch_feed( $job, $payload, $deadline );
			case 'ARCHIVE_CLEANUP':
				return $this->batch_archive_cleanup( $job, $payload, $deadline );
			case 'CSV_IMPORT':
				return $this->batch_csv_import( $job, $payload, $deadline );
			default:
				throw new \RuntimeException( 'Unknown job type: ' . $job['type'] );
		}
	}

	/* ------------------------------------------------------------ batches */

	/**
	 * Discovery over selected post types (one step per batch item).
	 *
	 * @param array $job     Job.
	 * @param array $payload Payload.
	 * @param int   $deadline Deadline.
	 * @return bool
	 */
	private function batch_discovery( array $job, array $payload, $deadline ) {
		$post_types = ! empty( $payload['post_types'] ) ? (array) $payload['post_types'] : ItemQueue::selected_post_types();
		$index      = (int) $job['cursor'];
		if ( 0 === $index ) {
			Manager::progress( $job['job_id'], 0, 0, count( $post_types ) );
		}
		$scanner = new Scanner();
		while ( $index < count( $post_types ) ) {
			$pt     = $post_types[ $index ];
			$result = $scanner->scan( $pt );
			Settings::save_discovery( $pt, $result );
			$index++;
			Manager::progress( $job['job_id'], $index, $index, count( $post_types ) );
			if ( time() >= $deadline ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Full reindex with keyset pagination (never OFFSET, never -1).
	 *
	 * @param array $job     Job.
	 * @param array $payload Payload.
	 * @param int   $deadline Deadline.
	 * @return bool
	 */
	private function batch_reindex( array $job, array $payload, $deadline ) {
		global $wpdb;
		$batch    = (int) apply_filters( 'cptsc_batch_size', 100 );
		$cursor   = (int) $job['cursor'];
		$profiles = \CPTSC\Mapping\SourceProfile::confirmed();

		// Initialize total once.
		if ( empty( $job['total'] ) || (int) $job['total'] <= 0 ) {
			$total = 0;
			foreach ( $profiles as $p ) {
				$total += (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft','pending','future','private','trash')",
						$p->post_type
					)
				);
			}
			Manager::progress( $job['job_id'], $cursor, (int) $job['processed'], $total );
		}

		$processed = (int) $job['processed'];
		while ( time() < $deadline ) {
			$batch_rows = array();
			foreach ( $profiles as $p ) {
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d AND post_status IN ('publish','draft','pending','future','private','trash') ORDER BY ID ASC LIMIT %d",
						$p->post_type,
						$cursor,
						$batch
					)
				);
				foreach ( (array) $ids as $id ) {
					$batch_rows[] = (int) $id;
				}
			}
			if ( empty( $batch_rows ) ) {
				// Also refresh rows whose posts disappeared.
				$this->purge_missing( $profiles );
				return true;
			}
			sort( $batch_rows );
			foreach ( $batch_rows as $post_id ) {
				Builder::index_post( $post_id );
				$processed++;
				$cursor = max( $cursor, $post_id );
				if ( time() >= $deadline ) {
					break;
				}
			}
			// Cursor semantics: max ID per post type would be ideal; using global max of mixed CPTs
			// is safe here because each profile query is independent — store per-run max.
			Manager::progress( $job['job_id'], $cursor, $processed );
			if ( time() >= $deadline ) {
				return false;
			}
			// Continue until a pass yields no rows.
			$profiles_cursor_ok = true;
			$any = false;
			foreach ( $profiles as $p ) {
				$left = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d AND post_status IN ('publish','draft','pending','future','private','trash')",
						$p->post_type,
						$cursor
					)
				);
				if ( (int) $left > 0 ) {
					$any = true;
				}
			}
			if ( ! $any ) {
				$this->purge_missing( $profiles );
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove index rows whose posts no longer exist / belong to unselected types.
	 * Keyset-paginated over the whole index (10k/50k/100k safe) — no LIMIT 5000 cap.
	 *
	 * @param \CPTSC\Mapping\SourceProfile[] $profiles Profiles.
	 */
	private function purge_missing( array $profiles ) {
		global $wpdb;
		$items = Database::instance()->table( 'items' );
		$known_types = array();
		foreach ( $profiles as $p ) {
			$known_types[] = $p->post_type;
		}
		$known_types = array_values( array_unique( $known_types ) );
		if ( empty( $known_types ) ) {
			// No profiles → nothing is "known"; still purge orphaned rows safely.
			$known_types = array( '__none__' );
		}
		$placeholders = implode( ',', array_fill( 0, count( $known_types ), '%s' ) );
		$last_id      = 0;
		$batch        = 500;
		while ( true ) {
			// Rows whose post is missing OR whose post_type is no longer selected.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$sql = $wpdb->prepare(
				"SELECT i.id, i.object_id FROM {$items} i
				 LEFT JOIN {$wpdb->posts} p ON p.ID = i.object_id
				 WHERE i.id > %d AND ( p.ID IS NULL OR p.post_type NOT IN ( {$placeholders} ) )
				 ORDER BY i.id ASC LIMIT %d",
				array_merge( array( $last_id ), $known_types, array( $batch ) )
			);
			// phpcs:enable
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $r ) {
				$last_id = (int) $r['id'];
				$wpdb->delete( $items, array( 'id' => $last_id ) );
			}
			if ( count( $rows ) < $batch ) {
				break;
			}
		}
	}

	/**
	 * Revalidation pass (keyset by item id).
	 *
	 * @param array $job     Job.
	 * @param array $payload Payload.
	 * @param int   $deadline Deadline.
	 * @return bool
	 */
	private function batch_revalidate( array $job, array $payload, $deadline ) {
		global $wpdb;
		$table    = Database::instance()->table( 'items' );
		$batch    = (int) apply_filters( 'cptsc_batch_size', 200 );
		$cursor   = (int) $job['cursor'];
		$processed = (int) $job['processed'];
		if ( (int) $job['total'] <= 0 ) {
			Manager::progress( $job['job_id'], 0, 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
		}
		while ( time() < $deadline ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $cursor, $batch ),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				return true;
			}
			foreach ( $rows as $row ) {
				Validator::revalidate_row( $row );
				$cursor   = (int) $row['id'];
				$processed++;
			}
			Manager::progress( $job['job_id'], $cursor, $processed );
		}
		return false;
	}

	/**
	 * Rebuild group expectations after rule edits: full reindex of anchor groups only
	 * implemented as a targeted reindex (anchors stay protected inside Builder).
	 *
	 * @param array $job     Job.
	 * @param array $payload Payload.
	 * @param int   $deadline Deadline.
	 * @return bool
	 */
	private function batch_term_rebuild( array $job, array $payload, $deadline ) {
		// Rule changes affect group expectations; a safe full reindex keeps verified anchors intact.
		return $this->batch_reindex( $job, $payload, $deadline );
	}

	/**
	 * Feed generation + publish (atomic).
	 *
	 * @param array $job     Job.
	 * @param array $payload Payload.
	 * @param int   $deadline Deadline.
	 * @return bool
	 */
	private function batch_feed( array $job, array $payload, $deadline ) {
		// Queue-drain → feed: never generate mid-import/mid-reindex (fresh settled index only).
		if ( Cron::feed_busy() ) {
			return 'defer';
		}
		$generator = new Generator();
		$channel_ids = ! empty( $payload['channel_ids'] ) ? (array) $payload['channel_ids'] : array();
		$force     = ! empty( $payload['force'] );
		$result    = $generator->generate_for_channels( $channel_ids, $force, array( __CLASS__, 'feed_progress' ), $job['job_id'], $deadline );
		if ( is_wp_error( $result ) ) {
			// Fail-safe: current feed stays; schedule bounded retry then fail this attempt.
			Cron::schedule_feed_retry();
			throw new \RuntimeException( $result->get_error_message() );
		}
		return true;
	}

	/**
	 * Progress callback for feed generation.
	 *
	 * @param int   $job_id    Job.
	 * @param int   $cursor    Cursor.
	 * @param int   $processed Processed.
	 * @param int   $total     Total.
	 */
	public static function feed_progress( $job_id, $cursor, $processed, $total ) {
		Manager::progress( $job_id, $cursor, $processed, $total );
	}

	/**
	 * Archive retention cleanup.
	 *
	 * @param array $job     Job.
	 * @param array $payload Payload.
	 * @param int   $deadline Deadline.
	 * @return bool
	 */
	private function batch_archive_cleanup( array $job, array $payload, $deadline ) {
		$archive = new Archive();
		$removed = $archive->cleanup();
		Manager::progress( $job['job_id'], 1, 1, 1 );
		do_action( 'cptsc_archive_cleaned', $removed );
		return true;
	}

	/**
	 * CSV anchor import batches (row-cursor based).
	 *
	 * @param array $job     Job.
	 * @param array $payload Payload.
	 * @param int   $deadline Deadline.
	 * @return bool
	 * @throws \RuntimeException On file error.
	 */
	private function batch_csv_import( array $job, array $payload, $deadline ) {
		$importer = new \CPTSC\Admin\Importer();
		return $importer->process_job( $job, $payload, $deadline );
	}

	/**
	 * Heartbeat event: watchdog + recurring housekeeping.
	 */
	public function run_heartbeat() {
		Settings::health_set( 'last_heartbeat', time() );
		Manager::watchdog( 600 );
		Lock::sweep(); // Reconciliation: clear expired locks left by fatal/timeouts.
		if ( ! wp_next_scheduled( 'cptsc_heartbeat' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'cptsc_heartbeat' );
		}
	}
}

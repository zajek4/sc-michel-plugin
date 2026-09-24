<?php
/**
 * Job creation / status API.
 *
 * @package CPTSC
 */

namespace CPTSC\Jobs;

use CPTSC\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job manager.
 */
final class Manager {

	/**
	 * Known job types.
	 *
	 * @return string[]
	 */
	public static function types() {
		return apply_filters(
			'cptsc_job_types',
			array(
				'DISCOVERY',
				'FULL_REINDEX',
				'CSV_IMPORT',
				'FEED_GENERATION',
				'ARCHIVE_CLEANUP',
				'TERM_RULE_REBUILD',
				'REVALIDATE',
			)
		);
	}

	/**
	 * Enqueue a job. When $unique is set, an active job of that type is reused.
	 *
	 * @param string $type    Type.
	 * @param array  $payload Payload.
	 * @param bool   $unique  Reuse active job of same type.
	 * @return int Job ID.
	 */
	public static function enqueue( $type, array $payload = array(), $unique = true ) {
		global $wpdb;
		$table = Database::instance()->table( 'jobs' );
		$now   = current_time( 'mysql' );

		if ( $unique ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT job_id FROM {$table} WHERE type = %s AND status IN ('queued','running','waiting_for_lock','paused') ORDER BY job_id DESC LIMIT 1",
					$type
				)
			);
			if ( $existing ) {
				self::set_status( (int) $existing, 'queued' );
				self::schedule_tick( (int) $existing );
				return (int) $existing;
			}
		}

		$wpdb->insert(
			$table,
			array(
				'type'       => $type,
				'status'     => 'queued',
				'payload'    => wp_json_encode( $payload ),
				'created_by' => get_current_user_id(),
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
		$job_id = (int) $wpdb->insert_id;
		self::schedule_tick( $job_id );
		return $job_id;
	}

	/**
	 * Schedule a worker tick for a job.
	 *
	 * @param int $job_id Job.
	 */
	public static function schedule_tick( $job_id ) {
		if ( ! wp_next_scheduled( 'cptsc_job_tick', array( (int) $job_id ) ) ) {
			wp_schedule_single_event( time() + 5, 'cptsc_job_tick', array( (int) $job_id ) );
		}
	}

	/**
	 * Fetch job row.
	 *
	 * @param int $job_id ID.
	 * @return array|null
	 */
	public static function get( $job_id ) {
		global $wpdb;
		$table = Database::instance()->table( 'jobs' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d", (int) $job_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Payload of a job.
	 *
	 * @param array $job Job row.
	 * @return array
	 */
	public static function payload( array $job ) {
		$data = isset( $job['payload'] ) ? json_decode( $job['payload'], true ) : null;
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Update status.
	 *
	 * @param int    $job_id  Job.
	 * @param string $status  Status.
	 * @param array  $extra   Extra columns.
	 */
	public static function set_status( $job_id, $status, array $extra = array() ) {
		global $wpdb;
		$table = Database::instance()->table( 'jobs' );
		$data  = array_merge(
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			$extra
		);
		if ( 'running' === $status && empty( $extra['started_at'] ) ) {
			$data['started_at'] = current_time( 'mysql' );
		}
		if ( in_array( $status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
			$data['finished_at'] = current_time( 'mysql' );
		}
		$wpdb->update( $table, $data, array( 'job_id' => (int) $job_id ) );
	}

	/**
	 * Save progress.
	 *
	 * @param int   $job_id    Job.
	 * @param int   $cursor    Cursor.
	 * @param int   $processed Processed.
	 * @param int   $total     Total.
	 * @param int   $failed    Failed.
	 */
	public static function progress( $job_id, $cursor, $processed, $total = null, $failed = null ) {
		global $wpdb;
		$table = Database::instance()->table( 'jobs' );
		$data  = array(
			'cursor'       => (int) $cursor,
			'processed'    => (int) $processed,
			'heartbeat_at' => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		);
		if ( null !== $total ) {
			$data['total'] = (int) $total;
		}
		if ( null !== $failed ) {
			$data['failed'] = (int) $failed;
		}
		$wpdb->update( $table, $data, array( 'job_id' => (int) $job_id ) );
		Lock::heartbeat( 'job_' . (int) $job_id, 'shared', 60 );
	}

	/**
	 * Record error and fail the job.
	 *
	 * @param int    $job_id Job.
	 * @param string $error  Error.
	 */
	public static function fail( $job_id, $error ) {
		global $wpdb;
		$table = Database::instance()->table( 'jobs' );
		$wpdb->update(
			$table,
			array(
				'last_error' => substr( (string) $error, 0, 2000 ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'job_id' => (int) $job_id )
		);
		self::set_status( $job_id, 'failed' );
		Lock::release( 'job_' . (int) $job_id );
		do_action( 'cptsc_job_failed', (int) $job_id, $error );
	}

	/**
	 * Active jobs.
	 *
	 * @param int $limit Limit.
	 * @return array[]
	 */
	public static function active( $limit = 10 ) {
		global $wpdb;
		$table = Database::instance()->table( 'jobs' );
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN ('queued','running','waiting_for_lock','paused','stalled') ORDER BY job_id DESC LIMIT %d",
				(int) $limit
			),
			ARRAY_A
		);
	}

	/**
	 * Latest finished job of a type.
	 *
	 * @param string $type Type.
	 * @return array|null
	 */
	public static function latest_of_type( $type ) {
		global $wpdb;
		$table = Database::instance()->table( 'jobs' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s ORDER BY job_id DESC LIMIT 1", $type ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Watchdog: mark running jobs with stale heartbeats as stalled.
	 *
	 * @param int $stale_after Seconds.
	 * @return int Count marked.
	 */
	public static function watchdog( $stale_after = 600 ) {
		global $wpdb;
		$table  = Database::instance()->table( 'jobs' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) $stale_after );
		$count  = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'stalled', updated_at = %s
				 WHERE status = 'running' AND (heartbeat_at IS NULL OR heartbeat_at < %s)",
				current_time( 'mysql' ),
				$cutoff
			)
		);
		return $count;
	}

	/**
	 * Requeue a stalled/paused/failed job.
	 *
	 * @param int $job_id Job.
	 * @return bool
	 */
	public static function resume( $job_id ) {
		$job = self::get( $job_id );
		if ( ! $job ) {
			return false;
		}
		if ( ! in_array( $job['status'], array( 'stalled', 'paused', 'failed', 'waiting_for_lock' ), true ) ) {
			return false;
		}
		self::set_status( $job_id, 'queued', array( 'last_error' => $job['last_error'] ) );
		Lock::release( 'job_' . (int) $job_id );
		self::schedule_tick( (int) $job_id );
		return true;
	}

	/**
	 * Jobs hook wiring is in Worker; Manager only stores state.
	 */
	public function hooks() {
		// No hooks — kept for symmetry with architecture outline.
	}
}

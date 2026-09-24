<?php
/**
 * Scheduling: WP-Cron defaults, custom intervals, server-cron heartbeat URL.
 *
 * @package CPTSC
 */

namespace CPTSC\Jobs;

use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cron management.
 */
final class Cron {

	/**
	 * Custom minute interval.
	 */
	const MINUTE_HOOK = 'every_minute';

	/**
	 * Register intervals + events.
	 */
	public function hooks() {
		add_filter( 'cron_schedules', array( $this, 'schedules' ) );
		add_action( 'cptsc_feed_publish', array( $this, 'maybe_publish_feed' ) );
		add_action( 'cptsc_feed_retry', array( $this, 'retry_publish_feed' ) );
		add_action( 'init', array( $this, 'ensure_recurring' ) );
		// Public server-cron endpoint (query var routed in Feed\Routes).
		add_action( 'template_redirect', array( $this, 'maybe_server_cron' ), 0 );
	}

	/**
	 * Custom schedules.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public function schedules( $schedules ) {
		$schedules[ self::MINUTE_HOOK ] = array(
			'interval' => 60,
			/* translators: cron schedule name */
			'display'  => __( 'Svaka minuta (CPTSC)', 'wp-cpt-sidrene-cijene' ),
		);
		return $schedules;
	}

	/**
	 * Schedule defaults on activation.
	 */
	public static function schedule_defaults() {
		if ( ! wp_next_scheduled( 'cptsc_queue_tick' ) ) {
			wp_schedule_event( time() + 60, self::MINUTE_HOOK, 'cptsc_queue_tick' );
		}
		if ( ! wp_next_scheduled( 'cptsc_heartbeat' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'cptsc_heartbeat' );
		}
		self::schedule_feed_publish();
	}

	/**
	 * Keep recurring events alive (guards against foreign cron wipes).
	 */
	public function ensure_recurring() {
		if ( ! wp_next_scheduled( 'cptsc_queue_tick' ) ) {
			wp_schedule_event( time() + 30, self::MINUTE_HOOK, 'cptsc_queue_tick' );
		}
		if ( ! wp_next_scheduled( 'cptsc_heartbeat' ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'cptsc_heartbeat' );
		}
		if ( ! wp_next_scheduled( 'cptsc_feed_publish' ) ) {
			self::schedule_feed_publish();
		}
	}

	/**
	 * Daily production publish slot — working days before 08:00 for traders;
	 * every day for service providers (rule: on change, by 08:00).
	 */
	public static function schedule_feed_publish() {
		if ( wp_next_scheduled( 'cptsc_feed_publish' ) ) {
			return;
		}
		// Next 07:00 in the WordPress site timezone (Europe/Zagreb, DST-safe) — not PHP server TZ.
		$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$now  = new \DateTimeImmutable( 'now', $tz );
		$next = $now->setTime( 7, 0, 0 );
		if ( $next->getTimestamp() <= $now->getTimestamp() ) {
			$next = $next->modify( 'tomorrow' )->setTime( 7, 0, 0 );
		}
		wp_schedule_event( $next->getTimestamp(), 'daily', 'cptsc_feed_publish' );
	}

	/**
	 * Clear all scheduled events (deactivation).
	 */
	public static function clear_scheduled() {
		wp_clear_scheduled_hook( 'cptsc_queue_tick' );
		wp_clear_scheduled_hook( 'cptsc_heartbeat' );
		wp_clear_scheduled_hook( 'cptsc_feed_publish' );
		wp_clear_scheduled_hook( 'cptsc_feed_retry' );
		wp_clear_scheduled_hook( 'cptsc_job_tick' );
	}

	/**
	 * Is catalog work in flight? Queue-drain guard: feed must be generated
	 * from a settled index — never mid-import/mid-reindex.
	 *
	 * @return bool
	 */
	public static function feed_busy() {
		if ( \CPTSC\Catalog\Queue::pending_count() > 0 ) {
			return true;
		}
		global $wpdb;
		$jobs = \CPTSC\Database::instance()->table( 'jobs' );
		$n = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$jobs}
			 WHERE type IN ('CSV_IMPORT','FULL_REINDEX','DISCOVERY','REVALIDATE','TERM_RULE_REBUILD')
			   AND status IN ('queued','running','waiting_for_lock','paused')"
		);
		return $n > 0;
	}

	/**
	 * Schedule a bounded one-shot retry (max 3 per cycle; cycle resets on
	 * the daily slot and on successful publish).
	 */
	public static function schedule_feed_retry() {
		if ( wp_next_scheduled( 'cptsc_feed_retry' ) ) {
			return;
		}
		$health   = Settings::health();
		$attempts = isset( $health['feed_retry_attempts'] ) ? (int) $health['feed_retry_attempts'] : 0;
		if ( $attempts >= 3 ) {
			return; // Leave it for the next daily slot / manual publish.
		}
		Settings::health_set( 'feed_retry_attempts', $attempts + 1 );
		wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, 'cptsc_feed_retry' );
	}

	/**
	 * Automatic feed publication entry (daily). Resets the retry cycle, then
	 * runs the guarded attempt.
	 */
	public function maybe_publish_feed() {
		Settings::health_set( 'feed_retry_attempts', 0 );
		$this->try_publish_feed();
	}

	/**
	 * Retry attempt (deferred/failed publish) — does not reset the cycle.
	 */
	public function retry_publish_feed() {
		$this->try_publish_feed();
	}

	/**
	 * Shared guarded publish: drain the queue first, then enqueue generation.
	 */
	private function try_publish_feed() {
		if ( ! Settings::get( 'feed.enabled', false ) || ! Settings::get( 'feed.auto_publish', true ) ) {
			return;
		}
		$item_type = Settings::get( 'item_type', 'product' );
		if ( 'product' === $item_type ) {
			// Workday check in WordPress timezone (Mon–Fri). No holiday calendar claimed.
			$tz_now = function_exists( 'current_datetime' ) ? current_datetime() : new \DateTimeImmutable( 'now', wp_timezone() );
			if ( in_array( (int) $tz_now->format( 'N' ), array( 6, 7 ), true ) ) {
				return; // Traders publish on working days (deadline: workday 08:00).
			}
		}
		if ( self::feed_busy() ) {
			// Queue-drain → feed: wait for import/reindex to settle; one generation after drain.
			self::schedule_feed_retry();
			return;
		}
		\CPTSC\Jobs\Manager::enqueue( 'FEED_GENERATION', array( 'force' => false ), true );
	}

	/**
	 * Handle signed server-cron request: /?cptsc_cron=1&cptsc_token=…
	 */
	public function maybe_server_cron() {
		if ( ! isset( $_GET['cptsc_cron'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token = isset( $_GET['cptsc_token'] ) ? sanitize_text_field( wp_unslash( $_GET['cptsc_token'] ) ) : '';
		$valid = (string) Settings::cron_token();
		if ( '' === $valid || ! hash_equals( $valid, $token ) ) {
			status_header( 403 );
			exit;
		}
		Settings::health_set( 'last_server_cron', time() );

		// Run due work inline (bounded) then exit quietly.
		try {
			\CPTSC\Catalog\Queue::process_batch();
		} catch ( \Throwable $e ) { // phpcs:ignore
			// Fail-safe: silent.
		}
		// Fire any due WP-Cron events without output.
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'ok'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- minimal machine response.
		exit;
	}

	/**
	 * Public (admin-display only) server cron URL.
	 *
	 * @return string
	 */
	public static function server_cron_url() {
		return add_query_arg(
			array(
				'cptsc_cron'   => '1',
				'cptsc_token'  => Settings::cron_token(),
			),
			home_url( '/' )
		);
	}

	/**
	 * Health snapshot for Diagnostics.
	 *
	 * @return array
	 */
	public static function health() {
		$health = Settings::health();
		return array(
			'wp_cron_disabled'     => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'queue_event'          => wp_next_scheduled( 'cptsc_queue_tick' ),
			'heartbeat_event'      => wp_next_scheduled( 'cptsc_heartbeat' ),
			'feed_event'           => wp_next_scheduled( 'cptsc_feed_publish' ),
			'feed_retry_event'     => wp_next_scheduled( 'cptsc_feed_retry' ),
			'feed_retry_attempts'  => isset( $health['feed_retry_attempts'] ) ? (int) $health['feed_retry_attempts'] : 0,
			'last_worker'          => isset( $health['last_worker'] ) ? (int) $health['last_worker'] : 0,
			'last_heartbeat'       => isset( $health['last_heartbeat'] ) ? (int) $health['last_heartbeat'] : 0,
			'last_server_cron'     => isset( $health['last_server_cron'] ) ? (int) $health['last_server_cron'] : 0,
			'last_feed_publish'    => isset( $health['last_feed_publish'] ) ? (int) $health['last_feed_publish'] : 0,
		);
	}
}

<?php
/**
 * Plugin bootstrap: wires modules, hooks and lifecycle.
 *
 * @package CPTSC
 */

namespace CPTSC;

use CPTSC\Admin\Admin;
use CPTSC\Admin\Metabox;
use CPTSC\Admin\Setup;
use CPTSC\Anchor\Audit;
use CPTSC\Anchor\FirstListing;
use CPTSC\Catalog\Builder;
use CPTSC\Catalog\Queue;
use CPTSC\Feed\Archive;
use CPTSC\Feed\Channel;
use CPTSC\Feed\Generator;
use CPTSC\Feed\Routes;
use CPTSC\Frontend\Automatic;
use CPTSC\Frontend\Shortcode;
use CPTSC\Jobs\Cron;
use CPTSC\Jobs\Manager as JobsManager;
use CPTSC\Jobs\Worker as JobsWorker;
use CPTSC\Integrations\ACF;
use CPTSC\Integrations\Polylang;
use CPTSC\Integrations\WPML;
use CPTSC\Migrations\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central plugin orchestrator.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether plugin is in production-active state.
	 *
	 * @var bool
	 */
	private $active = false;

	/**
	 * Get singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bootstrap all modules.
	 */
	public function boot() {
		load_plugin_textdomain( 'wp-cpt-sidrene-cijene', false, dirname( CPTSC_PLUGIN_BASENAME ) . '/languages' );

		Migrator::maybe_migrate();

		$this->active = ( 'ACTIVE' === Settings::get( 'setup_state', 'NOT_STARTED' ) );

		( Database::instance() )->hooks();

		// Integrations (safe no-ops when plugins are absent).
		( new ACF() )->hooks();
		( new WPML() )->hooks();
		( new Polylang() )->hooks();

		// Change detection + queue.
		( new Queue() )->hooks();

		// First listing capture.
		( new FirstListing() )->hooks();

		// Jobs + cron.
		( new JobsManager() )->hooks();
		( new JobsWorker() )->hooks();
		( new Cron() )->hooks();

		// Feed + public routes.
		( new Generator() )->hooks();
		( new Archive() )->hooks();
		( new Routes() )->hooks();

		// Frontend.
		( new Shortcode() )->hooks();
		if ( $this->is_active() ) {
			( new Automatic() )->hooks();
		}

		// Admin.
		if ( is_admin() ) {
			( new Admin() )->hooks();
			( new Setup() )->hooks();
			( new Metabox() )->hooks();
		} else {
			// Still register metabox saves are admin-only; nothing else needed.
		}

		Audit::instance()->hooks();

		/**
		 * Fires after the plugin has fully booted.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'cptsc_booted', $this );
	}

	/**
	 * Plugin is production-active (setup finished).
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->active;
	}

	/**
	 * Activation routine.
	 */
	public static function activate() {
		Database::instance()->install();
		Settings::install_defaults();
		Channel::ensure_default();
		Cron::schedule_defaults();
		Rules_Flusher::flush();
		do_action( 'cptsc_activated' );
	}

	/**
	 * Deactivation routine — keeps all data, stops background work.
	 */
	public static function deactivate() {
		Cron::clear_scheduled();
		Rules_Flusher::flush();
		do_action( 'cptsc_deactivated' );
	}
}

/**
 * Rewrite rules helper (kept tiny on purpose).
 */
class Rules_Flusher {

	/**
	 * Register rules then flush.
	 */
	public static function flush() {
		Routes::add_rules();
		flush_rewrite_rules( false );
	}
}

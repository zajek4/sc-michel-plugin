<?php
/**
 * Admin menu, page router and shared assets.
 *
 * @package CPTSC
 */

namespace CPTSC\Admin;

use CPTSC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin bootstrap.
 */
final class Admin {

	/**
	 * Base slug.
	 */
	const SLUG = 'cptsc-dashboard';

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'redirect_wizard' ) );
		add_action( 'admin_notices', array( $this, 'flash_notices' ) );

		// AJAX endpoints.
		add_action( 'wp_ajax_cptsc_discover', array( $this, 'ajax_discover' ) );
		add_action( 'wp_ajax_cptsc_inspect', array( $this, 'ajax_inspect' ) );
		add_action( 'wp_ajax_cptsc_verify_selector', array( $this, 'ajax_verify_selector' ) );
		add_action( 'wp_ajax_cptsc_save_term_rules', array( $this, 'ajax_save_term_rules' ) );
		add_action( 'wp_ajax_cptsc_test_run', array( $this, 'ajax_test_run' ) );
		add_action( 'wp_ajax_cptsc_job_status', array( $this, 'ajax_job_status' ) );
		add_action( 'wp_ajax_cptsc_resume_job', array( $this, 'ajax_resume_job' ) );
		add_action( 'wp_ajax_cptsc_import_upload', array( $this, 'ajax_import_upload' ) );
		add_action( 'wp_ajax_cptsc_import_start', array( $this, 'ajax_import_start' ) );
		add_action( 'wp_ajax_cptsc_copy_diagnostics', array( $this, 'noop' ) );
	}

	/**
	 * No-op (JS clipboard handles copy client-side).
	 */
	public function noop() {
		wp_send_json_success( array() );
	}

	/**
	 * Flash notices.
	 */
	public function flash_notices() {
		$flash = get_transient( 'cptsc_flash' );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( 'cptsc_flash' );
		$type = isset( $flash[1] ) && 'error' === $flash[1] ? 'error' : 'success';
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( isset( $flash[0] ) ? $flash[0] : '' )
		);
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$item_type = Settings::get( 'item_type', 'product' );
		$items_label = 'service' === $item_type ? __( 'Usluge', 'wp-cpt-sidrene-cijene' ) : __( 'Proizvodi', 'wp-cpt-sidrene-cijene' );

		add_menu_page(
			__( 'Sidrene cijene', 'wp-cpt-sidrene-cijene' ),
			__( 'Sidrene cijene', 'wp-cpt-sidrene-cijene' ),
			'manage_options',
			self::SLUG,
			array( $this, 'page_dashboard' ),
			'dashicons-money-alt',
			28
		);

		add_submenu_page( self::SLUG, __( 'Pregled', 'wp-cpt-sidrene-cijene' ), __( 'Pregled', 'wp-cpt-sidrene-cijene' ), 'manage_options', self::SLUG, array( $this, 'page_dashboard' ) );
		add_submenu_page( self::SLUG, $items_label, $items_label, 'manage_options', 'cptsc-catalog', array( $this, 'page_catalog' ) );
		add_submenu_page( self::SLUG, __( 'Digitalni cjenik', 'wp-cpt-sidrene-cijene' ), __( 'Digitalni cjenik', 'wp-cpt-sidrene-cijene' ), 'manage_options', 'cptsc-feed', array( $this, 'page_feed' ) );
		add_submenu_page( self::SLUG, __( 'Uvoz sidrenih podataka', 'wp-cpt-sidrene-cijene' ), __( 'Uvoz', 'wp-cpt-sidrene-cijene' ), 'manage_options', 'cptsc-import', array( $this, 'page_import' ) );
		add_submenu_page( self::SLUG, __( 'Arhiva', 'wp-cpt-sidrene-cijene' ), __( 'Arhiva', 'wp-cpt-sidrene-cijene' ), 'manage_options', 'cptsc-archive', array( $this, 'page_archive' ) );
		add_submenu_page( self::SLUG, __( 'Postavke', 'wp-cpt-sidrene-cijene' ), __( 'Postavke', 'wp-cpt-sidrene-cijene' ), 'manage_options', 'cptsc-settings', array( $this, 'page_settings' ) );
		add_submenu_page( self::SLUG, __( 'Dijagnostika', 'wp-cpt-sidrene-cijene' ), __( 'Dijagnostika', 'wp-cpt-sidrene-cijene' ), 'manage_options', 'cptsc-diagnostics', array( $this, 'page_diagnostics' ) );
		add_submenu_page( self::SLUG, __( 'Postavljanje', 'wp-cpt-sidrene-cijene' ), __( 'Postavljanje', 'wp-cpt-sidrene-cijene' ), 'manage_options', 'cptsc-setup', array( '\CPTSC\Admin\Setup', 'render_page' ) );
	}

	/**
	 * When setup incomplete, plugin pages redirect to the wizard.
	 */
	public function redirect_wizard() {
		if ( ! isset( $_GET['page'] ) ) { // phpcs:ignore
			return;
		}
		$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore
		if ( 'cptsc-setup' === $page ) {
			return;
		}
		// Import + diagnostics stay reachable while the wizard is in progress
		// (step 8 links to CSV import; diagnostics helps unstick background work).
		$active_pages = array( self::SLUG, 'cptsc-catalog', 'cptsc-feed', 'cptsc-archive', 'cptsc-settings' );
		if ( ! in_array( $page, $active_pages, true ) ) {
			return;
		}
		if ( 'ACTIVE' === Settings::get( 'setup_state', 'NOT_STARTED' ) ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=cptsc-setup' ) );
		exit;
	}

	/**
	 * Shared admin assets (plugin screens + metaboxes only).
	 *
	 * @param string $hook Hook.
	 */
	public function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_plugin_page = ( false !== strpos( (string) $hook, 'cptsc' ) );
		$is_metabox_screen = $screen && ! empty( $screen->post_type ) && in_array( $screen->post_type, Settings::get( 'post_types', array() ), true );

		if ( ! $is_plugin_page && ! $is_metabox_screen ) {
			return;
		}
		wp_enqueue_style( 'cptsc-admin', CPTSC_PLUGIN_URL . 'assets/css/admin.css', array(), CPTSC_VERSION );
		if ( $is_plugin_page ) {
			wp_enqueue_script( 'cptsc-admin', CPTSC_PLUGIN_URL . 'assets/js/admin.js', array(), CPTSC_VERSION, true );
			wp_localize_script(
				'cptsc-admin',
				'cptscAdmin',
				array(
					'ajax'   => admin_url( 'admin-ajax.php' ),
					'nonce'  => wp_create_nonce( 'cptsc_admin' ),
					'i18n'   => array(
						'confirmReset' => __( 'Ova radnja je nepovratna. Želite li nastaviti?', 'wp-cpt-sidrene-cijene' ),
						'working'      => __( 'Obrađujem…', 'wp-cpt-sidrene-cijene' ),
						'done'         => __( 'Završeno.', 'wp-cpt-sidrene-cijene' ),
						'error'        => __( 'Došlo je do pogreške. Pokušajte ponovno.', 'wp-cpt-sidrene-cijene' ),
					),
				)
			);
		}
	}

	/* ----------------------------------------------------------- routing */

	/**
	 * Dashboard page.
	 */
	public function page_dashboard() {
		( new Dashboard() )->render();
	}

	/**
	 * Catalog page.
	 */
	public function page_catalog() {
		( new Catalog() )->render();
	}

	/**
	 * Feed page.
	 */
	public function page_feed() {
		( new Feed() )->render();
	}

	/**
	 * Import page.
	 */
	public function page_import() {
		( new Importer() )->render();
	}

	/**
	 * Archive page.
	 */
	public function page_archive() {
		( new Feed() )->render_archive_page();
	}

	/**
	 * Settings page.
	 */
	public function page_settings() {
		( new SettingsPage() )->render();
	}

	/**
	 * Diagnostics page.
	 */
	public function page_diagnostics() {
		( new Diagnostics() )->render();
	}

	/* ------------------------------------------------------------- AJAX */

	/**
	 * Shared nonce+cap check.
	 *
	 * @return bool
	 */
	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nemate dopuštenje.', 'wp-cpt-sidrene-cijene' ) ), 403 );
		}
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cptsc_admin' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sigurnosna provjera nije prošla. Osvježite stranicu.', 'wp-cpt-sidrene-cijene' ) ), 403 );
		}
		return true;
	}

	/**
	 * Run discovery (sync, bounded sample) for a post type.
	 */
	public function ajax_discover() {
		self::guard();
		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		if ( ! $post_type ) {
			wp_send_json_error( array( 'message' => 'missing post_type' ) );
		}
		$scanner = new \CPTSC\Discovery\Scanner();
		$result  = $scanner->scan( $post_type );
		Settings::save_discovery( $post_type, $result );
		wp_send_json_success( $result );
	}

	/**
	 * Frontend inspector candidates for a sample post.
	 */
	public function ajax_inspect() {
		self::guard();
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( $post_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Nedostaje ID objave.', 'wp-cpt-sidrene-cijene' ) ) );
		}
		$url     = get_permalink( $post_id );
		$html    = '';
		// Normal secure HTTP — SSL verification stays ON by default.
		// Filter for local/self-signed staging only; never disable in production code.
		$sslverify = (bool) apply_filters( 'cptsc_inspector_sslverify', true );
		$resp    = wp_remote_get( $url, array( 'timeout' => 15, 'sslverify' => $sslverify ) );
		if ( ! is_wp_error( $resp ) ) {
			$html = (string) wp_remote_retrieve_body( $resp );
		}
		$candidates = array();
		if ( $html ) {
			$candidates = \CPTSC\Frontend\Inspector::candidates( $html );
		}
		// Mark which candidates exist inside the_content as well.
		foreach ( $candidates as &$c ) {
			$c['in_content'] = \CPTSC\Frontend\Inspector::selector_in_content( $c['selector'], $post_id );
		}
		unset( $c );
		wp_send_json_success(
			array(
				'url'         => $url,
				'candidates'  => $candidates,
				'fetched'     => (bool) $html,
			)
		);
	}

	/**
	 * Verify a selector against post content.
	 */
	public function ajax_verify_selector() {
		self::guard();
		$selector = isset( $_POST['selector'] ) ? sanitize_text_field( wp_unslash( $_POST['selector'] ) ) : '';
		$post_id  = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! \CPTSC\Frontend\Inspector::is_safe_selector( $selector ) || $post_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Selector nije prihvaćen.', 'wp-cpt-sidrene-cijene' ) ) );
		}
		$ok = \CPTSC\Frontend\Inspector::selector_in_content( $selector, $post_id );
		if ( $ok ) {
			Settings::update(
				array(
					'frontend' => array(
						'state'          => 'auto_verified',
						'selector'       => $selector,
						'sample_post_id' => $post_id,
					),
				)
			);
		}
		wp_send_json_success( array( 'verified' => (bool) $ok ) );
	}

	/**
	 * Save term rules for the anchor engine.
	 */
	public function ajax_save_term_rules() {
		self::guard();
		$rules = isset( $_POST['rules'] ) ? json_decode( wp_unslash( $_POST['rules'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_array( $rules ) ) {
			wp_send_json_error( array( 'message' => 'bad rules' ) );
		}
		\CPTSC\Anchor\Rules::delete_all();
		foreach ( $rules as $rule ) {
			$taxonomy = isset( $rule['taxonomy'] ) ? sanitize_key( $rule['taxonomy'] ) : '';
			$term_id  = isset( $rule['term_id'] ) ? (int) $rule['term_id'] : 0;
			$group    = isset( $rule['group'] ) ? sanitize_key( $rule['group'] ) : '';
			$incl     = ! empty( $rule['include_descendants'] );
			if ( $taxonomy && $term_id && $group && isset( \CPTSC\Anchor\Groups::all()[ $group ] ) ) {
				\CPTSC\Anchor\Rules::upsert( $taxonomy, $term_id, $group, $incl );
			}
		}
		Settings::set( 'term_rules_confirmed', true );
		\CPTSC\Jobs\Manager::enqueue( 'TERM_RULE_REBUILD', array(), true );
		wp_send_json_success( array( 'count' => count( $rules ) ) );
	}

	/**
	 * Test mode (dry-run, no writes to production state).
	 */
	public function ajax_test_run() {
		self::guard();
		$test = new TestMode();
		wp_send_json_success( $test->run( wp_unslash( $_POST ) ) ); // phpcs:ignore
	}

	/**
	 * Poll job status.
	 */
	public function ajax_job_status() {
		self::guard();
		$job_id = isset( $_REQUEST['job_id'] ) ? (int) $_REQUEST['job_id'] : 0;
		$job    = \CPTSC\Jobs\Manager::get( $job_id );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => 'not found' ) );
		}
		wp_send_json_success( $job );
	}

	/**
	 * Resume a stalled/failed job.
	 */
	public function ajax_resume_job() {
		self::guard();
		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		$ok     = \CPTSC\Jobs\Manager::resume( $job_id );
		wp_send_json_success( array( 'resumed' => $ok ) );
	}

	/**
	 * CSV import upload (temp store + dry-run preview) — AJAX variant.
	 */
	public function ajax_import_upload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nemate dopuštenje.', 'wp-cpt-sidrene-cijene' ) ), 403 );
		}
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cptsc_admin' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sigurnosna provjera nije prošla.', 'wp-cpt-sidrene-cijene' ) ), 403 );
		}
		$importer = new Importer();
		$importer->handle_upload();
	}

	/**
	 * Start CSV import job (AJAX).
	 */
	public function ajax_import_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nemate dopuštenje.', 'wp-cpt-sidrene-cijene' ) ), 403 );
		}
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cptsc_admin' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sigurnosna provjera nije prošla.', 'wp-cpt-sidrene-cijene' ) ), 403 );
		}
		$staged = get_option( Importer::STAGED, null );
		if ( ! is_array( $staged ) || empty( $staged['path'] ) || ! is_readable( $staged['path'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Nema pripremljene datoteke.', 'wp-cpt-sidrene-cijene' ) ) );
		}
		$job_id = \CPTSC\Jobs\Manager::enqueue(
			'CSV_IMPORT',
			array(
				'path'      => $staged['path'],
				'overwrite' => ! empty( $_POST['overwrite'] ),
			),
			true
		);
		set_transient( 'cptsc_import_job', $job_id, HOUR_IN_SECONDS );
		wp_send_json_success( array( 'job_id' => $job_id ) );
	}

	/**
	 * AJAX capability gate.
	 */
	private function guard_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nemate dopuštenje.', 'wp-cpt-sidrene-cijene' ) ), 403 );
		}
	}
}

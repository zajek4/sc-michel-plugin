<?php
/**
 * Plugin Name:       WP CPT Sidrene cijene
 * Plugin URI:        https://example.com/wp-cpt-sidrene-cijene
 * Description:       Dodatna (sidrena) cijena i digitalni cjenik za WordPress Custom Post Typeove — bez ovisnosti o WooCommerceu. NN 101/2026.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            CPTSC
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-cpt-sidrene-cijene
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CPTSC_VERSION', '1.2.0' );
define( 'CPTSC_DB_VERSION', '1.2.0' );
define( 'CPTSC_PLUGIN_FILE', __FILE__ );
define( 'CPTSC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CPTSC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CPTSC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Simple PSR-4 style autoloader for the CPTSC\ namespace.
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'CPTSC\\' ) ) {
			return;
		}
		$relative = substr( $class, 6 ); // Strip "CPTSC\".
		$file     = CPTSC_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook(
	__FILE__,
	array( 'CPTSC\\Plugin', 'activate' )
);

register_deactivation_hook(
	__FILE__,
	array( 'CPTSC\\Plugin', 'deactivate' )
);

add_action(
	'plugins_loaded',
	function () {
		CPTSC\Plugin::instance()->boot();
	},
	5
);

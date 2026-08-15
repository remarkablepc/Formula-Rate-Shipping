<?php
/**
 * Test bootstrap: loads WordPress, WooCommerce, and the Formula Rate Shipping plugin.
 *
 * Usage: php tests/run-tests.php
 */

$wp_load = 'C:/xampp/htdocs/wp/wp-load.php';

if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "ERROR: wp-load.php not found at {$wp_load}\n" );
	fwrite( STDERR, "Update the \$wp_load path in tests/bootstrap.php to match your local WordPress install.\n" );
	exit( 1 );
}

require_once $wp_load;

// If WordPress already loaded the plugin (it is active in this install),
// the Formula_Evaluator class will already be defined. In that case we must
// NOT require the plugin file again, or we'll redeclare its functions.
if ( class_exists( 'Formula_Evaluator' ) ) {
	fwrite( STDERR, "INFO: Plugin already loaded by WordPress (active install). Using existing class.\n" );
} else {
	$plugin_file = dirname( __DIR__ ) . '/formula-rate-shipping.php';

	if ( ! file_exists( $plugin_file ) ) {
		fwrite( STDERR, "ERROR: Plugin file not found at {$plugin_file}\n" );
		exit( 1 );
	}

	require_once $plugin_file;
}

if ( ! class_exists( 'Formula_Evaluator' ) ) {
	fwrite( STDERR, "ERROR: Formula_Evaluator class was not defined after loading the plugin.\n" );
	exit( 1 );
}

<?php

declare( strict_types=1 );

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php" . PHP_EOL;
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * The add-on has nothing to test without the plugin it extends, so a missing
 * free plugin stops the run instead of letting every test pass vacuously.
 *
 * @return void
 */
function _sigil_pro_manually_load_plugins() {
	$free = getenv( 'SIGIL_FREE_DIR' );
	if ( ! $free ) {
		$free = dirname( __DIR__, 2 ) . '/sigil-2fa';
	}
	$free_main = rtrim( $free, '/' ) . '/sigil-2fa.php';
	if ( ! file_exists( $free_main ) ) {
		echo "Could not find the free plugin at {$free_main}; set SIGIL_FREE_DIR." . PHP_EOL;
		exit( 1 );
	}

	require $free_main;
	require dirname( __DIR__ ) . '/sigil-2fa-pro.php';
}

tests_add_filter( 'muplugins_loaded', '_sigil_pro_manually_load_plugins' );

require $_tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/trait-clears-attempts.php';

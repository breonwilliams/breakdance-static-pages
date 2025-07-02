<?php
/**
 * PHPUnit bootstrap file for Breakdance Static Pages
 *
 * @package Breakdance_Static_Pages
 */

// Get the WordPress tests directory.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit parameters to the test suite.
if ( ! defined( 'WP_TESTS_DIR' ) ) {
	define( 'WP_TESTS_DIR', $_tests_dir );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( dirname( __FILE__ ) ) . '/breakdance-static-pages.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

// Include test helper functions.
require_once dirname( __FILE__ ) . '/class-bsp-test-case.php';
require_once dirname( __FILE__ ) . '/trait-bsp-test-helpers.php';
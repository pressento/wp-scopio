<?php
/**
 * PHPUnit bootstrap for WordPress plugin tests.
 *
 * Loads WordPress test library and activates the plugin before tests run.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
$_core_dir  = getenv( 'WP_CORE_DIR' ) ?: '/tmp/wordpress';

// PHPUnit Polyfills – required by WP test suite since 5.9.
$_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( $_polyfills_path && file_exists( $_polyfills_path . '/phpunitpolyfills-autoload.php' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_polyfills_path );
} elseif ( $_polyfills_path ) {
	// Search for the autoload in standard Composer vendor layout.
	$_autoload = $_polyfills_path . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
	if ( file_exists( $_autoload ) ) {
		define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( $_autoload ) );
	}
}

// Fallback: try common Composer global paths.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	$_candidates = array(
		'/root/.composer/vendor/yoast/phpunit-polyfills',
		getenv( 'HOME' ) . '/.composer/vendor/yoast/phpunit-polyfills',
		__DIR__ . '/vendor/yoast/phpunit-polyfills',
	);
	foreach ( $_candidates as $_candidate ) {
		if ( $_candidate && file_exists( $_candidate . '/phpunitpolyfills-autoload.php' ) ) {
			define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_candidate );
			break;
		}
	}
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find WordPress test library at {$_tests_dir}/includes/functions.php" . PHP_EOL;
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 *
 * Inside the Docker test container the plugin is mounted at /app/wp-scopio/
 * via the `docker-compose.test.yml` volume mapping:
 *   ./plugins/wp-scopio  →  /app/wp-scopio
 * So dirname(__DIR__) resolves to /app/ and the path below is correct.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/wp-scopio/wp-scopio.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

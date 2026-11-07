<?php
/**
 * PHPUnit bootstrap: loads WooCommerce and the plugin into the WordPress test suite.
 *
 * @package ecomweb-for-woocommerce
 */

$ecomweb_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $ecomweb_tests_dir ) {
	$ecomweb_tests_dir = '/wordpress-phpunit';
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && ! getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once $ecomweb_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		require_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		require_once dirname( __DIR__ ) . '/ecomweb-for-woocommerce.php';
	}
);

tests_add_filter(
	'setup_theme',
	function () {
		WC_Install::install();
	}
);

require_once $ecomweb_tests_dir . '/includes/bootstrap.php';

foreach ( glob( __DIR__ . '/helpers/*.php' ) as $ecomweb_helper ) {
	require_once $ecomweb_helper;
}

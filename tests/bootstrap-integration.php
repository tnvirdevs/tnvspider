<?php
/**
 * Bootstrap for the integration suite: loads the WordPress test suite and
 * this plugin.
 *
 * @package WST
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Required by wp-phpunit.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

// WST_TEST_WP_PHPUNIT_DIR pairs with WST_TEST_ABSPATH for other WordPress versions.
$wst_tests_dir = getenv( 'WST_TEST_WP_PHPUNIT_DIR' );
if ( false === $wst_tests_dir || '' === $wst_tests_dir ) {
	$wst_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}
if ( false === $wst_tests_dir || '' === $wst_tests_dir ) {
	throw new RuntimeException( 'WP_PHPUNIT__DIR is not set; run composer install.' );
}

require $wst_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/wp-site-translator.php';
	}
);

require $wst_tests_dir . '/includes/bootstrap.php';

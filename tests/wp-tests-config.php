<?php
/**
 * WordPress test-suite configuration. Database settings come from the
 * environment so the same file works in wp-env, CI and local MySQL.
 *
 * @package WST
 */

/**
 * Read a test setting from the environment.
 *
 * @param string $name     Environment variable.
 * @param string $fallback Value when the variable is unset or empty.
 */
function wst_test_env( string $name, string $fallback ): string {
	$value = getenv( $name );

	return false === $value || '' === $value ? $fallback : $value;
}

// WST_TEST_ABSPATH lets CI run the suite against another WordPress version.
define( 'ABSPATH', rtrim( wst_test_env( 'WST_TEST_ABSPATH', dirname( __DIR__ ) . '/vendor/johnpbloch/wordpress-core' ), '/' ) . '/' );

define( 'DB_NAME', wst_test_env( 'WST_TEST_DB_NAME', 'wst_tests' ) );
define( 'DB_USER', wst_test_env( 'WST_TEST_DB_USER', 'root' ) );
define( 'DB_PASSWORD', wst_test_env( 'WST_TEST_DB_PASSWORD', '' ) );
define( 'DB_HOST', wst_test_env( 'WST_TEST_DB_HOST', '127.0.0.1' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );

<?php
/**
 * Uninstall: deletes all plugin data only when the owner turned on
 * "Delete all data on uninstall" (Advanced → Data).
 *
 * @package WST
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Autoloader.php';

\WST\Autoloader::register( __DIR__ . '/src' );

global $wpdb;
\WST\Uninstaller::run( $wpdb );

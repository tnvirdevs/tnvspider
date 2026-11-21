<?php
/**
 * Bootstrap for the unit suite: no WordPress, no database.
 *
 * @package WST
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/src/Autoloader.php';

\WST\Autoloader::register( dirname( __DIR__ ) . '/src' );

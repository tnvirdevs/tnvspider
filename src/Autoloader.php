<?php
/**
 * PSR-4 autoloader for the WST namespace. No Composer runtime dependency.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST;

/**
 * Maps WST\Foo\Bar to {base}/Foo/Bar.php.
 */
final class Autoloader {

	/**
	 * Register the autoloader.
	 *
	 * @param string $baseDir Absolute path of the src directory.
	 */
	public static function register( string $baseDir ): void {
		$prefix  = __NAMESPACE__ . '\\';
		$baseDir = rtrim( $baseDir, '/\\' ) . '/';

		spl_autoload_register(
			static function ( string $className ) use ( $prefix, $baseDir ): void {
				if ( ! str_starts_with( $className, $prefix ) ) {
					return;
				}
				$file = $baseDir . str_replace( '\\', '/', substr( $className, strlen( $prefix ) ) ) . '.php';
				if ( is_file( $file ) ) {
					require $file;
				}
			}
		);
	}
}

<?php
/**
 * Built script registration (build/*.js from npm run build).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST;

/**
 * Registers a script built by @wordpress/scripts with the dependencies from
 * its .asset.php file. A missing build is a packaging error: the script is
 * not registered and administrators see a notice naming the file.
 */
final class Assets {

	/**
	 * Register build/{name}.js under $handle.
	 *
	 * @param string $handle     Script handle.
	 * @param string $name       Build entry name.
	 * @param string $pluginFile Main plugin file.
	 * @phpstan-param non-empty-string $handle
	 * @return bool Whether the script was registered.
	 * @throws \RuntimeException When WordPress gives no URL for the file.
	 */
	public static function registerScript( string $handle, string $name, string $pluginFile ): bool {
		$asset = dirname( $pluginFile ) . '/build/' . $name . '.asset.php';
		$meta  = is_readable( $asset ) ? require $asset : null;
		if ( ! is_array( $meta ) ) {
			add_action(
				'admin_notices',
				static function () use ( $name ): void {
					if ( current_user_can( 'manage_options' ) ) {
						/* translators: %s: file name */
						echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'WP Site Translator: build/%s.js is missing. Run "npm run build".', 'wp-site-translator' ), $name ) ) . '</p></div>';
					}
				}
			);

			return false;
		}
		$src = plugins_url( 'build/' . $name . '.js', $pluginFile );
		if ( '' === $src ) {
			throw new \RuntimeException( 'No URL for build/' . esc_html( $name ) . '.js.' );
		}
		wp_register_script(
			$handle,
			$src,
			isset( $meta['dependencies'] ) && is_array( $meta['dependencies'] ) ? $meta['dependencies'] : array(),
			isset( $meta['version'] ) ? (string) $meta['version'] : Config::VERSION,
			true
		);
		wp_set_script_translations( $handle, 'wp-site-translator' );

		return true;
	}
}

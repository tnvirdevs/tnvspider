<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST;

use WST\Database\Schema;

/**
 * Plugin bootstrap. Keeps hook registration in one place.
 */
final class Plugin {

	/**
	 * Register hooks. Called once from the main plugin file.
	 *
	 * @param string $file Absolute path of the main plugin file.
	 */
	public static function boot( string $file ): void {
		register_activation_hook(
			$file,
			static function (): void {
				self::schema()->install();
			}
		);

		// Covers updates that replace files without re-activation.
		add_action(
			'plugins_loaded',
			static function (): void {
				self::schema()->maybeUpgrade();
			}
		);
	}

	/**
	 * Schema manager on the global connection.
	 */
	private static function schema(): Schema {
		global $wpdb;

		return new Schema( $wpdb );
	}
}

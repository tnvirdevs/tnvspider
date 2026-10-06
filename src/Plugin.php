<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST;

use WST\Cli\StringCommand;
use WST\Database\Schema;
use WST\Log\Logger;
use WST\Render\DiscoveryGate;
use WST\Render\HeadTags;
use WST\Render\Pipeline;
use WST\Routing\LanguageUrls;
use WST\Routing\Router;
use WST\Routing\Urls;
use WST\Storage\StringStore;
use WST\Switcher\Switcher;

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

		$settings = Settings::load();
		$target   = $settings->targetLanguage();

		if ( null !== $target ) {
			global $wpdb;
			$home   = (string) get_option( 'home' );
			$urls   = Urls::fromHome( $home, rest_get_url_prefix() );
			$byLang = new LanguageUrls( $urls, $target, LanguageUrls::origin( $home ) );
			$logger = new Logger( $wpdb, self::schema() );

			// The language must be known before the locale and theme load.
			( new Router( $settings, $target, $urls ) )->boot();
			( new HeadTags( $settings, $settings->defaultLanguage(), $target, $byLang ) )->boot();
			( new Switcher( $settings->defaultLanguage(), $target, $byLang ) )->boot();
			( new Pipeline( $settings, $target, self::strings(), new DiscoveryGate( $settings, $logger ), $logger, $urls ) )->boot();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wst string', new StringCommand( self::strings(), $settings ) );
		}
	}

	/**
	 * String storage on the global connection.
	 */
	private static function strings(): StringStore {
		global $wpdb;

		return new StringStore( $wpdb, self::schema() );
	}

	/**
	 * Schema manager on the global connection.
	 */
	private static function schema(): Schema {
		global $wpdb;

		return new Schema( $wpdb );
	}
}

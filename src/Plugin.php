<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST;

use WST\Admin\AdminPage;
use WST\Admin\EditorPage;
use WST\Admin\Health;
use WST\Admin\Isolation;
use WST\Admin\PostPanel;
use WST\Cache\Purger;
use WST\Cli\ProviderCommand;
use WST\Cli\QueueCommand;
use WST\Cli\StringCommand;
use WST\Database\Schema;
use WST\Editor\AdminBar;
use WST\Editor\EditorRequest;
use WST\Editor\PageTarget;
use WST\Editor\Preview;
use WST\Languages\Registry;
use WST\Log\Logger;
use WST\Modes\OffPages;
use WST\Migration\Guard;
use WST\Migration\TranslatePress;
use WST\Modes\Resolver;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\Secrets;
use WST\Providers\Selector;
use WST\Providers\StatusReport;
use WST\Providers\Tester;
use WST\Queue\AutoQueue;
use WST\Queue\Queue;
use WST\Queue\RateLimiter;
use WST\Queue\Requests;
use WST\Queue\Scheduler;
use WST\Queue\Usage;
use WST\Queue\Worker;
use WST\Render\DiscoveryGate;
use WST\Render\HeadTags;
use WST\Render\Pipeline;
use WST\Rest\EditorController;
use WST\Rest\HealthController;
use WST\Rest\ImportController;
use WST\Rest\MigrationController;
use WST\Rest\PagesController;
use WST\Rest\ProvidersController;
use WST\Rest\QueueController;
use WST\Rest\SettingsController;
use WST\Rest\SiteController;
use WST\Rest\StringsController;
use WST\Routing\LanguageUrls;
use WST\Routing\Router;
use WST\Routing\Urls;
use WST\Site\SitePages;
use WST\Storage\StringStore;
use WST\Transfer\Exporter;
use WST\Transfer\Importer;
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
				Access::install();
			}
		);

		// Covers updates that replace files without re-activation.
		add_action(
			'plugins_loaded',
			static function (): void {
				self::schema()->maybeUpgrade();
				Access::maybeInstall();
			}
		);

		global $wpdb;
		$settings  = Settings::load();
		$target    = $settings->targetLanguage();
		$secrets   = new Secrets();
		$providers = ProviderRegistry::configured( $settings, $secrets );
		$state     = new ProviderState();
		$queue     = new Queue( $wpdb, self::schema() );
		$worker    = static fn(): Worker => self::worker( $settings, $queue, $providers, $state, $secrets );
		$scheduler = new Scheduler( $queue, $worker );
		$scheduler->boot();
		add_action( 'init', array( Resolver::class, 'registerMeta' ) );
		$logger   = new Logger( $wpdb, self::schema(), $settings->choice( 'log_level' ) );
		$selector = new Selector( $settings, $providers, $state );
		$tester   = new Tester( $settings, $providers, $state, $secrets );
		$status   = new StatusReport( $settings, $secrets, $providers, $state, $tester, new Usage( $wpdb, self::schema() ), $queue );
		( new QueueController( $queue, $scheduler, $worker, $settings, $selector, $providers ) )->boot();
		$settingsSaver = new SettingsController( $secrets, $queue, $scheduler );
		$settingsSaver->boot();
		( new ProvidersController( $status, $tester, $secrets ) )->boot();
		( new HealthController( $settings, new Health( $settings, $wpdb, self::schema(), $queue, $status, $selector, $logger ), $logger, self::strings(), $status ) )->boot();
		if ( is_admin() ) {
			( new AdminPage( $file, new Isolation( $file ) ) )->boot();
		}
		( new MigrationController( new TranslatePress( $wpdb ), $settings, $settingsSaver, self::strings(), $wpdb ) )->boot();
		// While TranslatePress is active our front end stays off (plan §13A.4).
		$frontEndOff = Guard::frontEndOff();
		if ( $frontEndOff ) {
			( new Guard() )->boot();
		}

		if ( null !== $target ) {
			$home   = (string) get_option( 'home' );
			$urls   = Urls::fromHome( $home, rest_get_url_prefix(), $settings->defaultPrefix() );
			$byLang = new LanguageUrls( $urls, $target, LanguageUrls::origin( $home ) );
			$modes  = new Resolver( $settings, $urls, $target );

			if ( ! $frontEndOff ) {
				// The language must be known before the locale and theme load.
				( new Router( $settings, $target, $urls ) )->boot();
				( new EditorRequest( $urls, $target ) )->boot();
				( new AdminBar( $urls, $byLang, $target, $file ) )->boot();
				( new HeadTags( $settings, $settings->defaultLanguage(), $target, $byLang, $modes ) )->boot();
				( new Switcher( $settings, $settings->defaultLanguage(), $target, $byLang, $modes, $file ) )->boot();
				( new OffPages( $settings, $modes, $byLang ) )->boot();
				$auto = new AutoQueue( $settings, $selector, $queue, $scheduler );
				( new Pipeline( $settings, $target, self::strings(), new DiscoveryGate( $settings, $logger ), $logger, $urls, $auto, $modes, new Preview( plugins_url( 'assets/preview.js', $file ) ) ) )->boot();
			}
			$purger = new Purger( self::strings(), $urls, $target, LanguageUrls::origin( $home ) );
			$purger->boot();
			$requests   = new Requests( $settings, self::strings(), $queue, $selector, $scheduler, $modes );
			$pageTarget = new PageTarget( $urls, $byLang, $target, $modes, self::strings() );
			( new StringsController( $requests, $target, $scheduler, $worker, self::strings(), $pageTarget ) )->boot();
			( new PagesController( self::strings(), $modes, $target, $purger ) )->boot();
			( new EditorController( $pageTarget ) )->boot();
			( new ImportController( new Importer( self::strings(), $target ) ) )->boot();
			( new SiteController( new SitePages( $settings, $modes, $urls, $target ), self::strings(), $requests, $queue, $status, $target ) )->boot();
			( new Exporter( self::strings(), $target ) )->boot();
			if ( is_admin() ) {
				( new PostPanel( self::strings(), $modes, $requests, $target, $file, $settings->flag( 'editor_mt_on_manual' ) ) )->boot();
				( new EditorPage( $settings, $target, $selector, new Isolation( $file ), $file ) )->boot();
			}
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wst string', new StringCommand( self::strings(), $settings ) );
			\WP_CLI::add_command( 'wst queue', new QueueCommand( $settings, $queue, $scheduler, $selector, $status, $worker ) );
			\WP_CLI::add_command( 'wst provider', new ProviderCommand( $tester ) );
		}
	}

	/**
	 * The queue worker with its collaborators on the global connection.
	 *
	 * @param Settings         $settings  Settings.
	 * @param Queue            $queue     Queue.
	 * @param ProviderRegistry $providers Configured adapters.
	 * @param ProviderState    $state     Pauses and quotas.
	 * @param Secrets          $secrets   Key store.
	 */
	private static function worker( Settings $settings, Queue $queue, ProviderRegistry $providers, ProviderState $state, Secrets $secrets ): Worker {
		global $wpdb;

		return new Worker(
			$settings,
			$queue,
			self::strings(),
			new RateLimiter( $wpdb ),
			new Usage( $wpdb, self::schema() ),
			$state,
			$providers,
			new Selector( $settings, $providers, $state ),
			new Registry(),
			$secrets,
			new Logger( $wpdb, self::schema(), $settings->choice( 'log_level' ) ),
			$wpdb
		);
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

<?php
/**
 * Keeps other plugins' code off the translation editor screen (plan §11).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Admin;

/**
 * On the editor screen, callbacks from plugins, must-use plugins and themes
 * (except this plugin) are removed from the admin page's script, style,
 * header, footer and notice hooks, and their enqueued scripts and styles
 * are dequeued just before printing. Core's own callbacks and assets stay,
 * so the admin menu and bar still work. A plugin that breaks JavaScript
 * elsewhere in the admin therefore cannot break the editor.
 */
final class Isolation {

	/** Hooks whose third-party callbacks are removed. */
	public const HOOKS = array(
		'admin_enqueue_scripts',
		'admin_print_styles',
		'admin_print_scripts',
		'admin_head',
		'in_admin_header',
		'admin_notices',
		'all_admin_notices',
		'network_admin_notices',
		'user_admin_notices',
		'in_admin_footer',
		'admin_footer',
		'admin_print_footer_scripts',
		'wp_print_scripts',
		'wp_print_styles',
		'wp_print_footer_scripts',
	);

	/** Notice hooks; on our settings screen only these are cleaned. */
	public const NOTICE_HOOKS = array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' );

	/**
	 * Real paths of directories whose code counts as third-party.
	 *
	 * @var list<string>
	 */
	private array $foreignRoots = array();

	/**
	 * Real path of this plugin.
	 *
	 * @var string
	 */
	private string $ownRoot;

	/**
	 * URL path of this plugin's directory.
	 *
	 * @var string
	 */
	private string $ownUrlPath;

	/**
	 * Create the guard.
	 *
	 * @param string $pluginFile Main plugin file.
	 */
	public function __construct( string $pluginFile ) {
		$this->ownRoot    = self::real( dirname( $pluginFile ) );
		$this->ownUrlPath = rtrim( (string) wp_parse_url( plugins_url( '', $pluginFile ), PHP_URL_PATH ), '/' );
		foreach ( array( WP_PLUGIN_DIR, WPMU_PLUGIN_DIR, get_theme_root() ) as $dir ) {
			$this->foreignRoots[] = self::real( $dir );
		}
	}

	/**
	 * Strip third-party callbacks now and dequeue their assets before printing.
	 * Call on current_screen for the editor screen only.
	 */
	public function apply(): void {
		foreach ( self::HOOKS as $hook ) {
			$this->stripHook( $hook );
		}
		foreach ( array( 'admin_print_styles', 'admin_print_scripts', 'admin_print_footer_scripts', 'wp_print_scripts', 'wp_print_styles', 'wp_print_footer_scripts' ) as $hook ) {
			add_action( $hook, array( $this, 'dequeueForeign' ), PHP_INT_MIN );
		}
		add_action( 'admin_head', array( $this, 'stripLateCallbacks' ), PHP_INT_MIN );
	}

	/**
	 * Hide third-party admin notices (our other screens keep other plugins'
	 * scripts, which some admin features need). Call on current_screen.
	 */
	public function hideNotices(): void {
		$strip = function (): void {
			foreach ( self::NOTICE_HOOKS as $hook ) {
				$this->stripHook( $hook );
			}
		};
		$strip();
		// Notices hooked after current_screen are removed just before they print.
		add_action( 'in_admin_header', $strip, PHP_INT_MIN );
	}

	/**
	 * Remove callbacks that were added after current_screen (for example by
	 * code running on admin_enqueue_scripts).
	 */
	public function stripLateCallbacks(): void {
		foreach ( self::HOOKS as $hook ) {
			$this->stripHook( $hook );
		}
	}

	/**
	 * Dequeue scripts and styles loaded from plugin, must-use plugin or theme
	 * directories, except this plugin's.
	 */
	public function dequeueForeign(): void {
		foreach ( array( wp_scripts(), wp_styles() ) as $deps ) {
			foreach ( $deps->queue as $handle ) {
				$item = $deps->registered[ $handle ] ?? null;
				if ( null !== $item && is_string( $item->src ) && $this->isForeignUrl( $item->src ) ) {
					$deps->dequeue( $handle );
				}
			}
		}
	}

	/**
	 * Whether a callback's code lives in a third-party plugin or theme.
	 *
	 * @param mixed $callback Callback.
	 */
	public function isForeign( $callback ): bool {
		$file = self::fileOf( $callback );
		if ( null === $file ) {
			return false;
		}
		// The longest matching directory decides (a development checkout of
		// this plugin may contain WordPress and other plugins below it).
		$file    = self::real( $file );
		$foreign = 0;
		foreach ( $this->foreignRoots as $root ) {
			if ( '' !== $root && str_starts_with( $file, $root . '/' ) ) {
				$foreign = max( $foreign, strlen( $root ) );
			}
		}
		$own = str_starts_with( $file, $this->ownRoot . '/' ) ? strlen( $this->ownRoot ) : 0;

		return $foreign > $own;
	}

	/**
	 * Remove third-party callbacks of one hook.
	 *
	 * @param string $hook Hook name.
	 */
	private function stripHook( string $hook ): void {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( $this->isForeign( $callback['function'] ) ) {
					remove_action( $hook, $callback['function'], $priority );
				}
			}
		}
	}

	/**
	 * Whether an asset URL points into a third-party plugin or theme.
	 *
	 * @param string $src Asset URL.
	 */
	private function isForeignUrl( string $src ): bool {
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		foreach ( array( content_url(), plugins_url(), get_theme_root_uri() ) as $base ) {
			$basePath = (string) wp_parse_url( $base, PHP_URL_PATH );
			if ( '' !== $basePath && str_starts_with( $path, rtrim( $basePath, '/' ) . '/' ) ) {
				return ! str_starts_with( $path, $this->ownUrlPath . '/' );
			}
		}

		return false;
	}

	/**
	 * File that defines a callback, or null for internal functions.
	 *
	 * @param mixed $callback Callback.
	 */
	private static function fileOf( $callback ): ?string {
		try {
			if ( $callback instanceof \Closure || ( is_string( $callback ) && ! str_contains( $callback, '::' ) && function_exists( $callback ) ) ) {
				$reflection = new \ReflectionFunction( $callback );
			} elseif ( is_array( $callback ) && 2 === count( $callback ) && ( is_object( $callback[0] ) || is_string( $callback[0] ) ) && is_string( $callback[1] ) ) {
				$reflection = new \ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( is_string( $callback ) && str_contains( $callback, '::' ) ) {
				$reflection = new \ReflectionMethod( $callback );
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$reflection = new \ReflectionMethod( $callback, '__invoke' );
			} else {
				return null;
			}
		} catch ( \ReflectionException $e ) {
			// A callback that cannot be reflected cannot run either; core will report it.
			return null;
		}
		$file = $reflection->getFileName();

		return false === $file ? null : $file;
	}

	/**
	 * Real path without a trailing slash ('' when it does not exist).
	 *
	 * @param string $path Path.
	 */
	private static function real( string $path ): string {
		$real = realpath( $path );

		return false === $real ? '' : rtrim( wp_normalize_path( $real ), '/' );
	}
}

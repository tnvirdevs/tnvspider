<?php
/**
 * The Translator admin screen (plan §10).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Admin;

use WST\Assets;
use WST\Config;
use WST\Switcher\Switcher;
use WST\Transfer\Csv;
use WST\Transfer\Exporter;
use WST\Transfer\Importer;

/**
 * Top-level "Translator" menu that hosts the React app (build/admin.js).
 * The app talks to wst/v1 through apiFetch, which sends the REST nonce;
 * nothing secret is printed into the page.
 */
final class AdminPage {

	public const MENU_SLUG     = Config::SLUG;
	public const SCRIPT_HANDLE = 'wst-admin';
	public const STYLE_HANDLE  = 'wst-admin';

	/**
	 * Hook suffix of the page, once added.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Create the page.
	 *
	 * @param string    $pluginFile Main plugin file.
	 * @param Isolation $isolation  Third-party notice guard.
	 */
	public function __construct( private string $pluginFile, private Isolation $isolation ) {
	}

	/**
	 * Register the menu and assets.
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'current_screen', array( $this, 'hideForeignNotices' ) );
	}

	/**
	 * Other plugins' notices are not shown on this screen.
	 *
	 * @param \WP_Screen $screen Current screen.
	 */
	public function hideForeignNotices( $screen ): void {
		if ( '' !== $this->hook && $screen instanceof \WP_Screen && $this->hook === $screen->id ) {
			$this->isolation->hideNotices();
		}
	}

	/**
	 * Add the top-level menu.
	 */
	public function addMenu(): void {
		$hook       = add_menu_page(
			__( 'Translator', 'wp-site-translator' ),
			__( 'Translator', 'wp-site-translator' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-translation',
			81
		);
		$this->hook = $hook;
		// The first submenu entry names this screen; Editor is added after it.
		add_submenu_page( self::MENU_SLUG, __( 'Translator settings', 'wp-site-translator' ), __( 'Settings', 'wp-site-translator' ), 'manage_options', self::MENU_SLUG );
	}

	/**
	 * Mount point; the app renders a notice itself if it cannot load data.
	 */
	public function render(): void {
		echo '<div class="wrap"><div id="wst-admin-app"><p>' . esc_html__( 'Loading…', 'wp-site-translator' ) . '</p></div>';
		echo '<noscript><p>' . esc_html__( 'The Translator settings need JavaScript.', 'wp-site-translator' ) . '</p></noscript></div>';
	}

	/**
	 * Load the app on its own screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}
		if ( ! Assets::registerScript( self::SCRIPT_HANDLE, 'admin', $this->pluginFile ) ) {
			return;
		}
		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_add_inline_script( self::SCRIPT_HANDLE, 'window.wstAdmin = ' . (string) wp_json_encode( $this->data() ) . ';', 'before' );
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( self::STYLE_HANDLE, plugins_url( 'build/admin.css', $this->pluginFile ), array( 'wp-components' ), Config::VERSION );
		// The live switcher preview uses the front-end styles.
		wp_enqueue_style( Switcher::STYLE_HANDLE, plugins_url( 'assets/switcher.css', $this->pluginFile ), array(), Config::VERSION );
	}

	/**
	 * Static data the app needs besides the REST API.
	 *
	 * @return array<string, mixed>
	 */
	private function data(): array {
		$cronDisabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		return array(
			'shortcode'  => '[' . Switcher::SHORTCODE . ']',
			'menusUrl'   => admin_url( 'nav-menus.php' ),
			'serverCron' => $cronDisabled ? Health::serverCronCommand() : '',
			'export'     => array(
				'url'    => admin_url( 'admin-post.php' ),
				'action' => Exporter::ACTION,
				'nonce'  => wp_create_nonce( Exporter::ACTION ),
			),
			'import'     => array(
				'chunk'        => Importer::MAX_ROWS,
				'maxFileBytes' => Importer::MAX_FILE_BYTES,
				'maxRows'      => Importer::MAX_FILE_ROWS,
				'columns'      => Csv::COLUMNS,
			),
		);
	}
}

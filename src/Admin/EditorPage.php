<?php
/**
 * Translator → Editor admin screen (plan §11).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Admin;

use WST\Migration\Guard;
use WST\Access;
use WST\Assets;
use WST\Config;
use WST\Languages\Language;
use WST\Providers\Selector;
use WST\Queue\Usage;
use WST\Settings;

/**
 * The translation editor lives in the admin, never on the front end. Its
 * screen loads only our bundle (see Isolation), so other plugins' scripts
 * and notices cannot break it. Needs the wst_translate capability.
 */
final class EditorPage {

	public const SLUG          = 'wst-editor';
	public const SCRIPT_HANDLE = 'wst-editor';

	/** Editor opened from a "not found" page: nothing to scan. */
	public const CONTEXT_NOT_FOUND = '404';

	/** Editor opened from search results: not one page. */
	public const CONTEXT_SEARCH = 'search';

	/**
	 * Hook suffix of the screen, once added.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Create the screen.
	 *
	 * @param Settings  $settings   Settings.
	 * @param Language  $target     Target language.
	 * @param Selector  $selector   Active provider.
	 * @param Isolation $isolation  Third-party code guard.
	 * @param string    $pluginFile Main plugin file.
	 */
	public function __construct(
		private Settings $settings,
		private Language $target,
		private Selector $selector,
		private Isolation $isolation,
		private string $pluginFile
	) {
	}

	/**
	 * URL of the editor for a post or a path.
	 *
	 * @param int|null    $postId  Post id.
	 * @param string|null $path    Site path without language prefix.
	 * @param string|null $context self::CONTEXT_NOT_FOUND or self::CONTEXT_SEARCH.
	 */
	public static function url( ?int $postId = null, ?string $path = null, ?string $context = null ): string {
		$args = array( 'page' => self::SLUG );
		if ( null !== $postId ) {
			$args['post'] = $postId;
		} elseif ( null !== $path ) {
			$args['path'] = rawurlencode( $path );
		}
		if ( null !== $context ) {
			$args['context'] = $context;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Register the screen.
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ), 20 );
		add_action( 'current_screen', array( $this, 'isolate' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), PHP_INT_MAX );
	}

	/**
	 * Add Translator → Editor (editors reach it too).
	 */
	public function addMenu(): void {
		$hook       = add_submenu_page(
			AdminPage::MENU_SLUG,
			__( 'Translation editor', 'wp-site-translator' ),
			__( 'Editor', 'wp-site-translator' ),
			Access::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
		$this->hook = false === $hook ? '' : $hook;
	}

	/**
	 * Keep third-party code off this screen.
	 *
	 * @param \WP_Screen $screen Current screen.
	 */
	public function isolate( $screen ): void {
		if ( '' !== $this->hook && $screen instanceof \WP_Screen && $this->hook === $screen->id ) {
			$this->isolation->apply();
		}
	}

	/**
	 * Mount point.
	 */
	public function render(): void {
		echo '<div class="wrap"><div id="wst-editor-app"><p>' . esc_html__( 'Loading the translation editor…', 'wp-site-translator' ) . '</p></div>';
		echo '<noscript><p>' . esc_html__( 'The translation editor needs JavaScript.', 'wp-site-translator' ) . '</p></noscript></div>';
	}

	/**
	 * Load the editor bundle on its screen.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook || ! Assets::registerScript( self::SCRIPT_HANDLE, 'editor', $this->pluginFile ) ) {
			return;
		}
		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_add_inline_script( self::SCRIPT_HANDLE, 'window.wstEditor = ' . (string) wp_json_encode( $this->data() ) . ';', 'before' );
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( self::SCRIPT_HANDLE, plugins_url( 'build/editor.css', $this->pluginFile ), array( 'wp-components' ), Config::VERSION );
	}

	/**
	 * What the editor needs besides the REST API.
	 *
	 * @return array<string, mixed>
	 */
	private function data(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only page selection.
		$post    = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$path    = isset( $_GET['path'] ) && is_string( $_GET['path'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['path'] ) ) ) : '';
		$context = isset( $_GET['context'] ) ? sanitize_key( wp_unslash( $_GET['context'] ) ) : '';
		// phpcs:enable
		$mt = null !== $this->selector->active( $this->settings->defaultLanguage(), $this->target, Usage::period() );

		return array(
			'postId'        => $post > 0 ? $post : null,
			'path'          => '' === $path ? null : $path,
			'context'       => in_array( $context, array( self::CONTEXT_NOT_FOUND, self::CONTEXT_SEARCH ), true ) ? $context : null,
			'language'      => array(
				'tag'    => $this->target->tag(),
				'dir'    => $this->target->dir(),
				'native' => $this->target->nativeName(),
			),
			'providerReady' => $mt,
			'mtOnManual'    => $this->settings->flag( 'editor_mt_on_manual' ),
			'paused'        => Guard::frontEndOff(),
			'settingsUrl'   => current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=' . AdminPage::MENU_SLUG ) : '',
		);
	}
}

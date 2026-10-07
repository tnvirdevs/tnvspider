<?php
/**
 * Page mode panel in the post editors (plan §9 "Page UI").
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Admin;

use WST\Access;
use WST\Config;
use WST\Languages\Language;
use WST\Modes\Resolver;
use WST\Queue\RequestRefused;
use WST\Queue\Requests;
use WST\Rest\PagesController;
use WST\Settings;
use WST\Storage\StringStore;

/**
 * Block editor: a document settings panel (built script `post-panel`).
 * Classic editor: a meta box with the same controls; "Translate this page
 * now" works there without JavaScript through admin-post.php. Both show the
 * page's own mode, the mode that applies and the coverage.
 */
final class PostPanel {

	public const NONCE_FIELD     = 'wst_mode_nonce';
	public const NONCE_ACTION    = 'wst_save_mode';
	public const TRANSLATE_HOOK  = 'wst_translate_post';
	public const SCRIPT_HANDLE   = 'wst-post-panel';
	private const NOTICE_PREFIX  = Config::PREFIX . 'post_notice_';
	private const NOTICE_SECONDS = 120;

	/**
	 * Create the panel.
	 *
	 * @param StringStore $store    Coverage.
	 * @param Resolver    $modes    Page modes.
	 * @param Requests    $requests Explicit translation requests.
	 * @param Language    $target   Target language.
	 * @param string      $pluginFile Main plugin file (for asset URLs).
	 */
	public function __construct(
		private StringStore $store,
		private Resolver $modes,
		private Requests $requests,
		private Language $target,
		private string $pluginFile
	) {
	}

	/**
	 * Register the hooks.
	 */
	public function boot(): void {
		add_action( 'add_meta_boxes', array( $this, 'addMetaBox' ) );
		add_action( 'save_post', array( $this, 'save' ) );
		add_action( 'admin_post_' . self::TRANSLATE_HOOK, array( $this, 'translateNow' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueueBlockEditor' ) );
	}

	/**
	 * Classic editor meta box (hidden in the block editor, which has the panel).
	 *
	 * @param string $postType Post type being edited.
	 */
	public function addMetaBox( $postType ): void {
		if ( ! in_array( $postType, PagesController::postTypes(), true ) ) {
			return;
		}
		add_meta_box(
			'wst-page-mode',
			__( 'Translation', 'wp-site-translator' ),
			array( $this, 'renderMetaBox' ),
			(string) $postType,
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Meta box body.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function renderMetaBox( $post ): void {
		$own       = Resolver::pageMode( $post->ID );
		$effective = $this->modes->resolvePost( $post->ID );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		echo '<p><label for="wst-mode"><strong>' . esc_html__( 'Translation mode', 'wp-site-translator' ) . '</strong></label></p>';
		echo '<select id="wst-mode" name="wst_mode" class="widefat" aria-describedby="wst-mode-help">';
		foreach ( self::modeLabels() as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $own, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<p id="wst-mode-help" class="description">' . esc_html( self::effectiveText( $effective ) ) . ' ' . esc_html__( 'The mode decides whether visits discover and queue new strings. Translations already made apply on every page.', 'wp-site-translator' ) . '</p>';

		if ( ! current_user_can( Access::CAPABILITY ) ) {
			return;
		}
		$coverage = $this->store->postCoverage( $post->ID, $this->target->locale() );
		echo '<p>' . esc_html( self::coverageText( $coverage['total'], $coverage['translated'] ) ) . '</p>';
		if ( Settings::MODE_OFF !== $effective['mode'] && $coverage['total'] > $coverage['translated'] ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::TRANSLATE_HOOK . '&post_id=' . $post->ID ), self::TRANSLATE_HOOK . '_' . $post->ID );
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $url ), esc_html__( 'Translate this page now', 'wp-site-translator' ) );
		}
	}

	/**
	 * Save the classic meta box.
	 *
	 * @param int $postId Post id.
	 */
	public function save( $postId ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}
		if ( wp_is_post_autosave( (int) $postId ) || wp_is_post_revision( (int) $postId ) || ! current_user_can( 'edit_post', (int) $postId ) ) {
			return;
		}
		$mode = isset( $_POST['wst_mode'] ) ? sanitize_key( wp_unslash( $_POST['wst_mode'] ) ) : Resolver::INHERIT;
		if ( ! in_array( $mode, Resolver::PAGE_VALUES, true ) ) {
			return;
		}
		if ( Resolver::INHERIT === $mode ) {
			delete_post_meta( (int) $postId, Resolver::META_KEY );
		} else {
			update_post_meta( (int) $postId, Resolver::META_KEY, $mode );
		}
	}

	/**
	 * Handle "Translate this page now" from the classic editor (admin-post.php).
	 */
	public function translateNow(): void {
		$postId = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		check_admin_referer( self::TRANSLATE_HOOK . '_' . $postId );
		if ( 0 === $postId || ! current_user_can( Access::CAPABILITY ) || ! current_user_can( 'edit_post', $postId ) ) {
			wp_die( esc_html__( 'You are not allowed to translate this page.', 'wp-site-translator' ), 403 );
		}
		try {
			$result  = $this->requests->post( $postId, $this->target );
			$message = 0 === $result['queued']
				? __( 'Nothing to translate: every string of this page has a translation.', 'wp-site-translator' )
				/* translators: %d: number of strings */
				: sprintf( _n( '%d string queued for translation.', '%d strings queued for translation.', $result['queued'], 'wp-site-translator' ), $result['queued'] );
			$this->setNotice( 'success', $message );
		} catch ( RequestRefused $e ) {
			$this->setNotice( 'error', $e->getMessage() );
		}
		wp_safe_redirect( (string) get_edit_post_link( $postId, 'url' ) );
		exit;
	}

	/**
	 * Show the result of "Translate this page now" once.
	 */
	public function notice(): void {
		$key    = self::NOTICE_PREFIX . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'error' === $notice['type'] ? 'error' : 'success', esc_html( (string) $notice['message'] ) );
	}

	/**
	 * Load the block editor panel for public post types.
	 */
	public function enqueueBlockEditor(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! in_array( $screen->post_type, PagesController::postTypes(), true ) ) {
			return;
		}
		$dir   = dirname( $this->pluginFile ) . '/build/';
		$asset = $dir . 'post-panel.asset.php';
		if ( ! is_readable( $asset ) ) {
			// A packaging error, not a runtime condition: say so where it shows.
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'WP Site Translator: build/post-panel.js is missing. Run "npm run build".', 'wp-site-translator' ) . '</p></div>';
				}
			);

			return;
		}
		$meta = require $asset;
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'build/post-panel.js', $this->pluginFile ),
			is_array( $meta ) && isset( $meta['dependencies'] ) ? (array) $meta['dependencies'] : array(),
			is_array( $meta ) && isset( $meta['version'] ) ? (string) $meta['version'] : Config::VERSION,
			true
		);
		wp_set_script_translations( self::SCRIPT_HANDLE, 'wp-site-translator' );
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.wstPostPanel = ' . wp_json_encode(
				array(
					'canTranslate' => current_user_can( Access::CAPABILITY ),
					'modes'        => self::modeLabels(),
					'language'     => $this->target->nativeName(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Labels of the page values.
	 *
	 * @return array<string, string>
	 */
	public static function modeLabels(): array {
		return array(
			Resolver::INHERIT     => __( 'Default (path rules, then site mode)', 'wp-site-translator' ),
			Settings::MODE_AUTO   => __( 'Automatic', 'wp-site-translator' ),
			Settings::MODE_MANUAL => __( 'Manual only', 'wp-site-translator' ),
			Settings::MODE_OFF    => __( 'Off (not translated)', 'wp-site-translator' ),
		);
	}

	/**
	 * "Applies: Manual only (path rule)".
	 *
	 * @param array{mode: string, source: string} $effective Resolved mode.
	 */
	public static function effectiveText( array $effective ): string {
		$sources = array(
			Resolver::SOURCE_PAGE => __( 'this page', 'wp-site-translator' ),
			Resolver::SOURCE_PATH => __( 'path rule', 'wp-site-translator' ),
			Resolver::SOURCE_SITE => __( 'site mode', 'wp-site-translator' ),
		);

		/* translators: 1: mode label, 2: where it comes from */
		return sprintf( __( 'Applies now: %1$s (%2$s).', 'wp-site-translator' ), self::modeLabels()[ $effective['mode'] ] ?? $effective['mode'], $sources[ $effective['source'] ] ?? $effective['source'] );
	}

	/**
	 * Coverage sentence.
	 *
	 * @param int $total      Strings on the page.
	 * @param int $translated Translated strings.
	 */
	public static function coverageText( int $total, int $translated ): string {
		if ( 0 === $total ) {
			return __( 'No strings recorded yet: the translated page has not been visited or scanned.', 'wp-site-translator' );
		}

		/* translators: 1: translated strings, 2: all strings, 3: percent */
		return sprintf( __( 'Translated: %1$d of %2$d strings (%3$d%%).', 'wp-site-translator' ), $translated, $total, (int) floor( 100 * $translated / $total ) );
	}

	/**
	 * Remember a notice for the current user's next admin page.
	 *
	 * @param string $type    success or error.
	 * @param string $message Text.
	 */
	private function setNotice( string $type, string $message ): void {
		set_transient(
			self::NOTICE_PREFIX . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			self::NOTICE_SECONDS
		);
	}
}

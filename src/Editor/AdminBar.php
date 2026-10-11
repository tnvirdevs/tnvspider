<?php
/**
 * "Translate" toolbar item on the front end and in wp-admin (plan §11 entry points).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Editor;

use WST\Access;
use WST\Admin\AdminPage;
use WST\Admin\EditorPage;
use WST\Config;
use WST\Languages\Language;
use WST\Routing\LanguageUrls;
use WST\Routing\Urls;

/**
 * For users with wst_translate, on every front-end page in both languages
 * (home, archives, shop, products, search, 404, off pages) and in wp-admin:
 * a top-level "Translate" item with the translation icon, kept as an icon
 * on phones. Children: "Translate this page" (front end and post edit
 * screens), "Translator settings" (administrators) and, on the editor,
 * "Back to page". Pages that cannot be scanned still link to the editor,
 * which explains why.
 */
final class AdminBar {

	public const NODE = 'wst-translate';

	/**
	 * Create the toolbar item.
	 *
	 * @param Urls         $urls       URL helper.
	 * @param LanguageUrls $byLang     Per-language URLs.
	 * @param Language     $target     Target language.
	 * @param string       $pluginFile Main plugin file (for the stylesheet).
	 */
	public function __construct( private Urls $urls, private LanguageUrls $byLang, private Language $target, private string $pluginFile ) {
	}

	/**
	 * Register the hooks.
	 */
	public function boot(): void {
		add_action( 'admin_bar_menu', array( $this, 'addNodes' ), 80 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueStyle' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueStyle' ) );
	}

	/**
	 * Icon styles, also on phones.
	 */
	public function enqueueStyle(): void {
		if ( is_admin_bar_showing() && current_user_can( Access::CAPABILITY ) && null === EditorRequest::current() ) {
			wp_enqueue_style( 'wst-admin-bar', plugins_url( 'assets/admin-bar.css', $this->pluginFile ), array( 'admin-bar' ), Config::VERSION );
		}
	}

	/**
	 * Add the item and its children.
	 *
	 * @param \WP_Admin_Bar $bar Toolbar.
	 */
	public function addNodes( $bar ): void {
		if ( ! current_user_can( Access::CAPABILITY ) || null !== EditorRequest::current() ) {
			return;
		}
		$thisPage = is_admin() ? $this->adminPostLink() : $this->frontEndLink();
		$bar->add_node(
			array(
				'id'    => self::NODE,
				'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Translate', 'wp-site-translator' ) . '</span>',
				'href'  => $thisPage ?? EditorPage::url(),
				'meta'  => array( 'title' => __( 'Translate this site', 'wp-site-translator' ) ),
			)
		);
		if ( null !== $thisPage ) {
			$bar->add_node(
				array(
					'parent' => self::NODE,
					'id'     => self::NODE . '-page',
					'title'  => esc_html__( 'Translate this page', 'wp-site-translator' ),
					'href'   => $thisPage,
				)
			);
		}
		$back = is_admin() ? $this->backToPage() : null;
		if ( null !== $back ) {
			$bar->add_node(
				array(
					'parent' => self::NODE,
					'id'     => self::NODE . '-back',
					'title'  => esc_html__( 'Back to page', 'wp-site-translator' ),
					'href'   => $back,
				)
			);
		}
		if ( ! is_admin() || null === $thisPage ) {
			$bar->add_node(
				array(
					'parent' => self::NODE,
					'id'     => self::NODE . '-editor',
					'title'  => esc_html__( 'Translation editor', 'wp-site-translator' ),
					'href'   => EditorPage::url(),
				)
			);
		}
		if ( current_user_can( 'manage_options' ) ) {
			$bar->add_node(
				array(
					'parent' => self::NODE,
					'id'     => self::NODE . '-settings',
					'title'  => esc_html__( 'Translator settings', 'wp-site-translator' ),
					'href'   => admin_url( 'admin.php?page=' . AdminPage::MENU_SLUG ),
				)
			);
		}
	}

	/**
	 * Editor link for the front-end page being viewed, in either language.
	 */
	private function frontEndLink(): ?string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only parsed.
		$path = $this->urls->unprefixedPath( $uri, $this->target->slug() );
		if ( null === $path ) {
			return null;
		}
		if ( is_404() ) {
			return EditorPage::url( null, $path, EditorPage::CONTEXT_NOT_FOUND );
		}
		if ( is_search() ) {
			return EditorPage::url( null, null, EditorPage::CONTEXT_SEARCH );
		}
		$post = is_singular() ? get_queried_object() : null;

		return $post instanceof \WP_Post ? EditorPage::url( $post->ID ) : EditorPage::url( null, $path );
	}

	/**
	 * Editor link for the post being edited in wp-admin, or null.
	 */
	private function adminPostLink(): ?string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only link.
		$postId = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( null === $screen || 'post' !== $screen->base || 0 === $postId || ! is_post_type_viewable( $screen->post_type ) ) {
			return null;
		}

		return EditorPage::url( $postId );
	}

	/**
	 * On the editor screen: the translated page being edited.
	 */
	private function backToPage(): ?string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! str_ends_with( $screen->id, '_page_' . EditorPage::SLUG ) ) {
			return null;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only link.
		$postId = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$path   = isset( $_GET['path'] ) && is_string( $_GET['path'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['path'] ) ) ) : '';
		// phpcs:enable
		if ( $postId > 0 ) {
			$link = get_permalink( $postId );
			if ( false === $link ) {
				return null;
			}
			$post = get_post( $postId );
			if ( null === $post || ! is_post_publicly_viewable( $post ) ) {
				return $link;
			}
			$path = (string) wp_parse_url( $link, PHP_URL_PATH );
		}
		if ( '' === $path || ! str_starts_with( $path, '/' ) ) {
			return null;
		}

		return $this->byLang->forUri( $path, false )['target'] ?? null;
	}
}

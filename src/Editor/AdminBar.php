<?php
/**
 * "Translate this page" in the toolbar on the front end (plan §11 entry points).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Editor;

use WST\Access;
use WST\Admin\EditorPage;
use WST\Languages\Language;
use WST\Modes\Resolver;
use WST\Routing\Urls;
use WST\Settings;

/**
 * Shown to users with wst_translate on front-end pages that are not "off",
 * in either language; it opens the editor for the page being viewed.
 */
final class AdminBar {

	/**
	 * Create the node builder.
	 *
	 * @param Urls     $urls   URL helper.
	 * @param Language $target Target language.
	 * @param Resolver $modes  Page modes.
	 */
	public function __construct( private Urls $urls, private Language $target, private Resolver $modes ) {
	}

	/**
	 * Register the toolbar hook.
	 */
	public function boot(): void {
		add_action( 'admin_bar_menu', array( $this, 'addNode' ), 80 );
	}

	/**
	 * Add the node.
	 *
	 * @param \WP_Admin_Bar $bar Toolbar.
	 */
	public function addNode( $bar ): void {
		if ( is_admin() || null !== EditorRequest::current() || ! current_user_can( Access::CAPABILITY ) || is_404() || Settings::MODE_OFF === $this->modes->current()['mode'] ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only parsed.
		$path = $this->urls->unprefixedPath( $uri, $this->target->slug() );
		if ( null === $path ) {
			return;
		}
		$post = is_singular() ? get_queried_object() : null;
		$url  = $post instanceof \WP_Post && is_post_publicly_viewable( $post ) ? EditorPage::url( $post->ID ) : EditorPage::url( null, $path );
		$bar->add_node(
			array(
				'id'    => 'wst-translate',
				'title' => esc_html__( 'Translate this page', 'wp-site-translator' ),
				'href'  => $url,
			)
		);
	}
}

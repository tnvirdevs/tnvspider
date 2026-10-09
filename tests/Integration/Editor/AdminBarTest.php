<?php
/**
 * The "Translate" toolbar item: every front-end page type in both languages,
 * wp-admin, the editor's "Back to page", and who sees it.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Editor;

use WP_UnitTestCase;
use WST\Access;
use WST\Admin\AdminPage;
use WST\Admin\EditorPage;
use WST\Editor\AdminBar;
use WST\Editor\EditorRequest;
use WST\Languages\Current;
use WST\Languages\Registry;
use WST\Routing\LanguageUrls;
use WST\Routing\Router;
use WST\Routing\Urls;
use WST\Settings;

final class AdminBarTest extends WP_UnitTestCase {

	private const PLUGIN_FILE = __DIR__ . '/../../../wp-site-translator.php';

	private int $postId = 0;

	private int $productId = 0;

	private int $draftId = 0;

	private int $cartId = 0;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		// Registered after pretty permalinks are on, so it and the categories get rewrite rules.
		register_post_type(
			'product',
			array(
				'public'      => true,
				'has_archive' => 'shop',
				'rewrite'     => array( 'slug' => 'product' ),
			)
		);
		create_initial_taxonomies();
		flush_rewrite_rules( false );
		Access::install();
		$this->postId    = self::factory()->post->create( array( 'post_name' => 'hello' ) );
		$this->productId = self::factory()->post->create(
			array(
				'post_type' => 'product',
				'post_name' => 'widget',
			)
		);
		$this->cartId    = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'cart',
			)
		);
		$this->draftId   = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_name'   => 'soon',
			)
		);
		self::factory()->category->create( array( 'slug' => 'news' ) );
		wp_set_post_categories( $this->postId, array( (int) get_term_by( 'slug', 'news', 'category' )->term_id ) );
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
	}

	public function tear_down(): void {
		unset( $GLOBALS['current_screen'], $_GET['post'], $_GET['path'] );
		unregister_post_type( 'product' );
		EditorRequest::reset();
		$this->unhookRouters();
		parent::tear_down();
	}

	/**
	 * Toolbar built for a request.
	 *
	 * @param string      $uri    Request URI; its query fills $_GET.
	 * @param string      $role   Role of the current user.
	 * @param string|null $screen Admin screen id, for wp-admin requests.
	 */
	private function bar( string $uri, string $role = 'editor', ?string $screen = null ): \WP_Admin_Bar {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		$settings = new Settings( array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() );
		$target   = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$urls     = Urls::fromHome( 'http://example.org', 'wp-json' );
		$this->unhookRouters();
		// The Router detects the language from REQUEST_URI when it boots.
		$_SERVER['REQUEST_URI'] = $uri;
		( new Router( $settings, $target, $urls ) )->boot();
		$this->go_to( 'http://example.org' . $uri );
		if ( null !== $screen ) {
			set_current_screen( $screen ); // After go_to(), which clears it.
		}
		$bar = new \WP_Admin_Bar();
		( new AdminBar( $urls, new LanguageUrls( $urls, $target, 'http://example.org' ), $target, self::PLUGIN_FILE ) )->addNodes( $bar );

		return $bar;
	}

	/**
	 * Remove the hooks of a Router booted earlier in the same test.
	 */
	private function unhookRouters(): void {
		global $wp_filter;
		foreach ( array( 'locale', 'gettext_with_context', 'do_parse_request', 'parse_request', 'home_url', 'wp_redirect' ) as $hook ) {
			foreach ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array() as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Router ) {
						remove_filter( $hook, $callback['function'], $priority );
					}
				}
			}
		}
		Current::reset();
	}

	private function href( \WP_Admin_Bar $bar, string $id ): ?string {
		$node = $bar->get_node( $id );

		return null === $node ? null : html_entity_decode( (string) $node->href );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function frontEndPages(): array {
		return array(
			'home, default'         => array( '/', 'path=%2F' ),
			'home, target'          => array( '/bn/', 'path=%2F' ),
			'post, default'         => array( '/hello/', 'post=' ),
			'post, target'          => array( '/bn/hello/', 'post=' ),
			'archive, default'      => array( '/category/news/', 'path=%2Fcategory%2Fnews%2F' ),
			'archive, target'       => array( '/bn/category/news/', 'path=%2Fcategory%2Fnews%2F' ),
			'shop archive, default' => array( '/shop/', 'path=%2Fshop%2F' ),
			'shop archive, target'  => array( '/bn/shop/', 'path=%2Fshop%2F' ),
			'product, target'       => array( '/bn/product/widget/', 'post=' ),
			'cart page, default'    => array( '/cart/', 'post=' ),
			'search, default'       => array( '/?s=hello', 'context=search' ),
			'search, target'        => array( '/bn/?s=hello', 'context=search' ),
			'not found, default'    => array( '/no-such-page/', 'path=%2Fno-such-page%2F&context=404' ),
			'not found, target'     => array( '/bn/no-such-page/', 'path=%2Fno-such-page%2F&context=404' ),
		);
	}

	/**
	 * @dataProvider frontEndPages
	 */
	public function test_every_front_end_page_type_gets_the_item_in_both_languages( string $uri, string $expected ): void {
		$bar = $this->bar( $uri );

		$top = $bar->get_node( AdminBar::NODE );
		$this->assertNotNull( $top, $uri );
		$this->assertStringContainsString( 'class="ab-icon"', (string) $top->title, 'The icon stays on phones.' );
		$this->assertStringContainsString( '>Translate</span>', (string) $top->title );
		$page = (string) $this->href( $bar, AdminBar::NODE . '-page' );
		$this->assertStringStartsWith( 'http://example.org/wp-admin/admin.php?page=' . EditorPage::SLUG . '&', $page );
		$this->assertStringContainsString( $expected, $page );
		if ( ! str_contains( $expected, 'context=404' ) ) {
			$this->assertStringNotContainsString( 'context=404', $page, 'This page exists.' );
		}
		$this->assertSame( $page, $this->href( $bar, AdminBar::NODE ), 'The top item opens this page too.' );
		$this->assertNull( $bar->get_node( AdminBar::NODE . '-settings' ), 'Editors do not manage settings.' );
	}

	public function test_the_post_link_names_the_post_on_both_languages(): void {
		$this->assertStringEndsWith( 'post=' . $this->postId, (string) $this->href( $this->bar( '/hello/' ), AdminBar::NODE . '-page' ) );
		$this->assertStringEndsWith( 'post=' . $this->productId, (string) $this->href( $this->bar( '/bn/product/widget/' ), AdminBar::NODE . '-page' ) );
		$this->assertStringEndsWith( 'post=' . $this->cartId, (string) $this->href( $this->bar( '/cart/' ), AdminBar::NODE . '-page' ) );
	}

	public function test_administrators_also_get_the_settings_link(): void {
		$bar = $this->bar( '/bn/hello/', 'administrator' );

		$this->assertSame( 'http://example.org/wp-admin/admin.php?page=' . AdminPage::MENU_SLUG, $this->href( $bar, AdminBar::NODE . '-settings' ) );
	}

	public function test_users_without_the_capability_get_nothing(): void {
		$this->assertNull( $this->bar( '/bn/hello/', 'author' )->get_node( AdminBar::NODE ) );
	}

	public function test_nothing_is_added_to_scan_and_preview_renders(): void {
		$bar = new \WP_Admin_Bar();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$settings = new Settings( array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() );
		$target   = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$urls     = Urls::fromHome( 'http://example.org', 'wp-json' );
		$property = new \ReflectionProperty( EditorRequest::class, 'current' );
		$property->setAccessible( true );
		$property->setValue( null, array( 'mode' => 'scan' ) );

		( new AdminBar( $urls, new LanguageUrls( $urls, $target, 'http://example.org' ), $target, self::PLUGIN_FILE ) )->addNodes( $bar );

		$this->assertNull( $bar->get_node( AdminBar::NODE ) );
	}

	public function test_post_edit_screen_links_to_the_editor_for_that_post(): void {
		$bar = $this->bar( '/wp-admin/post.php?post=' . $this->postId . '&action=edit', 'editor', 'post' );

		$this->assertSame( EditorPage::url( $this->postId ), $this->href( $bar, AdminBar::NODE ) );
		$this->assertSame( EditorPage::url( $this->postId ), $this->href( $bar, AdminBar::NODE . '-page' ) );
		$this->assertNull( $bar->get_node( AdminBar::NODE . '-back' ) );
	}

	public function test_other_admin_screens_link_to_the_editor(): void {
		$bar = $this->bar( '/wp-admin/', 'editor', 'dashboard' );

		$this->assertSame( EditorPage::url(), $this->href( $bar, AdminBar::NODE ) );
		$this->assertNull( $bar->get_node( AdminBar::NODE . '-page' ) );
		$this->assertSame( EditorPage::url(), $this->href( $bar, AdminBar::NODE . '-editor' ) );
	}

	public function test_editor_screen_links_back_to_the_translated_page(): void {
		$screen = 'translator_page_' . EditorPage::SLUG;
		$editor = '/wp-admin/admin.php?page=' . EditorPage::SLUG;

		$this->assertSame( 'http://example.org/bn/hello/', $this->href( $this->bar( $editor . '&post=' . $this->postId, 'editor', $screen ), AdminBar::NODE . '-back' ) );
		$this->assertSame( 'http://example.org/bn/shop/', $this->href( $this->bar( $editor . '&path=%2Fshop%2F', 'editor', $screen ), AdminBar::NODE . '-back' ) );
		$this->assertSame( get_permalink( $this->draftId ), $this->href( $this->bar( $editor . '&post=' . $this->draftId, 'editor', $screen ), AdminBar::NODE . '-back' ), 'A draft has no translated address: its preview link.' );
		$this->assertNull( $this->bar( $editor, 'editor', $screen )->get_node( AdminBar::NODE . '-back' ), 'No page chosen yet.' );
	}
}

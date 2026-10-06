<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Routing;

use WP_UnitTestCase;
use WST\Languages\Current;
use WST\Languages\Registry;
use WST\Routing\Router;
use WST\Settings;

final class RouterTest extends WP_UnitTestCase {

	private int $postId;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->postId = self::factory()->post->create(
			array(
				'post_name'  => 'hello-world',
				'post_title' => 'Hello world',
			)
		);
	}

	public function tear_down(): void {
		Current::reset();
		unset( $_SERVER['HTTP_REFERER'], $_GET['wst_lang'], $_REQUEST['wst_lang'] );
		parent::tear_down();
	}

	private function boot( string $target = 'bn_BD' ): void {
		$settings = new Settings( array( 'target_language' => $target ), 'en_US', new Registry() );
		$router   = Router::forSite( $settings );
		$this->assertNotNull( $router );
		$router->boot();
	}

	public function test_no_router_without_a_target_language(): void {
		$this->assertNull( Router::forSite( new Settings( array(), 'en_US', new Registry() ) ) );
	}

	public function test_prefixed_url_routes_to_the_post_in_the_target_language(): void {
		$this->boot();
		$this->go_to( '/bn/hello-world/' );

		$this->assertTrue( Current::isTarget() );
		$this->assertTrue( is_single( $this->postId ) );
		$this->assertSame( '/bn/hello-world/', $_SERVER['REQUEST_URI'], 'The prefixed URI is restored after parsing.' );
		$this->assertSame( 'bn_BD', get_locale() );
		$this->assertSame( home_url( '/hello-world/' ), get_permalink( $this->postId ) );
		$this->assertStringContainsString( '/bn/hello-world/', get_permalink( $this->postId ) );
	}

	public function test_prefixed_home_routes_to_the_front_page(): void {
		$this->boot();
		$this->go_to( '/bn/' );

		$this->assertTrue( Current::isTarget() );
		$this->assertTrue( is_home() );
		$this->assertSame( 'http://example.org/bn/', home_url( '/' ) );
	}

	public function test_default_language_is_untouched(): void {
		$this->boot();
		$this->go_to( '/hello-world/' );

		$this->assertFalse( Current::isTarget() );
		$this->assertTrue( is_single( $this->postId ) );
		$this->assertSame( 'http://example.org/hello-world/', get_permalink( $this->postId ) );
		$this->assertNotSame( 'bn_BD', get_locale() );
	}

	public function test_rest_and_asset_urls_keep_no_prefix(): void {
		$this->boot();
		$this->go_to( '/bn/' );

		$this->assertSame( 'http://example.org/wp-json/', get_rest_url() );
		$this->assertSame( 'http://example.org/wp-admin/', admin_url() );
		$this->assertStringNotContainsString( '/bn/', includes_url( 'js/jquery.js' ) );
	}

	public function test_canonical_redirect_keeps_the_prefix(): void {
		$this->boot();
		$this->go_to( '/bn/hello-world' );

		$this->assertSame( 'http://example.org/bn/hello-world/', redirect_canonical( 'http://example.org/bn/hello-world', false ) );
	}

	public function test_internal_redirects_stay_in_the_language(): void {
		$this->boot();
		$this->go_to( '/bn/' );

		$this->assertSame( '/bn/shop/', apply_filters( 'wp_redirect', '/shop/', 302 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$this->assertSame( 'https://other.example/x', apply_filters( 'wp_redirect', 'https://other.example/x', 302 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$this->assertSame( 'http://example.org/wp-login.php', apply_filters( 'wp_redirect', 'http://example.org/wp-login.php', 302 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
	}

	public function test_rtl_target_flips_direction_and_loads_rtl_stylesheets(): void {
		$this->boot( 'ar' );
		$this->go_to( '/ar/' );

		// WP_Locale and WP_Styles read the direction when they are created.
		$GLOBALS['wp_locale'] = new \WP_Locale(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Rebuilt after the locale switch.
		$GLOBALS['wp_styles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Rebuilt after the locale switch.
		$this->assertTrue( is_rtl() );

		wp_register_style( 'wst-test', 'http://example.org/wp-content/themes/t/style.css', array(), '1' );
		wp_style_add_data( 'wst-test', 'rtl', 'replace' );
		$html = get_echo( 'wp_print_styles', array( 'wst-test' ) );

		$this->assertStringContainsString( 'style-rtl.css', $html );
		$this->assertStringContainsString( 'dir="rtl"', get_echo( 'language_attributes' ) );
		$this->assertStringContainsString( 'lang="ar"', get_echo( 'language_attributes' ) );
	}

	public function test_ltr_default_stays_ltr(): void {
		$this->boot( 'ar' );
		$this->go_to( '/hello-world/' );
		$GLOBALS['wp_locale'] = new \WP_Locale(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Rebuilt after the locale switch.

		$this->assertFalse( is_rtl() );
	}

	public function test_ajax_language_from_referer(): void {
		$this->boot();
		$_SERVER['REQUEST_URI']  = '/wp-admin/admin-ajax.php';
		$_SERVER['HTTP_REFERER'] = 'http://example.org/bn/shop/';
		Router::forSite( new Settings( array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() ) )?->detect();

		$this->assertTrue( Current::isTarget() );
	}

	public function test_ajax_language_from_explicit_parameter_wins(): void {
		$_SERVER['REQUEST_URI']  = '/wp-json/wc/store/cart';
		$_SERVER['HTTP_REFERER'] = 'http://example.org/bn/shop/';
		$_REQUEST['wst_lang']    = 'en';
		Router::forSite( new Settings( array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() ) )?->detect();
		$this->assertFalse( Current::isTarget() );

		$_REQUEST['wst_lang'] = 'bn';
		Router::forSite( new Settings( array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() ) )?->detect();
		$this->assertTrue( Current::isTarget() );
	}

	public function test_admin_requests_are_never_target(): void {
		$_SERVER['REQUEST_URI']  = '/wp-admin/edit.php';
		$_SERVER['HTTP_REFERER'] = 'http://example.org/bn/shop/';
		Router::forSite( new Settings( array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() ) )?->detect();

		$this->assertFalse( Current::isTarget() );
	}
}

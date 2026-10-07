<?php
/**
 * "Prefix the default language" (plan §5, §10 Languages).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Routing;

use WP_UnitTestCase;
use WST\Languages\Current;
use WST\Languages\Registry;
use WST\Routing\LanguageUrls;
use WST\Routing\Router;
use WST\Settings;

final class DefaultPrefixTest extends WP_UnitTestCase {

	private int $postId;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->postId              = self::factory()->post->create( array( 'post_name' => 'hello-world' ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		delete_option( Router::USED_PREFIX_OPTION );
	}

	public function tear_down(): void {
		Current::reset();
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $values Settings.
	 */
	private function boot( array $values = array( 'prefix_default' => true ) ): Settings {
		$settings = new Settings( $values + array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() );
		$router   = Router::forSite( $settings );
		$this->assertNotNull( $router );
		$router->boot();

		return $settings;
	}

	/**
	 * Location and status of the redirect a request triggers, or null.
	 *
	 * @return array{0: string, 1: int}|null
	 */
	private function redirectOf( string $uri ): ?array {
		$seen = null;
		add_filter(
			'wp_redirect',
			static function ( $location, $status ) use ( &$seen ) {
				$seen = array( $location, $status );
				throw new \RuntimeException( 'redirected' );
			},
			99,
			2
		);
		try {
			$this->go_to( $uri );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		return $seen;
	}

	public function test_prefixed_default_url_routes_and_links_keep_the_prefix(): void {
		$this->boot();
		$this->go_to( '/en/hello-world/' );

		$this->assertFalse( Current::isTarget() );
		$this->assertTrue( is_single( $this->postId ) );
		$this->assertSame( '/en/hello-world/', $_SERVER['REQUEST_URI'] );
		$this->assertSame( 'http://example.org/en/hello-world/', get_permalink( $this->postId ) );
		$this->assertSame( 'http://example.org/en/', home_url( '/' ) );
		$this->assertSame( 'http://example.org/wp-json/', get_rest_url(), 'REST stays unprefixed.' );
	}

	public function test_unprefixed_page_urls_redirect_permanently_to_the_prefix(): void {
		$this->boot();

		$this->assertSame( array( 'http://example.org/en/hello-world/?x=1', 301 ), $this->redirectOf( '/hello-world/?x=1' ) );
	}

	public function test_target_language_still_works_and_swaps_prefixes(): void {
		$settings = $this->boot();
		$this->go_to( '/bn/hello-world/' );

		$this->assertTrue( Current::isTarget() );
		$this->assertTrue( is_single( $this->postId ) );
		$this->assertSame( 'http://example.org/bn/hello-world/', get_permalink( $this->postId ) );

		$urls = Router::forSite( $settings );
		$this->assertNotNull( $urls );
		$byLang = new LanguageUrls( \WST\Routing\Urls::fromHome( 'http://example.org', 'wp-json', $settings->defaultPrefix() ), $settings->targetLanguage() ?? throw new \LogicException(), 'http://example.org' );
		$this->assertSame(
			array(
				'default' => 'http://example.org/en/hello-world/',
				'target'  => 'http://example.org/bn/hello-world/',
			),
			$byLang->forUri( '/en/hello-world/', false )
		);
		$this->assertSame( 'http://example.org/en/hello-world/', $byLang->forUri( '/bn/hello-world/', false )['default'] ?? null );
	}

	public function test_turning_the_option_off_redirects_old_prefixed_urls_back(): void {
		$this->boot();
		$this->assertSame( 'en', get_option( Router::USED_PREFIX_OPTION ) );
		Current::reset();
		remove_all_filters( 'home_url' );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'do_parse_request' );
		remove_all_actions( 'parse_request' );

		$this->boot( array() );

		$this->assertSame( array( 'http://example.org/hello-world/', 301 ), $this->redirectOf( '/en/hello-world/' ) );
	}

	public function test_custom_default_slug_and_conflicting_slug(): void {
		$custom = new Settings(
			array(
				'target_language' => 'bn_BD',
				'prefix_default'  => true,
				'default_slug'    => 'english',
			),
			'en_US',
			new Registry()
		);
		$this->assertSame( 'english', $custom->defaultPrefix() );

		$clash = new Settings(
			array(
				'target_language' => 'bn_BD',
				'target_slug'     => 'en',
				'prefix_default'  => true,
			),
			'en_US',
			new Registry()
		);
		$this->assertNull( $clash->defaultPrefix(), 'Same slug as the target: no default prefix.' );
		$this->assertNull( ( new Settings( array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() ) )->defaultPrefix(), 'Off by default.' );
	}
}

<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Modes;

use WP_UnitTestCase;
use WST\Languages\Current;
use WST\Languages\Registry;
use WST\Modes\OffPages;
use WST\Modes\Resolver;
use WST\Render\HeadTags;
use WST\Routing\LanguageUrls;
use WST\Routing\Router;
use WST\Routing\Urls;
use WST\Settings;
use WST\Switcher\Switcher;

final class OffPagesTest extends WP_UnitTestCase {

	private int $postId;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->postId = self::factory()->post->create( array( 'post_name' => 'private-offer' ) );
		self::factory()->post->create( array( 'post_name' => 'public-page' ) );
		update_post_meta( $this->postId, Resolver::META_KEY, Settings::MODE_OFF );
	}

	public function tear_down(): void {
		Current::reset();
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $values Settings.
	 * @return array{0: OffPages, 1: HeadTags, 2: Switcher}
	 */
	private function boot( array $values = array() ): array {
		$settings = new Settings( $values + array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() );
		$target   = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$home     = (string) get_option( 'home' );
		$urls     = Urls::fromHome( $home, 'wp-json' );
		$byLang   = new LanguageUrls( $urls, $target, LanguageUrls::origin( $home ) );
		$modes    = new Resolver( $settings, $urls, $target );
		( new Router( $settings, $target, $urls ) )->boot();

		return array(
			new OffPages( $settings, $modes, $byLang ),
			new HeadTags( $settings, $settings->defaultLanguage(), $target, $byLang, $modes ),
			new Switcher( $settings->defaultLanguage(), $target, $byLang, $modes ),
		);
	}

	public function test_target_url_of_an_off_page_redirects_to_the_original_with_302(): void {
		[ $off ] = $this->boot();
		$this->go_to( '/bn/private-offer/?ref=x' );
		$seen = array();
		add_filter(
			'wp_redirect',
			static function ( $location, $status ) use ( &$seen ) {
				$seen = array( $location, $status );
				throw new \RuntimeException( 'redirected' );
			},
			10,
			2
		);

		try {
			$off->handle();
			$this->fail( 'Expected a redirect.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}
		$this->assertSame( array( 'http://example.org/private-offer/?ref=x', 302 ), $seen );
	}

	public function test_original_behaviour_keeps_the_url_and_points_canonical_to_the_original(): void {
		[ $off ] = $this->boot( array( 'off_behavior' => Settings::OFF_ORIGINAL ) );
		$this->go_to( '/bn/private-offer/' );
		add_filter(
			'wp_redirect',
			static function () {
				throw new \RuntimeException( 'Must not redirect.' );
			}
		);

		$off->handle();

		$this->assertSame( 'http://example.org/private-offer/', wp_get_canonical_url( $this->postId ) );
	}

	public function test_other_pages_and_default_language_requests_are_left_alone(): void {
		[ $off ] = $this->boot();
		add_filter(
			'wp_redirect',
			static function () {
				throw new \RuntimeException( 'Must not redirect.' );
			}
		);

		$this->go_to( '/bn/public-page/' );
		$off->handle();
		$this->go_to( '/private-offer/' );
		$off->handle();

		$this->assertTrue( true, 'No redirect.' );
	}

	public function test_off_pages_have_no_alternates_and_no_switcher(): void {
		[ , $head, $switcher ] = $this->boot();

		$this->go_to( '/private-offer/' );
		$this->assertNull( $head->alternates() );
		$this->assertSame( '', $switcher->render() );

		[ , $head, $switcher ] = $this->boot();
		$this->go_to( '/public-page/' );
		$this->assertArrayHasKey( 'bn-BD', $head->alternates() ?? array() );
		$this->assertStringContainsString( 'http://example.org/bn/public-page/', $switcher->render() );
	}
}

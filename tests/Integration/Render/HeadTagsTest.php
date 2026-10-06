<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Render;

use WP_UnitTestCase;
use WST\Languages\Current;
use WST\Languages\Registry;
use WST\Render\HeadTags;
use WST\Routing\LanguageUrls;
use WST\Routing\Router;
use WST\Routing\Urls;
use WST\Settings;
use WST\Switcher\Switcher;

final class HeadTagsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		self::factory()->post->create( array( 'post_name' => 'hello' ) );
	}

	public function tear_down(): void {
		Current::reset();
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $values Settings.
	 * @return array{0: HeadTags, 1: Switcher}
	 */
	private function boot( array $values = array() ): array {
		$settings = new Settings( array( 'target_language' => 'bn_BD' ) + $values, 'en_US', new Registry() );
		$target   = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$urls     = Urls::fromHome( 'http://example.org', 'wp-json' );
		$byLang   = new LanguageUrls( $urls, $target, 'http://example.org' );
		( new Router( $settings, $target, $urls ) )->boot();
		$head = new HeadTags( $settings, $settings->defaultLanguage(), $target, $byLang );
		$head->boot();
		$switcher = new Switcher( $settings->defaultLanguage(), $target, $byLang );
		$switcher->boot();

		return array( $head, $switcher );
	}

	public function test_alternates_on_a_target_page(): void {
		[ $head ] = $this->boot();
		$this->go_to( '/bn/hello/' );

		$this->assertSame(
			array(
				'en-US'     => 'http://example.org/hello/',
				'bn-BD'     => 'http://example.org/bn/hello/',
				'x-default' => 'http://example.org/hello/',
			),
			$head->alternates()
		);
		$html = get_echo( 'wp_head' );
		$this->assertStringContainsString( '<link rel="alternate" hreflang="bn-BD" href="http://example.org/bn/hello/" />', $html );
		$this->assertStringContainsString( '<link rel="canonical" href="http://example.org/bn/hello/" />', $html );
	}

	public function test_alternates_on_a_default_page_drop_query_and_region(): void {
		[ $head ] = $this->boot(
			array(
				'hreflang_drop_region' => true,
				'hreflang_x_default'   => false,
			)
		);
		$this->go_to( '/hello/?utm=1' );

		$this->assertSame(
			array(
				'en' => 'http://example.org/hello/',
				'bn' => 'http://example.org/bn/hello/',
			),
			$head->alternates()
		);
	}

	public function test_no_alternates_on_search_and_404(): void {
		[ $head ] = $this->boot();
		$this->go_to( '/bn/?s=soap' );
		$this->assertNull( $head->alternates() );

		$this->go_to( '/bn/missing-page/' );
		$this->assertNull( $head->alternates() );
	}

	public function test_drop_region_applies_to_the_lang_attribute(): void {
		$this->boot( array( 'hreflang_drop_region' => true ) );
		$this->go_to( '/bn/hello/' );

		$this->assertStringContainsString( 'lang="bn"', get_echo( 'language_attributes' ) );
	}

	public function test_switcher_links_to_the_same_page_in_both_languages(): void {
		[ , $switcher ] = $this->boot();
		$this->go_to( '/bn/hello/?ref=menu' );

		$html = do_shortcode( '[wst_switcher]' );

		$this->assertStringContainsString( 'data-wst-no-translate', $html );
		$this->assertStringContainsString( '<a href="http://example.org/hello/?ref=menu" hreflang="en-US" lang="en-US">English</a>', $html );
		$this->assertStringContainsString( '<a href="http://example.org/bn/hello/?ref=menu" hreflang="bn-BD" lang="bn-BD" aria-current="true">বাংলা</a>', $html );
		$this->assertSame( $html, $switcher->render() );
	}

	public function test_switcher_marks_default_language_on_default_pages(): void {
		[ , $switcher ] = $this->boot();
		$this->go_to( '/hello/' );

		$this->assertStringContainsString( 'lang="en-US" aria-current="true">English</a>', $switcher->render() );
	}
}

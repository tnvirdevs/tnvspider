<?php
/**
 * Plan §13A.7 / §16 Phase 6e: the suggestion data is the same for every
 * visitor and only present where a translated equivalent exists. The
 * browser-side decision table is tests/js/suggest.test.mjs.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Switcher;

use WP_UnitTestCase;
use WST\Languages\Current;
use WST\Languages\Registry;
use WST\Modes\Resolver;
use WST\Routing\LanguageUrls;
use WST\Routing\Router;
use WST\Routing\Urls;
use WST\Settings;
use WST\Switcher\Suggestion;

final class SuggestionTest extends WP_UnitTestCase {

	private int $postId = 0;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->postId = self::factory()->post->create( array( 'post_name' => 'hello' ) );
	}

	public function tear_down(): void {
		Current::reset();
		parent::tear_down();
	}

	/**
	 * Suggestion for a request.
	 *
	 * @param string               $uri    Request URI.
	 * @param array<string, mixed> $values Settings.
	 */
	private function suggestion( string $uri, array $values = array( 'lang_suggestion' => 'bar' ) ): Suggestion {
		$settings               = new Settings( $values + array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() );
		$target                 = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$urls                   = Urls::fromHome( 'http://example.org', 'wp-json' );
		$_SERVER['REQUEST_URI'] = $uri;
		( new Router( $settings, $target, $urls ) )->boot();
		$this->go_to( 'http://example.org' . $uri );

		return new Suggestion( $settings, $settings->defaultLanguage(), $target, new LanguageUrls( $urls, $target, 'http://example.org' ), new Resolver( $settings, $urls, $target ), dirname( __DIR__, 3 ) . '/wp-site-translator.php' );
	}

	public function test_default_language_page_suggests_the_translation(): void {
		$data = $this->suggestion( '/hello/?ref=mail' )->data();

		$this->assertSame(
			array(
				'mode'       => 'bar',
				'position'   => 'bottom',
				'current'    => 'en',
				'currentTag' => 'en-US',
				'close'      => 'Close',
				'other'      => array(
					'lang'   => 'bn',
					'tag'    => 'bn-BD',
					'url'    => 'http://example.org/bn/hello/?ref=mail',
					'text'   => 'বাংলা →',
					'region' => 'বাংলা',
				),
			),
			$data
		);
	}

	public function test_translated_page_suggests_the_default_language_with_its_text(): void {
		$data = $this->suggestion(
			'/bn/hello/',
			array(
				'lang_suggestion'         => 'redirect',
				'suggestion_position'     => 'top',
				'suggestion_text_default' => 'Read this page in English',
				'suggestion_text_target'  => 'এই পাতাটি বাংলায় পড়ুন',
			)
		)->data();

		$this->assertSame( array( 'redirect', 'top', 'bn' ), array( $data['mode'] ?? '', $data['position'] ?? '', $data['current'] ?? '' ) );
		$this->assertSame( array( 'en', 'http://example.org/hello/', 'Read this page in English' ), array( $data['other']['lang'], $data['other']['url'], $data['other']['text'] ) );
	}

	public function test_nothing_when_off_on_off_pages_404s_and_feeds(): void {
		$this->assertNull( $this->suggestion( '/hello/', array() )->data(), 'Off by default.' );

		update_post_meta( $this->postId, Resolver::META_KEY, 'off' );
		$this->assertNull( $this->suggestion( '/hello/' )->data(), 'No translated equivalent: no suggestion and never a redirect to it.' );

		$this->assertNull( $this->suggestion( '/no-such-page/' )->data() );
		$this->assertNull( $this->suggestion( '/feed/' )->data() );
	}

	public function test_script_and_data_are_enqueued_identically_for_everyone(): void {
		$suggestion = $this->suggestion( '/hello/' );
		wp_set_current_user( 0 );
		$suggestion->enqueue();
		$first = wp_scripts()->get_data( Suggestion::HANDLE, 'before' );
		wp_deregister_script( Suggestion::HANDLE );
		$_COOKIE['wst_lang_choice']      = 'bn';
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'bn-BD,bn;q=0.9';
		$suggestion->enqueue();

		$this->assertTrue( wp_script_is( Suggestion::HANDLE, 'enqueued' ) );
		$this->assertSame( $first, wp_scripts()->get_data( Suggestion::HANDLE, 'before' ), 'The server never varies the page by cookie or browser language.' );
		unset( $_COOKIE['wst_lang_choice'], $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
	}

	public function test_bar_texts_are_plain_and_short(): void {
		$settings = new Settings(
			array(
				'suggestion_text_target'  => '<b>বাংলা</b> ' . str_repeat( 'ক', 300 ),
				'suggestion_text_default' => array( 'not text' ),
			),
			'en_US',
			new Registry()
		);

		$this->assertSame( Settings::SUGGESTION_TEXT_MAX, mb_strlen( $settings->suggestionText( 'target' ) ) );
		$this->assertStringStartsWith( 'বাংলা ক', $settings->suggestionText( 'target' ), 'Tags are removed.' );
		$this->assertSame( '', $settings->suggestionText( 'default' ) );
	}
}

<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Editor;

use WP_UnitTestCase;
use WST\Editor\Preview;

// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Page fixtures, not output.
final class PreviewTest extends WP_UnitTestCase {

	private const PAGE = '<html><head><script src="/wp-content/plugins/hostile/break.js"></script><script type="application/ld+json">{"@type":"Thing"}</script>'
		. '<script type="module">import x from "/m.js";</script><!-- <script>kept()</script> --></head>'
		. '<body onload="boom()"><p class="a" onclick="steal()">Hello</p><img src="/x.png" onerror="boom()" alt="x"><SCRIPT>jQuery = undefined; throw new Error("hostile");</SCRIPT><p>World</p></body></html>';

	public function test_safe_mode_removes_executable_scripts_and_handlers_only(): void {
		$html = ( new Preview( 'http://example.org/wp-content/plugins/wp-site-translator/assets/preview.js' ) )->prepare( self::PAGE, false );

		$this->assertStringNotContainsString( 'hostile', $html );
		$this->assertStringNotContainsString( 'import x', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'onload', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringContainsString( '<script type="application/ld+json">{"@type":"Thing"}</script>', $html, 'Data scripts never run and stay.' );
		$this->assertStringContainsString( '<!-- <script>kept()</script> -->', $html, 'Comments are not scripts.' );
		$this->assertStringContainsString( '<p class="a" >Hello</p>', $html );
		$this->assertStringContainsString( '<script src="http://example.org/wp-content/plugins/wp-site-translator/assets/preview.js?ver=', $html );
		$this->assertSame( 1, substr_count( $html, 'id="wst-preview-js"' ) );
		$this->assertMatchesRegularExpression( '#id="wst-preview-js" data-wst-no-translate></script></body></html>$#', $html, 'Ours goes before </body>.' );
	}

	public function test_run_page_scripts_keeps_everything_and_adds_ours(): void {
		$html = ( new Preview( 'http://example.org/p.js' ) )->prepare( self::PAGE, true );

		$this->assertSame( self::PAGE, str_replace( '<script src="http://example.org/p.js?ver=' . \WST\Config::VERSION . '" id="wst-preview-js" data-wst-no-translate></script>', '', $html ) );
	}

	public function test_document_without_body_gets_the_script_at_the_end(): void {
		$this->assertStringEndsWith( '</script>', ( new Preview( 'http://example.org/p.js' ) )->prepare( '<p>Fragment</p>', false ) );
	}
}

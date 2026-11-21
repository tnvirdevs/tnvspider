<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Html;

use WP_UnitTestCase;
use WST\Html\Lexer;

/**
 * Pins the one HTML API internal we rely on: token spans read via bookmarks.
 */
final class LexerTest extends WP_UnitTestCase {

	public function test_token_spans_cover_exact_source_bytes(): void {
		$html  = "<p class=\"a\">Hi &amp; bye<!-- c --><br/></p>\n<script>x<y</script>";
		$lexer = new Lexer( $html );
		$seen  = array();
		while ( $lexer->next_token() ) {
			[ $start, $length ] = $lexer->tokenSpan();
			$seen[]             = substr( $html, $start, $length );
		}

		$this->assertSame(
			array( '<p class="a">', 'Hi &amp; bye', '<!-- c -->', '<br/>', '</p>', "\n", '<script>x<y</script>' ),
			$seen
		);
	}

	public function test_token_span_throws_when_not_on_a_token(): void {
		$this->expectException( \LogicException::class );
		( new Lexer( '<p>' ) )->tokenSpan();
	}
}

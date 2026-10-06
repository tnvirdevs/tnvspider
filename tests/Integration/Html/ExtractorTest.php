<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Html;

use WP_UnitTestCase;
use WST\Html\Extractor;
use WST\Html\Segment;

final class ExtractorTest extends WP_UnitTestCase {

	/**
	 * @return list<array{0: string, 1: string}> [kind, text] pairs.
	 */
	private function extract( string $html ): array {
		return array_map(
			static fn( Segment $s ): array => array( $s->kind, $s->text ),
			( new Extractor() )->extract( $html )
		);
	}

	public function test_text_nodes_are_decoded_and_normalised(): void {
		$this->assertSame(
			array( array( 'text', 'Fish & chips' ), array( 'text', 'Second line' ) ),
			$this->extract( "<div>\n  Fish &amp;\n chips </div><div>Second line</div>" )
		);
	}

	public function test_mixed_inline_content_becomes_one_inline_segment(): void {
		$this->assertSame(
			array( array( 'inline', 'Read <strong><a href="/x">our story</a></strong> today' ) ),
			$this->extract( '<p>Read <strong><a href="/x">our story</a></strong> today</p>' )
		);
	}

	public function test_block_children_keep_text_nodes_separate(): void {
		$this->assertSame(
			array( array( 'text', 'Intro' ), array( 'text', 'Body' ) ),
			$this->extract( '<div>Intro<p>Body</p></div>' )
		);
	}

	public function test_implied_end_tags_close_paragraphs_and_list_items(): void {
		$this->assertSame(
			array( array( 'inline', 'One <b>a</b>' ), array( 'text', 'Two' ), array( 'text', 'Para' ), array( 'text', 'Block' ) ),
			$this->extract( '<ul><li>One <b>a</b><li>Two</ul><p>Para<div>Block</div>' )
		);
	}

	public function test_excluded_content_is_skipped(): void {
		$html = '<script>var t = "Hello";</script><style>a{}</style><noscript>Enable JS</noscript>'
			. '<pre>code</pre><code>x()</code><template><p>Tpl</p></template><textarea>Typed</textarea>'
			. '<svg><title>Icon</title><text>Label</text></svg><!-- Comment -->'
			. '<div translate="no">A</div><div class="x notranslate">B</div>'
			. '<div data-wst-no-translate>C</div><div data-no-translation>D <span>E</span></div><p>Kept</p>';

		$this->assertSame( array( array( 'text', 'Kept' ) ), $this->extract( $html ) );
	}

	public function test_self_closing_svg_children_do_not_swallow_the_document(): void {
		$this->assertSame(
			array( array( 'text', 'After' ) ),
			$this->extract( '<svg><style/><path d="M0"/></svg><p>After</p>' )
		);
	}

	public function test_attributes_title_and_meta_are_extracted(): void {
		$html = '<head><title>Shop &amp; more</title><meta name="description" content="Natural soap">'
			. '<meta property="og:title" content="Our shop"><meta name="viewport" content="width=device-width"></head>'
			. '<img src="a.jpg" alt="A cat"><input type="submit" value="Send"><input type="text" value="Typed" placeholder="Your name">'
			. '<a href="#" aria-label="Close" title="https://example.com">×</a>';

		$this->assertSame(
			array(
				array( 'title', 'Shop & more' ),
				array( 'meta', 'Natural soap' ),
				array( 'meta', 'Our shop' ),
				array( 'attr', 'A cat' ),
				array( 'attr', 'Send' ),
				array( 'attr', 'Your name' ),
				array( 'attr', 'Close' ),
			),
			$this->extract( $html )
		);
	}

	public function test_attribute_inside_inline_segment_is_linked_to_it(): void {
		$segments = ( new Extractor() )->extract( '<p>Go <a href="/" title="Home page">home</a></p>' );

		$this->assertCount( 2, $segments );
		$this->assertSame( Segment::INLINE, $segments[0]->kind );
		$this->assertSame( $segments[0], $segments[1]->parent );
	}

	public function test_inline_with_non_inline_child_is_not_merged(): void {
		$this->assertSame(
			array( array( 'text', 'Photo' ), array( 'attr', 'Cat' ), array( 'text', 'caption' ) ),
			$this->extract( '<p>Photo <img alt="Cat" src="c.jpg"> <em>caption</em></p>' )
		);
	}

	public function test_offsets_point_at_the_original_bytes(): void {
		$html     = "<p>  Hello\n world  </p>";
		$segments = ( new Extractor() )->extract( $html );

		$this->assertSame( "Hello\n world", substr( $html, $segments[0]->start, $segments[0]->length ) );
		$this->assertSame( '  ', $segments[0]->prefix );
		$this->assertSame( '  ', $segments[0]->suffix );
	}

	public function test_unclosed_script_leaves_the_rest_untranslated(): void {
		$this->assertSame(
			array( array( 'text', 'Before' ) ),
			$this->extract( '<p>Before</p><script>never closed <p>Hidden</p>' )
		);
	}
}

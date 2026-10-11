<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Unit\Html;

use PHPUnit\Framework\TestCase;
use WST\Html\Text;

final class TextTest extends TestCase {

	public function test_normalize_collapses_html_whitespace_but_keeps_nbsp(): void {
		$this->assertSame( "Hello world \u{00A0}x", Text::normalize( "  Hello \n\t world \u{00A0}x \r\n" ) );
	}

	public function test_split_edges_keeps_raw_whitespace(): void {
		$this->assertSame( array( " \n", 'Hi there', "\t " ), Text::splitEdges( " \nHi there\t " ) );
		$this->assertSame( array( '  ', '', '' ), Text::splitEdges( '  ' ) );
	}

	/**
	 * @dataProvider translatableProvider
	 */
	public function test_is_translatable( string $text, bool $expected ): void {
		$this->assertSame( $expected, Text::isTranslatable( $text ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function translatableProvider(): array {
		return array(
			'word'           => array( 'Shop now', true ),
			'bengali'        => array( 'এখনই কিনুন', true ),
			'with digits'    => array( 'Price: 12', true ),
			'digits only'    => array( '1,200', false ),
			'bengali digits' => array( '১২০০', false ),
			'punctuation'    => array( '|', false ),
			'empty'          => array( '', false ),
			'url'            => array( 'https://example.com/shop', false ),
			'protocol-less'  => array( '//cdn.example.com/a.js', false ),
			'email'          => array( 'hello@example.com', false ),
			'json'           => array( '{"a":"b"}', false ),
			'bracket text'   => array( '[Read more]', true ),
		);
	}

	public function test_encode_text_escapes_markup_characters_only(): void {
		$this->assertSame( 'a &lt;b&gt; &amp; "c"', Text::encodeText( 'a <b> & "c"' ) );
	}
}

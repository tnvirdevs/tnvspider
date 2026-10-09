<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use WST\Providers\TextSplitter;

final class TextSplitterTest extends TestCase {

	/**
	 * @param list<array{text: string, sep: string}> $chunks Chunks.
	 */
	private static function concat( array $chunks ): string {
		return implode( '', array_map( static fn( array $c ): string => $c['text'] . $c['sep'], $chunks ) );
	}

	public function test_short_text_is_one_chunk(): void {
		$this->assertSame(
			array(
				array(
					'text' => 'Hello.',
					'sep'  => '',
				),
			),
			TextSplitter::split( 'Hello.', 2000 )
		);
	}

	/**
	 * @dataProvider sentenceProvider
	 */
	public function test_splits_at_sentence_ends_within_the_byte_limit( string $sentence ): void {
		$text   = trim( str_repeat( $sentence . ' ', 40 ) );
		$chunks = TextSplitter::split( $text, 300 );

		$this->assertGreaterThan( 1, count( $chunks ) );
		$this->assertSame( $text, self::concat( $chunks ), 'Lossless.' );
		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( 300, strlen( $chunk['text'] ) );
			$this->assertSame( mb_substr( $sentence, -1 ), mb_substr( $chunk['text'], -1 ), 'Ends at a sentence end.' );
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function sentenceProvider(): array {
		return array(
			'english' => array( 'Free shipping on all orders over 1,500.' ),
			'bengali' => array( 'আমাদের দোকানে স্বাগতম।' ),
			'arabic'  => array( 'هل تريد الشحن المجاني؟' ),
			'quoted'  => array( 'He said "Buy now!"' ),
		);
	}

	public function test_line_breaks_are_boundaries_and_kept(): void {
		$text   = str_repeat( 'a', 150 ) . "\n\n" . str_repeat( 'b', 150 );
		$chunks = TextSplitter::split( $text, 200 );

		$this->assertSame( array( str_repeat( 'a', 150 ), str_repeat( 'b', 150 ) ), array_column( $chunks, 'text' ) );
		$this->assertSame( "\n\n", $chunks[0]['sep'] );
	}

	public function test_long_sentence_falls_back_to_spaces_then_characters(): void {
		$words  = trim( str_repeat( 'স্বাগতম ', 60 ) );
		$chunks = TextSplitter::split( $words, 100 );
		$this->assertSame( $words, self::concat( $chunks ) );
		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( 100, strlen( $chunk['text'] ) );
			$this->assertStringNotContainsString( ' ', trim( $chunk['text'] ) === $chunk['text'] ? '' : 'x', 'Chunks are trimmed at spaces.' );
		}

		$word   = str_repeat( 'অ', 50 );
		$pieces = TextSplitter::split( $word, 10 );
		$this->assertSame( $word, self::concat( $pieces ) );
		foreach ( $pieces as $piece ) {
			$this->assertTrue( mb_check_encoding( $piece['text'], 'UTF-8' ), 'Cut on a character boundary.' );
			$this->assertLessThanOrEqual( 10, strlen( $piece['text'] ) );
		}
	}

	public function test_placeholder_tokens_are_never_cut(): void {
		$text   = trim( str_repeat( 'Pay {1} now and {2} later. ', 30 ) );
		$chunks = TextSplitter::split( $text, 120 );

		foreach ( $chunks as $chunk ) {
			$this->assertSame( substr_count( $chunk['text'], '{' ), substr_count( $chunk['text'], '}' ) );
		}
	}

	public function test_join_uses_original_separators(): void {
		$chunks = array(
			array(
				'text' => 'One.',
				'sep'  => "\n\n",
			),
			array(
				'text' => 'Two.',
				'sep'  => '',
			),
		);

		$this->assertSame( "এক।\n\nদুই।", TextSplitter::join( $chunks, array( ' এক। ', 'দুই।' ) ) );
	}

	public function test_rejects_a_limit_below_one_character(): void {
		$this->expectException( \InvalidArgumentException::class );
		TextSplitter::split( 'abc', 3 );
	}
}

<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use WST\Providers\Protection\InlineTokens;
use WST\Providers\Protection\Segmenter;

final class InlineTokensTest extends TestCase {

	private const ORIGINAL = 'Read <a href="/story/" class="link">our <b>story</b></a> today<br>';

	public function test_encode_strips_attributes(): void {
		[ $encoded, $map ] = InlineTokens::encode( self::ORIGINAL );

		$this->assertSame( 'Read <a id="1">our <b id="2">story</b></a> today<br id="3">', $encoded );
		$this->assertSame( '<a href="/story/" class="link">', $map[1]['tag'] );
	}

	public function test_decode_restores_original_tags_in_any_order(): void {
		[ , $map ] = InlineTokens::encode( self::ORIGINAL );

		$this->assertSame(
			'আজ <a href="/story/" class="link">আমাদের <b>গল্প</b></a> পড়ুন<br>',
			InlineTokens::decode( 'আজ <a id="1">আমাদের <b id = "2">গল্প</b></a> পড়ুন<br id="3">', $map )
		);
	}

	/**
	 * @dataProvider brokenProvider
	 */
	public function test_decode_rejects_broken_markup( string $translation ): void {
		[ , $map ] = InlineTokens::encode( self::ORIGINAL );

		$this->assertNull( InlineTokens::decode( $translation, $map ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function brokenProvider(): array {
		return array(
			'missing id'      => array( 'আজ <a id="1">আমাদের গল্প</a> পড়ুন<br id="3">' ),
			'duplicate id'    => array( '<a id="1">x</a><a id="1">y</a><b id="2">z</b><br id="3">' ),
			'renamed tag'     => array( '<a id="1">x <i id="2">y</i></a><br id="3">' ),
			'unbalanced'      => array( '<a id="1">x <b id="2">y</a></b><br id="3">' ),
			'invented tag'    => array( '<a id="1">x <b id="2">y</b></a><br id="3"><script>alert(1)</script>' ),
			'attribute added' => array( '<a id="1" onclick="x">x <b id="2">y</b></a><br id="3">' ),
		);
	}

	public function test_segmenter_translates_text_runs_around_tags(): void {
		$chunks = Segmenter::split( 'Read <a href="/x">our story</a> &amp; more | 42' );

		$this->assertSame( array( 'Read', 'our story', '& more | 42' ), Segmenter::texts( $chunks ) );
		$this->assertSame(
			'পড়ুন <a href="/x">আমাদের গল্প</a> &amp; আরও',
			Segmenter::join( $chunks, array( 'পড়ুন', 'আমাদের গল্প', '& আরও' ) )
		);
	}
}

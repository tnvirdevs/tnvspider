<?php
/**
 * Phase 0 acceptance test for offset-based replacement.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Html;

use WP_HTML_Tag_Processor;
use WP_UnitTestCase;
use WST\Html\Extractor;
use WST\Html\Replacer;
use WST\Html\Segment;

/**
 * For saved real pages and the html5lib inputs:
 * - an empty translation map returns the input byte for byte;
 * - a map that wraps every string in markers keeps the tag structure, and
 *   extracting the output again yields exactly the wrapped strings.
 */
final class RoundTripTest extends WP_UnitTestCase {

	private const OPEN  = '⟦';
	private const CLOSE = '⟧';

	/**
	 * @dataProvider pageProvider
	 */
	public function test_saved_pages_round_trip( string $file ): void {
		$this->assertRoundTrip( (string) file_get_contents( $file ), true );
	}

	/**
	 * @dataProvider html5libProvider
	 */
	public function test_html5lib_inputs_round_trip( string $html ): void {
		if ( ! is_dir( dirname( __DIR__, 2 ) . '/fixtures/html5lib-tests' ) ) {
			$this->markTestSkipped( 'Run bin/fetch-html5lib-tests.sh to fetch the html5lib tokenizer tests.' );
		}
		$this->assertRoundTrip( $html, false );
		$this->assertRoundTrip( '<p>' . $html . '</p>', false );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function pageProvider(): array {
		$cases = array();
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/fixtures/pages/*.html' ) as $file ) {
			$cases[ basename( (string) $file ) ] = array( (string) $file );
		}

		return $cases;
	}

	/**
	 * Every input of the html5lib tokenizer tests, fetched by
	 * bin/fetch-html5lib-tests.sh. Double-escaped inputs (lone surrogates)
	 * are not valid UTF-8 documents and are left out.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function html5libProvider(): array {
		$dir = dirname( __DIR__, 2 ) . '/fixtures/html5lib-tests/tokenizer';
		if ( ! is_dir( $dir ) ) {
			return array( 'missing' => array( '' ) );
		}

		$cases = array();
		foreach ( (array) glob( $dir . '/*.test' ) as $file ) {
			$data = json_decode( (string) file_get_contents( (string) $file ), true );
			foreach ( $data['tests'] ?? array() as $i => $test ) {
				if ( ! empty( $test['doubleEscaped'] ) ) {
					continue;
				}
				$cases[ basename( (string) $file ) . '#' . $i ] = array( (string) $test['input'] );
			}
		}

		return $cases;
	}

	private function assertRoundTrip( string $html, bool $requireSegments ): void {
		$extractor = new Extractor();
		$replacer  = new Replacer();
		$segments  = $extractor->extract( $html );

		if ( $requireSegments ) {
			$this->assertNotEmpty( $segments, 'A real page must yield translatable strings.' );
		}

		$this->assertSame( $html, $replacer->apply( $html, $segments, array() ), 'Empty map must not change a byte.' );

		$map      = array();
		$expected = array();
		foreach ( $segments as $segment ) {
			$map[ $segment->text ] = self::OPEN . $segment->text . self::CLOSE;
		}
		foreach ( $segments as $segment ) {
			// Children of a replaced inline segment come back unchanged inside it.
			$expected[] = array( $segment->kind, null === $segment->parent ? $map[ $segment->text ] : $segment->text );
		}

		$output = $replacer->apply( $html, $segments, $map );

		$this->assertSame( $this->tagSequence( $html ), $this->tagSequence( $output ), 'Tag structure changed.' );

		$again = array_map(
			static fn( Segment $s ): array => array( $s->kind, $s->text ),
			$extractor->extract( $output )
		);
		$this->assertSame( $expected, $again, 'Re-extracting the output must yield exactly the wrapped strings.' );
	}

	/**
	 * @return list<string>
	 */
	private function tagSequence( string $html ): array {
		$processor = new WP_HTML_Tag_Processor( $html );
		$tags      = array();
		while ( $processor->next_token() ) {
			if ( '#tag' === $processor->get_token_type() ) {
				$tags[] = ( $processor->is_tag_closer() ? '/' : '' ) . $processor->get_tag();
			}
		}

		return $tags;
	}
}

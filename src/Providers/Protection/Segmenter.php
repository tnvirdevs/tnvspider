<?php
/**
 * Splits inline HTML into text runs for providers without HTML support.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers\Protection;

use WST\Html\Text;

/**
 * Tags never reach a plain-text provider: each text run between tags is
 * translated on its own and the runs are joined back around the original
 * tags. Word order across tags cannot change, so such translations carry
 * flag 1 (segmented).
 */
final class Segmenter {

	/**
	 * Split a fragment into tags and text runs.
	 *
	 * @param string $html Inline HTML.
	 * @return list<array{0: bool, 1: string}> [isTranslatableText, chunk] in order.
	 */
	public static function split( string $html ): array {
		$parts  = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$parts  = false === $parts ? array( $html ) : $parts;
		$chunks = array();
		foreach ( $parts as $i => $part ) {
			if ( '' === $part ) {
				continue;
			}
			$isText   = 0 === $i % 2;
			$chunks[] = array( $isText && Text::isTranslatable( Text::normalize( html_entity_decode( $part, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ), $part );
		}

		return $chunks;
	}

	/**
	 * Join chunks back, replacing translatable runs in order. Leading and
	 * trailing whitespace of each run is kept from the original.
	 *
	 * @param list<array{0: bool, 1: string}> $chunks       From split().
	 * @param string[]                        $translations One plain-text translation per translatable run.
	 */
	public static function join( array $chunks, array $translations ): string {
		$out = '';
		$i   = 0;
		foreach ( $chunks as [ $translatable, $chunk ] ) {
			if ( ! $translatable ) {
				$out .= $chunk;
				continue;
			}
			[ $before, , $after ] = Text::splitEdges( $chunk );
			$out                 .= $before . Text::encodeText( $translations[ $i++ ] ?? '' ) . $after;
		}

		return $out;
	}

	/**
	 * Plain text of each translatable run, decoded and normalised.
	 *
	 * @param list<array{0: bool, 1: string}> $chunks From split().
	 * @return list<string>
	 */
	public static function texts( array $chunks ): array {
		$texts = array();
		foreach ( $chunks as [ $translatable, $chunk ] ) {
			if ( $translatable ) {
				$texts[] = Text::normalize( html_entity_decode( $chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}
		}

		return $texts;
	}
}

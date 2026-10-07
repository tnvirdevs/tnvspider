<?php
/**
 * Splits long plain text for providers with a per-text size limit.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

/**
 * Cuts a text into chunks of at most $maxBytes UTF-8 bytes, preferring
 * sentence ends and line breaks, then spaces, and only as a last resort the
 * middle of a word (on a character boundary). Each chunk keeps the
 * whitespace that followed it, so the chunks concatenate back to the input
 * exactly, and translated chunks join with the original separators.
 */
final class TextSplitter {

	/** Sentence ends in the scripts we serve (Latin, Bengali/Devanagari danda, Arabic, CJK), or a line break. */
	private const SENTENCE_BREAK = '/((?:(?<=[.!?…।॥؟。！？])|(?<=[.!?…।॥؟。！？]["\'”’)\]]))\s+|\s*\n\s*)/u';

	/**
	 * Split a text.
	 *
	 * @param string $text     Plain text.
	 * @param int    $maxBytes Largest chunk in bytes (at least 4).
	 * @return list<array{text: string, sep: string}> Chunks in order; text plus sep of all chunks equals $text.
	 * @throws \InvalidArgumentException When $maxBytes is below 4 (one UTF-8 character).
	 */
	public static function split( string $text, int $maxBytes ): array {
		if ( $maxBytes < 4 ) {
			throw new \InvalidArgumentException( 'maxBytes must be at least 4.' );
		}
		if ( strlen( $text ) <= $maxBytes ) {
			return array(
				array(
					'text' => $text,
					'sep'  => '',
				),
			);
		}

		$chunks = array();
		foreach ( self::pieces( $text, self::SENTENCE_BREAK ) as $sentence ) {
			if ( strlen( $sentence['text'] ) <= $maxBytes ) {
				$chunks[] = $sentence;
				continue;
			}
			foreach ( self::pieces( $sentence['text'], '/(\s+)/u' ) as $word ) {
				if ( strlen( $word['text'] ) <= $maxBytes ) {
					$chunks[] = $word;
					continue;
				}
				$rest = $word['text'];
				do {
					$cut      = mb_strcut( $rest, 0, $maxBytes, 'UTF-8' );
					$rest     = substr( $rest, strlen( $cut ) );
					$left     = strlen( $rest );
					$chunks[] = array(
						'text' => $cut,
						'sep'  => '',
					);
				} while ( $left > $maxBytes );
				$chunks[] = array(
					'text' => $rest,
					'sep'  => $word['sep'],
				);
			}
			$end = array_pop( $chunks );
			if ( null !== $end ) {
				$chunks[] = array(
					'text' => $end['text'],
					'sep'  => $end['sep'] . $sentence['sep'],
				);
			}
		}

		return self::pack( $chunks, $maxBytes );
	}

	/**
	 * Join translated chunks with the original separators.
	 *
	 * @param array<int, array{text: string, sep: string}> $chunks       From split().
	 * @param string[]                                     $translations One per chunk, in order.
	 * @phpstan-param list<array{text: string, sep: string}> $chunks
	 * @phpstan-param list<string> $translations
	 */
	public static function join( array $chunks, array $translations ): string {
		$out = '';
		foreach ( $chunks as $i => $chunk ) {
			$out .= trim( $translations[ $i ] ?? '' ) . $chunk['sep'];
		}

		return $out;
	}

	/**
	 * Split at a separator pattern with one capturing group.
	 *
	 * @param string $text    Text.
	 * @param string $pattern Separator regex.
	 * @return list<array{text: string, sep: string}>
	 * @throws \RuntimeException When the text is not valid UTF-8.
	 */
	private static function pieces( string $text, string $pattern ): array {
		$parts = preg_split( $pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			throw new \RuntimeException( 'Could not split the text (invalid UTF-8?).' );
		}
		$pieces = array();
		$count  = count( $parts );
		for ( $i = 0; $i < $count; $i += 2 ) {
			$body = $parts[ $i ];
			$sep  = $parts[ $i + 1 ] ?? '';
			$end  = '' === $body ? array_pop( $pieces ) : null;
			if ( null !== $end ) {
				$pieces[] = array(
					'text' => $end['text'],
					'sep'  => $end['sep'] . $sep,
				);
				continue;
			}
			$pieces[] = array(
				'text' => $body,
				'sep'  => $sep,
			);
		}

		return $pieces;
	}

	/**
	 * Merge neighbouring chunks while they fit, so few requests are used.
	 *
	 * @param list<array{text: string, sep: string}> $chunks   Chunks, each within the limit.
	 * @param int                                    $maxBytes Limit.
	 * @return list<array{text: string, sep: string}>
	 */
	private static function pack( array $chunks, int $maxBytes ): array {
		$packed = array();
		$open   = null;
		foreach ( $chunks as $chunk ) {
			if ( null !== $open && strlen( $open['text'] . $open['sep'] . $chunk['text'] ) <= $maxBytes ) {
				$open = array(
					'text' => $open['text'] . $open['sep'] . $chunk['text'],
					'sep'  => $chunk['sep'],
				);
				continue;
			}
			if ( null !== $open ) {
				$packed[] = $open;
			}
			$open = $chunk;
		}
		if ( null !== $open ) {
			$packed[] = $open;
		}

		return $packed;
	}
}

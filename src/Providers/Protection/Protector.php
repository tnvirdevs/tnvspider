<?php
/**
 * Shared placeholder protection for every provider (plan §7, §13A.1).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers\Protection;

use WST\Html\Text;

/**
 * Swaps parts that must survive translation unchanged for opaque tokens:
 * URLs, e-mail addresses, printf placeholders, {{…}} variables, shortcode
 * remnants, text that already looks like a token, and never-translate terms.
 */
final class Protector {

	/** Default token format; the provider adapters confirm it survives translation. */
	public const DEFAULT_FORMAT = '[[%d]]';

	private const PATTERNS = array(
		'https?:\/\/[^\s<>"\']+|www\.[^\s<>"\']+',
		'[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}',
		'%(?:\d+\$)?[-+ 0#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]',
		'\{\{[^{}]+\}\}',
		'\[\/?[a-z][a-z0-9_\-]*(?:\s[^\[\]]*)?\]',
	);

	/**
	 * Regex for the never-translate terms, or '' when there are none.
	 *
	 * @var string
	 */
	private string $termPattern = '';

	/**
	 * Normalised terms for whole-string matches.
	 *
	 * @var array<string, true>
	 */
	private array $termSet = array();

	/**
	 * Create a protector.
	 *
	 * @param string[] $terms           Never-translate terms (brand names).
	 * @param bool     $caseInsensitive Whether terms match regardless of case.
	 * @param bool     $wholeWord       Whether terms match only as whole words.
	 * @param string   $format          Token format with one %d.
	 */
	public function __construct(
		array $terms = array(),
		private bool $caseInsensitive = true,
		bool $wholeWord = true,
		private string $format = self::DEFAULT_FORMAT
	) {
		$terms = array_values( array_filter( array_map( 'trim', $terms ), static fn( string $t ): bool => '' !== $t ) );
		usort( $terms, static fn( string $a, string $b ): int => mb_strlen( $b ) <=> mb_strlen( $a ) );
		if ( array() !== $terms ) {
			$alternation       = implode( '|', array_map( static fn( string $t ): string => preg_quote( $t, '/' ), $terms ) );
			$this->termPattern = $wholeWord ? '(?<![\p{L}\p{N}_])(?:' . $alternation . ')(?![\p{L}\p{N}_])' : '(?:' . $alternation . ')';
			if ( $caseInsensitive ) {
				$this->termPattern = '(?i:' . $this->termPattern . ')';
			}
		}
		foreach ( $terms as $term ) {
			$this->termSet[ $this->fold( $term ) ] = true;
		}
	}

	/**
	 * Whether the whole string is a never-translate term; such strings are
	 * never sent to a provider.
	 *
	 * @param string $text Normalised text.
	 */
	public function isTerm( string $text ): bool {
		return isset( $this->termSet[ $this->fold( trim( $text ) ) ] );
	}

	/**
	 * Replace protected parts with tokens. In HTML mode only text between
	 * tags is touched.
	 *
	 * @param string $text Text or HTML fragment.
	 * @param bool   $html Whether $text is HTML.
	 */
	public function protect( string $text, bool $html = false ): ProtectedText {
		$tokens = array();
		if ( ! $html ) {
			return new ProtectedText( $this->swap( $text, $tokens, false ), $tokens, $this->format );
		}
		$out = '';
		foreach ( self::splitTags( $text ) as $i => $part ) {
			$out .= 1 === $i % 2 ? $part : $this->swap( $part, $tokens, false );
		}

		return new ProtectedText( $out, $tokens, $this->format );
	}

	/**
	 * Whole-sentence form of an inline fragment for plain-text providers:
	 * every tag becomes a token too, so the provider sees "Read {1}our
	 * story{2} today" and may move the tags with the words. Text between tags
	 * is sent decoded. Token originals are HTML (tags raw, protected text
	 * encoded), so restore() must be given the provider output already
	 * passed through Text::encodeText().
	 *
	 * @param string $html Inline HTML fragment.
	 */
	public function protectInline( string $html ): ProtectedText {
		$tokens = array();
		$out    = '';
		foreach ( self::splitTags( $html ) as $i => $part ) {
			if ( 1 === $i % 2 ) {
				$n            = count( $tokens ) + 1;
				$tokens[ $n ] = $part;
				$out         .= sprintf( $this->format, $n );
				continue;
			}
			$out .= $this->swap( html_entity_decode( $part, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $tokens, true );
		}

		return new ProtectedText( $out, $tokens, $this->format );
	}

	/**
	 * Replace protected parts of a text chunk with numbered tokens.
	 *
	 * @param string             $chunk  Plain text.
	 * @param array<int, string> $tokens Token number => original, extended in place.
	 * @param bool               $encode Store originals HTML-encoded (& < >).
	 */
	private function swap( string $chunk, array &$tokens, bool $encode ): string {
		$pattern = '/' . implode( '|', array_merge( array( ProtectedText::tokenRegex( $this->format ) ), self::PATTERNS, '' === $this->termPattern ? array() : array( $this->termPattern ) ) ) . '/u';

		return (string) preg_replace_callback(
			$pattern,
			function ( array $m ) use ( &$tokens, $encode ): string {
				$n            = count( $tokens ) + 1;
				$tokens[ $n ] = $encode ? Text::encodeText( $m[0] ) : $m[0];

				return sprintf( $this->format, $n );
			},
			$chunk
		);
	}

	/**
	 * Split HTML into text (even indexes) and tags (odd indexes).
	 *
	 * @param string $html HTML fragment.
	 * @return list<string>
	 */
	private static function splitTags( string $html ): array {
		$parts = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );

		return false === $parts ? array( $html ) : $parts;
	}

	/**
	 * Case folding for term comparison.
	 *
	 * @param string $text Text.
	 */
	private function fold( string $text ): string {
		return $this->caseInsensitive ? mb_strtolower( $text ) : $text;
	}
}

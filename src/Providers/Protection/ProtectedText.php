<?php
/**
 * Text with protected parts swapped for tokens.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers\Protection;

/**
 * Result of Protector::protect(). restore() puts the originals back and
 * refuses a translation that lost, duplicated or invented a token.
 */
final class ProtectedText {

	/**
	 * Create the value.
	 *
	 * @param string             $text   Text sent to the provider.
	 * @param array<int, string> $tokens Token number => original text.
	 * @param string             $format sprintf format of a token, e.g. "[[%d]]".
	 */
	public function __construct(
		public string $text,
		public array $tokens,
		private string $format
	) {
	}

	/**
	 * The translation with tokens replaced by their originals, or null when a
	 * token is missing, repeated or unknown, or when token punctuation was
	 * left behind outside a token (TranslateX returned "[ [ 3]]]]" for
	 * "[[3]]", fixture translatex/tokens-format-bn.json). Whitespace a provider
	 * adds inside a token is tolerated.
	 *
	 * @param string $translation Provider output.
	 */
	public function restore( string $translation ): ?string {
		if ( array() === $this->tokens ) {
			return $translation;
		}
		$seen     = array();
		$valid    = true;
		$restored = preg_replace_callback(
			self::pattern( $this->format ),
			function ( array $m ) use ( &$seen, &$valid ): string {
				$n = (int) $m[1];
				if ( ! isset( $this->tokens[ $n ] ) || isset( $seen[ $n ] ) ) {
					$valid = false;

					return $m[0];
				}
				$seen[ $n ] = true;

				return $this->tokens[ $n ];
			},
			$translation
		);

		return $valid && null !== $restored && count( $seen ) === count( $this->tokens ) && $this->sameStrayPunctuation( $translation ) ? $restored : null;
	}

	/**
	 * Whether the translation has, outside its tokens, as many of each
	 * punctuation character of the token format as the sent text has.
	 *
	 * @param string $translation Provider output.
	 */
	private function sameStrayPunctuation( string $translation ): bool {
		$marks = array_unique( array_filter( mb_str_split( str_replace( '%d', '', $this->format ) ), static fn( string $c ): bool => 1 !== preg_match( '/[\p{L}\p{N}\s]/u', $c ) ) );
		if ( array() === $marks ) {
			return true;
		}
		$pattern = self::pattern( $this->format );
		$sent    = (string) preg_replace( $pattern, '', $this->text );
		$got     = (string) preg_replace( $pattern, '', $translation );
		foreach ( $marks as $mark ) {
			if ( mb_substr_count( $sent, $mark ) !== mb_substr_count( $got, $mark ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Regex matching a token of $format, tolerating inner whitespace.
	 *
	 * @param string $format sprintf format with one %d.
	 */
	public static function pattern( string $format ): string {
		return '/' . self::tokenRegex( $format ) . '/u';
	}

	/**
	 * Regex body (no delimiters) matching a token of $format.
	 *
	 * @param string $format sprintf format with one %d.
	 */
	public static function tokenRegex( string $format ): string {
		[ $before, $after ] = explode( '%d', $format, 2 );
		$loose              = static fn( string $part ): string => implode( '\s*', array_map( static fn( string $c ): string => preg_quote( $c, '/' ), mb_str_split( $part ) ) );

		return $loose( $before ) . '\s*(\d+)\s*' . $loose( $after );
	}
}

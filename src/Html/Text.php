<?php
/**
 * Text normalisation and the "is this worth translating" filter.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Html;

/**
 * Pure string helpers shared by extraction and replacement.
 */
final class Text {

	/** HTML ASCII whitespace. */
	public const WHITESPACE = " \t\n\r\f";

	/**
	 * Collapse runs of HTML whitespace to one space and trim.
	 * Non-breaking spaces are content and are kept.
	 *
	 * @param string $text Decoded text.
	 */
	public static function normalize( string $text ): string {
		return trim( (string) preg_replace( '/[ \t\n\r\f]+/', ' ', $text ), self::WHITESPACE );
	}

	/**
	 * Split raw bytes into leading whitespace, content and trailing whitespace.
	 *
	 * @param string $raw Source bytes.
	 * @return array{0: string, 1: string, 2: string}
	 */
	public static function splitEdges( string $raw ): array {
		$lead = strspn( $raw, self::WHITESPACE );
		if ( strlen( $raw ) === $lead ) {
			return array( $raw, '', '' );
		}
		$trail = strlen( $raw ) - strlen( rtrim( $raw, self::WHITESPACE ) );

		return array(
			substr( $raw, 0, $lead ),
			substr( $raw, $lead, strlen( $raw ) - $lead - $trail ),
			substr( $raw, strlen( $raw ) - $trail ),
		);
	}

	/**
	 * Whether a normalised string should be translated: it needs a letter and
	 * must not be a URL, an e-mail address or JSON-looking data.
	 *
	 * @param string $text Normalised text.
	 */
	public static function isTranslatable( string $text ): bool {
		if ( '' === $text || 1 !== preg_match( '/\p{L}/u', $text ) ) {
			return false;
		}
		if ( 1 === preg_match( '#^(?:[a-z][a-z0-9+.-]*:)?//\S+$#i', $text ) ) {
			return false;
		}
		if ( 1 === preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $text ) ) {
			return false;
		}
		$first = $text[0];
		if ( ( '{' === $first || '[' === $first ) && null !== json_decode( $text ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Encode a plain-text translation for an HTML text node.
	 *
	 * @param string $text Plain text.
	 */
	public static function encodeText( string $text ): string {
		return strtr(
			$text,
			array(
				'&' => '&amp;',
				'<' => '&lt;',
				'>' => '&gt;',
			)
		);
	}
}

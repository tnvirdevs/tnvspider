<?php
/**
 * Tag placeholders for inline segments sent to HTML-capable providers (D4).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers\Protection;

/**
 * Replaces every opening tag of an inline fragment with a bare numbered tag,
 * e.g. `<a href="/x" class="y">` becomes `<a id="1">`, so providers never see
 * or change attributes. decode() puts the original tags back and rejects
 * output with missing, repeated, renamed or invented tags.
 */
final class InlineTokens {

	private const VOID = array(
		'br'  => true,
		'wbr' => true,
	);

	/**
	 * Encode a fragment.
	 *
	 * @param string $html Inline HTML (inner HTML of an inline segment).
	 * @return array{0: string, 1: array<int, array{name: string, tag: string}>} Encoded HTML and id => original tag.
	 */
	public static function encode( string $html ): array {
		$map     = array();
		$encoded = preg_replace_callback(
			'/<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/',
			static function ( array $m ) use ( &$map ): string {
				$name = strtolower( $m[2] );
				if ( '/' === $m[1] ) {
					return '</' . $name . '>';
				}
				$id         = count( $map ) + 1;
				$map[ $id ] = array(
					'name' => $name,
					'tag'  => $m[0],
				);

				return '<' . $name . ' id="' . $id . '">';
			},
			$html
		);

		return array( (string) $encoded, $map );
	}

	/**
	 * Restore the original tags in a translation, or null when the tags do not
	 * match: every id exactly once with its original name, closers balanced,
	 * no other tags.
	 *
	 * @param string                                       $translation Provider output.
	 * @param array<int, array{name: string, tag: string}> $map         From encode().
	 */
	public static function decode( string $translation, array $map ): ?string {
		$seen  = array();
		$stack = array();
		$valid = true;
		$out   = preg_replace_callback(
			'/<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9]*)\s*(?:id\s*=\s*["\']?(\d+)["\']?)?\s*\/?\s*>/',
			static function ( array $m ) use ( $map, &$seen, &$stack, &$valid ): string {
				$name = strtolower( $m[2] );
				if ( '/' === $m[1] ) {
					if ( array_pop( $stack ) !== $name ) {
						$valid = false;
					}

					return '</' . $name . '>';
				}
				$id = isset( $m[3] ) ? (int) $m[3] : 0;
				if ( ! isset( $map[ $id ] ) || isset( $seen[ $id ] ) || $map[ $id ]['name'] !== $name ) {
					$valid = false;

					return $m[0];
				}
				$seen[ $id ] = true;
				if ( ! isset( self::VOID[ $name ] ) ) {
					$stack[] = $name;
				}

				return $map[ $id ]['tag'];
			},
			$translation
		);

		if ( ! $valid || null === $out || array() !== $stack || count( $seen ) !== count( $map ) ) {
			return null;
		}
		// Anything left that looks like a tag was not produced by encode().
		if ( 1 === preg_match( '/<\s*\/?\s*[a-zA-Z]/', self::stripKnown( $out, $map ) ) ) {
			return null;
		}

		return $out;
	}

	/**
	 * Remove the restored original tags and their closers, to look for leftovers.
	 *
	 * @param string                                       $html Restored HTML.
	 * @param array<int, array{name: string, tag: string}> $map  From encode().
	 */
	private static function stripKnown( string $html, array $map ): string {
		foreach ( $map as $entry ) {
			$html = str_replace( array( $entry['tag'], '</' . $entry['name'] . '>' ), '', $html );
		}

		return $html;
	}
}

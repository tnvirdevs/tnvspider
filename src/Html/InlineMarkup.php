<?php
/**
 * Safety checks for HTML inside inline segments.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Html;

/**
 * A translated inline segment may reorder text and tags but must keep exactly
 * the original tags and attributes (plan §6.5, decision P8). Anything else
 * is rejected.
 */
final class InlineMarkup {

	/** Reason given for an original that wp_kses() would alter (see survivesSanitize()). */
	public const UNSTORABLE = 'The original uses markup that WordPress removes when saving HTML (wp_kses, for example an unsupported CSS property), so no translation can be stored without changing the page. Exclude the element or translate it in the theme.';

	/**
	 * Tag sequence of an HTML fragment: openers with their sorted attributes
	 * and closers, e.g. ['A href="/x"', '/A']. Style values are compared by
	 * their declarations, because wp_kses() (sanitize()) rewrites
	 * "display:none;" as "display:none" (known issue 11).
	 *
	 * @param string $html Fragment.
	 * @return list<string>
	 */
	public static function signature( string $html ): array {
		$processor = new \WP_HTML_Tag_Processor( $html );
		$tags      = array();
		while ( $processor->next_token() ) {
			if ( '#tag' !== $processor->get_token_type() ) {
				continue;
			}
			$tag = (string) $processor->get_tag();
			if ( $processor->is_tag_closer() ) {
				$tags[] = '/' . $tag;
				continue;
			}
			$names = $processor->get_attribute_names_with_prefix( '' ) ?? array();
			sort( $names );
			foreach ( $names as $name ) {
				$value = $processor->get_attribute( $name );
				if ( 'style' === $name && is_string( $value ) ) {
					$value = self::declarations( $value );
				}
				$tag .= ' ' . $name . ( true === $value ? '' : '="' . (string) $value . '"' );
			}
			$tags[] = $tag;
		}

		return $tags;
	}

	/**
	 * Whether a translation has the same tags and attributes as the original:
	 * every tag exactly as often, in any order (word order may move a tag),
	 * and properly nested (decision P8).
	 *
	 * @param string $original    Original inline HTML.
	 * @param string $translation Translated inline HTML.
	 */
	public static function sameStructure( string $original, string $translation ): bool {
		$a = self::signature( $original );
		$b = self::signature( $translation );
		sort( $a );
		$sorted = $b;
		sort( $sorted );

		return $a === $sorted && self::wellNested( $b );
	}

	/**
	 * Whether wp_kses() keeps the original's markup as it is (up to style
	 * formatting). When it does not (for example an unsupported CSS property
	 * such as user-select), no translation of the string can be stored
	 * without changing how the page behaves.
	 *
	 * @param string $original Original inline HTML.
	 */
	public static function survivesSanitize( string $original ): bool {
		return self::signature( $original ) === self::signature( self::sanitize( $original, $original ) );
	}

	/**
	 * A style value as its declarations: trimmed, empty ones dropped,
	 * joined with ";" (the form safecss_filter_attr() produces).
	 *
	 * @param string $style Style attribute value.
	 */
	private static function declarations( string $style ): string {
		$parts = array();
		foreach ( explode( ';', $style ) as $declaration ) {
			$declaration = trim( $declaration );
			if ( '' !== $declaration ) {
				$parts[] = $declaration;
			}
		}

		return implode( ';', $parts );
	}

	/**
	 * Whether closers match openers in a signature.
	 *
	 * @param string[] $signature From signature().
	 */
	private static function wellNested( array $signature ): bool {
		$stack = array();
		foreach ( $signature as $entry ) {
			if ( str_starts_with( $entry, '/' ) ) {
				if ( array_pop( $stack ) !== substr( $entry, 1 ) ) {
					return false;
				}
				continue;
			}
			$name = (string) strtok( $entry, ' ' );
			if ( 'BR' !== $name && 'WBR' !== $name ) {
				$stack[] = $name;
			}
		}

		return array() === $stack;
	}

	/**
	 * Strip everything from a translation that the original does not use:
	 * only the original's tags, each with only its original attributes.
	 *
	 * @param string $original    Original inline HTML.
	 * @param string $translation Translated inline HTML.
	 */
	public static function sanitize( string $original, string $translation ): string {
		$allowed   = array();
		$processor = new \WP_HTML_Tag_Processor( $original );
		while ( $processor->next_tag() ) {
			$tag             = strtolower( (string) $processor->get_tag() );
			$allowed[ $tag ] = $allowed[ $tag ] ?? array();
			foreach ( $processor->get_attribute_names_with_prefix( '' ) ?? array() as $name ) {
				$allowed[ $tag ][ $name ] = true;
			}
		}

		return wp_kses( $translation, $allowed );
	}
}

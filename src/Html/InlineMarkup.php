<?php
/**
 * Safety checks for HTML inside inline segments.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Html;

/**
 * A translated inline segment may reorder text but must keep exactly the
 * original tags and attributes (plan §6.5). Anything else is rejected.
 */
final class InlineMarkup {

	/**
	 * Tag sequence of an HTML fragment: openers with their sorted attributes
	 * and closers, e.g. ['A href="/x"', '/A'].
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
				$tag  .= ' ' . $name . ( true === $value ? '' : '="' . (string) $value . '"' );
			}
			$tags[] = $tag;
		}

		return $tags;
	}

	/**
	 * Whether a translation has the same tags and attributes as the original.
	 *
	 * @param string $original    Original inline HTML.
	 * @param string $translation Translated inline HTML.
	 */
	public static function sameStructure( string $original, string $translation ): bool {
		return self::signature( $original ) === self::signature( $translation );
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

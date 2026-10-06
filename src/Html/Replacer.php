<?php
/**
 * Applies translations to the original bytes of a document.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Html;

/**
 * Splices translations into the original HTML. Bytes outside replaced
 * regions are never touched.
 */
final class Replacer {

	/**
	 * Return $html with every translated segment replaced.
	 *
	 * @param string                $html         The document the segments were extracted from.
	 * @param Segment[]             $segments     Segments from Extractor::extract().
	 * @phpstan-param list<Segment> $segments
	 * @param array<string, string> $translations Translation keyed by Segment::$text. Text values are
	 *                                            plain text; inline values are HTML.
	 */
	public function apply( string $html, array $segments, array $translations ): string {
		// Byte edits keyed by start offset.
		$edits = array();
		// Tag-token edits grouped per token, so one tag is rewritten once.
		$tagEdits = array();

		foreach ( $segments as $segment ) {
			if ( ! isset( $translations[ $segment->text ] ) ) {
				continue;
			}
			if ( null !== $segment->parent && isset( $translations[ $segment->parent->text ] ) ) {
				continue;
			}
			$translation = $translations[ $segment->text ];

			if ( $segment->isTagEdit() ) {
				$tagEdits[ $segment->start . ':' . $segment->length ][] = array( $segment, $translation );
				continue;
			}

			$body                     = Segment::INLINE === $segment->kind ? $translation : Text::encodeText( $translation );
			$edits[ $segment->start ] = array(
				'start'  => $segment->start,
				'length' => $segment->length,
				'text'   => $body,
			);
		}

		foreach ( $tagEdits as $group ) {
			$first                  = $group[0][0];
			$edits[ $first->start ] = array(
				'start'  => $first->start,
				'length' => $first->length,
				'text'   => $this->editTag( substr( $html, $first->start, $first->length ), $group ),
			);
		}

		krsort( $edits );
		foreach ( $edits as $edit ) {
			$html = substr_replace( $html, $edit['text'], $edit['start'], $edit['length'] );
		}

		return $html;
	}

	/**
	 * Rewrite one tag token through the HTML API so attribute quoting and
	 * escaping stay correct and the rest of the tag is untouched.
	 *
	 * @param string                                   $token Source bytes of the tag token.
	 * @param array<int, array{0: Segment, 1: string}> $group Segments of this tag with their translations.
	 * @throws \RuntimeException When the HTML API cannot apply an edit.
	 */
	private function editTag( string $token, array $group ): string {
		$processor = new \WP_HTML_Tag_Processor( $token );
		if ( ! $processor->next_tag() ) {
			throw new \RuntimeException( 'Expected a tag token to edit.' );
		}

		foreach ( $group as [ $segment, $translation ] ) {
			$applied = Segment::TITLE === $segment->kind
				? $processor->set_modifiable_text( $translation )
				: $processor->set_attribute( (string) $segment->attribute, $translation );
			if ( ! $applied ) {
				throw new \RuntimeException( 'The HTML API rejected a ' . esc_html( $segment->kind ) . ' edit.' );
			}
		}

		return $processor->get_updated_html();
	}
}

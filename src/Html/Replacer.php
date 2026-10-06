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
	 * @param string                $html           The document the segments were extracted from.
	 * @param Segment[]             $segments       Segments from Extractor::extract().
	 * @param array<string, string> $translations   Translation keyed by Segment::$text. Text values are
	 *                                              plain text; inline values are HTML.
	 * @param array[]               $attributeEdits Extra attribute values to set on tag tokens, e.g.
	 *                                              rewritten links: [start, length, name, value].
	 *                                              Edits inside a replaced inline segment are skipped;
	 *                                              the caller rewrites the inline translation itself.
	 * @phpstan-param list<Segment> $segments
	 * @phpstan-param list<array{0: int, 1: int, 2: string, 3: string}> $attributeEdits
	 */
	public function apply( string $html, array $segments, array $translations, array $attributeEdits = array() ): string {
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
				$tagEdits[ $segment->start . ':' . $segment->length ][] = array( $segment->start, $segment->length, $segment, $translation );
				continue;
			}

			$edits[ $segment->start ] = array(
				'start'  => $segment->start,
				'length' => $segment->length,
				'text'   => Segment::INLINE === $segment->kind ? $translation : Text::encodeText( $translation ),
			);
		}

		$replaced = array();
		foreach ( $edits as $edit ) {
			$replaced[] = array( $edit['start'], $edit['start'] + $edit['length'] );
		}
		foreach ( $attributeEdits as [ $start, $length, $name, $value ] ) {
			foreach ( $replaced as [ $from, $to ] ) {
				if ( $start >= $from && $start < $to ) {
					continue 2;
				}
			}
			$tagEdits[ $start . ':' . $length ][] = array( $start, $length, $name, $value );
		}

		foreach ( $tagEdits as $group ) {
			[ $start, $length ] = $group[0];
			$edits[ $start ]    = array(
				'start'  => $start,
				'length' => $length,
				'text'   => $this->editTag( substr( $html, $start, $length ), $group ),
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
	 * @param string  $token Source bytes of the tag token.
	 * @param array[] $group Edits of this tag: [start, length, Segment, translation] or
	 *                       [start, length, attribute, value].
	 * @phpstan-param list<array{0: int, 1: int, 2: Segment|string, 3: string}> $group
	 * @throws \RuntimeException When the HTML API cannot apply an edit.
	 */
	private function editTag( string $token, array $group ): string {
		$processor = new \WP_HTML_Tag_Processor( $token );
		if ( ! $processor->next_tag() ) {
			throw new \RuntimeException( 'Expected a tag token to edit.' );
		}

		foreach ( $group as [ , , $target, $value ] ) {
			if ( $target instanceof Segment ) {
				$applied = Segment::TITLE === $target->kind
					? $processor->set_modifiable_text( $value )
					: $processor->set_attribute( (string) $target->attribute, $value );
			} else {
				$applied = $processor->set_attribute( $target, $value );
			}
			if ( ! $applied ) {
				throw new \RuntimeException( 'The HTML API rejected an edit of a tag.' );
			}
		}

		return $processor->get_updated_html();
	}
}

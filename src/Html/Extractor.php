<?php
/**
 * Finds translatable strings in an HTML document without re-serialising it.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Html;

/**
 * Walks the document with the core HTML API lexer and keeps a light element
 * stack (with the common HTML5 implied end tags) to know what is excluded
 * and which elements hold only inline content.
 */
final class Extractor {

	/** Tags whose content may be merged into one inline segment. */
	private const INLINE_TAGS = array(
		'A'      => true,
		'ABBR'   => true,
		'B'      => true,
		'BDI'    => true,
		'BR'     => true,
		'CITE'   => true,
		'EM'     => true,
		'I'      => true,
		'MARK'   => true,
		'Q'      => true,
		'S'      => true,
		'SMALL'  => true,
		'SPAN'   => true,
		'STRONG' => true,
		'SUB'    => true,
		'SUP'    => true,
		'U'      => true,
		'WBR'    => true,
	);

	/** Elements whose whole subtree is never translated. */
	private const EXCLUDED_TAGS = array(
		'CODE'     => true,
		'MATH'     => true,
		'NOSCRIPT' => true,
		'PRE'      => true,
		'SVG'      => true,
		'TEMPLATE' => true,
	);

	/** Elements the lexer returns as one token, content included. */
	private const SINGLE_TOKEN_TAGS = array(
		'IFRAME'   => true,
		'NOEMBED'  => true,
		'NOFRAMES' => true,
		'SCRIPT'   => true,
		'STYLE'    => true,
		'TEXTAREA' => true,
		'TITLE'    => true,
		'XMP'      => true,
	);

	private const VOID_TAGS = array(
		'AREA'     => true,
		'BASE'     => true,
		'BASEFONT' => true,
		'BGSOUND'  => true,
		'BR'       => true,
		'COL'      => true,
		'EMBED'    => true,
		'FRAME'    => true,
		'HR'       => true,
		'IMG'      => true,
		'INPUT'    => true,
		'KEYGEN'   => true,
		'LINK'     => true,
		'META'     => true,
		'PARAM'    => true,
		'SOURCE'   => true,
		'TRACK'    => true,
		'WBR'      => true,
	);

	/** Opening one of these closes an open P element. */
	private const CLOSES_P = array(
		'ADDRESS'    => true,
		'ARTICLE'    => true,
		'ASIDE'      => true,
		'BLOCKQUOTE' => true,
		'CENTER'     => true,
		'DD'         => true,
		'DETAILS'    => true,
		'DIALOG'     => true,
		'DIR'        => true,
		'DIV'        => true,
		'DL'         => true,
		'DT'         => true,
		'FIELDSET'   => true,
		'FIGCAPTION' => true,
		'FIGURE'     => true,
		'FOOTER'     => true,
		'FORM'       => true,
		'H1'         => true,
		'H2'         => true,
		'H3'         => true,
		'H4'         => true,
		'H5'         => true,
		'H6'         => true,
		'HEADER'     => true,
		'HGROUP'     => true,
		'HR'         => true,
		'LI'         => true,
		'LISTING'    => true,
		'MAIN'       => true,
		'MENU'       => true,
		'NAV'        => true,
		'OL'         => true,
		'P'          => true,
		'PRE'        => true,
		'SEARCH'     => true,
		'SECTION'    => true,
		'SUMMARY'    => true,
		'TABLE'      => true,
		'UL'         => true,
		'XMP'        => true,
	);

	/** Elements that stop the search for an open P (button scope). */
	private const P_SCOPE_BOUNDARY = array(
		'APPLET'   => true,
		'BUTTON'   => true,
		'CAPTION'  => true,
		'MARQUEE'  => true,
		'OBJECT'   => true,
		'TABLE'    => true,
		'TD'       => true,
		'TEMPLATE' => true,
		'TH'       => true,
	);

	/** Attributes translated on any element. */
	private const TEXT_ATTRIBUTES = array( 'placeholder', 'title', 'alt', 'aria-label', 'aria-placeholder' );

	/** META elements whose content is translated, keyed by name or property. */
	private const META_KEYS = array(
		'description'         => true,
		'og:title'            => true,
		'og:description'      => true,
		'twitter:title'       => true,
		'twitter:description' => true,
	);

	/**
	 * The document being walked.
	 *
	 * @var string
	 */
	private string $html = '';

	/**
	 * Tokenizer over $html.
	 *
	 * @var Lexer
	 */
	private Lexer $lexer;

	/**
	 * Attribute names of the current start tag, as keys.
	 *
	 * @var array<string, true>
	 */
	private array $attributes = array();

	/**
	 * Open elements, innermost last.
	 *
	 * @var list<Frame>
	 */
	private array $frames = array();

	/**
	 * Segments found so far, in document order.
	 *
	 * @var list<Segment>
	 */
	private array $segments = array();

	/**
	 * Link attributes of the last extraction: [start, length, attribute, value].
	 *
	 * @var list<array{0: int, 1: int, 2: string, 3: string}>
	 */
	private array $links = array();

	/**
	 * Extract the translatable segments of a document or fragment.
	 *
	 * @param string $html Document or fragment.
	 * @return list<Segment>
	 */
	public function extract( string $html ): array {
		$this->html     = $html;
		$this->lexer    = new Lexer( $html );
		$this->frames   = array();
		$this->segments = array();
		$this->links    = array();

		while ( $this->lexer->next_token() ) {
			switch ( $this->lexer->get_token_type() ) {
				case '#tag':
					if ( $this->lexer->is_tag_closer() ) {
						$this->closeTag();
					} else {
						$this->openTag();
					}
					break;

				case '#text':
					$this->text();
					break;

				default:
					// Comments, CDATA, doctype and the like are never translated
					// and are not allowed inside an inline segment.
					$this->breakInline();
			}
		}

		// Elements still open at the end of the input close at its end. If the
		// lexer stopped on an incomplete token, they close where it stopped.
		$end = strlen( $html );
		if ( $this->lexer->paused_at_incomplete_token() ) {
			$this->breakInline();
		}
		while ( array() !== $this->frames ) {
			$this->popFrame( $end );
		}

		$segments       = $this->segments;
		$this->segments = array();
		$this->frames   = array();

		return $segments;
	}

	/**
	 * Handle a start tag: implied end tags, exclusion, segments, stack.
	 */
	private function openTag(): void {
		$tag                = (string) $this->lexer->get_tag();
		[ $start, $length ] = $this->lexer->tokenSpan();

		$names            = $this->lexer->get_attribute_names_with_prefix( '' );
		$this->attributes = null === $names ? array() : array_fill_keys( $names, true );

		if ( 'html' === $this->lexer->get_namespace() ) {
			$this->closeImplied( $tag, $start );
		}

		$parent         = $this->current();
		$parentExcluded = null !== $parent && $parent->excluded;
		$excluded       = $parentExcluded || isset( self::EXCLUDED_TAGS[ $tag ] ) || $this->hasNoTranslateMarker();

		if ( $excluded || ! isset( self::INLINE_TAGS[ $tag ] ) ) {
			$this->breakInline();
		} elseif ( null !== $parent ) {
			$parent->hasInlineTag = true;
		}

		if ( ! $excluded ) {
			$this->tagSegments( $tag, $start, $length );
		}
		$this->collectLink( $tag, $start, $length );

		if ( isset( self::SINGLE_TOKEN_TAGS[ $tag ] ) && 'html' === $this->lexer->get_namespace() ) {
			return;
		}
		if ( isset( self::VOID_TAGS[ $tag ] ) || 'HTML' === $tag || 'HEAD' === $tag || 'BODY' === $tag ) {
			return;
		}
		if ( 'html' !== $this->lexer->get_namespace() && $this->lexer->has_self_closing_flag() ) {
			return;
		}

		$frame          = new Frame( $tag, $start + $length, count( $this->segments ), $excluded );
		$frame->foreign = 'SVG' === $tag || 'MATH' === $tag;
		$this->frames[] = $frame;

		if ( $frame->foreign ) {
			$this->lexer->change_parsing_namespace( 'SVG' === $tag ? 'svg' : 'math' );
		}
	}

	/**
	 * Link targets of the last extract() call: href of A and AREA, action of
	 * FORM, as [start, length, attribute, decoded value] of the tag token.
	 * Links that carry hreflang point at a specific language and are left out.
	 *
	 * @return list<array{0: int, 1: int, 2: string, 3: string}>
	 */
	public function links(): array {
		return $this->links;
	}

	/**
	 * Record the link attribute of the current start tag, if any.
	 *
	 * @param string $tag    Upper-case tag name.
	 * @param int    $start  Byte offset of the tag token.
	 * @param int    $length Byte length of the tag token.
	 */
	private function collectLink( string $tag, int $start, int $length ): void {
		$attribute = 'FORM' === $tag ? 'action' : ( 'A' === $tag || 'AREA' === $tag ? 'href' : null );
		if ( null === $attribute || ! isset( $this->attributes[ $attribute ] ) || isset( $this->attributes['hreflang'] ) ) {
			return;
		}
		$value = $this->lexer->get_attribute( $attribute );
		if ( is_string( $value ) && '' !== $value ) {
			$this->links[] = array( $start, $length, $attribute, $value );
		}
	}

	/**
	 * Handle an end tag: close the matching element and anything inside it.
	 */
	private function closeTag(): void {
		$tag       = (string) $this->lexer->get_tag();
		[ $start ] = $this->lexer->tokenSpan();

		for ( $i = count( $this->frames ) - 1; $i >= 0; $i-- ) {
			if ( $this->frames[ $i ]->tag === $tag ) {
				$this->popTo( $i, $start );
				return;
			}
		}
		// A closer with no matching open element is ignored, as browsers do.
	}

	/**
	 * Handle a text node.
	 */
	private function text(): void {
		[ $start, $length ] = $this->lexer->tokenSpan();
		$normalized         = Text::normalize( $this->lexer->get_modifiable_text() );
		$frame              = $this->current();

		if ( '' === $normalized || ( null !== $frame && $frame->excluded ) ) {
			return;
		}
		if ( null !== $frame ) {
			++$frame->textNodes;
		}
		if ( ! Text::isTranslatable( $normalized ) ) {
			return;
		}

		if ( null !== $frame ) {
			$frame->hasText = true;
		}

		[ $prefix, , $suffix ] = Text::splitEdges( substr( $this->html, $start, $length ) );
		$this->segments[]      = new Segment(
			Segment::TEXT,
			$normalized,
			$start + strlen( $prefix ),
			$length - strlen( $prefix ) - strlen( $suffix ),
			$prefix,
			$suffix
		);
	}

	/**
	 * Attribute, META and TITLE segments of the current start tag.
	 *
	 * @param string $tag    Upper-case tag name.
	 * @param int    $start  Byte offset of the tag token.
	 * @param int    $length Byte length of the tag token.
	 */
	private function tagSegments( string $tag, int $start, int $length ): void {
		if ( 'TITLE' === $tag ) {
			if ( 'html' === $this->lexer->get_namespace() ) {
				$this->addTagSegment( Segment::TITLE, $this->lexer->get_modifiable_text(), $start, $length, null );
			}
			return;
		}

		if ( 'META' === $tag ) {
			if ( ! isset( $this->attributes['content'] ) ) {
				return;
			}
			$key = $this->lexer->get_attribute( 'name' );
			if ( ! is_string( $key ) ) {
				$key = $this->lexer->get_attribute( 'property' );
			}
			if ( is_string( $key ) && isset( self::META_KEYS[ strtolower( $key ) ] ) ) {
				$this->addAttributeSegment( Segment::META, 'content', $start, $length );
			}
			return;
		}

		if ( 'LINK' === $tag ) {
			// Titles of feed, oEmbed and other head links are never displayed.
			return;
		}

		foreach ( self::TEXT_ATTRIBUTES as $name ) {
			$this->addAttributeSegment( Segment::ATTR, $name, $start, $length );
		}

		if ( 'INPUT' === $tag ) {
			$type = $this->lexer->get_attribute( 'type' );
			if ( is_string( $type ) && in_array( strtolower( $type ), array( 'submit', 'button', 'reset' ), true ) ) {
				$this->addAttributeSegment( Segment::ATTR, 'value', $start, $length );
			}
		}
	}

	/**
	 * Add a segment for one attribute of the current tag, if present and translatable.
	 *
	 * @param string $kind   Segment kind.
	 * @param string $name   Attribute name.
	 * @param int    $start  Byte offset of the tag token.
	 * @param int    $length Byte length of the tag token.
	 */
	private function addAttributeSegment( string $kind, string $name, int $start, int $length ): void {
		if ( ! isset( $this->attributes[ $name ] ) ) {
			return;
		}
		$value = $this->lexer->get_attribute( $name );
		if ( is_string( $value ) ) {
			$this->addTagSegment( $kind, $value, $start, $length, $name );
		}
	}

	/**
	 * Add a tag-token segment when the decoded value is translatable.
	 *
	 * @param string      $kind      Segment kind.
	 * @param string      $decoded   Decoded value.
	 * @param int         $start     Byte offset of the tag token.
	 * @param int         $length    Byte length of the tag token.
	 * @param string|null $attribute Attribute name, null for TITLE.
	 */
	private function addTagSegment( string $kind, string $decoded, int $start, int $length, ?string $attribute ): void {
		$normalized = Text::normalize( $decoded );
		if ( Text::isTranslatable( $normalized ) ) {
			$this->segments[] = new Segment( $kind, $normalized, $start, $length, '', '', $attribute );
		}
	}

	/**
	 * Whether the current start tag opts out of translation.
	 */
	private function hasNoTranslateMarker(): bool {
		if ( array() === $this->attributes ) {
			return false;
		}
		if ( isset( $this->attributes['data-wst-no-translate'] ) || isset( $this->attributes['data-no-translation'] ) ) {
			return true;
		}
		$translate = $this->lexer->get_attribute( 'translate' );
		if ( is_string( $translate ) && 'no' === strtolower( trim( $translate ) ) ) {
			return true;
		}

		return isset( $this->attributes['class'] ) && true === $this->lexer->has_class( 'notranslate' );
	}

	/**
	 * Close elements that HTML5 ends implicitly when $tag opens.
	 *
	 * @param string $tag Upper-case tag name being opened.
	 * @param int    $at  Byte offset where the closed elements end.
	 */
	private function closeImplied( string $tag, int $at ): void {
		if ( isset( self::CLOSES_P[ $tag ] ) ) {
			$this->closeNearest( array( 'P' => true ), self::P_SCOPE_BOUNDARY, $at );
		}

		switch ( $tag ) {
			case 'LI':
				$this->closeNearest(
					array( 'LI' => true ),
					array(
						'UL'   => true,
						'OL'   => true,
						'MENU' => true,
					) + self::P_SCOPE_BOUNDARY,
					$at
				);
				break;
			case 'DD':
			case 'DT':
				$this->closeNearest(
					array(
						'DD' => true,
						'DT' => true,
					),
					array( 'DL' => true ) + self::P_SCOPE_BOUNDARY,
					$at
				);
				break;
			case 'OPTION':
				$this->closeCurrentIf( array( 'OPTION' => true ), $at );
				break;
			case 'OPTGROUP':
				$this->closeCurrentIf( array( 'OPTION' => true ), $at );
				$this->closeCurrentIf( array( 'OPTGROUP' => true ), $at );
				break;
			case 'TR':
				$this->closeNearest(
					array( 'TR' => true ),
					array(
						'TABLE' => true,
						'TBODY' => true,
						'THEAD' => true,
						'TFOOT' => true,
					),
					$at
				);
				break;
			case 'TD':
			case 'TH':
				$this->closeNearest(
					array(
						'TD' => true,
						'TH' => true,
					),
					array(
						'TR'    => true,
						'TABLE' => true,
					),
					$at
				);
				break;
			case 'TBODY':
			case 'THEAD':
			case 'TFOOT':
				$this->closeNearest(
					array(
						'TBODY' => true,
						'THEAD' => true,
						'TFOOT' => true,
					),
					array( 'TABLE' => true ),
					$at
				);
				break;
			case 'H1':
			case 'H2':
			case 'H3':
			case 'H4':
			case 'H5':
			case 'H6':
				$this->closeCurrentIf(
					array(
						'H1' => true,
						'H2' => true,
						'H3' => true,
						'H4' => true,
						'H5' => true,
						'H6' => true,
					),
					$at
				);
				break;
			case 'A':
				$this->closeNearest( array( 'A' => true ), array(), $at );
				break;
		}
	}

	/**
	 * Close the nearest open element named in $targets, unless a boundary
	 * element is found first.
	 *
	 * @param array<string, true> $targets    Tags to close.
	 * @param array<string, true> $boundaries Tags that stop the search.
	 * @param int                 $at         Byte offset where the closed elements end.
	 */
	private function closeNearest( array $targets, array $boundaries, int $at ): void {
		for ( $i = count( $this->frames ) - 1; $i >= 0; $i-- ) {
			$tag = $this->frames[ $i ]->tag;
			if ( isset( $targets[ $tag ] ) ) {
				$this->popTo( $i, $at );
				return;
			}
			if ( isset( $boundaries[ $tag ] ) || $this->frames[ $i ]->foreign ) {
				return;
			}
		}
	}

	/**
	 * Close the current element if it is one of $targets.
	 *
	 * @param array<string, true> $targets Tags to close.
	 * @param int                 $at      Byte offset where the element ends.
	 */
	private function closeCurrentIf( array $targets, int $at ): void {
		$frame = $this->current();
		if ( null !== $frame && isset( $targets[ $frame->tag ] ) ) {
			$this->popFrame( $at );
		}
	}

	/**
	 * Pop the innermost element, which ends at byte $closerStart, and merge
	 * its content into one inline segment when it qualifies. Its flags are
	 * passed up to the parent, so each token only touches one frame.
	 *
	 * @param int $closerStart Byte offset where the element's content ends.
	 */
	private function popFrame( int $closerStart ): void {
		$frame = array_pop( $this->frames );
		if ( null === $frame ) {
			return;
		}
		$parent = $this->current();
		if ( null !== $parent ) {
			$parent->inlineOnly   = $parent->inlineOnly && $frame->inlineOnly;
			$parent->hasText      = $parent->hasText || $frame->hasText;
			$parent->hasInlineTag = $parent->hasInlineTag || $frame->hasInlineTag;
			$parent->textNodes   += $frame->textNodes;
		}
		if ( $frame->foreign && ! $this->insideForeign() ) {
			$this->lexer->change_parsing_namespace( 'html' );
		}
		// Merging only helps when text runs on both sides of a tag can be
		// reordered; a lone link or bold word stays a plain text segment.
		if ( $frame->excluded || ! $frame->inlineOnly || ! $frame->hasText || ! $frame->hasInlineTag || $frame->textNodes < 2 ) {
			return;
		}

		$raw                         = substr( $this->html, $frame->contentStart, $closerStart - $frame->contentStart );
		[ $prefix, $inner, $suffix ] = Text::splitEdges( $raw );
		$inline                      = new Segment(
			Segment::INLINE,
			Text::normalize( $inner ),
			$frame->contentStart + strlen( $prefix ),
			strlen( $inner ),
			$prefix,
			$suffix
		);

		// Text and smaller inline segments inside are replaced by this one;
		// attribute segments stay and are applied only if this one is not.
		$inside           = array_splice( $this->segments, $frame->firstSegment );
		$this->segments[] = $inline;
		foreach ( $inside as $segment ) {
			if ( $segment->isTagEdit() ) {
				$segment->parent  = $inline;
				$this->segments[] = $segment;
			}
		}
	}

	/**
	 * Mark the current element, and through popFrame() its ancestors, as no
	 * longer inline-only.
	 */
	private function breakInline(): void {
		$frame = $this->current();
		if ( null !== $frame ) {
			$frame->inlineOnly = false;
		}
	}

	/**
	 * Close every element from the top of the stack down to index $index.
	 *
	 * @param int $index Stack index of the outermost element to close.
	 * @param int $at    Byte offset where the closed elements end.
	 */
	private function popTo( int $index, int $at ): void {
		for ( $open = count( $this->frames ); $open > $index; $open-- ) {
			$this->popFrame( $at );
		}
	}

	/**
	 * Whether an SVG or MathML element is still open.
	 */
	private function insideForeign(): bool {
		foreach ( $this->frames as $frame ) {
			if ( $frame->foreign ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The innermost open element.
	 */
	private function current(): ?Frame {
		$count = count( $this->frames );

		return 0 === $count ? null : $this->frames[ $count - 1 ];
	}
}

<?php
/**
 * One translatable piece of a document and where it lives.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Html;

/**
 * A translatable string found by the Extractor.
 *
 * Text and inline segments replace the bytes of [start, start + length).
 * Attribute and title segments replace a whole tag token via the HTML API.
 */
final class Segment {

	public const TEXT   = 'text';
	public const INLINE = 'inline';
	public const ATTR   = 'attr';
	public const TITLE  = 'title';
	public const META   = 'meta';

	/** Text added by JavaScript, found by the dynamic scan (plan §13A.5c); never produced by the extractor. */
	public const DYNAMIC = 'dynamic';

	/**
	 * Inline segment this one sits inside, if any. When the parent is
	 * replaced, this segment is not applied separately.
	 *
	 * @var Segment|null
	 */
	public ?Segment $parent = null;

	/**
	 * Create a segment.
	 *
	 * @param string      $kind      One of the class constants.
	 * @param string      $text      Normalised original: decoded text, or inner HTML for inline segments.
	 * @param int         $start     Byte offset of the replaced region.
	 * @param int         $length    Byte length of the replaced region.
	 * @param string      $prefix    Whitespace kept before the replacement (text and inline only).
	 * @param string      $suffix    Whitespace kept after the replacement (text and inline only).
	 * @param string|null $attribute Attribute name for attr and meta segments.
	 */
	public function __construct(
		public string $kind,
		public string $text,
		public int $start,
		public int $length,
		public string $prefix = '',
		public string $suffix = '',
		public ?string $attribute = null
	) {
	}

	/**
	 * Whether the replacement is applied to a whole tag token.
	 */
	public function isTagEdit(): bool {
		return self::ATTR === $this->kind || self::META === $this->kind || self::TITLE === $this->kind;
	}
}

<?php
/**
 * An open element on the Extractor's stack.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Html;

/**
 * Mutable bookkeeping for one open element. Internal to the Extractor.
 */
final class Frame {

	/**
	 * Whether the element is SVG or MathML (foreign content).
	 *
	 * @var bool
	 */
	public bool $foreign = false;

	/**
	 * Whether every descendant so far is text or an inline tag.
	 *
	 * @var bool
	 */
	public bool $inlineOnly = true;

	/**
	 * Whether a translatable text node was found inside.
	 *
	 * @var bool
	 */
	public bool $hasText = false;

	/**
	 * Whether an inline tag was found inside.
	 *
	 * @var bool
	 */
	public bool $hasInlineTag = false;

	/**
	 * Open an element.
	 *
	 * @param string $tag          Upper-case tag name.
	 * @param int    $contentStart Byte offset just after the start tag.
	 * @param int    $firstSegment Number of segments found before this element.
	 * @param bool   $excluded     Whether the subtree is never translated.
	 */
	public function __construct(
		public string $tag,
		public int $contentStart,
		public int $firstSegment,
		public bool $excluded
	) {
	}
}

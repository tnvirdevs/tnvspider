<?php
/**
 * WordPress HTML API tokenizer with access to the current token's byte span.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Html;

/**
 * Thin subclass of the core Tag Processor.
 *
 * Core keeps token offsets private; bookmarks are the protected extension
 * point for subclasses, so the span is read through a short-lived bookmark.
 * LexerTest pins this behaviour against the bundled WordPress version.
 */
final class Lexer extends \WP_HTML_Tag_Processor {

	private const BOOKMARK = 'wst_span';

	/**
	 * Byte offset and length of the token the lexer is paused on.
	 *
	 * @return array{0: int, 1: int}
	 * @throws \LogicException When the lexer is not paused on a token.
	 */
	public function tokenSpan(): array {
		if ( null === $this->get_token_type() || ! $this->set_bookmark( self::BOOKMARK ) ) {
			throw new \LogicException( 'The lexer is not paused on a token.' );
		}
		$span = $this->bookmarks[ self::BOOKMARK ];
		$this->release_bookmark( self::BOOKMARK );

		return array( $span->start, $span->length );
	}
}

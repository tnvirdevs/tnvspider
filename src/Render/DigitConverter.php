<?php
/**
 * Digit conversion on target-language output (plan §13A.6).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Render;

use WST\Html\Extractor;
use WST\Html\Selectors;

/**
 * Writes ASCII digits 0–9 as Bengali, Arabic-Indic or Persian digits in
 * visible text only: text tokens outside excluded areas (code, pre, svg,
 * no-translate markers, the exclude selectors and, when asked, WooCommerce
 * prices). Attributes, URLs, e-mail addresses, character references,
 * script, style, textarea, form values and never-translate terms keep
 * their digits; digits already converted are left alone.
 */
final class DigitConverter {

	/** Digit sets by mode. */
	public const SETS = array(
		'bengali'      => '০১২৩৪৫৬৭৮৯',
		'arabic_indic' => '٠١٢٣٤٥٦٧٨٩',
		'persian'      => '۰۱۲۳۴۵۶۷۸۹',
	);

	/** Price markup skipped when "Skip WooCommerce prices" is on. */
	public const PRICE_SELECTORS = array( '.woocommerce-Price-amount', '.price' );

	/**
	 * ASCII digit => target digit (PHP keeps the digit keys as integers; strtr() accepts them).
	 *
	 * @var array<int, string>
	 */
	private array $map;

	/**
	 * Pattern: group 1 = text whose digits stay (references, URLs, e-mails, terms); otherwise a digit run.
	 *
	 * @var string
	 */
	private string $protected;

	/**
	 * Create the converter.
	 *
	 * @param string   $mode       One of the SETS keys.
	 * @param string[] $selectors  Exclude selectors (prices included by the caller when skipped).
	 * @phpstan-param list<string> $selectors
	 * @param string[] $terms      Never-translate terms: their digits stay.
	 * @phpstan-param list<string> $terms
	 * @param bool     $caseless   Whether terms match case-insensitively.
	 * @throws \InvalidArgumentException For an unknown mode.
	 */
	public function __construct( string $mode, private array $selectors, array $terms, bool $caseless ) {
		if ( ! isset( self::SETS[ $mode ] ) ) {
			throw new \InvalidArgumentException( 'Unknown digit mode.' );
		}
		$this->map = mb_str_split( self::SETS[ $mode ] );
		$parts     = array(
			'&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]*);', // Character references.
			'(?:[a-z][a-z0-9+.-]*:)?//[^\s<]+|www\.[^\s<]+', // URLs.
			'[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+', // E-mail addresses.
		);
		foreach ( $terms as $term ) {
			if ( '' !== $term && 1 === preg_match( '/[0-9]/', $term ) ) {
				$parts[] = preg_quote( $term, '~' );
			}
		}
		$this->protected = '~(' . implode( '|', $parts ) . ')|[0-9]+~u' . ( $caseless ? 'i' : '' );
	}

	/**
	 * Convert the visible text of a document or fragment.
	 *
	 * @param string $html HTML.
	 */
	public function html( string $html ): string {
		if ( 1 !== preg_match( '/[0-9]/', $html ) ) {
			return $html;
		}
		$extractor = new Extractor( new Selectors( $this->selectors ) );
		$extractor->extract( $html );
		$out = '';
		$at  = 0;
		foreach ( $extractor->textSpans() as [ $start, $length ] ) {
			$out .= substr( $html, $at, $start - $at ) . $this->text( substr( $html, $start, $length ) );
			$at   = $start + $length;
		}

		return $out . substr( $html, $at );
	}

	/**
	 * Convert the digits of a piece of text (raw HTML text bytes or plain text).
	 *
	 * @param string $text Text.
	 */
	public function text( string $text ): string {
		if ( 1 !== preg_match( '/[0-9]/', $text ) ) {
			return $text;
		}

		return (string) preg_replace_callback(
			$this->protected,
			fn( array $found ): string => isset( $found[1] ) && '' !== $found[1] ? $found[0] : strtr( $found[0], $this->map ),
			$text
		);
	}
}

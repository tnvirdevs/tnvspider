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
 * Writes ASCII digits 0–9 as Bengali, Arabic-Indic or Persian digits — or,
 * in "latin" mode for a target such as English, those digits as 0–9 — in
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

	/** Mode that writes Bengali, Arabic-Indic and Persian digits as 0–9. */
	public const LATIN = 'latin';

	/** Price markup skipped when "Skip WooCommerce prices" is on. */
	public const PRICE_SELECTORS = array( '.woocommerce-Price-amount', '.price' );

	/**
	 * Source digit => target digit (strtr() map).
	 *
	 * @var array<int|string, string>
	 */
	private array $map;

	/**
	 * Regex class of the digits this mode converts.
	 *
	 * @var string
	 */
	private string $digitClass;

	/**
	 * Pattern: group 1 = text whose digits stay (references, URLs, e-mails, terms); otherwise a digit run.
	 *
	 * @var string
	 */
	private string $protected;

	/**
	 * Create the converter.
	 *
	 * @param string   $mode       One of the SETS keys, or self::LATIN.
	 * @param string[] $selectors  Exclude selectors (prices included by the caller when skipped).
	 * @phpstan-param list<string> $selectors
	 * @param string[] $terms      Never-translate terms: their digits stay.
	 * @phpstan-param list<string> $terms
	 * @param bool     $caseless   Whether terms match case-insensitively.
	 * @param string[] $languages  Primary subtags of the site's two languages: text marked
	 *                             with another language (lang="ar") keeps its digits.
	 * @phpstan-param list<string> $languages
	 * @throws \InvalidArgumentException For an unknown mode.
	 */
	public function __construct( string $mode, private array $selectors, array $terms, bool $caseless, private array $languages = array() ) {
		if ( self::LATIN === $mode ) {
			$this->map = array();
			foreach ( self::SETS as $set ) {
				foreach ( mb_str_split( $set ) as $digit => $char ) {
					$this->map[ $char ] = (string) $digit;
				}
			}
			$this->digitClass = '[' . implode( '', self::SETS ) . ']';
		} elseif ( isset( self::SETS[ $mode ] ) ) {
			$this->map        = mb_str_split( self::SETS[ $mode ] );
			$this->digitClass = '[0-9]';
		} else {
			throw new \InvalidArgumentException( 'Unknown digit mode.' );
		}
		$parts = array(
			'&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]*);', // Character references.
			'(?:[a-z][a-z0-9+.-]*:)?//[^\s<]+|www\.[^\s<]+', // URLs.
			'[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+', // E-mail addresses.
		);
		foreach ( $terms as $term ) {
			if ( '' !== $term && 1 === preg_match( '~' . $this->digitClass . '~u', $term ) ) {
				$parts[] = preg_quote( $term, '~' );
			}
		}
		$this->protected = '~(' . implode( '|', $parts ) . ')|' . $this->digitClass . '+~u' . ( $caseless ? 'i' : '' );
	}

	/**
	 * Convert the visible text of a document or fragment.
	 *
	 * @param string $html HTML.
	 */
	public function html( string $html ): string {
		if ( 1 !== preg_match( '~' . $this->digitClass . '~u', $html ) ) {
			return $html;
		}
		$extractor = new Extractor( new Selectors( $this->selectors ), $this->languages );
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
		if ( 1 !== preg_match( '~' . $this->digitClass . '~u', $text ) ) {
			return $text;
		}

		return (string) preg_replace_callback(
			$this->protected,
			fn( array $found ): string => isset( $found[1] ) && '' !== $found[1] ? $found[0] : strtr( $found[0], $this->map ),
			$text
		);
	}
}

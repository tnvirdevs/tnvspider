<?php
/**
 * Turns queued strings into provider requests and the replies back into
 * validated translations (plan §7).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

use WST\Html\InlineMarkup;
use WST\Html\Segment;
use WST\Html\Text;
use WST\Providers\Protection\InlineTokens;
use WST\Providers\Protection\ProtectedText;
use WST\Providers\Protection\Protector;
use WST\Providers\Protection\Segmenter;

/**
 * Built for one batch of strings. requests() lists the provider calls, each
 * within the provider's item and character caps; complete() takes their
 * results and returns one outcome per string.
 */
final class RequestPlan {

	/** Translation flag: inline string translated run by run (word order may be off). */
	public const FLAG_SEGMENTED = 1;

	/** Translation flag: the provider's tag syntax needed repair. */
	public const FLAG_TAGS_REPAIRED = 4;

	/** Provider id recorded for strings that are a never-translate term. */
	public const PROTECTED_TERM = 'term';

	/**
	 * Provider calls: list of [html, items keyed by unit id].
	 *
	 * @var list<array{html: bool, items: array<int, string>}>
	 */
	private array $requests = array();

	/**
	 * How each string is assembled from units.
	 *
	 * @var array<int, array{mode: string, units: list<int>, original: string, chunks?: list<array{0: bool, 1: string}>, map?: array<int, array{name: string, tag: string}>}>
	 */
	private array $strings = array();

	/**
	 * Protected text per unit.
	 *
	 * @var array<int, ProtectedText>
	 */
	private array $units = array();

	/**
	 * Strings resolved without a provider call (never-translate terms).
	 *
	 * @var array<int, array{translation: string, flags: int}>
	 */
	private array $resolved = array();

	/**
	 * Strings that can never be stored (see InlineMarkup::survivesSanitize()):
	 * string id => reason. Nothing is sent for them.
	 *
	 * @var array<int, string>
	 */
	private array $rejected = array();

	/**
	 * Build the plan.
	 *
	 * @param array<int, array{text: string, kind: string, segment?: bool}> $strings String id => original, kind and
	 *                                                                      whether to segment an inline string (retry after a tag mismatch).
	 * @param Capabilities                                                  $caps      Provider capabilities.
	 * @param Protector                                                     $protector Shared protection.
	 * @param int                                                           $maxItems  Effective items per request.
	 * @param int                                                           $maxChars  Effective characters per request.
	 * @param bool                                                          $segmentInline Translate every inline string run by run even if HTML is supported.
	 */
	public function __construct(
		array $strings,
		Capabilities $caps,
		Protector $protector,
		int $maxItems,
		int $maxChars,
		bool $segmentInline = false
	) {
		$html  = array();
		$plain = array();
		$next  = 1;
		foreach ( $strings as $id => $string ) {
			if ( $protector->isTerm( $string['text'] ) ) {
				$this->resolved[ $id ] = array(
					'translation' => $string['text'],
					'flags'       => 0,
				);
				continue;
			}
			if ( Segment::INLINE === $string['kind'] && ! InlineMarkup::survivesSanitize( $string['text'] ) ) {
				$this->rejected[ $id ] = InlineMarkup::UNSTORABLE;
				continue;
			}
			if ( Segment::INLINE === $string['kind'] && $caps->supportsHtml && ! $segmentInline && ! ( $string['segment'] ?? false ) ) {
				[ $encoded, $map ]    = InlineTokens::encode( $string['text'] );
				$this->units[ $next ] = $protector->protect( $encoded, true );
				$html[ $next ]        = $this->units[ $next ]->text;
				$this->strings[ $id ] = array(
					'mode'     => 'html',
					'units'    => array( $next++ ),
					'original' => $string['text'],
					'map'      => $map,
				);
				continue;
			}
			if ( Segment::INLINE === $string['kind'] && ! $caps->supportsHtml && ! $segmentInline && ! ( $string['segment'] ?? false ) ) {
				// Plain-text provider: the whole sentence with tags as tokens, so
				// word order can change; a mismatch retries as segments.
				$this->units[ $next ] = $protector->protectInline( $string['text'] );
				$plain[ $next ]       = $this->units[ $next ]->text;
				$this->strings[ $id ] = array(
					'mode'     => 'sentence',
					'units'    => array( $next++ ),
					'original' => $string['text'],
				);
				continue;
			}
			if ( Segment::INLINE === $string['kind'] ) {
				$chunks = Segmenter::split( $string['text'] );
				$units  = array();
				foreach ( Segmenter::texts( $chunks ) as $text ) {
					$this->units[ $next ] = $protector->protect( $text );
					$plain[ $next ]       = $this->units[ $next ]->text;
					$units[]              = $next++;
				}
				$this->strings[ $id ] = array(
					'mode'     => 'segments',
					'units'    => $units,
					'original' => $string['text'],
					'chunks'   => $chunks,
				);
				continue;
			}
			$this->units[ $next ] = $protector->protect( $string['text'] );
			$plain[ $next ]       = $this->units[ $next ]->text;
			$this->strings[ $id ] = array(
				'mode'     => 'text',
				'units'    => array( $next++ ),
				'original' => $string['text'],
			);
		}

		$this->pack( $html, true, $maxItems, $maxChars );
		$this->pack( $plain, false, $maxItems, $maxChars );
	}

	/**
	 * Provider calls to make, in order.
	 *
	 * @return list<array{html: bool, items: array<int, string>}>
	 */
	public function requests(): array {
		return $this->requests;
	}

	/**
	 * Unit ids a string depends on; empty for strings resolved without a request.
	 *
	 * @param int $stringId String id.
	 * @return list<int>
	 */
	public function unitsOf( int $stringId ): array {
		return $this->strings[ $stringId ]['units'] ?? array();
	}

	/**
	 * Whether a string was refused without a request; retrying cannot help.
	 *
	 * @param int $stringId String id.
	 */
	public function isRejected( int $stringId ): bool {
		return isset( $this->rejected[ $stringId ] );
	}

	/**
	 * Characters that will be sent, for budgets and rate limits.
	 */
	public function chars(): int {
		$chars = 0;
		foreach ( $this->requests as $request ) {
			$chars += array_sum( array_map( 'mb_strlen', $request['items'] ) );
		}

		return $chars;
	}

	/**
	 * Assemble the outcome of every string from the provider results.
	 *
	 * @param array<int, string> $unitTranslations Unit id => provider output, for every unit that succeeded.
	 * @return array{translated: array<int, array{translation: string, flags: int}>, failed: array<int, string>}
	 */
	public function complete( array $unitTranslations ): array {
		$translated = $this->resolved;
		$failed     = $this->rejected;

		foreach ( $this->strings as $id => $plan ) {
			$restored = array();
			foreach ( $plan['units'] as $unit ) {
				$output = $unitTranslations[ $unit ] ?? null;
				if ( null === $output || '' === trim( $output ) ) {
					$failed[ $id ] = 'Empty or missing translation.';
					continue 2;
				}
				$value = $this->units[ $unit ]->restore( 'sentence' === $plan['mode'] ? Text::encodeText( $output ) : $output );
				if ( null === $value ) {
					// The "Tags " prefix makes the queue retry an inline string as segments.
					$failed[ $id ] = 'sentence' === $plan['mode'] ? 'Tags or placeholders in the translation do not match the original.' : 'A protected placeholder was lost or changed.';
					continue 2;
				}
				$restored[] = trim( $value );
			}

			if ( 'text' === $plan['mode'] ) {
				$translated[ $id ] = array(
					'translation' => $restored[0],
					'flags'       => 0,
				);
				continue;
			}

			$flags = 0;
			if ( 'sentence' === $plan['mode'] ) {
				$html = $restored[0];
			} elseif ( 'segments' === $plan['mode'] ) {
				$html   = Segmenter::join( $plan['chunks'] ?? array(), $restored );
				$flags |= self::FLAG_SEGMENTED;
			} else {
				$map  = $plan['map'] ?? array();
				$html = InlineTokens::decode( $restored[0], $map );
				if ( null === $html ) {
					$failed[ $id ] = 'Tags in the translation do not match the original.';
					continue;
				}
				if ( preg_match_all( '/<[a-z][a-z0-9]* id="\d+">/', $restored[0] ) !== count( $map ) ) {
					$flags |= self::FLAG_TAGS_REPAIRED;
				}
			}

			$html = InlineMarkup::sanitize( $plan['original'], $html );
			if ( ! InlineMarkup::sameStructure( $plan['original'], $html ) ) {
				$failed[ $id ] = 'Tags in the translation do not match the original.';
				continue;
			}
			$translated[ $id ] = array(
				'translation' => $html,
				'flags'       => $flags,
			);
		}

		return array(
			'translated' => $translated,
			'failed'     => $failed,
		);
	}

	/**
	 * Split units into requests within the caps. A single unit larger than
	 * the character cap still gets its own request; the provider rejects it
	 * if it is really too large.
	 *
	 * @param array<int, string> $units    Unit id => text.
	 * @param bool               $html     Whether the units are HTML.
	 * @param int                $maxItems Items per request.
	 * @param int                $maxChars Characters per request.
	 */
	private function pack( array $units, bool $html, int $maxItems, int $maxChars ): void {
		$current = array();
		$chars   = 0;
		foreach ( $units as $id => $text ) {
			$length = mb_strlen( $text );
			if ( array() !== $current && ( count( $current ) >= $maxItems || $chars + $length > $maxChars ) ) {
				$this->requests[] = array(
					'html'  => $html,
					'items' => $current,
				);
				$current          = array();
				$chars            = 0;
			}
			$current[ $id ] = $text;
			$chars         += $length;
		}
		if ( array() !== $current ) {
			$this->requests[] = array(
				'html'  => $html,
				'items' => $current,
			);
		}
	}
}

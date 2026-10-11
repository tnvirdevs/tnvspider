<?php
/**
 * Match report after a TranslatePress import (plan §13A.4).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Migration;

use WST\Html\Extractor;
use WST\Html\Segment;
use WST\Html\Selectors;
use WST\Languages\Language;
use WST\Routing\Urls;
use WST\Settings;
use WST\Storage\StringStore;

/**
 * Loads chosen pages in the default language as a visitor (this works
 * while TranslatePress is active, which leaves default-language pages
 * alone), extracts their strings with our extractor and reports how many
 * already have a translation, plus the most frequent ones that do not.
 * Records nothing and calls no provider.
 */
final class MatchReport {

	/** Most pages per report. */
	public const MAX_PAGES = 20;

	/** Unmatched strings listed. */
	public const TOP = 25;

	private const TIMEOUT = 20;

	/**
	 * Create the report.
	 *
	 * @param Settings    $settings Settings (exclude selectors, string length limit).
	 * @param StringStore $store    Translations.
	 * @param Urls        $urls     URL helper.
	 * @param Language    $target   Target language.
	 */
	public function __construct(
		private Settings $settings,
		private StringStore $store,
		private Urls $urls,
		private Language $target
	) {
	}

	/**
	 * Report for these paths.
	 *
	 * @param string[] $paths Site paths (default language, no prefix).
	 * @phpstan-param list<string> $paths
	 * @return array{pages: list<array{path: string, error: string, strings: int, matched: int}>, strings: int, matched: int, percent: float|null, unmatched: list<array{text: string, kind: string, pages: int}>}
	 */
	public function run( array $paths ): array {
		$pages   = array();
		$all     = array();
		$counted = array();
		foreach ( array_slice( array_values( array_unique( $paths ) ), 0, self::MAX_PAGES ) as $path ) {
			$html = $this->fetch( $path );
			if ( ! is_array( $html ) ) {
				$pages[] = array(
					'path'    => $path,
					'error'   => $html,
					'strings' => 0,
					'matched' => 0,
				);
				continue;
			}
			$strings = $this->strings( $html[0] );
			$known   = $this->store->lookup( array_keys( $strings ), $this->target->locale() );
			$matched = 0;
			foreach ( $strings as $text => $kind ) {
				$isMatched        = null !== ( $known[ $text ]['translated'] ?? null );
				$matched         += $isMatched ? 1 : 0;
				$all[ $text ]     = array( $kind, $isMatched );
				$counted[ $text ] = ( $counted[ $text ] ?? 0 ) + 1;
			}
			$pages[] = array(
				'path'    => $path,
				'error'   => '',
				'strings' => count( $strings ),
				'matched' => $matched,
			);
		}

		$unmatched = array();
		foreach ( $all as $text => [ $kind, $isMatched ] ) {
			if ( ! $isMatched ) {
				$unmatched[] = array(
					'text'  => (string) $text,
					'kind'  => $kind,
					'pages' => $counted[ $text ],
				);
			}
		}
		usort( $unmatched, static fn( array $a, array $b ): int => array( $b['pages'], mb_strlen( $b['text'] ) ) <=> array( $a['pages'], mb_strlen( $a['text'] ) ) );
		$matched = count( $all ) - count( $unmatched );

		return array(
			'pages'     => $pages,
			'strings'   => count( $all ),
			'matched'   => $matched,
			'percent'   => array() === $all ? null : round( 100 * $matched / count( $all ), 1 ),
			'unmatched' => array_slice( $unmatched, 0, self::TOP ),
		);
	}

	/**
	 * Strings of a page as the pipeline keys them.
	 *
	 * @param string $html Page.
	 * @return array<string, string> Normalised original => kind.
	 */
	private function strings( string $html ): array {
		$segments  = ( new Extractor( new Selectors( $this->settings->excludeSelectors() ) ) )->extract( $html );
		$maxLength = $this->settings->number( 'max_string_length' );
		$strings   = array();
		foreach ( $segments as $segment ) {
			$text = Segment::INLINE === $segment->kind ? $this->neutralLinks( $segment->text ) : $segment->text;
			if ( mb_strlen( $text ) <= $maxLength && ! isset( $strings[ $text ] ) ) {
				$strings[ $text ] = $segment->kind;
			}
		}

		return $strings;
	}

	/**
	 * Inline originals are stored without language prefixes in links.
	 *
	 * @param string $html Inline fragment.
	 */
	private function neutralLinks( string $html ): string {
		$prefix = $this->urls->defaultPrefix();
		if ( null === $prefix || ! str_contains( $html, 'href' ) ) {
			return $html;
		}
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
			$href = $processor->get_attribute( 'href' );
			if ( is_string( $href ) && null === $processor->get_attribute( 'hreflang' ) ) {
				$plain = $this->urls->removePrefix( $href, $prefix );
				if ( $plain !== $href ) {
					$processor->set_attribute( 'href', $plain );
				}
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * Load a default-language page as a visitor.
	 *
	 * @param string $path Site path.
	 * @return array{0: string}|string The HTML, or the reason it could not be loaded.
	 */
	private function fetch( string $path ) {
		if ( ! str_starts_with( $path, '/' ) ) {
			return __( 'Give a path that starts with /.', 'wp-site-translator' );
		}
		$response = wp_remote_get(
			home_url( $path ),
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 3,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status */
			return sprintf( __( 'The page answered with HTTP %d.', 'wp-site-translator' ), $code );
		}

		return array( wp_remote_retrieve_body( $response ) );
	}
}

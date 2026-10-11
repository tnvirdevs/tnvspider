<?php
/**
 * The same page in each language.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Routing;

use WST\Languages\Language;

/**
 * Builds the default-language and target-language URL of a request URI.
 */
final class LanguageUrls {

	/**
	 * Create the helper.
	 *
	 * @param Urls     $urls   URL helper.
	 * @param Language $target Target language.
	 * @param string   $origin Scheme and host of the home URL, e.g. https://example.com.
	 */
	public function __construct(
		private Urls $urls,
		private Language $target,
		private string $origin
	) {
	}

	/**
	 * Origin (scheme, host, port) of a home URL.
	 *
	 * @param string $homeUrl Unfiltered home URL.
	 */
	public static function origin( string $homeUrl ): string {
		$parts = wp_parse_url( $homeUrl );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return '';
		}

		return ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * URLs of the page behind $uri in both languages, or null for URIs outside
	 * the site or exempt from routing.
	 *
	 * @param string $uri       Request URI, with or without language prefix.
	 * @param bool   $keepQuery Whether to keep the query string (switcher) or drop it (hreflang).
	 * @return array{default: string, target: string}|null
	 */
	public function forUri( string $uri, bool $keepQuery ): ?array {
		$path = $this->urls->unprefixedPath( $uri, $this->target->slug() );
		if ( null === $path ) {
			return null;
		}
		$query = strpbrk( (string) strtok( $uri, '#' ), '?' );
		$plain = $this->origin . $path . ( $keepQuery && false !== $query ? $query : '' );

		return array(
			'default' => null === $this->urls->defaultPrefix() ? $plain : $this->urls->addPrefix( $plain, (string) $this->urls->defaultPrefix() ),
			'target'  => $this->urls->addPrefix( $plain, $this->target->slug() ),
		);
	}

	/**
	 * URLs of the current request.
	 *
	 * @param bool $keepQuery Whether to keep the query string.
	 * @return array{default: string, target: string}|null
	 */
	public function forCurrentRequest( bool $keepQuery ): ?array {
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only parsed; output is escaped by callers.

		return $this->forUri( $uri, $keepQuery );
	}
}

<?php
/**
 * Pure URL helpers for language prefixes.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Routing;

/**
 * Adds, detects and strips the /{slug}/ language prefix. All paths are
 * handled relative to the site's home path, so sub-directory installs work.
 */
final class Urls {

	/**
	 * Create the helper.
	 *
	 * @param string $homePath   Path of the home URL without trailing slash, '' for the domain root.
	 * @param string $homeHost   Host of the home URL, lower case.
	 * @param string $restPrefix REST URL prefix, usually wp-json.
	 */
	public function __construct(
		private string $homePath,
		private string $homeHost,
		private string $restPrefix
	) {
		$this->homePath = rtrim( $homePath, '/' );
		$this->homeHost = strtolower( $homeHost );
	}

	/**
	 * Build from a home URL.
	 *
	 * @param string $homeUrl    Unfiltered home URL.
	 * @param string $restPrefix REST URL prefix.
	 */
	public static function fromHome( string $homeUrl, string $restPrefix ): self {
		$parts = wp_parse_url( $homeUrl );
		$parts = is_array( $parts ) ? $parts : array();

		return new self( (string) ( $parts['path'] ?? '' ), (string) ( $parts['host'] ?? '' ), $restPrefix );
	}

	/**
	 * Path of a request URI relative to the home path, without leading slash
	 * and without query string. Null when the URI is outside the home path.
	 *
	 * @param string $uri Request URI or URL path.
	 */
	public function relativePath( string $uri ): ?string {
		$path = (string) strtok( $uri, '?#' );
		if ( '' === $path ) {
			$path = '/';
		}
		if ( '' !== $this->homePath ) {
			if ( $path !== $this->homePath && ! str_starts_with( $path, $this->homePath . '/' ) ) {
				return null;
			}
			$path = substr( $path, strlen( $this->homePath ) );
		}

		return ltrim( $path, '/' );
	}

	/**
	 * Whether a relative path belongs to WordPress internals that are never
	 * served in a language (admin, login, REST, cron, XML-RPC, robots,
	 * sitemaps, static directories, PHP entry points).
	 *
	 * @param string $relativePath Path from relativePath().
	 */
	public function isExempt( string $relativePath ): bool {
		$first = strtolower( (string) strtok( $relativePath, '/' ) );
		if ( '' === $first ) {
			return false;
		}
		if ( in_array( $first, array( 'wp-admin', 'wp-content', 'wp-includes', 'robots.txt', 'favicon.ico', strtolower( $this->restPrefix ) ), true ) ) {
			return true;
		}
		if ( str_ends_with( $first, '.php' ) ) {
			return true;
		}

		return 1 === preg_match( '/^wp-sitemap.*\.(xml|xsl)$/', $first );
	}

	/**
	 * Whether the relative path carries the language prefix.
	 *
	 * @param string $relativePath Path from relativePath().
	 * @param string $slug         Language slug.
	 */
	public function hasPrefix( string $relativePath, string $slug ): bool {
		return $relativePath === $slug || str_starts_with( $relativePath, $slug . '/' );
	}

	/**
	 * Remove the language prefix from a request URI, keeping the query string.
	 *
	 * @param string $uri  Request URI.
	 * @param string $slug Language slug.
	 */
	public function stripPrefix( string $uri, string $slug ): string {
		$relative = $this->relativePath( $uri );
		if ( null === $relative || ! $this->hasPrefix( $relative, $slug ) ) {
			return $uri;
		}
		$query = strpbrk( $uri, '?#' );
		$rest  = substr( $relative, strlen( $slug ) + 1 );

		return $this->homePath . '/' . $rest . ( false === $query ? '' : $query );
	}

	/**
	 * Site path of a request URI without the language prefix and without the
	 * query string, e.g. /blog/shop/ for /blog/bn/shop/?x=1. Null when the URI
	 * is outside the home path or exempt.
	 *
	 * @param string $uri  Request URI.
	 * @param string $slug Language slug.
	 */
	public function unprefixedPath( string $uri, string $slug ): ?string {
		$relative = $this->relativePath( $uri );
		if ( null === $relative || $this->isExempt( $relative ) ) {
			return null;
		}
		if ( $this->hasPrefix( $relative, $slug ) ) {
			$relative = substr( $relative, strlen( $slug ) + 1 );
		}

		return $this->homePath . '/' . $relative;
	}

	/**
	 * Add the language prefix to an internal URL or path. External URLs,
	 * exempt paths and already prefixed URLs are returned unchanged.
	 *
	 * @param string $url  Absolute URL, protocol-relative URL or root-relative path.
	 * @param string $slug Language slug.
	 */
	public function addPrefix( string $url, string $slug ): string {
		$split = $this->splitInternal( $url );
		if ( null === $split ) {
			return $url;
		}
		[ $origin, $path, $suffix ] = $split;
		$relative                   = $this->relativePath( $path );
		if ( null === $relative || $this->isExempt( $relative ) || $this->hasPrefix( $relative, $slug ) ) {
			return $url;
		}

		return $origin . $this->homePath . '/' . $slug . '/' . $relative . $suffix;
	}

	/**
	 * Split an internal URL into origin, path and query/fragment suffix.
	 * Null for external, relative-to-document or non-HTTP URLs.
	 *
	 * @param string $url URL or path.
	 * @return array{0: string, 1: string, 2: string}|null
	 */
	private function splitInternal( string $url ): ?array {
		if ( 1 === preg_match( '#^(https?:)?//([^/?\#]+)(.*)$#i', $url, $m ) ) {
			$host = strtolower( (string) preg_replace( '/:\d+$/', '', $m[2] ) );
			if ( $host !== $this->homeHost ) {
				return null;
			}
			$origin = $m[1] . '//' . $m[2];
			$rest   = '' === $m[3] ? '/' : $m[3];
		} elseif ( str_starts_with( $url, '/' ) ) {
			$origin = '';
			$rest   = $url;
		} else {
			return null;
		}
		if ( '/' !== $rest[0] ) {
			$rest = '/' . $rest;
		}
		$cut = strcspn( $rest, '?#' );

		return array( $origin, substr( $rest, 0, $cut ), substr( $rest, $cut ) );
	}
}

<?php
/**
 * Path rules: exact paths, trailing * wildcard and the {{home}} token.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Routing;

/**
 * Matches a site path against path rules such as "/shop/*" or "{{home}}".
 * Paths and rules are compared without query string and trailing slash.
 */
final class PathRules {

	/**
	 * Whether $path matches any rule.
	 *
	 * @param string   $path  Site path without language prefix.
	 * @param string[] $rules Rules.
	 */
	public static function matchesAny( string $path, array $rules ): bool {
		foreach ( $rules as $rule ) {
			if ( self::matches( $path, $rule ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether $path matches one rule. "{{home}}" is the home page; "/shop/*"
	 * matches /shop and everything below it; "/sale*" matches any path that
	 * starts with /sale.
	 *
	 * @param string $path Site path.
	 * @param string $rule Rule.
	 */
	public static function matches( string $path, string $rule ): bool {
		$path = self::normalize( $path );
		$rule = trim( $rule );
		if ( '{{home}}' === $rule ) {
			return '/' === $path;
		}
		if ( str_ends_with( $rule, '*' ) ) {
			$prefix = rtrim( $rule, '*' );
			$base   = self::normalize( $prefix );
			if ( str_ends_with( $prefix, '/' ) ) {
				// "/shop/*": the section and everything below it.
				return $path === $base || str_starts_with( $path, rtrim( $base, '/' ) . '/' );
			}

			// "/hel*": any path starting with these characters.
			return str_starts_with( $path, $base );
		}

		return self::normalize( $rule ) === $path;
	}

	/**
	 * Leading slash, no trailing slash, no query string, lower case.
	 *
	 * @param string $path Path.
	 */
	private static function normalize( string $path ): string {
		return '/' . trim( strtolower( (string) strtok( $path, '?#' ) ), '/' );
	}
}

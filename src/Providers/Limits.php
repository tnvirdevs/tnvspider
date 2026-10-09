<?php
/**
 * Effective limits of one provider (plan §8).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

use WST\Settings;

/**
 * User settings combined with provider defaults and hard caps. For every
 * rate: 0 means unlimited.
 */
final class Limits {

	/**
	 * Create limits.
	 *
	 * @param int    $rpm           Requests per minute.
	 * @param int    $rpd           Requests per day.
	 * @param int    $cpm           Characters per minute.
	 * @param int    $maxItems      Items per request.
	 * @param int    $maxChars      Characters per request.
	 * @param int    $concurrency   Parallel workers.
	 * @param int    $maxAttempts   Attempts per string before it fails.
	 * @param int    $monthlyCap    Characters per calendar month.
	 * @param int    $timeout       HTTP timeout in seconds.
	 * @param string $dayTimezone Time zone of the daily window.
	 */
	public function __construct(
		public int $rpm,
		public int $rpd,
		public int $cpm,
		public int $maxItems,
		public int $maxChars,
		public int $concurrency = 1,
		public int $maxAttempts = 5,
		public int $monthlyCap = 0,
		public int $timeout = 30,
		public string $dayTimezone = 'UTC'
	) {
	}

	/**
	 * Effective limits: user value where given, else the provider default;
	 * items and characters per request never exceed the provider caps
	 * (0 = use the cap).
	 *
	 * @param Settings     $settings Settings.
	 * @param string       $id       Provider id.
	 * @param Capabilities $caps     Provider capabilities.
	 */
	public static function resolve( Settings $settings, string $id, Capabilities $caps ): self {
		$given = $settings->providerSettings( $id );
		$int   = static fn( string $key, int $fallback ): int => isset( $given[ $key ] ) ? (int) $given[ $key ] : $fallback;
		$cap   = static fn( int $user, int $hard ): int => 0 === $user ? $hard : min( $user, $hard );

		return new self(
			$int( 'requests_per_minute', $caps->defaultRpm ),
			$int( 'requests_per_day', $caps->defaultRpd ),
			$int( 'chars_per_minute', $caps->defaultCpm ),
			$cap( $int( 'max_items_per_request', 0 ), $caps->maxItems ),
			$cap( $int( 'max_chars_per_request', 0 ), $caps->maxChars ),
			$int( 'concurrency', 1 ),
			$int( 'max_attempts', 5 ),
			$int( 'monthly_char_cap', 0 ),
			$int( 'timeout', 30 ),
			$caps->dayTimezone
		);
	}
}

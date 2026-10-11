<?php
/**
 * What a provider can do and its hard limits.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

/**
 * Hard caps are what the provider accepts at all; user settings can only
 * lower them (plan §8).
 */
final class Capabilities {

	/**
	 * Describe a provider.
	 *
	 * @param bool   $supportsHtml       Whether HTML can be sent (inline segments keep their tags).
	 * @param int    $maxItems           Hard cap of items per request.
	 * @param int    $maxChars           Hard cap of characters per request.
	 * @param int    $defaultRpm         Default requests per minute (0 = unlimited).
	 * @param int    $defaultRpd         Default requests per day (0 = unlimited).
	 * @param int    $defaultCpm         Default characters per minute (0 = unlimited).
	 * @param string $dayTimezone      Time zone in which the provider's daily quota resets.
	 * @param string $tokenFormat      Placeholder token format that survives this provider (one %d).
	 */
	public function __construct(
		public bool $supportsHtml,
		public int $maxItems,
		public int $maxChars,
		public int $defaultRpm = 0,
		public int $defaultRpd = 0,
		public int $defaultCpm = 0,
		public string $dayTimezone = 'UTC',
		public string $tokenFormat = Protection\Protector::DEFAULT_FORMAT
	) {
	}
}

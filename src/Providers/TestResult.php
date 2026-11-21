<?php
/**
 * Outcome of a provider connection test.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

/**
 * Shown to the site owner; never contains secrets.
 */
final class TestResult {

	/**
	 * Create a result.
	 *
	 * @param bool                 $ok      Whether the provider answered correctly.
	 * @param string               $message Human-readable outcome.
	 * @param array<string, mixed> $details Non-secret details, e.g. sample translation or language count.
	 */
	public function __construct(
		public bool $ok,
		public string $message,
		public array $details = array()
	) {
	}
}

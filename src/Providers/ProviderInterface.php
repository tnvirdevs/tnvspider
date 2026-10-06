<?php
/**
 * Contract every machine translation provider implements (plan §7).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

use WST\Providers\Errors\ProviderError;

/**
 * A machine translation service. Implementations are stateless HTTP
 * adapters; batching, rate limiting and retries belong to the queue.
 */
interface ProviderInterface {

	/**
	 * Stable id: translatex, microsoft or gemini.
	 */
	public function id(): string;

	/**
	 * Human-readable name.
	 */
	public function label(): string;

	/**
	 * Limits and features of this provider with the current settings.
	 */
	public function capabilities(): Capabilities;

	/**
	 * Whether the provider can translate between two locales.
	 *
	 * @param string $source Source locale.
	 * @param string $target Target locale.
	 * @throws ProviderError When support cannot be determined (e.g. auth failure).
	 */
	public function supportsPair( string $source, string $target ): bool;

	/**
	 * Translate a batch. Items are plain text, or HTML when
	 * capabilities()->supportsHtml and $html is true.
	 *
	 * @param array<int, string> $items  String id => text, already protected.
	 * @param string             $source Source locale.
	 * @param string             $target Target locale.
	 * @param bool               $html   Whether the items are HTML.
	 * @throws ProviderError For failures of the whole batch.
	 */
	public function translate( array $items, string $source, string $target, bool $html ): BatchResult;

	/**
	 * Check credentials and connectivity with one small request.
	 */
	public function testConnection(): TestResult;
}

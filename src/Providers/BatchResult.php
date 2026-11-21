<?php
/**
 * Outcome of one translate request.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

/**
 * Per-item results of a batch. An item is either translated or failed;
 * failed items are retried by the queue.
 */
final class BatchResult {

	/**
	 * Error prefix for a response whose result count differs from the input;
	 * the queue retries such rows one per request (see Worker).
	 */
	public const COUNT_MISMATCH = 'Result count mismatch';

	/** Error prefix for a request the provider found too large; retried the same way. */
	public const TOO_LARGE = 'Request too large';

	/**
	 * Translations by string id.
	 *
	 * @var array<int, string>
	 */
	public array $translations = array();

	/**
	 * Error messages by string id.
	 *
	 * @var array<int, string>
	 */
	public array $errors = array();

	/**
	 * Characters the provider bills for this request.
	 *
	 * @var int
	 */
	public int $charsBilled = 0;

	/**
	 * Requests the provider says are left in its own window, if reported.
	 *
	 * @var int|null
	 */
	public ?int $rateLimitRemaining = null;
}

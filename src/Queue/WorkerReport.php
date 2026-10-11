<?php
/**
 * What one worker run did.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Queue;

/**
 * Counters for CLI output, the admin runner and tests.
 */
final class WorkerReport {

	/**
	 * Batches claimed.
	 *
	 * @var int
	 */
	public int $batches = 0;

	/**
	 * Provider requests sent.
	 *
	 * @var int
	 */
	public int $requests = 0;

	/**
	 * Strings translated.
	 *
	 * @var int
	 */
	public int $translated = 0;

	/**
	 * Failed attempts counted.
	 *
	 * @var int
	 */
	public int $failed = 0;

	/**
	 * Shortest wait reported by the rate limiter or a provider, 0 when none.
	 *
	 * @var float
	 */
	public float $wait = 0.0;

	/**
	 * Provider id => reason it could not be used.
	 *
	 * @var array<string, string>
	 */
	public array $blocked = array();

	/**
	 * Provider id => fallback that took over its rows.
	 *
	 * @var array<string, string>
	 */
	public array $fallbacks = array();

	/**
	 * Record a wait; the shortest one decides when to run again.
	 *
	 * @param float $seconds Seconds.
	 */
	public function noteWait( float $seconds ): void {
		$this->wait = 0.0 === $this->wait ? $seconds : min( $this->wait, $seconds );
	}
}

<?php
/**
 * WP-CLI: wp wst queue run|status|retry-failed|clear.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Cli;

use WST\Providers\Selector;
use WST\Providers\StatusReport;
use WST\Queue\Queue;
use WST\Queue\Scheduler;
use WST\Queue\Usage;
use WST\Queue\Worker;
use WST\Settings;

/**
 * Run and inspect the machine-translation queue.
 */
final class QueueCommand {

	/**
	 * Builds the worker on demand.
	 *
	 * @var callable(): Worker
	 */
	private $workerFactory;

	/**
	 * Create the command.
	 *
	 * @param Settings     $settings      Settings.
	 * @param Queue        $queue         Queue.
	 * @param Scheduler    $scheduler     Cron runner.
	 * @param Selector     $selector      Active provider.
	 * @param StatusReport $status        Provider status.
	 * @param callable     $workerFactory Returns the worker.
	 * @phpstan-param callable(): Worker $workerFactory
	 */
	public function __construct(
		private Settings $settings,
		private Queue $queue,
		private Scheduler $scheduler,
		private Selector $selector,
		private StatusReport $status,
		callable $workerFactory
	) {
		$this->workerFactory = $workerFactory;
	}

	/**
	 * Translate queued strings now, within the rate limits.
	 *
	 * ## OPTIONS
	 *
	 * [--batches=<n>]
	 * : Stop after this many batches. Default: until the time budget is used or nothing is due.
	 *
	 * [--budget=<seconds>]
	 * : Time budget.
	 * ---
	 * default: 20
	 * ---
	 *
	 * @param string[]              $args  Positional arguments.
	 * @param array<string, string> $assoc Options.
	 * @phpstan-param list<string>  $args
	 */
	public function run( array $args, array $assoc ): void {
		unset( $args );
		$batches = isset( $assoc['batches'] ) ? max( 1, (int) $assoc['batches'] ) : PHP_INT_MAX;
		$budget  = max( 1.0, (float) ( $assoc['budget'] ?? Worker::TIME_BUDGET ) );
		$report  = ( $this->workerFactory )()->run( $budget, $batches );
		$this->scheduler->stopWhenIdle();

		\WP_CLI::line( sprintf( 'Batches: %d, requests: %d, translated: %d, failed: %d.', $report->batches, $report->requests, $report->translated, $report->failed ) );
		if ( $report->wait > 0 ) {
			\WP_CLI::line( sprintf( 'Rate limit: next request allowed in %.1f s.', $report->wait ) );
		}
		foreach ( $report->fallbacks as $from => $to ) {
			\WP_CLI::line( sprintf( 'Moved %s rows to the fallback provider %s.', $from, $to ) );
		}
		foreach ( $report->blocked as $id => $reason ) {
			\WP_CLI::line( sprintf( 'Blocked %s: %s', $id, $reason ) );
		}
		\WP_CLI::success( sprintf( '%d pending.', $this->queue->stats()['pending'] ) );
	}

	/**
	 * Show queue counts, provider state and monthly usage.
	 *
	 * @param string[]              $args  Positional arguments.
	 * @param array<string, string> $assoc Options.
	 * @phpstan-param list<string>  $args
	 */
	public function status( array $args, array $assoc ): void {
		unset( $args, $assoc );
		$stats  = $this->queue->stats();
		$period = Usage::period();
		\WP_CLI::line( sprintf( 'Pending: %d, processing: %d, failed: %d, characters waiting: %d.', $stats['pending'], $stats['processing'], $stats['failed'], $stats['chars_pending'] ) );

		$target = $this->settings->targetLanguage();
		if ( null !== $target ) {
			$active = $this->selector->active( $this->settings->defaultLanguage(), $target, $period );
			\WP_CLI::line(
				null === $active
					? 'Active provider: none.'
					: 'Active provider: ' . $active['id'] . ( '' === $active['fallbackReason'] ? '' : ' (fallback: ' . $active['fallbackReason'] . ')' )
			);
		}

		$rows = array();
		foreach ( $this->status->all() as $provider ) {
			$rows[] = array(
				'provider'   => $provider['id'] . ( '' === $provider['role'] ? '' : ' (' . $provider['role'] . ')' ),
				'configured' => $provider['configured'] ? 'yes' : 'no key',
				'tested'     => null === $provider['verified_at'] ? 'not tested yet' : wp_date( 'Y-m-d H:i', $provider['verified_at'] ),
				'languages'  => $provider['any_language'] ? 'any' : ( null === $provider['languages'] ? 'not loaded yet' : (string) $provider['languages'] ),
				'state'      => '' === $provider['unavailable'] ? 'ok' : $provider['unavailable'],
				'queued'     => $provider['queued'],
				'chars_used' => $provider['usage']['chars'] . ( $provider['usage']['cap'] > 0 ? ' / ' . $provider['usage']['cap'] : '' ),
				'last_error' => $provider['last_error'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'provider', 'configured', 'tested', 'languages', 'state', 'queued', 'chars_used', 'last_error' ) );
		foreach ( $this->status->all() as $provider ) {
			if ( $provider['configured'] && null === $provider['verified_at'] ) {
				\WP_CLI::line( sprintf( '%s has not passed a connection test with its current key: run "wp wst provider test %s".', $provider['label'], $provider['id'] ) );
			}
		}

		$next = wp_next_scheduled( Scheduler::HOOK );
		\WP_CLI::line( false === $next ? 'Cron: not scheduled (no queued work).' : 'Cron: next run ' . wp_date( 'Y-m-d H:i:s', $next ) . '.' );
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			\WP_CLI::line( 'DISABLE_WP_CRON is set. Run the queue from the server cron every minute, e.g.: * * * * * wp --path=' . ABSPATH . ' cron event run --due-now' );
		}
	}

	/**
	 * Give every failed string a fresh set of attempts.
	 *
	 * @subcommand retry-failed
	 *
	 * @param string[]              $args  Positional arguments.
	 * @param array<string, string> $assoc Options.
	 * @phpstan-param list<string>  $args
	 */
	public function retryFailed( array $args, array $assoc ): void {
		unset( $args, $assoc );
		$count = $this->queue->retryFailed( time() );
		if ( $count > 0 ) {
			$this->scheduler->ensure();
		}
		\WP_CLI::success( sprintf( '%d failed rows queued again.', $count ) );
	}

	/**
	 * Delete queue rows. Stored translations are not touched.
	 *
	 * ## OPTIONS
	 *
	 * [--state=<state>]
	 * : Only rows in this state.
	 * ---
	 * options:
	 *   - pending
	 *   - processing
	 *   - failed
	 * ---
	 *
	 * @param string[]              $args  Positional arguments.
	 * @param array<string, string> $assoc Options.
	 * @phpstan-param list<string>  $args
	 */
	public function clear( array $args, array $assoc ): void {
		unset( $args );
		$state = $assoc['state'] ?? null;
		if ( null !== $state && ! in_array( $state, array( 'pending', 'processing', 'failed' ), true ) ) {
			\WP_CLI::error( 'Unknown state: ' . $state );
		}
		$count = $this->queue->clear( $state );
		$this->scheduler->stopWhenIdle();
		\WP_CLI::success( sprintf( '%d rows deleted.', $count ) );
	}
}

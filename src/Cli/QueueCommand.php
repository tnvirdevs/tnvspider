<?php
/**
 * WP-CLI: wp wst queue run|status|retry-failed|clear.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Cli;

use WST\Providers\Limits;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\Selector;
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
	 * @param Settings         $settings      Settings.
	 * @param Queue            $queue         Queue.
	 * @param Scheduler        $scheduler     Cron runner.
	 * @param ProviderRegistry $providers     Configured adapters.
	 * @param ProviderState    $state         Pauses and quotas.
	 * @param Usage            $usage         Monthly usage.
	 * @param callable         $workerFactory Returns the worker.
	 * @phpstan-param callable(): Worker $workerFactory
	 */
	public function __construct(
		private Settings $settings,
		private Queue $queue,
		private Scheduler $scheduler,
		private ProviderRegistry $providers,
		private ProviderState $state,
		private Usage $usage,
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
			$active = ( new Selector( $this->settings, $this->providers, $this->state ) )->active( $this->settings->defaultLanguage(), $target, $period );
			\WP_CLI::line(
				null === $active
					? 'Active provider: none.'
					: 'Active provider: ' . $active['id'] . ( '' === $active['fallbackReason'] ? '' : ' (fallback: ' . $active['fallbackReason'] . ')' )
			);
		}

		$rows = array();
		foreach ( Settings::PROVIDER_IDS as $id ) {
			$adapter = $this->providers->get( $id );
			$cap     = null === $adapter ? 0 : Limits::resolve( $this->settings, $id, $adapter->capabilities() )->monthlyCap;
			$rows[]  = array(
				'provider'   => $id,
				'configured' => null === $adapter ? 'no key' : 'yes',
				'state'      => $this->state->unavailableReason( $id, $period ),
				'queued'     => $stats['by_provider'][ $id ] ?? 0,
				'chars_used' => $this->usage->chars( $id, $period ) . ( $cap > 0 ? ' / ' . $cap : '' ),
				'last_error' => $stats['last_errors'][ $id ] ?? '',
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'provider', 'configured', 'state', 'queued', 'chars_used', 'last_error' ) );

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

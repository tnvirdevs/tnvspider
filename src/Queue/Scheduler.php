<?php
/**
 * WP-Cron trigger for the queue worker (plan §8).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Queue;

use WST\Config;

/**
 * A one-minute cron event that exists only while the queue has work.
 */
final class Scheduler {

	public const HOOK     = Config::PREFIX . 'queue_run';
	public const INTERVAL = Config::PREFIX . 'every_minute';

	/**
	 * Builds the worker on demand, so ordinary requests never construct it.
	 *
	 * @var callable(): Worker
	 */
	private $workerFactory;

	/**
	 * Create the scheduler.
	 *
	 * @param Queue    $queue         Queue.
	 * @param callable $workerFactory Returns the worker.
	 * @phpstan-param callable(): Worker $workerFactory
	 */
	public function __construct( private Queue $queue, callable $workerFactory ) {
		$this->workerFactory = $workerFactory;
	}

	/**
	 * Register the interval and the event handler.
	 */
	public function boot(): void {
		add_filter( 'cron_schedules', array( $this, 'addInterval' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- One minute is the plan's interval; the event only exists while work is queued.
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Add the one-minute interval (plan §8: the event exists only while
	 * work is queued).
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function addInterval( $schedules ): array {
		$schedules                   = is_array( $schedules ) ? $schedules : array();
		$schedules[ self::INTERVAL ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => 'Every minute (WP Site Translator queue)',
		);

		return $schedules;
	}

	/**
	 * Make sure the event is scheduled.
	 *
	 * @throws \RuntimeException When WordPress refuses to schedule it.
	 */
	public function ensure(): void {
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		$scheduled = wp_schedule_event( time(), self::INTERVAL, self::HOOK, array(), true );
		if ( is_wp_error( $scheduled ) ) {
			throw new \RuntimeException( 'Could not schedule the translation queue: ' . esc_html( $scheduled->get_error_message() ) );
		}
	}

	/**
	 * Cron handler: run the worker, then drop the event when nothing is left.
	 */
	public function run(): void {
		( $this->workerFactory )()->run();
		$this->stopWhenIdle();
	}

	/**
	 * Remove the event when the queue has no pending or processing rows.
	 */
	public function stopWhenIdle(): void {
		if ( ! $this->queue->hasWork() ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}
}

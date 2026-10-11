<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Queue;

use WP_UnitTestCase;
use WST\Database\Schema;
use WST\Queue\Queue;
use WST\Queue\Scheduler;
use WST\Queue\Worker;

final class SchedulerTest extends WP_UnitTestCase {

	private Queue $queue;

	private int $runs = 0;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->queue = new Queue( $wpdb, new Schema( $wpdb ) );
		wp_clear_scheduled_hook( Scheduler::HOOK );
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( Scheduler::HOOK );
		parent::tear_down();
	}

	private function scheduler(): Scheduler {
		$scheduler = new Scheduler(
			$this->queue,
			function (): Worker {
				++$this->runs;
				throw new \LogicException( 'Worker built.' );
			}
		);
		$scheduler->boot();

		return $scheduler;
	}

	public function test_ensure_schedules_one_minute_event_once(): void {
		$scheduler = $this->scheduler();
		$scheduler->ensure();
		$scheduler->ensure();

		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK ) );
		$this->assertSame( Scheduler::INTERVAL, wp_get_schedule( Scheduler::HOOK ) );
		$this->assertSame( MINUTE_IN_SECONDS, wp_get_schedules()[ Scheduler::INTERVAL ]['interval'] );
		$this->assertCount( 1, array_filter( _get_cron_array(), static fn( array $hooks ): bool => isset( $hooks[ Scheduler::HOOK ] ) ) );
	}

	public function test_event_is_dropped_only_when_the_queue_is_empty(): void {
		$scheduler = $this->scheduler();
		$scheduler->ensure();
		$this->queue->enqueue( array( 42 ), 'bn_BD', 'translatex', Queue::PRIORITY_VISITOR, time() );

		$scheduler->stopWhenIdle();
		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK ), 'Work is queued.' );

		$this->queue->clear();
		$scheduler->stopWhenIdle();
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK ) );
	}

	public function test_cron_hook_runs_the_worker(): void {
		$this->scheduler();

		try {
			do_action( Scheduler::HOOK ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Prefixed constant.
			$this->fail( 'Expected the worker to be built.' );
		} catch ( \LogicException $e ) {
			$this->assertSame( 1, $this->runs );
		}
	}
}

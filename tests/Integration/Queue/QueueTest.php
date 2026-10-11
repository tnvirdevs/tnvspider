<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Queue;

use WP_UnitTestCase;
use WST\Database\Schema;
use WST\Queue\Queue;
use WST\Storage\StringStore;

final class QueueTest extends WP_UnitTestCase {

	private const NOW = 1_800_000_000;

	private Queue $queue;

	private StringStore $store;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$schema      = new Schema( $wpdb );
		$this->queue = new Queue( $wpdb, $schema );
		$this->store = new StringStore( $wpdb, $schema );
	}

	/**
	 * @param array<string, string> $strings Text => kind.
	 * @return array<string, int>
	 */
	private function strings( array $strings ): array {
		return $this->store->recordOnPage( $strings, '/', null );
	}

	public function test_claim_orders_by_priority_and_respects_limits(): void {
		$ids = $this->strings(
			array(
				'aaaaaaaaaa' => 'text',
				'bbbbbbbbbb' => 'text',
				'cccccccccc' => 'text',
			)
		);
		$this->queue->enqueue( array( $ids['aaaaaaaaaa'], $ids['bbbbbbbbbb'] ), 'bn_BD', 'p', Queue::PRIORITY_BULK, self::NOW );
		$this->queue->enqueue( array( $ids['cccccccccc'] ), 'bn_BD', 'p', Queue::PRIORITY_EDITOR, self::NOW );

		$claimed = $this->queue->claim( 'p', 'bn_BD', 10, 25, 60, self::NOW );

		$this->assertSame( array( 'cccccccccc', 'aaaaaaaaaa' ), array_column( $claimed, 'text' ), 'Editor priority first; 25 characters fit two strings.' );
		$this->assertSame( array(), $this->queue->claim( 'p', 'en_US', 10, 1000, 60, self::NOW ) );
		$this->assertCount( 1, $this->queue->claim( 'p', 'bn_BD', 10, 1000, 60, self::NOW ), 'Claimed rows are not claimed twice.' );
	}

	public function test_expired_claims_are_due_again(): void {
		$ids = $this->strings( array( 'Hello' => 'text' ) );
		$this->queue->enqueue( array_values( $ids ), 'bn_BD', 'p', Queue::PRIORITY_VISITOR, self::NOW );

		$this->assertCount( 1, $this->queue->claim( 'p', 'bn_BD', 10, 1000, 60, self::NOW ) );
		$this->assertCount( 0, $this->queue->claim( 'p', 'bn_BD', 10, 1000, 60, self::NOW + 30 ) );
		$this->assertCount( 1, $this->queue->claim( 'p', 'bn_BD', 10, 1000, 60, self::NOW + 61 ), 'A crashed worker\'s claim expires.' );
	}

	public function test_requeue_raises_priority_and_explicit_requests_revive_failed_rows(): void {
		$ids = $this->strings( array( 'Hello' => 'text' ) );
		$this->queue->enqueue( array_values( $ids ), 'bn_BD', 'p', Queue::PRIORITY_BULK, self::NOW );
		$row = $this->queue->claim( 'p', 'bn_BD', 10, 1000, 60, self::NOW )[0];
		$this->queue->fail( $row, 'boom', 1, '', self::NOW );
		$this->assertSame( 1, $this->queue->stats()['failed'] );

		$this->queue->enqueue( array_values( $ids ), 'bn_BD', 'p', Queue::PRIORITY_VISITOR, self::NOW );
		$this->assertSame( 1, $this->queue->stats()['failed'], 'Visitor discovery does not revive failed rows.' );

		$this->queue->enqueue( array_values( $ids ), 'bn_BD', 'p', Queue::PRIORITY_PAGE_NOW, self::NOW );
		$this->assertSame( 0, $this->queue->stats()['failed'] );
		$this->assertCount( 1, $this->queue->claim( 'p', 'bn_BD', 10, 1000, 60, self::NOW ) );
	}

	public function test_fail_backs_off_then_hands_over_to_the_fallback(): void {
		$ids = $this->strings( array( 'Hello' => 'text' ) );
		$this->queue->enqueue( array_values( $ids ), 'bn_BD', 'p', Queue::PRIORITY_VISITOR, self::NOW );
		$row = $this->queue->claim( 'p', 'bn_BD', 10, 1000, 60, self::NOW )[0];

		$this->queue->fail( $row, 'HTTP 503', 2, 'f', self::NOW );
		$this->assertCount( 0, $this->queue->claim( 'p', 'bn_BD', 10, 1000, 60, self::NOW + Queue::BACKOFF_BASE - 1 ) );
		$row = $this->queue->claim( 'p', 'bn_BD', 10, 1000, 60, self::NOW + Queue::BACKOFF_BASE )[0];

		$this->queue->fail( $row, 'HTTP 503', 2, 'f', self::NOW );
		$this->assertSame( 0, $this->queue->stats()['failed'] );
		$this->assertSame( array( 'f' => 1 ), $this->queue->stats()['by_provider'] );
		$this->assertSame( 0, $this->queue->claim( 'f', 'bn_BD', 10, 1000, 60, self::NOW )[0]['attempts'] ?? -1, 'Fresh attempts on the fallback.' );
	}

	public function test_manual_save_removes_queued_rows(): void {
		$ids = $this->strings( array( 'Hello' => 'text' ) );
		$this->queue->enqueue( array_values( $ids ), 'bn_BD', 'p', Queue::PRIORITY_VISITOR, self::NOW );

		$this->store->saveManual( 'Hello', 'text', 'bn_BD', 'হ্যালো', 0 );

		$this->assertFalse( $this->queue->hasWork() );
	}

	public function test_retry_failed_and_clear(): void {
		$ids = $this->strings(
			array(
				'Hello' => 'text',
				'World' => 'text',
			)
		);
		$this->queue->enqueue( array_values( $ids ), 'bn_BD', 'p', Queue::PRIORITY_VISITOR, self::NOW );
		foreach ( $this->queue->claim( 'p', 'bn_BD', 10, 1000, 60, self::NOW ) as $row ) {
			$this->queue->fail( $row, 'boom', 1, '', self::NOW );
		}

		$this->assertSame( 2, $this->queue->retryFailed( self::NOW ) );
		$this->assertSame( 2, $this->queue->stats()['pending'] );
		$this->assertSame( 2, $this->queue->clear() );
		$this->assertFalse( $this->queue->hasWork() );
	}
}

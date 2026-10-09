<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Log;

use WP_UnitTestCase;
use WST\Database\Schema;
use WST\Log\Logger;

final class LoggerTest extends WP_UnitTestCase {

	public function test_writes_entries_and_keeps_only_the_latest(): void {
		global $wpdb;
		$schema = new Schema( $wpdb );
		$logger = new Logger( $wpdb, $schema );
		$table  = $schema->table( 'log' );

		$logger->error( 'render', 'Boom', array( 'path' => '/bn/' ) );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT level, source, message, context FROM %i ORDER BY id DESC LIMIT 1', $table ) );
		$this->assertSame( array( 'error', 'render', 'Boom', '{"path":"\/bn\/"}' ), array( $row->level, $row->source, $row->message, $row->context ) );

		for ( $i = 0; $i < Logger::KEEP + 5; $i++ ) {
			$logger->warning( 'test', 'Entry ' . $i );
		}
		$this->assertSame( (string) Logger::KEEP, $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) );
	}

	public function test_error_level_skips_warnings(): void {
		global $wpdb;
		$schema = new Schema( $wpdb );
		$logger = new Logger( $wpdb, $schema, 'error' );

		$logger->warning( 'queue', 'Quiet' );
		$logger->error( 'queue', 'Loud' );

		$this->assertSame( array( 'Loud' ), array_column( $logger->recent( 10, null, 'queue' ), 'message' ) );
	}

	public function test_recent_and_count_since(): void {
		global $wpdb;
		$schema = new Schema( $wpdb );
		$logger = new Logger( $wpdb, $schema );
		$logger->error( 'render', 'Old' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = %s', $schema->table( 'log' ), gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );
		$logger->error( 'render', 'New', array( 'path' => '/bn/' ) );
		$logger->warning( 'render', 'Warn' );
		$logger->error( 'queue', 'Other' );

		$this->assertSame( 1, $logger->countSince( 'error', 'render', DAY_IN_SECONDS ) );
		$this->assertSame( array( 'Other', 'New', 'Old' ), array_column( $logger->recent( 10, 'error' ), 'message' ), 'Newest first.' );
		$latest = $logger->recent( 1, 'error', 'render' )[0];
		$this->assertSame( array( 'path' => '/bn/' ), $latest['context'] );
		$this->assertCount( 2, $logger->recent( 2 ) );
	}
}

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
}

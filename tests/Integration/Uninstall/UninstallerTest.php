<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Uninstall;

use WP_UnitTestCase;
use WST\Access;
use WST\Database\Schema;
use WST\Modes\Resolver;
use WST\Queue\Scheduler;
use WST\Settings;
use WST\Uninstaller;

/**
 * Runs against a connection copy with its own table prefix: the test suite
 * turns its CREATE/DROP TABLE into temporary tables, so the real plugin
 * tables are untouched and nothing is committed.
 */
final class UninstallerTest extends WP_UnitTestCase {

	private \wpdb $db;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->db         = clone $wpdb;
		$this->db->prefix = 'wstun_';
		( new Schema( $this->db ) )->install();
		Access::install();
	}

	private function tableExists( string $table ): bool {
		$suppressed = $this->db->suppress_errors( true );
		$this->db->get_var( 'SELECT 1 FROM ' . ( new Schema( $this->db ) )->table( $table ) . ' LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed test table name.
		$exists = '' === $this->db->last_error;
		$this->db->suppress_errors( $suppressed );

		return $exists;
	}

	public function test_keeps_everything_unless_delete_on_uninstall_is_on(): void {
		update_option( Settings::OPTION, array( 'target_language' => 'bn_BD' ) );

		$this->assertFalse( Uninstaller::run( $this->db ) );
		$this->assertTrue( $this->tableExists( 'strings' ) );
		$this->assertNotFalse( get_option( Settings::OPTION ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( Access::CAPABILITY ) );
	}

	public function test_deletes_tables_options_meta_capability_and_cron(): void {
		update_option( Settings::OPTION, array( 'delete_on_uninstall' => true ) );
		update_option( 'wst_provider_state', array( 'x' => 1 ) );
		set_transient( 'wst_probe', 'x' );
		update_option( 'unrelated_option', 'keep' );
		$post = self::factory()->post->create();
		update_post_meta( $post, Resolver::META_KEY, 'off' );
		wp_schedule_event( time(), 'hourly', Scheduler::HOOK );

		$this->assertTrue( Uninstaller::run( $this->db ) );

		foreach ( Schema::TABLES as $table ) {
			$this->assertFalse( $this->tableExists( $table ), $table );
		}
		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( 'wst_provider_state' ) );
		$this->assertFalse( get_option( Schema::VERSION_OPTION ) );
		$this->assertFalse( get_transient( 'wst_probe' ) );
		$this->assertSame( 'keep', get_option( 'unrelated_option' ) );
		$this->assertSame( '', get_post_meta( $post, Resolver::META_KEY, true ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Access::CAPABILITY ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Access::CAPABILITY ) );
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK ) );
	}
}

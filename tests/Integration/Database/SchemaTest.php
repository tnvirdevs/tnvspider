<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Database;

use WP_UnitTestCase;
use WST\Database\Schema;

/**
 * The tables are created when the plugin loads in the test bootstrap
 * (plugins_loaded → maybeUpgrade), so these tests check the real result.
 */
final class SchemaTest extends WP_UnitTestCase {

	private Schema $schema;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->schema = new Schema( $wpdb );
	}

	/**
	 * @dataProvider columnProvider
	 *
	 * @param string                $table   Table key.
	 * @param array<string, string> $columns Column name => DESCRIBE type.
	 */
	public function test_table_has_expected_columns( string $table, array $columns ): void {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $this->schema->table( $table ) ) );

		$this->assertNotEmpty( $rows, "Table {$table} is missing." );
		$actual = array();
		foreach ( $rows as $row ) {
			// MySQL 8 drops integer display widths; compare without them.
			$actual[ $row->Field ] = preg_replace( '/^(tinyint|smallint|int|bigint)\(\d+\)/', '$1', $row->Type );
		}
		$this->assertSame( $columns, $actual );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, string>}>
	 */
	public function columnProvider(): array {
		return array(
			'strings'      => array(
				'strings',
				array(
					'id'         => 'bigint unsigned',
					'hash'       => 'char(32)',
					'kind'       => 'varchar(24)',
					'original'   => 'longtext',
					'char_count' => 'int unsigned',
					'is_global'  => 'tinyint',
					'created_at' => 'datetime',
				),
			),
			'translations' => array(
				'translations',
				array(
					'id'         => 'bigint unsigned',
					'string_id'  => 'bigint unsigned',
					'lang'       => 'varchar(12)',
					'translated' => 'longtext',
					'status'     => 'tinyint',
					'provider'   => 'varchar(24)',
					'flags'      => 'smallint unsigned',
					'updated_by' => 'bigint unsigned',
					'updated_at' => 'datetime',
				),
			),
			'occurrences'  => array(
				'occurrences',
				array(
					'string_id' => 'bigint unsigned',
					'page_key'  => 'char(32)',
					'post_id'   => 'bigint unsigned',
					'last_seen' => 'datetime',
				),
			),
			'pages'        => array(
				'pages',
				array(
					'page_key'  => 'char(32)',
					'path'      => 'varchar(255)',
					'post_id'   => 'bigint unsigned',
					'last_seen' => 'datetime',
					'last_scan' => 'datetime',
				),
			),
			'queue'        => array(
				'queue',
				array(
					'id'              => 'bigint unsigned',
					'string_id'       => 'bigint unsigned',
					'lang'            => 'varchar(12)',
					'provider'        => 'varchar(24)',
					'priority'        => 'tinyint',
					'state'           => 'varchar(12)',
					'attempts'        => 'tinyint unsigned',
					'next_attempt_at' => 'datetime',
					'locked_until'    => 'datetime',
					'last_error'      => 'text',
					'created_at'      => 'datetime',
				),
			),
			'usage'        => array(
				'usage',
				array(
					'provider' => 'varchar(24)',
					'period'   => 'char(7)',
					'chars'    => 'bigint unsigned',
					'requests' => 'bigint unsigned',
				),
			),
			'log'          => array(
				'log',
				array(
					'id'         => 'bigint unsigned',
					'created_at' => 'datetime',
					'level'      => 'varchar(8)',
					'source'     => 'varchar(24)',
					'message'    => 'text',
					'context'    => 'longtext',
				),
			),
		);
	}

	/**
	 * @dataProvider uniqueKeyProvider
	 *
	 * @param string       $table   Table key.
	 * @param string       $key     Index name.
	 * @param list<string> $columns Indexed columns in order.
	 */
	public function test_unique_keys( string $table, string $key, array $columns ): void {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $this->schema->table( $table ), $key )
		);

		$this->assertNotEmpty( $rows, "Index {$key} missing on {$table}." );
		$this->assertSame( '0', (string) $rows[0]->Non_unique, "Index {$key} on {$table} must be unique." );
		$this->assertSame( $columns, array_map( static fn( $row ): string => $row->Column_name, $rows ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: list<string>}>
	 */
	public function uniqueKeyProvider(): array {
		return array(
			'strings hash'             => array( 'strings', 'hash', array( 'hash' ) ),
			'translations string/lang' => array( 'translations', 'string_lang', array( 'string_id', 'lang' ) ),
			'occurrences primary'      => array( 'occurrences', 'PRIMARY', array( 'string_id', 'page_key' ) ),
			'pages primary'            => array( 'pages', 'PRIMARY', array( 'page_key' ) ),
			'queue string/lang'        => array( 'queue', 'string_lang', array( 'string_id', 'lang' ) ),
			'usage primary'            => array( 'usage', 'PRIMARY', array( 'provider', 'period' ) ),
		);
	}

	public function test_install_creates_every_table_on_an_empty_database(): void {
		global $wpdb;
		// A connection copy with an unused prefix; the test suite turns these
		// CREATE TABLE statements into temporary tables.
		$fresh         = clone $wpdb;
		$fresh->prefix = 'wstfresh_';

		$changes = ( new Schema( $fresh ) )->install();

		$expected = array();
		foreach ( Schema::TABLES as $table ) {
			$expected[ 'wstfresh_wst_' . $table ] = 'Created table wstfresh_wst_' . $table;
		}
		$this->assertSame( $expected, $changes );
	}

	public function test_install_fails_loudly_when_a_table_is_not_created(): void {
		global $wpdb;
		$suppressed    = $wpdb->suppress_errors( true );
		$fresh         = clone $wpdb;
		$fresh->prefix = 'wstbroken_';
		$break         = static fn( string $query ): string => str_contains( $query, 'wstbroken_wst_pages' ) ? 'CREATE TABLE broken syntax' : $query;
		add_filter( 'query', $break );

		try {
			( new Schema( $fresh ) )->install();
			$this->fail( 'install() must throw when a table is missing.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'wstbroken_wst_pages', $e->getMessage() );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppressed );
		}
	}

	public function test_install_is_idempotent_and_dbdelta_sees_no_drift(): void {
		$this->assertSame( array(), $this->schema->install() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
	}

	public function test_maybe_upgrade_runs_only_when_the_stored_version_is_older(): void {
		update_option( Schema::VERSION_OPTION, Schema::VERSION );
		$this->assertFalse( $this->schema->maybeUpgrade() );

		update_option( Schema::VERSION_OPTION, Schema::VERSION - 1 );
		$this->assertTrue( $this->schema->maybeUpgrade() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
	}

	public function test_unknown_table_name_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->schema->table( 'users' );
	}

	public function test_strings_hash_rejects_duplicates(): void {
		global $wpdb;
		$table = $this->schema->table( 'strings' );
		$row   = array(
			'hash'       => md5( 'Hello' ),
			'kind'       => 'text',
			'original'   => 'Hello',
			'char_count' => 5,
			'created_at' => '2026-10-06 00:00:00',
		);

		$this->assertSame( 1, $wpdb->insert( $table, $row ) );
		$wpdb->suppress_errors( true );
		$second = $wpdb->insert( $table, $row );
		$wpdb->suppress_errors( false );
		$this->assertFalse( $second );
	}
}

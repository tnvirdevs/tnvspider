<?php
/**
 * Plan §13A.3 / §16 Phase 6a: export filters and streaming, import outcomes
 * per conflict policy, validation, dry run, and a lossless round trip.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Transfer;

use WP_UnitTestCase;
use WST\Database\Schema;
use WST\Languages\Registry;
use WST\Rest\ImportController;
use WST\Settings;
use WST\Storage\StringStore;
use WST\Transfer\Csv;
use WST\Transfer\Exporter;
use WST\Transfer\Importer;

final class CsvTransferTest extends WP_UnitTestCase {

	private StringStore $store;

	private Schema $schema;

	private Exporter $exporter;

	private Importer $importer;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->schema   = new Schema( $wpdb );
		$this->store    = new StringStore( $wpdb, $this->schema );
		$target         = ( new Settings( array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() ) )->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$this->exporter = new Exporter( $this->store, $target );
		$this->importer = new Importer( $this->store, $target );
		wp_cache_flush();
	}

	/**
	 * Manual, machine and untranslated strings on two pages.
	 *
	 * @return array<string, int> Original => id.
	 */
	private function seed(): array {
		$ids  = $this->store->recordOnPage(
			array(
				'Welcome'                              => 'text',
				'Read <a href="/story/">our story</a>' => 'inline',
				'=1+2 apples'                          => 'text',
				'Say "hi", then go'                    => 'attr',
				'Untranslated one'                     => 'text',
			),
			'/',
			null
		);
		$ids += $this->store->recordOnPage( array( 'About us' => 'title' ), '/about/', 7 );
		$this->store->saveManual( 'Welcome', 'text', 'bn_BD', 'স্বাগতম', 1 );
		$this->store->saveManual( 'Read <a href="/story/">our story</a>', 'inline', 'bn_BD', '<a href="/story/">আমাদের গল্প</a> পড়ুন', 1 );
		$this->store->saveMachine( $ids['=1+2 apples'], '=1+2 apples', 'bn_BD', '-৩টি আপেল', 'translatex', 0 );
		$this->store->saveMachine( $ids['Say "hi", then go'], 'Say "hi", then go', 'bn_BD', "বলুন \"হাই\",\nতারপর যান", 'translatex', 0 );
		$this->store->saveMachine( $ids['About us'], 'About us', 'bn_BD', 'আমাদের সম্পর্কে', 'translatex', 0 );

		return $ids;
	}

	/**
	 * Export into memory.
	 *
	 * @return array{raw: string, rows: list<array<string, string>>, chunks: int}
	 */
	private function export( string $filter, string $path = '' ): array {
		$out = fopen( 'php://memory', 'w+b' );
		$this->assertIsResource( $out );
		$chunks = 0;
		$this->exporter->write(
			$out,
			'page' === $filter ? 'all' : $filter,
			$this->exporter->pageKey( $filter, $path ),
			static function () use ( &$chunks ): void {
				++$chunks;
			}
		);
		rewind( $out );
		$raw = (string) stream_get_contents( $out );

		return array(
			'raw'    => $raw,
			'rows'   => self::parse( $raw ),
			'chunks' => $chunks,
		);
	}

	/**
	 * Read a CSV file as the import screen does: BOM tolerated, rows keyed by
	 * the header, with line numbers.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function parse( string $raw ): array {
		$in = fopen( 'php://memory', 'w+b' );
		if ( false === $in ) {
			throw new \RuntimeException( 'No memory stream.' );
		}
		fwrite( $in, str_starts_with( $raw, Csv::BOM ) ? substr( $raw, 3 ) : $raw );
		rewind( $in );
		$header = fgetcsv( $in, null, ',', '"', '' );
		$rows   = array();
		$line   = 1;
		while ( false !== ( $cells = fgetcsv( $in, null, ',', '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Reading a stream.
			$rows[] = array( 'line' => ++$line ) + array_combine( (array) $header, $cells );
		}

		return $rows;
	}

	/**
	 * Every translation row, for comparing states.
	 *
	 * @return list<array<string, string|null>>
	 */
	private function translations(): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare( 'SELECT s.original, t.translated, t.status, t.provider FROM %i t JOIN %i s ON s.id = t.string_id ORDER BY s.id', $this->schema->table( 'translations' ), $this->schema->table( 'strings' ) ), ARRAY_A );
	}

	public function test_export_writes_bom_header_guarded_cells_and_pages(): void {
		$this->seed();

		$export = $this->export( 'all' );

		$this->assertStringStartsWith( Csv::BOM . "original,translated,status,kind,lang,pages\n", $export['raw'] );
		$this->assertCount( 6, $export['rows'] );
		$byOriginal = array_column( $export['rows'], null, 'original' );
		$this->assertSame( "'=1+2 apples", $byOriginal["'=1+2 apples"]['original'], 'Formula-like cells are guarded.' );
		$this->assertSame( "'-৩টি আপেল", $byOriginal["'=1+2 apples"]['translated'] );
		$this->assertSame( 'machine', $byOriginal["'=1+2 apples"]['status'] );
		$this->assertSame( 'manual', $byOriginal['Welcome']['status'] );
		$this->assertSame( '', $byOriginal['Untranslated one']['translated'] );
		$this->assertSame( '', $byOriginal['Untranslated one']['status'] );
		$this->assertSame( 'inline', $byOriginal['Read <a href="/story/">our story</a>']['kind'] );
		$this->assertSame( 'bn_BD', $byOriginal['Welcome']['lang'] );
		$this->assertSame( '/', $byOriginal['Welcome']['pages'] );
		$this->assertSame( '/about', $byOriginal['About us']['pages'], 'Paths as stored for page keys.' );
		$this->assertSame( "বলুন \"হাই\",\nতারপর যান", $byOriginal['Say "hi", then go']['translated'], 'Quotes, commas and line breaks survive.' );
	}

	public function test_export_filters(): void {
		$this->seed();
		$originals = fn( string $filter, string $path = '' ): array => array_column( $this->export( $filter, $path )['rows'], 'original' );

		$this->assertSame( array( 'Untranslated one' ), $originals( 'untranslated' ) );
		$this->assertSame( array( 'Welcome', 'Read <a href="/story/">our story</a>' ), $originals( 'manual' ) );
		$this->assertSame( array( "'=1+2 apples", 'Say "hi", then go', 'About us' ), $originals( 'machine' ) );
		$this->assertSame( array( 'About us' ), $originals( 'page', '/about/' ) );
	}

	public function test_page_filter_needs_a_recorded_page(): void {
		$this->seed();

		foreach ( array( array( 'page', '/nowhere/' ), array( 'page', 'about' ), array( 'everything', '' ) ) as [ $filter, $path ] ) {
			try {
				$this->exporter->pageKey( $filter, $path );
				$this->fail( 'Accepted ' . $filter . ' ' . $path );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_export_streams_in_chunks(): void {
		$strings = array();
		for ( $i = 1; $i <= Exporter::CHUNK * 2 + 1; $i++ ) {
			$strings[ 'String number ' . $i ] = 'text';
		}
		$this->store->recordOnPage( $strings, '/many/', null );

		$export = $this->export( 'all' );

		$this->assertCount( Exporter::CHUNK * 2 + 1, $export['rows'] );
		$this->assertSame( 3, $export['chunks'] );
		$this->assertSame( 'String number ' . ( Exporter::CHUNK * 2 + 1 ), end( $export['rows'] )['original'] );
	}

	public function test_export_then_import_round_trips_losslessly(): void {
		global $wpdb;
		$this->seed();
		$before = $this->translations();
		$rows   = $this->export( 'all' )['rows'];
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->schema->table( 'translations' ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->schema->table( 'strings' ) ) );

		$result = $this->importer->run( $rows, 'overwrite_all', 'file', true, 1 );

		$this->assertSame( 5, $result['counts']['new'] );
		$this->assertSame( 1, $result['counts']['skipped'], 'The untranslated row.' );
		$this->assertSame( 0, $result['counts']['invalid'], (string) wp_json_encode( $result['rows'] ) );
		$after = $this->translations();
		$strip = static fn( array $rows ): array => array_map( static fn( array $row ): array => array_diff_key( $row, array( 'provider' => 1 ) ), $rows );
		$this->assertSame( $strip( $before ), $strip( $after ), 'Originals, translations and statuses are identical.' );
		$this->assertSame( StringStore::IMPORT_PROVIDER, $this->store->find( 'About us', 'bn_BD' ) ? $after[4]['provider'] : null );
		$this->assertSame( 'inline', $this->store->find( 'Read <a href="/story/">our story</a>', 'bn_BD' )['kind'] ?? null, 'Kinds come from the file for new strings.' );

		$again = $this->importer->run( $rows, 'overwrite_all', 'file', true, 1 );
		$this->assertSame( 5, $again['counts']['unchanged'], 'A second import changes nothing.' );
	}

	public function test_dry_run_writes_nothing(): void {
		$this->seed();
		$before = $this->translations();
		$rows   = array(
			array(
				'line'       => 2,
				'original'   => 'Welcome',
				'translated' => 'নতুন স্বাগতম',
			),
			array(
				'line'       => 3,
				'original'   => 'Brand new',
				'translated' => 'একদম নতুন',
			),
		);

		$result = $this->importer->run( $rows, 'overwrite_all', 'manual', false, 1 );

		$this->assertSame( 1, $result['counts']['update'] );
		$this->assertSame( 1, $result['counts']['new'] );
		$this->assertSame( $before, $this->translations() );
		$this->assertNull( $this->store->find( 'Brand new', 'bn_BD' ) );
	}

	public function test_conflict_policies(): void {
		$this->seed();
		$rows   = array(
			array(
				'line'       => 2,
				'original'   => 'Welcome',
				'translated' => 'অন্য স্বাগতম',
			), // Manual, differs.
			array(
				'line'       => 3,
				'original'   => "'=1+2 apples",
				'translated' => 'তিনটি আপেল',
			), // Machine, differs (guarded cell).
			array(
				'line'       => 4,
				'original'   => 'Untranslated one',
				'translated' => 'অনূদিত নয়',
			), // Known, no translation.
			array(
				'line'       => 5,
				'original'   => 'About us',
				'translated' => 'আমাদের সম্পর্কে',
			), // Same text, machine → manual is an update.
		);
		$expect = array(
			'add_only'      => array(
				'new'      => 1,
				'update'   => 0,
				'conflict' => 3,
			),
			'keep_manual'   => array(
				'new'      => 1,
				'update'   => 2,
				'conflict' => 1,
			),
			'overwrite_all' => array(
				'new'      => 1,
				'update'   => 3,
				'conflict' => 0,
			),
		);
		foreach ( $expect as $policy => $counts ) {
			$result = $this->importer->run( $rows, $policy, 'manual', false, 1 );
			$this->assertSame( $counts, array_intersect_key( $result['counts'], $counts ), $policy );
		}

		$applied = $this->importer->run( $rows, 'keep_manual', 'manual', true, 1 );
		$this->assertSame( array( 2 ), array_column( $applied['rows'], 'line' ), 'Only the kept manual row is listed.' );
		$this->assertSame( 'স্বাগতম', $this->store->find( 'Welcome', 'bn_BD' )['translated'] ?? null, 'Manual kept.' );
		$this->assertSame( 'তিনটি আপেল', $this->store->find( '=1+2 apples', 'bn_BD' )['translated'] ?? null, 'Machine overwritten; the guard apostrophe was removed.' );
		$this->assertSame( StringStore::STATUS_MANUAL, $this->store->find( 'About us', 'bn_BD' )['status'] ?? null );
	}

	public function test_invalid_rows_are_reported_with_reasons(): void {
		$this->seed();
		$rows = array(
			array(
				'line'       => 2,
				'original'   => 'Welcome',
				'translated' => 'স্বাগতম',
				'lang'       => 'ar',
			),
			array(
				'line'       => 3,
				'original'   => 'A',
				'translated' => 'ক',
				'kind'       => 'script',
			),
			array(
				'line'       => 4,
				'original'   => 'B',
				'translated' => 'খ',
				'status'     => 'approved',
			),
			array(
				'line'       => 5,
				'original'   => '   ',
				'translated' => 'গ',
			),
			array(
				'line'       => 6,
				'original'   => 'Read <a href="/story/">our story</a>',
				'translated' => '<a href="/story/" onclick="steal()">আমাদের গল্প</a>',
			),
			array(
				'line'       => 7,
				'original'   => "Bad \xC3\x28 bytes",
				'translated' => 'ঘ',
			),
			array(
				'line'       => 8,
				'original'   => 'Huge',
				'translated' => str_repeat( 'x', Importer::MAX_CELL_BYTES + 1 ),
			),
			array(
				'line'       => 9,
				'original'   => 'C',
				'translated' => array( 'not', 'text' ),
			),
			'not a row',
			array(
				'line'       => 11,
				'original'   => 'D',
				'translated' => 'ঙ',
			),
			array(
				'line'       => 12,
				'original'   => ' D ',
				'translated' => 'চ',
			),
			array(
				'line'       => 13,
				'original'   => 'E',
				'translated' => '  ',
			),
		);

		$result = $this->importer->run( $rows, 'keep_manual', 'manual', true, 1 );

		$this->assertSame( 1, $result['counts']['new'], 'Only D.' );
		$this->assertSame( 1, $result['counts']['skipped'] );
		$this->assertSame( 10, $result['counts']['invalid'] );
		$reasons = array_column( $result['rows'], 'reason', 'line' );
		$this->assertStringContainsString( 'not the target language (bn_BD)', $reasons[2] );
		$this->assertStringContainsString( 'Unknown kind "script"', $reasons[3] );
		$this->assertStringContainsString( 'Unknown status "approved"', $reasons[4] );
		$this->assertSame( 'The original is empty.', $reasons[5] );
		$this->assertStringContainsString( 'tags and attributes', $reasons[6] );
		$this->assertStringContainsString( 'not valid UTF-8', $reasons[7] );
		$this->assertStringContainsString( 'longer than 63 KB', $reasons[8] );
		$this->assertSame( 'Malformed row.', $reasons[9] );
		$this->assertSame( 2, array_count_values( array_column( $result['rows'], 'reason' ) )['Malformed row.'], 'A row that is not an object too (listed at its position).' );
		$this->assertSame( 'Same original as line 11.', $reasons[12] );
		$this->assertSame( 'No translation in the file.', $reasons[13] );
		$this->assertSame( '<a href="/story/">আমাদের গল্প</a> পড়ুন', $this->store->find( 'Read <a href="/story/">our story</a>', 'bn_BD' )['translated'] ?? null );
	}

	public function test_import_status_options_set_status_and_provider_and_clear_the_queue(): void {
		global $wpdb;
		$ids = $this->seed();
		$wpdb->insert(
			$this->schema->table( 'queue' ),
			array(
				'string_id'       => $ids['Untranslated one'],
				'lang'            => 'bn_BD',
				'provider'        => 'translatex',
				'state'           => 'pending',
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s' ),
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$fired = array();
		add_action(
			'wst_strings_translated',
			static function ( $stringIds, $lang ) use ( &$fired ): void {
				$fired[] = array( $stringIds, $lang );
			},
			10,
			2
		);
		$row = static fn( string $original, string $translated, string $status = '' ): array => array(
			'original'   => $original,
			'translated' => $translated,
			'status'     => $status,
		);

		$this->importer->run( array( $row( 'Untranslated one', 'যন্ত্র অনুবাদ' ) ), 'keep_manual', 'machine', true, 1 );
		$this->importer->run( array( $row( 'Fresh', 'তাজা', 'machine' ) ), 'keep_manual', 'manual', true, 1 );
		$this->importer->run( array( $row( 'From file', 'ফাইল থেকে', 'machine' ) ), 'keep_manual', 'file', true, 1 );

		$this->assertSame( StringStore::STATUS_MACHINE, $this->store->find( 'Untranslated one', 'bn_BD' )['status'] ?? null );
		$this->assertSame( StringStore::STATUS_MANUAL, $this->store->find( 'Fresh', 'bn_BD' )['status'] ?? null, 'The status column is ignored unless "as in the file".' );
		$this->assertSame( StringStore::STATUS_MACHINE, $this->store->find( 'From file', 'bn_BD' )['status'] ?? null );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->schema->table( 'queue' ) ) ), 'Translated now: nothing left to queue.' );
		$providers = $wpdb->get_col( $wpdb->prepare( 'SELECT provider FROM %i WHERE string_id IN (%d, %d)', $this->schema->table( 'translations' ), $ids['Untranslated one'], (int) $this->store->find( 'Fresh', 'bn_BD' )['id'] ) );
		$this->assertContains( StringStore::IMPORT_PROVIDER, $providers );
		$this->assertContains( null, $providers, 'Manual rows have no provider.' );
		$this->assertSame( array( array( $ids['Untranslated one'] ), 'bn_BD' ), $fired[0], 'Pages that are now finished get purged.' );
		$this->assertCount( 3, $fired );
	}

	public function test_rest_routes_need_manage_options_and_limit_the_chunk(): void {
		global $wp_rest_server;
		remove_all_actions( 'rest_api_init' );
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.
		( new ImportController( $this->importer ) )->boot();
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$call = static function ( string $route, array $body ): \WP_REST_Response {
			$request = new \WP_REST_Request( 'POST', '/wst/v1/import/' . $route );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );

			return rest_do_request( $request );
		};
		$one  = array(
			'rows' => array(
				array(
					'line'       => 2,
					'original'   => 'Hello',
					'translated' => 'হ্যালো',
				),
			),
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $call( 'check', $one )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$check = $call( 'check', $one );
		$this->assertSame( 200, $check->get_status() );
		$this->assertSame( 1, $check->get_data()['counts']['new'] );
		$this->assertNull( $this->store->find( 'Hello', 'bn_BD' ) );
		$this->assertSame( 400, $call( 'check', $one + array( 'policy' => 'merge' ) )->get_status() );
		$this->assertSame( 400, $call( 'check', array( 'rows' => array_fill( 0, Importer::MAX_ROWS + 1, $one['rows'][0] ) ) )->get_status() );

		$this->assertSame( 1, $call( 'apply', $one )->get_data()['counts']['new'] );
		$this->assertSame( 'হ্যালো', $this->store->find( 'Hello', 'bn_BD' )['translated'] ?? null );
	}

	public function test_download_needs_manage_options_and_a_nonce(): void {
		$this->seed();
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$die    = function ( int $user, array $query ): string {
			wp_set_current_user( $user );
			$_GET     = $query;
			$_REQUEST = $query;
			try {
				$this->exporter->download();
			} catch ( \WPDieException $e ) {
				return $e->getMessage();
			}
			$this->fail( 'The download did not stop.' );
		};

		$this->assertStringContainsString( 'not allowed to export', $die( $editor, array() ) );
		$this->assertStringContainsString( 'The link you followed has expired', $die( $admin, array( 'filter' => 'all' ) ) );
		wp_set_current_user( $admin );
		$query = array(
			'filter'   => 'page',
			'path'     => '/nowhere/',
			'_wpnonce' => wp_create_nonce( Exporter::ACTION ),
		);
		$this->assertStringContainsString( 'No strings are recorded for this page', $die( $admin, $query ) );
	}
}

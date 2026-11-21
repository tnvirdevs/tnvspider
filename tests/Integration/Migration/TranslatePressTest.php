<?php
/**
 * Plan §13A.4 / §16 Phase 6b: TranslatePress detection, settings prefill,
 * read-only and idempotent import, match report, content references, the
 * coexistence guard and the [language-switcher] alias.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Migration;

use WP_UnitTestCase;
use WST\Database\Schema;
use WST\Languages\Current;
use WST\Languages\Registry;
use WST\Migration\DictionaryImport;
use WST\Migration\Guard;
use WST\Migration\MatchReport;
use WST\Migration\TranslatePress;
use WST\Modes\Resolver;
use WST\Plugin;
use WST\Providers\Secrets;
use WST\Queue\Queue;
use WST\Queue\Scheduler;
use WST\Rest\MigrationController;
use WST\Rest\SettingsController;
use WST\Routing\LanguageUrls;
use WST\Routing\Router;
use WST\Routing\Urls;
use WST\Settings;
use WST\Storage\StringStore;
use WST\Switcher\Switcher;
use WST\Transfer\Importer;

final class TranslatePressTest extends WP_UnitTestCase {

	private const PLUGIN_FILE = __DIR__ . '/../../../wp-site-translator.php';

	private string $table = '';

	private StringStore $store;

	/**
	 * Dictionary tables are created once, outside the per-test transaction:
	 * DDL would commit it. SHOW TABLES does not list temporary tables, so
	 * they are real tables, emptied before each test.
	 */
	public static function wpSetUpBeforeClass(): void {
		global $wpdb;
		foreach ( array( 'bn_bd', 'xx_yy' ) as $target ) {
			$table = $wpdb->prefix . 'trp_dictionary_en_us_' . $target;
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture.
			$wpdb->query( "CREATE TABLE `{$table}` (id bigint(20) AUTO_INCREMENT NOT NULL PRIMARY KEY, original longtext NOT NULL, translated longtext, status int(20) DEFAULT 0, block_type int(20) DEFAULT 0, original_id bigint(20) DEFAULT NULL) {$wpdb->get_charset_collate()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture.
		}
	}

	public static function wpTearDownAfterClass(): void {
		global $wpdb;
		foreach ( array( 'bn_bd', 'xx_yy' ) as $target ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}trp_dictionary_en_us_{$target}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture.
		}
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->store = new StringStore( $wpdb, new Schema( $wpdb ) );
		$this->table = $wpdb->prefix . 'trp_dictionary_en_us_bn_bd';
		$wpdb->query( "DELETE FROM `{$this->table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture; rolled back after the test.
		wp_cache_flush();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global: later tests get a fresh one.
		Current::reset();
		wp_clear_scheduled_hook( Scheduler::HOOK );
		parent::tear_down();
	}

	private function trpSettings( array $extra = array() ): void {
		update_option(
			TranslatePress::SETTINGS_OPTION,
			$extra + array(
				'default-language'                     => 'en_US',
				'translation-languages'                => array( 'en_US', 'bn_BD' ),
				'url-slugs'                            => array(
					'en_US' => 'en',
					'bn_BD' => 'bangla',
				),
				'add-subdirectory-to-default-language' => 'no',
			)
		);
	}

	/**
	 * Rows as TranslatePress stores them.
	 *
	 * @param list<array{0: string, 1: string|null, 2: int, 3?: int}> $rows Original, translated, status, block type.
	 */
	private function rows( array $rows ): void {
		global $wpdb;
		foreach ( $rows as $row ) {
			$wpdb->insert(
				$this->table,
				array(
					'original'    => $row[0],
					'translated'  => $row[1],
					'status'      => $row[2],
					'block_type'  => $row[3] ?? 0,
					'original_id' => null,
				)
			);
		}
	}

	private function fixture(): void {
		$this->trpSettings();
		$this->rows(
			array(
				array( 'Fish &amp; chips', 'মাছ &amp; চিপস', 1 ),
				array( "  Hello\n   world ", 'হ্যালো বিশ্ব', 2 ),
				array( 'Read <a href="/story/">our story</a> today', 'আজই <a href="/story/">আমাদের গল্প</a> পড়ুন', 2 ),
				array( 'Not yet', null, 0 ),
				array( 'Empty machine', '', 1 ),
				array( '<p>Old block</p>', '<p>পুরনো ব্লক</p>', 2, 2 ),
				array( 'Bad <b>tags</b>', 'খারাপ <i>ট্যাগ</i>', 1 ),
				array( 'Keep mine', 'TranslatePress version', 1 ),
			)
		);
	}

	private function settings(): Settings {
		return new Settings( array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() );
	}

	private function import(): DictionaryImport {
		global $wpdb;
		$target = $this->settings()->targetLanguage() ?? throw new \LogicException( 'No target.' );

		return new DictionaryImport( new TranslatePress( $wpdb ), new Importer( $this->store, $target ) );
	}

	private function checksum(): string {
		global $wpdb;

		return md5( (string) wp_json_encode( $wpdb->get_results( "SELECT * FROM `{$this->table}` ORDER BY id", ARRAY_A ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture.
	}

	public function test_detection_and_counts(): void {
		global $wpdb;
		$tp = new TranslatePress( $wpdb );
		$this->assertTrue( $tp->present(), 'The dictionary table alone counts as data to migrate.' );
		$this->assertSame( array(), $tp->tables(), 'Tables are matched against the TranslatePress languages.' );
		$this->assertFalse( TranslatePress::isActive() );

		$this->fixture();
		update_option( 'active_plugins', array( TranslatePress::PLUGIN_FILE ) );

		$this->assertTrue( TranslatePress::isActive() );
		$this->assertSame(
			array(
				'default'        => 'en_US',
				'languages'      => array( 'en_US', 'bn_BD' ),
				'slugs'          => array(
					'en_US' => 'en',
					'bn_BD' => 'bangla',
				),
				'prefix_default' => false,
			),
			$tp->settings()
		);
		$this->assertSame(
			array(
				array(
					'table'   => $this->table,
					'default' => 'en_US',
					'target'  => 'bn_BD',
					'counts'  => array(
						'total'        => 8,
						'machine'      => 3,
						'manual'       => 2,
						'untranslated' => 2,
						'deprecated'   => 1,
					),
				),
			),
			$tp->tables()
		);
		$this->expectException( \InvalidArgumentException::class );
		$tp->rows( $wpdb->prefix . 'users', 0, 10 );
	}

	public function test_dry_run_reads_only_and_writes_nothing(): void {
		$this->fixture();
		$before = $this->checksum();

		$result = $this->import()->run( $this->table, 0, 'add_only', false, 1 );

		$this->assertSame( 4, $result['counts']['new'] );
		$this->assertSame( 1, $result['counts']['invalid'], 'Changed inline markup.' );
		$this->assertSame( 3, $result['counts']['skipped'] );
		$this->assertSame(
			array(
				'untranslated' => 2,
				'deprecated'   => 1,
			),
			$result['skipped']
		);
		global $wpdb;
		$last = (int) $wpdb->get_var( "SELECT MAX(id) FROM `{$this->table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture.
		$this->assertSame( array( 8, $last, true ), array( $result['read'], $result['next'], $result['done'] ), 'Resumes after the last row read.' );
		$this->assertNull( $this->store->find( 'Fish & chips', 'bn_BD' ) );
		$this->assertSame( $before, $this->checksum() );
	}

	public function test_import_maps_statuses_normalises_and_is_idempotent(): void {
		$this->fixture();
		$this->store->saveManual( 'Keep mine', 'text', 'bn_BD', 'আমার অনুবাদ', 1 );
		$before = $this->checksum();

		$first = $this->import()->run( $this->table, 0, 'add_only', true, 1 );

		$this->assertSame( array( 3, 1, 1 ), array( $first['counts']['new'], $first['counts']['conflict'], $first['counts']['invalid'] ) );
		$fish = $this->store->find( 'Fish & chips', 'bn_BD' );
		$this->assertSame( array( 'text', 'মাছ & চিপস', StringStore::STATUS_MACHINE ), array( $fish['kind'] ?? '', $fish['translated'] ?? '', $fish['status'] ?? 0 ), 'Entities decoded like the extractor; status 1 is machine.' );
		$this->assertSame( StringStore::STATUS_MANUAL, $this->store->find( 'Hello world', 'bn_BD' )['status'] ?? null, 'Whitespace collapsed; status 2 is manual.' );
		global $wpdb;
		$this->assertSame( DictionaryImport::SOURCE, $wpdb->get_var( $wpdb->prepare( 'SELECT provider FROM %i WHERE string_id = %d', ( new Schema( $wpdb ) )->table( 'translations' ), $fish['id'] ?? 0 ) ), 'Machine translations record where they came from.' );
		$inline = $this->store->find( 'Read <a href="/story/">our story</a> today', 'bn_BD' );
		$this->assertSame( array( 'inline', 'আজই <a href="/story/">আমাদের গল্প</a> পড়ুন' ), array( $inline['kind'] ?? '', $inline['translated'] ?? '' ) );
		$this->assertSame( 'আমার অনুবাদ', $this->store->find( 'Keep mine', 'bn_BD' )['translated'] ?? null, 'Keep existing is the default policy.' );
		$this->assertNull( $this->store->find( '<p>Old block</p>', 'bn_BD' ), 'Deprecated blocks are skipped.' );
		$this->assertSame( $before, $this->checksum(), 'TranslatePress data is never modified.' );

		$again = $this->import()->run( $this->table, 0, 'add_only', true, 1 );
		$this->assertSame( array( 0, 3 ), array( $again['counts']['new'], $again['counts']['unchanged'] ), 'Re-running changes nothing.' );

		$overwrite = $this->import()->run( $this->table, 0, 'overwrite_all', true, 1 );
		$this->assertSame( 1, $overwrite['counts']['update'] );
		$this->assertSame( 'TranslatePress version', $this->store->find( 'Keep mine', 'bn_BD' )['translated'] ?? null );
	}

	public function test_import_goes_in_chunks(): void {
		global $wpdb;
		$this->trpSettings();
		$values = array();
		for ( $i = 1; $i <= DictionaryImport::CHUNK + 5; $i++ ) {
			$values[] = $wpdb->prepare( '(%s, %s, 1, 0)', 'Row ' . $i, 'সারি ' . $i );
		}
		$wpdb->query( "INSERT INTO `{$this->table}` (original, translated, status, block_type) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Values prepared above.

		$first  = $this->import()->run( $this->table, 0, 'add_only', true, 1 );
		$second = $this->import()->run( $this->table, $first['next'], 'add_only', true, 1 );

		$this->assertSame( array( DictionaryImport::CHUNK, false ), array( $first['counts']['new'], $first['done'] ) );
		$this->assertSame( array( 5, true ), array( $second['counts']['new'], $second['done'] ) );
	}

	/**
	 * Migration routes with a fresh REST server.
	 *
	 * @return callable(string, string, array<string, mixed>): \WP_REST_Response
	 */
	private function rest(): callable {
		global $wpdb, $wp_rest_server;
		remove_all_actions( 'rest_api_init' );
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.
		$queue          = new Queue( $wpdb, new Schema( $wpdb ) );
		$saver          = new SettingsController(
			new Secrets(),
			$queue,
			new Scheduler(
				$queue,
				static function (): \WST\Queue\Worker {
					throw new \LogicException( 'No worker.' );
				}
			)
		);
		( new MigrationController( new TranslatePress( $wpdb ), Settings::load(), $saver, $this->store, $wpdb ) )->boot();
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		return static function ( string $method, string $route, array $params ): \WP_REST_Response {
			$request = new \WP_REST_Request( $method, '/wst/v1/migration/' . $route );
			if ( 'POST' === $method ) {
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( (string) wp_json_encode( $params ) );
			}

			return rest_do_request( $request );
		};
	}

	public function test_wizard_prefills_settings_then_imports_and_reports(): void {
		$this->fixture();
		update_option( 'active_plugins', array( TranslatePress::PLUGIN_FILE ) );
		$call = $this->rest();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $call( 'GET', 'status', array() )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$status = $call( 'GET', 'status', array() )->get_data();
		$this->assertTrue( $status['active'] );
		$this->assertSame(
			array(
				'default_language' => 'en_US',
				'target_language'  => 'bn_BD',
				'target_slug'      => 'bangla',
				'default_slug'     => 'en',
				'prefix_default'   => false,
			),
			$status['prefill'][ $this->table ]['settings']
		);
		$this->assertNull( $status['checklist']['slugs_match'], 'No target language yet.' );
		$this->assertSame( 409, $call( 'POST', 'import', array( 'table' => $this->table ) )->get_status(), 'Settings first.' );

		$this->assertSame( 200, $call( 'POST', 'settings', array( 'table' => $this->table ) )->get_status() );
		$saved = Settings::load();
		$this->assertSame( array( 'bn_BD', 'bangla' ), array( $saved->targetLanguage()?->locale(), $saved->targetLanguage()?->slug() ) );

		$call = $this->rest();
		$dry  = $call( 'POST', 'import', array( 'table' => $this->table ) )->get_data();
		$this->assertSame( 4, $dry['counts']['new'] );
		$this->assertNull( $this->store->find( 'Fish & chips', 'bn_BD' ), 'Dry run is the default.' );
		$applied = $call(
			'POST',
			'import',
			array(
				'table'   => $this->table,
				'dry_run' => false,
			)
		)->get_data();
		$this->assertSame( 4, $applied['counts']['new'] );
		$this->assertNotNull( $this->store->find( 'Fish & chips', 'bn_BD' ) );
		$status = $call( 'GET', 'status', array() )->get_data();
		$this->assertTrue( $status['checklist']['slugs_match'] );
		$this->assertFalse( $status['checklist']['translatepress_inactive'] );
		$this->assertSame( 'http://example.org/bangla/', $status['checklist']['urls'][0]['ours'] );
		$this->assertTrue( $status['checklist']['urls'][0]['same'] );

		$this->assertSame( 400, $call( 'POST', 'import', array( 'table' => $GLOBALS['wpdb']->prefix . 'options' ) )->get_status() );
		$this->assertFalse( get_option( MigrationController::FLUSHED_OPTION, false ) );
		$this->assertTrue( $call( 'POST', 'flush', array() )->get_data()['flushed'] );
		$this->assertNotFalse( get_option( MigrationController::FLUSHED_OPTION, false ) );
	}

	public function test_prefill_refuses_unknown_languages(): void {
		$this->trpSettings(
			array(
				'translation-languages' => array( 'en_US', 'xx_YY' ),
				'url-slugs'             => array(),
			)
		);
		global $wpdb;
		$this->table = $wpdb->prefix . 'trp_dictionary_en_us_xx_yy';
		$call        = $this->rest();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $call( 'POST', 'settings', array( 'table' => $this->table ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertStringContainsString( 'xx_YY is not available', $response->get_data()['message'] );
	}

	public function test_match_report_counts_translated_strings_of_default_language_pages(): void {
		$this->store->saveManual( 'Welcome home', 'text', 'bn_BD', 'স্বাগতম', 1 );
		$this->store->saveManual( 'Read <a href="/story/">our story</a>', 'inline', 'bn_BD', '<a href="/story/">আমাদের গল্প</a> পড়ুন', 1 );
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				$pages = array(
					'http://example.org/'       => '<html><body><h1>Welcome home</h1><p>Read <a href="/story/">our story</a></p><p>Only here</p></body></html>',
					'http://example.org/about/' => '<html><body><h1>Welcome home</h1><p>Only here</p><p>About text</p></body></html>',
				);

				return array(
					'headers'  => array(),
					'body'     => $pages[ $url ] ?? '',
					'response' => array( 'code' => isset( $pages[ $url ] ) ? 200 : 404 ),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
		$settings = $this->settings();
		$target   = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$report   = new MatchReport( $settings, $this->store, Urls::fromHome( 'http://example.org', 'wp-json' ), $target );

		$result = $report->run( array( '/', '/about/', '/missing/', 'no-slash' ) );

		$this->assertSame( array( 4, 2, 50.0 ), array( $result['strings'], $result['matched'], $result['percent'] ) );
		$this->assertSame( array( 'Only here', 'About text' ), array_column( $result['unmatched'], 'text' ), 'Most frequent first.' );
		$this->assertSame( array( 3, 2 ), array( $result['pages'][0]['strings'], $result['pages'][0]['matched'] ) );
		$this->assertSame( 'The page answered with HTTP 404.', $result['pages'][2]['error'] );
		$this->assertSame( 'Give a path that starts with /.', $result['pages'][3]['error'] );
		$this->assertCount( 0, $this->store->findMany( array( 'Only here', 'About text' ), 'bn_BD' ), 'The report records nothing.' );
	}

	public function test_references_find_shortcodes_widgets_and_menu_items(): void {
		global $wpdb;
		$post = self::factory()->post->create( array( 'post_content' => 'Hi [language-switcher] and [trp_language language="bn_BD"]x[/trp_language]' ) );
		self::factory()->post->create( array( 'post_content' => 'Talks about language switchers without a shortcode' ) );
		update_option( 'widget_text', array( 2 => array( 'text' => '[language-switcher]' ) ) );
		$menu = wp_create_nav_menu( 'Main' );
		$this->assertIsInt( $menu );
		$item = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'     => 'Languages',
				'menu-item-status'    => 'publish',
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'language_switcher',
				'menu-item-object-id' => 1,
			)
		);
		$this->assertIsInt( $item );

		$refs = ( new TranslatePress( $wpdb ) )->references();

		$this->assertSame( array( $post ), array_column( $refs['posts'], 'id' ) );
		$this->assertSame( array( 'language-switcher', 'trp_language' ), $refs['posts'][0]['found'] );
		$this->assertSame( array( 'widget_text' ), array_column( $refs['widgets'], 'option' ) );
		$this->assertSame( array( array( $menu, 1 ) ), array_map( static fn( array $m ): array => array( $m['id'], $m['items'] ), $refs['menus'] ) );
	}

	public function test_guard_keeps_the_front_end_off_while_translatepress_is_active(): void {
		update_option( 'wst_settings', array( 'target_language' => 'bn_BD' ) );
		$routers = static function (): int {
			global $wp_filter;
			$found = 0;
			foreach ( isset( $wp_filter['do_parse_request'] ) ? $wp_filter['do_parse_request']->callbacks : array() as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$found += is_array( $callback['function'] ) && $callback['function'][0] instanceof Router ? 1 : 0;
				}
			}

			return $found;
		};
		remove_all_filters( 'do_parse_request' );
		update_option( 'active_plugins', array( TranslatePress::PLUGIN_FILE ) );

		Plugin::boot( self::PLUGIN_FILE );

		$this->assertTrue( Guard::frontEndOff() );
		$this->assertSame( 0, $routers(), 'No router while TranslatePress is active.' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		do_action( 'admin_notices' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$this->assertStringContainsString( 'paused on the front end while TranslatePress is active', (string) ob_get_clean() );

		update_option( 'active_plugins', array() );
		Plugin::boot( self::PLUGIN_FILE );
		$this->assertSame( 1, $routers(), 'Back on when TranslatePress is not active.' );
	}

	public function test_language_switcher_shortcode_alias(): void {
		$settings = static fn( bool $alias ): Settings => new Settings(
			array(
				'target_language'    => 'bn_BD',
				'trp_switcher_alias' => $alias,
			),
			'en_US',
			new Registry()
		);
		$boot     = static function ( Settings $settings ): void {
			remove_shortcode( Switcher::TRP_SHORTCODE );
			$target = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
			$urls   = Urls::fromHome( 'http://example.org', 'wp-json' );
			( new Switcher( $settings, $settings->defaultLanguage(), $target, new LanguageUrls( $urls, $target, 'http://example.org' ), new Resolver( $settings, $urls, $target ), self::PLUGIN_FILE ) )->boot();
		};

		$boot( $settings( true ) );
		$this->assertTrue( shortcode_exists( Switcher::TRP_SHORTCODE ) );
		$this->assertStringContainsString( 'wst-switcher', do_shortcode( '[language-switcher]' ) );

		$boot( $settings( false ) );
		$this->assertFalse( shortcode_exists( Switcher::TRP_SHORTCODE ) );

		update_option( 'active_plugins', array( TranslatePress::PLUGIN_FILE ) );
		$boot( $settings( true ) );
		$this->assertFalse( shortcode_exists( Switcher::TRP_SHORTCODE ), 'TranslatePress serves its own shortcode while active.' );
	}
}

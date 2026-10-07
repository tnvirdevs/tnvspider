<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use WST\Admin\Health;
use WST\Database\Schema;
use WST\Languages\Registry;
use WST\Log\Logger;
use WST\Providers\Capabilities;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\Secrets;
use WST\Providers\Selector;
use WST\Providers\StatusReport;
use WST\Providers\Tester;
use WST\Providers\TestResult;
use WST\Queue\Queue;
use WST\Queue\RateLimiter;
use WST\Queue\Scheduler;
use WST\Queue\Usage;
use WST\Queue\Worker;
use WST\Rest\HealthController;
use WST\Rest\ProvidersController;
use WST\Rest\QueueController;
use WST\Rest\SettingsController;
use WST\Settings;
use WST\Storage\StringStore;
use WST\Tests\Support\FakeProvider;

/**
 * Admin app routes (plan §14) with a fake provider: no HTTP, no keys.
 */
final class AdminRoutesTest extends WP_UnitTestCase {

	private Queue $queue;

	private StringStore $store;

	private FakeProvider $provider;

	private Logger $logger;

	public function set_up(): void {
		parent::set_up();
		foreach ( Secrets::NAMES as $name ) {
			if ( false !== getenv( $name ) || defined( $name ) ) {
				$this->markTestSkipped( $name . ' is set in the environment; it overrides the stored option.' );
			}
		}
		global $wpdb, $wp_rest_server;
		$schema         = new Schema( $wpdb );
		$this->queue    = new Queue( $wpdb, $schema );
		$this->store    = new StringStore( $wpdb, $schema );
		$this->logger   = new Logger( $wpdb, $schema );
		$this->provider = new FakeProvider( 'translatex', new Capabilities( true, 10, 1000 ) );
		delete_option( ProviderState::OPTION );
		delete_option( Secrets::OPTION );
		$values = array(
			'target_language' => 'bn_BD',
			'provider'        => 'translatex',
			'providers'       => array( 'translatex' => array( 'requests_per_minute' => 30 ) ),
		);
		update_option( Settings::OPTION, $values );

		$settings  = new Settings( $values, 'en_US', new Registry() );
		$secrets   = new Secrets();
		$registry  = new ProviderRegistry( array( 'translatex' => $this->provider ) );
		$state     = new ProviderState();
		$selector  = new Selector( $settings, $registry, $state );
		$tester    = new Tester( $settings, $registry, $state, $secrets );
		$status    = new StatusReport( $settings, $secrets, $registry, $state, $tester, new Usage( $wpdb, $schema ), $this->queue );
		$worker    = fn(): Worker => new Worker( $settings, $this->queue, $this->store, new RateLimiter( $wpdb ), new Usage( $wpdb, $schema ), $state, $registry, $selector, new Registry(), $secrets, $this->logger, $wpdb );
		$scheduler = new Scheduler( $this->queue, $worker );

		remove_all_actions( 'rest_api_init' );
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.
		( new QueueController( $this->queue, $scheduler, $worker, $settings, $selector, $registry ) )->boot();
		( new SettingsController( $secrets, $this->queue, $scheduler ) )->boot();
		( new ProvidersController( $status, $tester, $secrets ) )->boot();
		( new HealthController( $settings, new Health( $settings, $wpdb, $schema, $this->queue, $status, $selector, $this->logger ), $this->logger, $this->store, $status ) )->boot();
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.
		wp_clear_scheduled_hook( Scheduler::HOOK );
		delete_option( Secrets::OPTION );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $params JSON body (POST) or query (GET).
	 */
	private function call( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wst/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	private function admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Required arguments are included: WordPress validates them before the
	 * permission check.
	 *
	 * @return array<string, array{0: string, 1: string, 2?: array<string, mixed>}>
	 */
	public function routeProvider(): array {
		return array(
			'settings get'   => array( 'GET', '/settings' ),
			'settings post'  => array( 'POST', '/settings' ),
			'languages'      => array( 'GET', '/languages' ),
			'providers'      => array( 'GET', '/providers' ),
			'provider test'  => array( 'POST', '/providers/translatex/test' ),
			'queue'          => array( 'GET', '/queue' ),
			'retry failed'   => array( 'POST', '/queue/retry-failed' ),
			'queue clear'    => array( 'POST', '/queue/clear', array( 'state' => 'all' ) ),
			'overview'       => array( 'GET', '/overview' ),
			'health'         => array( 'GET', '/health' ),
			'log'            => array( 'GET', '/log' ),
			'machine clear'  => array( 'POST', '/data/machine/clear', array( 'confirm' => true ) ),
			'orphans'        => array( 'GET', '/data/orphans' ),
			'orphans delete' => array( 'POST', '/data/orphans/clear', array( 'confirm' => true ) ),
		);
	}

	/**
	 * @dataProvider routeProvider
	 * @param array<string, mixed> $params Required arguments.
	 */
	public function test_every_route_requires_manage_options( string $method, string $route, array $params = array() ): void {
		$this->assertSame( 401, $this->call( $method, $route, $params )->get_status(), 'Anonymous.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->call( $method, $route, $params )->get_status(), 'Editor (has wst_translate, not manage_options).' );
		$this->assertSame( array(), $this->provider->calls );
	}

	public function test_secrets_are_write_only(): void {
		$this->admin();
		$key = 'sk-test-secret-value-123';

		$saved = $this->call( 'POST', '/settings', array( 'secrets' => array( 'WST_TRANSLATEX_KEY' => $key ) ) );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( $key, ( new Secrets() )->get( 'WST_TRANSLATEX_KEY' ) );

		foreach ( array( $saved, $this->call( 'GET', '/settings' ), $this->call( 'GET', '/providers' ) ) as $response ) {
			$json = (string) wp_json_encode( $response->get_data() );
			$this->assertStringNotContainsString( $key, $json );
			$this->assertStringNotContainsString( 'sk-test', $json, 'Not even a prefix of the key.' );
		}
		$this->assertSame(
			array(
				'set'    => true,
				'source' => 'option',
			),
			$saved->get_data()['secrets']['WST_TRANSLATEX_KEY']
		);

		$this->call( 'POST', '/settings', array( 'secrets' => array( 'WST_TRANSLATEX_KEY' => '' ) ) );
		$this->assertFalse( ( new Secrets() )->has( 'WST_TRANSLATEX_KEY' ), 'An empty value removes the stored key.' );
	}

	public function test_a_secret_from_the_environment_cannot_be_overwritten(): void {
		$this->admin();
		putenv( 'WST_GEMINI_KEY=from-env' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Simulates a key set in the server environment.
		try {
			$response = $this->call( 'POST', '/settings', array( 'secrets' => array( 'WST_GEMINI_KEY' => 'other' ) ) );
			$this->assertSame( 400, $response->get_status() );
			$this->assertArrayHasKey( 'secrets.WST_GEMINI_KEY', $response->get_data()['data']['invalid'] );
			$this->assertSame( 'env', $this->call( 'GET', '/settings' )->get_data()['secrets']['WST_GEMINI_KEY']['source'] );
		} finally {
			putenv( 'WST_GEMINI_KEY' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Restores the environment.
		}
	}

	public function test_save_stores_valid_settings_and_purges_page_caches(): void {
		$this->admin();
		$purged = 0;
		add_action(
			'wst_purge_all',
			static function () use ( &$purged ): void {
				++$purged;
			}
		);

		$response = $this->call(
			'POST',
			'/settings',
			array(
				'settings' => array(
					'target_slug'       => 'bangla',
					'switcher_style'    => 'codes',
					'exclude_selectors' => array( '.no-tr', 'footer .legal' ),
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['saved'] );
		$this->assertSame( 'bangla', $response->get_data()['languages']['target']['slug'] );
		$stored = Settings::load();
		$this->assertSame( 'codes', $stored->choice( 'switcher_style' ) );
		$this->assertSame( array( '.no-tr', 'footer .legal' ), $stored->excludeSelectors() );
		$this->assertSame( 'translatex', $stored->provider(), 'Keys not submitted are kept.' );
		$this->assertSame( 1, $purged );

		$this->call( 'POST', '/settings', array( 'settings' => array( 'target_slug' => 'bangla' ) ) );
		$this->assertSame( 1, $purged, 'No purge when nothing changed.' );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public function invalidProvider(): array {
		return array(
			'bad slug'           => array( array( 'target_slug' => 'Bad Slug!' ), 'target_slug' ),
			'unsupported css'    => array( array( 'exclude_selectors' => array( '.ok', 'div > p' ) ), 'exclude_selectors' ),
			'unknown key'        => array( array( 'flags_style' => 'flags' ), 'flags_style' ),
			'bad choice'         => array( array( 'switcher_style' => 'flags' ), 'switcher_style' ),
			'bad colour'         => array(
				array(
					'switcher_colors' => array(
						'text'       => 'red',
						'background' => '#fff',
						'accent'     => '#000',
					),
				),
				'switcher_colors',
			),
			'same languages'     => array( array( 'target_language' => 'en_US' ), 'target_language' ),
			'fallback = primary' => array( array( 'fallback_provider' => 'translatex' ), 'fallback_provider' ),
			'limit out of range' => array( array( 'providers' => array( 'translatex' => array( 'concurrency' => 9 ) ) ), 'providers' ),
			'prefix collision'   => array(
				array(
					'prefix_default' => true,
					'default_slug'   => 'bn',
				),
				'default_slug',
			),
		);
	}

	/**
	 * @dataProvider invalidProvider
	 * @param array<string, mixed> $settings Submitted settings.
	 */
	public function test_invalid_values_are_rejected_and_nothing_is_saved( array $settings, string $field ): void {
		$this->admin();
		$before = get_option( Settings::OPTION );

		$response = $this->call( 'POST', '/settings', array( 'settings' => $settings ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( $field, $response->get_data()['data']['invalid'] );
		$this->assertSame( $before, get_option( Settings::OPTION ) );
	}

	public function test_key_order_of_nested_values_does_not_matter(): void {
		$this->admin();

		$response = $this->call(
			'POST',
			'/settings',
			array(
				'settings' => array(
					'providers'       => array(
						'translatex' => array(
							'plan'                => 'startup',
							'requests_per_minute' => 40,
						),
					),
					'switcher_colors' => array(
						'accent'     => '#000000',
						'text'       => '#111111',
						'background' => '#ffffff',
					),
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 40, Settings::load()->providerSettings( 'translatex' )['requests_per_minute'] );
	}

	public function test_unsupported_selectors_are_named(): void {
		$this->admin();

		$invalid = $this->call( 'POST', '/settings', array( 'settings' => array( 'exclude_selectors' => array( '.ok', 'div > p' ) ) ) )->get_data()['data']['invalid'];

		$this->assertStringContainsString( 'div > p', $invalid['exclude_selectors'] );
		$this->assertStringNotContainsString( '.ok', $invalid['exclude_selectors'] );
	}

	public function test_languages_list_names_and_direction(): void {
		$this->admin();

		$languages = array_column( $this->call( 'GET', '/languages' )->get_data(), null, 'locale' );

		$this->assertSame( 'বাংলা', $languages['bn_BD']['native'] );
		$this->assertFalse( $languages['bn_BD']['rtl'] );
		$this->assertTrue( $languages['ar']['rtl'] );
	}

	public function test_provider_is_not_verified_until_a_test_passes(): void {
		$this->admin();
		$row = array_column( $this->call( 'GET', '/providers' )->get_data(), null, 'id' )['translatex'];
		$this->assertNull( $row['verified_at'], 'Not verified yet.' );
		$this->assertSame( 'primary', $row['role'] );

		$this->provider->testResult = new TestResult( false, 'TranslateX rejected the API key: invalid api key' );
		$failed                     = $this->call( 'POST', '/providers/translatex/test' )->get_data();
		$this->assertFalse( $failed['ok'] );
		$this->assertNull( $failed['verified_at'] );
		$this->assertNull( array_column( $this->call( 'GET', '/providers' )->get_data(), null, 'id' )['translatex']['verified_at'], 'A failed test does not verify.' );

		$this->provider->testResult = new TestResult( true, 'TranslateX connection works.', array( 'sample' => 'হ্যালো' ) );
		$passed                     = $this->call( 'POST', '/providers/translatex/test' )->get_data();
		$this->assertTrue( $passed['ok'] );
		$this->assertIsInt( $passed['verified_at'] );
		$this->assertSame( $passed['verified_at'], array_column( $this->call( 'GET', '/providers' )->get_data(), null, 'id' )['translatex']['verified_at'] );
	}

	public function test_unknown_provider_has_no_test_route(): void {
		$this->admin();

		$this->assertSame( 404, $this->call( 'POST', '/providers/deepl/test' )->get_status() );
	}

	public function test_queue_status_retry_and_clear(): void {
		$this->admin();
		$ids = array_values( $this->store->recordOnPage( array_fill_keys( array( 'One', 'Two', 'Three' ), 'text' ), '/q/', null ) );
		$this->queue->enqueue( $ids, 'bn_BD', 'translatex', Queue::PRIORITY_VISITOR, time() );
		$row = $this->queue->claim( 'translatex', 'bn_BD', 1, 1000, 60, time() )[0];
		$this->queue->fail( $row, 'Boom', 1, '', time() );

		$status = $this->call( 'GET', '/queue' )->get_data();
		$this->assertSame( array( 2, 0, 1 ), array( $status['pending'], $status['processing'], $status['failed'] ) );
		$this->assertSame( 'translatex', $status['active']['id'] );
		$this->assertSame( 2, $status['eta_seconds'], 'One request of two items at 30 requests per minute.' );

		$this->assertSame( array( 'retried' => 1 ), $this->call( 'POST', '/queue/retry-failed' )->get_data() );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK ) );

		$this->assertSame( 400, $this->call( 'POST', '/queue/clear', array( 'state' => 'processing' ) )->get_status() );
		$this->assertSame( array( 'cleared' => 3 ), $this->call( 'POST', '/queue/clear', array( 'state' => 'all' ) )->get_data() );
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK ), 'Nothing left: the cron event is removed.' );
	}

	public function test_health_reports_each_check_and_the_loopback(): void {
		$this->admin();
		$requested = array();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$requested ) {
				$requested[] = $url;

				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 503,
						'message' => 'Service Unavailable',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);

		$checks = array_column( $this->call( 'GET', '/health' )->get_data()['checks'], null, 'id' );
		$this->assertSame( array( 'php', 'wp', 'tables', 'locks', 'languages', 'provider', 'cron', 'render' ), array_keys( $checks ) );
		$this->assertSame( 'ok', $checks['tables']['status'] );
		$this->assertSame( 'ok', $checks['locks']['status'] );
		$this->assertSame( 'warning', $checks['provider']['status'], 'Not verified yet.' );
		$this->assertSame( array(), $requested, 'No loopback unless asked.' );

		$this->logger->error( 'render', 'Pipeline failed' );
		$checks = array_column( $this->call( 'GET', '/health', array( 'loopback' => 'true' ) )->get_data()['checks'], null, 'id' );
		$this->assertSame( 'error', $checks['render']['status'] );
		$this->assertSame( 'warning', $checks['loopback']['status'] );
		$this->assertStringContainsString( '503', $checks['loopback']['message'] );
		$this->assertCount( 1, $requested );
	}

	public function test_log_filters_by_level_and_source(): void {
		$this->admin();
		$this->logger->warning( 'queue', 'Slow' );
		$this->logger->error( 'render', 'Broken' );

		$entries = $this->call(
			'GET',
			'/log',
			array(
				'level' => 'error',
				'limit' => 10,
			)
		)->get_data();

		$this->assertSame( array( 'Broken' ), array_column( $entries, 'message' ) );
		$this->assertSame( 400, $this->call( 'GET', '/log', array( 'level' => 'debug' ) )->get_status() );
	}

	public function test_clear_machine_translations_needs_confirmation_and_keeps_manual_ones(): void {
		$this->admin();
		$ids = $this->store->recordOnPage( array_fill_keys( array( 'Machine', 'Manual' ), 'text' ), '/d/', null );
		$this->store->saveMachine( $ids['Machine'], 'Machine', 'bn_BD', 'যন্ত্র', 'translatex', 0 );
		$this->store->saveManual( 'Manual', 'text', 'bn_BD', 'হাতে', 1 );

		$this->assertSame( 400, $this->call( 'POST', '/data/machine/clear' )->get_status() );
		$this->assertSame( array( 'deleted' => 1 ), $this->call( 'POST', '/data/machine/clear', array( 'confirm' => true ) )->get_data() );
		$this->assertNull( $this->store->find( 'Machine', 'bn_BD' )['translated'] ?? null );
		$this->assertSame( 'হাতে', $this->store->find( 'Manual', 'bn_BD' )['translated'] ?? null );
	}

	public function test_orphans_dry_run_then_delete(): void {
		global $wpdb;
		$this->admin();
		$ids    = $this->store->recordOnPage( array_fill_keys( array( 'Gone', 'Kept' ), 'text' ), '/o/', null );
		$schema = new Schema( $wpdb );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE string_id = %d', $schema->table( 'occurrences' ), $ids['Gone'] ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = %s', $schema->table( 'strings' ), gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ) );

		$dry = $this->call( 'GET', '/data/orphans', array( 'days' => 30 ) )->get_data();
		$this->assertSame( 1, $dry['count'] );
		$this->assertSame( 4, $dry['chars'] );
		$this->assertSame( 0, $this->call( 'GET', '/data/orphans', array( 'days' => 60 ) )->get_data()['count'], 'Younger than 60 days.' );

		$this->assertSame( 400, $this->call( 'POST', '/data/orphans/clear', array( 'days' => 30 ) )->get_status() );
		$this->assertSame(
			array( 'deleted' => 1 ),
			$this->call(
				'POST',
				'/data/orphans/clear',
				array(
					'days'    => 30,
					'confirm' => true,
				)
			)->get_data()
		);
		$this->assertSame( array( $ids['Kept'] ), $this->store->existingIds( array_values( $ids ) ) );
	}

	public function test_overview_checklist_and_coverage(): void {
		$this->admin();
		$ids = $this->store->recordOnPage( array_fill_keys( array( 'A', 'B' ), 'text' ), '/v/', null );

		$overview = $this->call( 'GET', '/overview' )->get_data();
		$this->assertSame(
			array(
				'language' => true,
				'provider' => false,
				'page'     => false,
			),
			$overview['checklist']
		);

		$this->store->saveMachine( $ids['A'], 'A', 'bn_BD', 'ক', 'translatex', 0 );
		$this->call( 'POST', '/providers/translatex/test' );
		$overview = $this->call( 'GET', '/overview' )->get_data();
		$this->assertSame(
			array(
				'language' => true,
				'provider' => true,
				'page'     => true,
			),
			$overview['checklist']
		);
		$this->assertSame(
			array(
				'total'   => 2,
				'machine' => 1,
				'manual'  => 0,
			),
			$overview['coverage']
		);
	}
}

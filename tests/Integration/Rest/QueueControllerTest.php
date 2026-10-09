<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Rest;

use WP_REST_Request;
use WP_UnitTestCase;
use WST\Database\Schema;
use WST\Languages\Registry;
use WST\Log\Logger;
use WST\Providers\Capabilities;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\Secrets;
use WST\Providers\Selector;
use WST\Queue\Queue;
use WST\Queue\RateLimiter;
use WST\Queue\Scheduler;
use WST\Queue\Usage;
use WST\Queue\Worker;
use WST\Rest\QueueController;
use WST\Settings;
use WST\Storage\StringStore;
use WST\Tests\Support\FakeProvider;

final class QueueControllerTest extends WP_UnitTestCase {

	private Queue $queue;

	private StringStore $store;

	private FakeProvider $provider;

	public function set_up(): void {
		parent::set_up();
		global $wpdb, $wp_rest_server;
		$schema         = new Schema( $wpdb );
		$this->queue    = new Queue( $wpdb, $schema );
		$this->store    = new StringStore( $wpdb, $schema );
		$this->provider = new FakeProvider( 'translatex', new Capabilities( true, 2, 5000 ) );
		delete_option( ProviderState::OPTION );
		delete_option( 'wst_rl_state_translatex' );

		$settings  = new Settings(
			array(
				'target_language' => 'bn_BD',
				'provider'        => 'translatex',
				'providers'       => array( 'translatex' => array( 'requests_per_minute' => 0 ) ),
			),
			'en_US',
			new Registry()
		);
		$registry  = new ProviderRegistry( array( 'translatex' => $this->provider ) );
		$state     = new ProviderState();
		$worker    = fn(): Worker => new Worker( $settings, $this->queue, $this->store, new RateLimiter( $wpdb ), new Usage( $wpdb, $schema ), $state, $registry, new Selector( $settings, $registry, $state ), new Registry(), new Secrets(), new Logger( $wpdb, $schema ), $wpdb );
		$scheduler = new Scheduler( $this->queue, $worker );
		$scheduler->boot();

		// Only this controller: the plugin booted its own one with the real (keyless) registry.
		remove_all_actions( 'rest_api_init' );
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.
		( new QueueController( $this->queue, $scheduler, $worker, $settings, new Selector( $settings, $registry, $state ), $registry ) )->boot();
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.
		wp_clear_scheduled_hook( Scheduler::HOOK );
		parent::tear_down();
	}

	private function dispatch(): \WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/wst/v1/queue/run' ) );
	}

	public function test_requires_manage_options(): void {
		$this->assertSame( 401, $this->dispatch()->get_status(), 'Anonymous.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->dispatch()->get_status(), 'Editor.' );
		$this->assertSame( array(), $this->provider->calls );
	}

	public function test_get_is_not_allowed(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 404, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wst/v1/queue/run' ) )->get_status() );
	}

	public function test_admin_run_processes_exactly_one_batch(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$ids = $this->store->recordOnPage(
			array(
				'One'   => 'text',
				'Two'   => 'text',
				'Three' => 'text',
			),
			'/page/',
			null
		);
		$this->queue->enqueue( array_values( $ids ), 'bn_BD', 'translatex', Queue::PRIORITY_VISITOR, time() );

		$response = $this->dispatch();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $data['batches'] );
		$this->assertSame( 2, $data['translated'], 'One batch of two items (provider cap).' );
		$this->assertSame( 1, $data['pending'] );
		$this->assertCount( 1, $this->provider->calls );
	}
}

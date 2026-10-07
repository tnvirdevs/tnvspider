<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Rest;

use WP_REST_Request;
use WP_UnitTestCase;
use WST\Access;
use WST\Cache\Purger;
use WST\Database\Schema;
use WST\Languages\Registry;
use WST\Log\Logger;
use WST\Modes\Resolver;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\Secrets;
use WST\Providers\Selector;
use WST\Queue\Queue;
use WST\Queue\RateLimiter;
use WST\Queue\Requests;
use WST\Queue\Scheduler;
use WST\Queue\Usage;
use WST\Queue\Worker;
use WST\Rest\PagesController;
use WST\Rest\StringsController;
use WST\Routing\Urls;
use WST\Settings;
use WST\Storage\StringStore;
use WST\Tests\Support\FakeProvider;

final class PagesAndStringsControllerTest extends WP_UnitTestCase {

	private StringStore $store;

	private Queue $queue;

	private FakeProvider $provider;

	private int $manualPost;

	private int $otherPost;

	/**
	 * Purged URLs.
	 *
	 * @var list<string>
	 */
	private array $purged = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb, $wp_rest_server;
		$this->set_permalink_structure( '/%postname%/' );
		$schema         = new Schema( $wpdb );
		$this->store    = new StringStore( $wpdb, $schema );
		$this->queue    = new Queue( $wpdb, $schema );
		$this->provider = new FakeProvider( 'translatex' );
		delete_option( ProviderState::OPTION );
		delete_option( 'wst_rl_state_translatex' );

		$this->manualPost = self::factory()->post->create(
			array(
				'post_name'  => 'handmade-soap',
				'post_title' => 'Handmade soap',
			)
		);
		$this->otherPost  = self::factory()->post->create(
			array(
				'post_name'  => 'about-us',
				'post_title' => 'About us',
				'post_type'  => 'page',
			)
		);
		update_post_meta( $this->manualPost, Resolver::META_KEY, Settings::MODE_MANUAL );
		$ids = $this->store->recordOnPage(
			array(
				'Soap intro' => 'text',
				'Soap price' => 'text',
			),
			'/handmade-soap/',
			$this->manualPost
		);
		$this->store->saveManual( 'Soap price', 'text', 'bn_BD', 'সাবানের দাম', 0 );
		unset( $ids );

		$settings  = new Settings(
			array(
				'target_language' => 'bn_BD',
				'provider'        => 'translatex',
				'providers'       => array( 'translatex' => array( 'requests_per_minute' => 0 ) ),
			),
			'en_US',
			new Registry()
		);
		$target    = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$urls      = Urls::fromHome( (string) get_option( 'home' ), 'wp-json' );
		$modes     = new Resolver( $settings, $urls, $target );
		$registry  = new ProviderRegistry( array( 'translatex' => $this->provider ) );
		$state     = new ProviderState();
		$worker    = fn(): Worker => new Worker( $settings, $this->queue, $this->store, new RateLimiter( $wpdb ), new Usage( $wpdb, $schema ), $state, $registry, new Selector( $settings, $registry, $state ), new Registry(), new Secrets(), new Logger( $wpdb, $schema ), $wpdb );
		$scheduler = new Scheduler( $this->queue, $worker );
		$scheduler->boot();
		$requests = new Requests( $settings, $this->store, $this->queue, new Selector( $settings, $registry, $state ), $scheduler, $modes );
		add_action(
			'wst_purge_url',
			function ( string $url ): void {
				$this->purged[] = $url;
			}
		);

		// Only these controllers: the plugin booted its own with the real (keyless) registry.
		remove_all_actions( 'rest_api_init' );
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.
		( new StringsController( $requests, $target, $scheduler, $worker ) )->boot();
		( new PagesController( $this->store, $modes, $target, new Purger( $this->store, $urls, $target, 'http://example.org' ) ) )->boot();
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.
		wp_clear_scheduled_hook( Scheduler::HOOK );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $params Parameters.
	 */
	private function call( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wst/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		return rest_get_server()->dispatch( $request );
	}

	private function login( string $role ): int {
		$user = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user );

		return $user;
	}

	public function test_capability_is_granted_to_administrators_and_editors_only(): void {
		Access::install();
		$this->assertTrue( get_role( 'administrator' )->has_cap( Access::CAPABILITY ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( Access::CAPABILITY ) );
		$this->assertFalse( get_role( 'author' )->has_cap( Access::CAPABILITY ) );
	}

	public function test_routes_need_the_translator_capability(): void {
		$this->assertSame( 401, $this->call( 'GET', '/pages' )->get_status() );
		$this->login( 'author' );
		$this->assertSame( 403, $this->call( 'GET', '/pages' )->get_status() );
		$this->assertSame(
			403,
			$this->call(
				'POST',
				'/pages/mode',
				array(
					'ids'  => array( $this->otherPost ),
					'mode' => 'off',
				)
			)->get_status()
		);
		$this->assertSame( 403, $this->call( 'POST', '/strings/translate', array( 'post_id' => $this->manualPost ) )->get_status() );
		$this->assertSame( array(), $this->provider->calls );
	}

	public function test_pages_list_shows_mode_source_and_coverage(): void {
		$this->login( 'editor' );
		$response = $this->call( 'GET', '/pages', array( 'search' => 'soap' ) );
		$items    = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( $this->manualPost, $items[0]['id'] );
		$this->assertSame( 'manual', $items[0]['mode'] );
		$this->assertSame(
			array(
				'mode'   => 'manual',
				'source' => 'page',
			),
			$items[0]['effective']
		);
		$this->assertSame(
			array(
				'total'      => 2,
				'translated' => 1,
				'percent'    => 50,
			),
			$items[0]['coverage']
		);
		$this->assertNotNull( $items[0]['last_seen'] );
	}

	public function test_pages_list_filters_by_mode_type_and_ids(): void {
		$this->login( 'editor' );

		$manual = wp_list_pluck( $this->call( 'GET', '/pages', array( 'mode' => 'manual' ) )->get_data(), 'id' );
		$this->assertSame( array( $this->manualPost ), $manual );

		$inherit = wp_list_pluck( $this->call( 'GET', '/pages', array( 'mode' => 'inherit' ) )->get_data(), 'id' );
		$this->assertContains( $this->otherPost, $inherit );
		$this->assertNotContains( $this->manualPost, $inherit );

		$pages = $this->call( 'GET', '/pages', array( 'post_type' => 'page' ) )->get_data();
		$this->assertSame( array( $this->otherPost ), wp_list_pluck( $pages, 'id' ) );
		$this->assertNull( $pages[0]['coverage']['percent'], 'Never seen: no coverage yet.' );

		$this->assertSame( array( $this->otherPost ), wp_list_pluck( $this->call( 'GET', '/pages', array( 'include' => array( $this->otherPost ) ) )->get_data(), 'id' ) );
	}

	public function test_bulk_mode_change_respects_edit_permission_and_purges_changed_pages(): void {
		get_role( 'author' )->add_cap( Access::CAPABILITY );
		$author = $this->login( 'author' );
		$own    = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_name'   => 'my-post',
			)
		);

		$data = $this->call(
			'POST',
			'/pages/mode',
			array(
				'ids'  => array( $own, $this->otherPost, 999999 ),
				'mode' => 'off',
			)
		)->get_data();

		$this->assertSame( array( $own ), $data['updated'] );
		$this->assertSame(
			array(
				$this->otherPost => 'not allowed',
				999999           => 'not found',
			),
			$data['skipped']
		);
		$this->assertSame( 'off', Resolver::pageMode( $own ) );
		$this->assertSame( array( 'http://example.org/bn/my-post/' ), $this->purged );

		$this->call(
			'POST',
			'/pages/mode',
			array(
				'ids'  => array( $own ),
				'mode' => 'inherit',
			)
		);
		$this->assertSame( '', get_post_meta( $own, Resolver::META_KEY, true ), 'Inherit removes the meta.' );
		get_role( 'author' )->remove_cap( Access::CAPABILITY );
	}

	public function test_translate_page_now_on_a_manual_page_queues_and_runs_one_batch(): void {
		$this->login( 'editor' );

		$queued = $this->call( 'POST', '/strings/translate', array( 'post_id' => $this->manualPost ) )->get_data();
		$this->assertSame( 1, $queued['queued'], 'Only the untranslated string; the manual one is never sent.' );
		$this->assertSame( array(), $this->provider->calls, 'mode=queue only queues.' );

		$now = $this->call(
			'POST',
			'/strings/translate',
			array(
				'post_id' => $this->manualPost,
				'mode'    => 'now',
			)
		)->get_data();
		$this->assertSame( 1, $now['translated'] );
		$this->assertSame( '[bn] Soap intro', $this->store->find( 'Soap intro', 'bn_BD' )['translated'] ?? null );
		$this->assertSame( 'সাবানের দাম', $this->store->find( 'Soap price', 'bn_BD' )['translated'] ?? null );
	}

	public function test_translate_validation_and_refusals(): void {
		$this->login( 'editor' );

		$this->assertSame( 400, $this->call( 'POST', '/strings/translate', array() )->get_status(), 'Neither ids nor post_id.' );
		$this->assertSame(
			400,
			$this->call(
				'POST',
				'/strings/translate',
				array(
					'ids'     => array( 1 ),
					'post_id' => 1,
				)
			)->get_status(),
			'Both.'
		);

		update_post_meta( $this->manualPost, Resolver::META_KEY, Settings::MODE_OFF );
		$refused = $this->call( 'POST', '/strings/translate', array( 'post_id' => $this->manualPost ) );
		$this->assertSame( 409, $refused->get_status() );
		$this->assertSame( 'wst_refused', $refused->get_data()['code'] );
	}
}

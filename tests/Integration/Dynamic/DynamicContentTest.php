<?php
/**
 * Plan §13A.5 / §16 Phase 6c: AJAX/REST fragments, the public lookup
 * (read-only, rate-limited, trusted proxy), the dynamic scan and the
 * script loader.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Dynamic;

use WP_UnitTestCase;
use WST\Access;
use WST\Database\Schema;
use WST\Dynamic\Client;
use WST\Dynamic\Fragments;
use WST\Editor\PageTarget;
use WST\Editor\Tokens;
use WST\Languages\Current;
use WST\Languages\Registry;
use WST\Log\Logger;
use WST\Modes\Resolver;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\Selector;
use WST\Queue\AutoQueue;
use WST\Queue\Queue;
use WST\Queue\Scheduler;
use WST\Render\DiscoveryGate;
use WST\Render\Pipeline;
use WST\Rest\DynamicController;
use WST\Routing\LanguageUrls;
use WST\Routing\Urls;
use WST\Settings;
use WST\Storage\StringStore;
use WST\Tests\Support\FakeProvider;

final class DynamicContentTest extends WP_UnitTestCase {

	private StringStore $store;

	private Queue $queue;

	private Schema $schema;

	private int $postId = 0;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->set_permalink_structure( '/%postname%/' );
		$this->schema = new Schema( $wpdb );
		$this->store  = new StringStore( $wpdb, $this->schema );
		$this->queue  = new Queue( $wpdb, $this->schema );
		$this->postId = self::factory()->post->create( array( 'post_name' => 'shop-page' ) );
		Access::install();
		delete_option( ProviderState::OPTION );
		$this->store->saveManual( 'View cart', 'text', 'bn_BD', 'কার্ট দেখুন', 1 );
		$this->store->saveManual( 'Product added', 'text', 'bn_BD', 'পণ্য যোগ হয়েছে', 1 );
		$this->store->saveManual( 'Read <a href="/story/">our story</a>', 'inline', 'bn_BD', '<a href="/story/">আমাদের গল্প</a> পড়ুন', 1 );
		wp_cache_flush();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global: later tests get a fresh one.
		Current::reset();
		unset( $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'], $_GET[ DynamicController::TOKEN_PARAM ] );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		wp_clear_scheduled_hook( Scheduler::HOOK );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $values Settings.
	 */
	private function settings( array $values = array() ): Settings {
		return new Settings(
			$values + array(
				'target_language' => 'bn_BD',
				'provider'        => 'translatex',
			),
			'en_US',
			new Registry()
		);
	}

	/**
	 * @param array<string, mixed> $values Settings.
	 * @return array{0: Pipeline, 1: Settings, 2: AutoQueue, 3: Resolver, 4: Urls}
	 */
	private function parts( array $values = array() ): array {
		global $wpdb;
		$settings = $this->settings( $values );
		$target   = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$urls     = Urls::fromHome( 'http://example.org', 'wp-json' );
		$logger   = new Logger( $wpdb, $this->schema );
		$registry = new ProviderRegistry( array( 'translatex' => new FakeProvider( 'translatex' ) ) );
		$schedule = new Scheduler(
			$this->queue,
			static function (): \WST\Queue\Worker {
				throw new \LogicException( 'No worker.' );
			}
		);
		$auto     = new AutoQueue( $settings, new Selector( $settings, $registry, new ProviderState() ), $this->queue, $schedule );
		$modes    = new Resolver( $settings, $urls, $target );

		return array( new Pipeline( $settings, $target, $this->store, new DiscoveryGate( $settings, $logger ), $logger, $urls, $auto, $modes, null ), $settings, $auto, $modes, $urls );
	}

	private function fragments( array $values = array() ): Fragments {
		global $wpdb;
		[ $pipeline, $settings ] = $this->parts( $values );

		return new Fragments( $settings, $pipeline, new Logger( $wpdb, $this->schema ) );
	}

	public function test_woocommerce_fragments_json_is_translated_safely(): void {
		$body = (string) wp_json_encode(
			array(
				'fragments' => array(
					'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content"><a href="http://example.org/cart/" class="button">View cart</a></div>',
					'span.cart-count'                  => '<span class="cart-count">2</span>',
				),
				'cart_hash' => 'View cart',
				'message'   => 'Product added',
				'name'      => 'View cart',
				'nonce'     => '<b>View cart</b>',
				'count'     => 2,
				'ok'        => true,
				'empty'     => new \stdClass(),
				'list'      => array( 'messages' => array( 'Product added', 'Unknown text' ) ),
			)
		);

		$out = json_decode( $this->fragments()->translateBody( $body ), true );

		$this->assertSame( '<div class="widget_shopping_cart_content"><a href="http://example.org/bn/cart/" class="button">কার্ট দেখুন</a></div>', $out['fragments']['div.widget_shopping_cart_content'], 'HTML values: text translated, internal link kept in the language; keys (selectors) unchanged.' );
		$this->assertSame( '<span class="cart-count">2</span>', $out['fragments']['span.cart-count'] );
		$this->assertSame( 'View cart', $out['cart_hash'], 'Hashes are never touched.' );
		$this->assertSame( 'পণ্য যোগ হয়েছে', $out['message'], 'Plain text under an allowlisted key.' );
		$this->assertSame( 'View cart', $out['name'], 'Plain text under other keys stays.' );
		$this->assertSame( '<b>View cart</b>', $out['nonce'], 'Nonces are never touched, even with HTML.' );
		$this->assertSame( array( 2, true ), array( $out['count'], $out['ok'] ) );
		$this->assertSame( array( 'পণ্য যোগ হয়েছে', 'Unknown text' ), $out['list']['messages'], 'List items use their parent key.' );
		$this->assertStringContainsString( '"empty":{}', $this->fragments()->translateBody( $body ), 'Empty objects stay objects.' );
	}

	public function test_unchanged_invalid_or_deep_bodies_come_back_byte_for_byte(): void {
		$fragments = $this->fragments();
		$plain     = "{\n  \"message\": \"Nothing known\",\n  \"url\": \"http://example.org/x\"\n}";
		$this->assertSame( $plain, $fragments->translateBody( $plain ), 'Nothing translated: original bytes, original formatting.' );
		$this->assertSame( '{"message": "View cart"', $fragments->translateBody( '{"message": "View cart"' ), 'Invalid JSON is left alone.' );
		$this->assertSame( '-1', $fragments->translateBody( '-1' ) );
		$deep = (string) wp_json_encode( array( 'a' => array( 'b' => array( 'c' => array( 'd' => array( 'e' => array( 'message' => 'View cart' ) ) ) ) ) ) );
		$this->assertSame( $deep, $fragments->translateBody( $deep ), 'Values deeper than five levels are not translated.' );
	}

	public function test_html_bodies_and_rest_responses_are_translated_only_on_target_requests(): void {
		$this->assertSame( '<p><a href="/bn/story/">আমাদের গল্প</a> পড়ুন</p>', $this->fragments()->translateBody( '<p>Read <a href="/story/">our story</a></p>' ) );

		$fragments = $this->fragments();
		$fragments->boot();
		$this->assertFalse( has_filter( 'rest_post_dispatch', array( $fragments, 'restResponse' ) ), 'Default-language requests are untouched.' );

		$target = $this->settings()->targetLanguage() ?? throw new \LogicException( 'No target.' );
		Current::set( $target, true );
		$off = $this->fragments( array( 'dynamic_fragments' => false ) );
		$off->boot();
		$this->assertFalse( has_filter( 'rest_post_dispatch', array( $off, 'restResponse' ) ), 'The toggle turns it off.' );
		$fragments->boot();
		$this->assertNotFalse( has_filter( 'rest_post_dispatch', array( $fragments, 'restResponse' ) ) );
		$response = $fragments->restResponse(
			new \WP_REST_Response(
				array(
					'notice' => 'View cart',
					'id'     => 5,
				)
			)
		);
		$this->assertSame(
			array(
				'notice' => 'কার্ট দেখুন',
				'id'     => 5,
			),
			$response->get_data()
		);
	}

	/**
	 * Dynamic routes with a fresh REST server.
	 *
	 * @param array<string, mixed> $values Settings.
	 * @return callable(string, array<string, mixed>): \WP_REST_Response
	 */
	private function rest( array $values = array() ): callable {
		global $wp_rest_server;
		[ $pipeline, $settings, $auto, $modes, $urls ] = $this->parts( $values );
		$target                                        = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$pages = new PageTarget( $urls, new LanguageUrls( $urls, $target, 'http://example.org' ), $target, $modes, $this->store );
		remove_all_actions( 'rest_api_init' );
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.
		( new DynamicController( $settings, $pipeline, $this->store, $pages, $modes, $auto, $target ) )->boot();
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		return static function ( string $route, array $body ): \WP_REST_Response {
			$request = new \WP_REST_Request( 'POST', '/wst/v1/' . $route );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );

			return rest_do_request( $request );
		};
	}

	private function stringCount(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->schema->table( 'strings' ) ) );
	}

	public function test_lookup_is_public_read_only_and_limited(): void {
		$this->assertSame( 404, $this->rest()( 'lookup', array( 'strings' => array( 'View cart' ) ) )->get_status(), 'Off by default: no route.' );

		$call   = $this->rest( array( 'dynamic_lookup' => true ) );
		$before = $this->stringCount();
		wp_set_current_user( 0 );

		$response = $call( 'lookup', array( 'strings' => array( '  View cart ', 'Never seen before', 'Product added' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'  View cart '  => '  কার্ট দেখুন ',
				'Product added' => 'পণ্য যোগ হয়েছে',
			),
			(array) $response->get_data()['translations']
		);
		$this->assertSame( $before, $this->stringCount(), 'Lookups never create strings.' );
		$this->assertSame( '{}', (string) wp_json_encode( $call( 'lookup', array( 'strings' => array( 'Nope' ) ) )->get_data()['translations'] ) );
		$this->assertSame( 400, $call( 'lookup', array( 'strings' => array_fill( 0, DynamicController::MAX_TEXTS + 1, 'x' ) ) )->get_status() );
		$this->assertSame( 400, $call( 'lookup', array( 'strings' => array( str_repeat( 'a', DynamicController::MAX_LENGTH + 1 ) ) ) )->get_status() );
	}

	public function test_lookup_rate_limit_per_visitor_ip_with_trusted_proxy(): void {
		$call                   = $this->rest(
			array(
				'dynamic_lookup' => true,
				'trusted_proxy'  => 'cloudflare',
			)
		);
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$body                   = array( 'strings' => array( 'View cart' ) );

		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.7';
		$codes                            = array();
		for ( $i = 0; $i <= DynamicController::RATE_PER_MINUTE; $i++ ) {
			$codes[] = $call( 'lookup', $body )->get_status();
		}
		$this->assertSame( array_fill( 0, DynamicController::RATE_PER_MINUTE, 200 ), array_slice( $codes, 0, DynamicController::RATE_PER_MINUTE ) );
		$this->assertSame( 429, end( $codes ) );

		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.8';
		$this->assertSame( 200, $call( 'lookup', $body )->get_status(), 'Another visitor behind the same proxy has its own limit.' );
	}

	public function test_client_ip_from_each_trusted_proxy_setting(): void {
		[ $pipeline ]                     = $this->parts();
		$ip                               = function ( array $values ) use ( $pipeline ): string {
			[ , $settings, $auto, $modes, $urls ] = $this->parts( $values );
			$target                               = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );

			return ( new DynamicController( $settings, $pipeline, $this->store, new PageTarget( $urls, new LanguageUrls( $urls, $target, 'http://example.org' ), $target, $modes, $this->store ), $modes, $auto, $target ) )->clientIp();
		};
		$_SERVER['REMOTE_ADDR']           = '10.0.0.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.7';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.1, 203.0.113.9';
		$_SERVER['HTTP_X_REAL_IP']        = '203.0.113.10';

		$this->assertSame( '10.0.0.1', $ip( array() ), 'No proxy: the connection address; headers are ignored.' );
		$this->assertSame( '203.0.113.7', $ip( array( 'trusted_proxy' => 'cloudflare' ) ) );
		$this->assertSame( '203.0.113.9', $ip( array( 'trusted_proxy' => 'forwarded' ) ), 'The address the trusted proxy added (last).' );
		$this->assertSame(
			'203.0.113.10',
			$ip(
				array(
					'trusted_proxy'        => 'custom',
					'trusted_proxy_header' => 'X-Real-IP',
				)
			)
		);
		$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
		$this->assertSame( '10.0.0.1', $ip( array( 'trusted_proxy' => 'cloudflare' ) ), 'An invalid header falls back to the connection address.' );
	}

	public function test_dynamic_scan_records_kind_dynamic_and_queues_by_mode(): void {
		$call  = $this->rest();
		$admin = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $admin );

		$registered = $call( 'scan/dynamic/register', array( 'post_id' => $this->postId ) )->get_data();
		$this->assertStringStartsWith( 'http://example.org/bn/shop-page/?' . DynamicController::TOKEN_PARAM . '=', $registered['url'] );

		$result = $call(
			'scan/dynamic',
			array(
				'token'   => $registered['token'],
				'strings' => array( 'Mini cart is empty', '  View cart ', '42', 'Mini cart is empty' ),
			)
		)->get_data();

		$this->assertSame(
			array(
				'found'  => 2,
				'new'    => 1,
				'queued' => 1,
			),
			$result,
			'Numbers are not translatable; duplicates count once; the translated string is not queued.'
		);
		$this->assertSame( 'dynamic', $this->store->find( 'Mini cart is empty', 'bn_BD' )['kind'] ?? null );
		$this->assertSame( 1, $this->queue->stats()['pending'] );
		global $wpdb;
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i o JOIN %i s ON s.id = o.string_id WHERE o.page_key = %s AND s.original = %s', $this->schema->table( 'occurrences' ), $this->schema->table( 'strings' ), StringStore::pageKey( '/shop-page/' ), 'Mini cart is empty' ) ) );

		update_post_meta( $this->postId, Resolver::META_KEY, 'manual' );
		$token = $call( 'scan/dynamic/register', array( 'post_id' => $this->postId ) )->get_data()['token'];
		$this->assertSame(
			0,
			$call(
				'scan/dynamic',
				array(
					'token'   => $token,
					'strings' => array( 'Another dynamic text' ),
				)
			)->get_data()['queued'],
			'Manual pages record but never queue.'
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame(
			403,
			$call(
				'scan/dynamic',
				array(
					'token'   => $token,
					'strings' => array( 'x y' ),
				)
			)->get_status(),
			'Tokens belong to the user who started the scan.'
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 403, $call( 'scan/dynamic/register', array( 'post_id' => $this->postId ) )->get_status() );
	}

	public function test_script_loads_for_lookup_and_scan_mode_only_where_it_should(): void {
		[ , $settings, , $modes ] = $this->parts( array( 'dynamic_lookup' => true ) );
		$target                   = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$plugin                   = dirname( __DIR__, 3 ) . '/wp-site-translator.php';
		$data                     = static function (): string {
			$script = wp_scripts()->get_data( Client::HANDLE, 'before' );

			return is_array( $script ) ? implode( '', $script ) : '';
		};

		$this->go_to( '/shop-page/' );
		( new Client( $settings, $modes, $plugin ) )->enqueue();
		$this->assertFalse( wp_script_is( Client::HANDLE, 'enqueued' ), 'Not on default-language pages.' );

		Current::set( $target, true );
		( new Client( $settings, $modes, $plugin ) )->enqueue();
		$this->assertTrue( wp_script_is( Client::HANDLE, 'enqueued' ) );
		$this->assertStringContainsString( 'wst\\/v1\\/lookup', $data() );
		$this->assertStringNotContainsString( 'nonce', $data(), 'Cached pages carry no nonce or token.' );

		wp_dequeue_script( Client::HANDLE );
		wp_deregister_script( Client::HANDLE );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$token                                  = Tokens::issue( Tokens::DYNAMIC, '/shop-page/' );
		$_GET[ DynamicController::TOKEN_PARAM ] = $token;
		$_SERVER['REQUEST_URI']                 = '/bn/shop-page/?' . DynamicController::TOKEN_PARAM . '=' . $token . '&x=1';
		$client                                 = new Client( $settings, $modes, $plugin );
		$client->readToken();
		$client->enqueue();
		$this->assertSame( '/bn/shop-page/?x=1', $_SERVER['REQUEST_URI'], 'The token is removed from the request.' );
		$this->assertStringContainsString( '"token":"' . $token . '"', $data() );
		$this->assertStringContainsString( '"nonce":"', $data() );

		update_post_meta( $this->postId, Resolver::META_KEY, 'off' );
		wp_dequeue_script( Client::HANDLE );
		wp_deregister_script( Client::HANDLE );
		$this->go_to( '/shop-page/' );
		Current::set( $target, true );
		( new Client( $settings, new Resolver( $settings, Urls::fromHome( 'http://example.org', 'wp-json' ), $target ), $plugin ) )->enqueue();
		$this->assertFalse( wp_script_is( Client::HANDLE, 'enqueued' ), 'Not on "off" pages.' );
	}
}

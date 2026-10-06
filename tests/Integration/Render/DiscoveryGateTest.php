<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Render;

use WP_UnitTestCase;
use WST\Database\Schema;
use WST\Languages\Registry;
use WST\Log\Logger;
use WST\Render\DiscoveryGate;
use WST\Settings;

final class DiscoveryGateTest extends WP_UnitTestCase {

	private int $postId;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->postId               = self::factory()->post->create( array( 'post_name' => 'hello' ) );
		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/140.0';
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTP_USER_AGENT'] );
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function gate( array $settings = array() ): DiscoveryGate {
		global $wpdb;

		return new DiscoveryGate(
			new Settings( array( 'target_language' => 'bn_BD' ) + $settings, 'en_US', new Registry() ),
			new Logger( $wpdb, new Schema( $wpdb ) )
		);
	}

	public function test_anonymous_visit_to_a_post_may_discover(): void {
		$this->go_to( '/hello/' );

		$this->assertTrue( $this->gate()->allowsRequest( '/hello/' ) );
	}

	public function test_logged_in_users_never_discover(): void {
		$this->go_to( '/hello/' );
		wp_set_current_user( self::factory()->user->create() );

		$this->assertFalse( $this->gate()->allowsRequest( '/hello/' ) );
	}

	public function test_crawlers_and_empty_user_agents_do_not_discover(): void {
		$this->go_to( '/hello/' );
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
		$this->assertFalse( $this->gate()->allowsRequest( '/hello/' ) );

		$_SERVER['HTTP_USER_AGENT'] = '';
		$this->assertFalse( $this->gate()->allowsRequest( '/hello/' ) );

		$this->assertTrue( $this->gate( array( 'block_crawlers' => false ) )->allowsRequest( '/hello/' ) );
	}

	public function test_query_strings_block_discovery_except_allowed_args(): void {
		$this->go_to( '/hello/' );
		$_GET = array( 'utm_source' => 'x' );
		$this->assertFalse( $this->gate()->allowsRequest( '/hello/' ) );

		$_GET = array( 'paged' => '2' );
		$this->assertTrue( $this->gate()->allowsRequest( '/hello/' ) );
	}

	public function test_search_and_404_do_not_discover(): void {
		$this->go_to( '/?s=random' );
		$this->assertFalse( $this->gate()->allowsRequest( '/' ) );

		$this->go_to( '/no-such-page/' );
		$this->assertFalse( $this->gate()->allowsRequest( '/no-such-page/' ) );
	}

	public function test_password_protected_posts_do_not_discover(): void {
		$protected = self::factory()->post->create(
			array(
				'post_name'     => 'secret',
				'post_password' => 'x',
			)
		);
		$this->go_to( get_permalink( $protected ) );

		$this->assertFalse( $this->gate()->allowsRequest( '/secret/' ) );
	}

	public function test_settings_switch_discovery_off(): void {
		$this->go_to( '/hello/' );

		$this->assertFalse( $this->gate( array( 'discover_on_visit' => false ) )->allowsRequest( '/hello/' ) );
		$this->assertFalse( $this->gate( array( 'site_mode' => 'manual' ) )->allowsRequest( '/hello/' ) );
		$this->assertFalse( $this->gate( array( 'never_discover_paths' => array( '/hel*' ) ) )->allowsRequest( '/hello/' ) );
	}

	public function test_post_requests_do_not_discover(): void {
		$this->go_to( '/hello/' );
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->assertFalse( $this->gate()->allowsRequest( '/hello/' ) );
	}

	public function test_filter_can_veto(): void {
		$this->go_to( '/hello/' );
		add_filter( 'wst_discovery_allowed', '__return_false' );

		$this->assertFalse( $this->gate()->allowsRequest( '/hello/' ) );
	}

	public function test_hourly_caps(): void {
		$gate = $this->gate(
			array(
				'discovery_cap_page_hour' => 5,
				'discovery_cap_site_hour' => 7,
			)
		);

		$this->assertSame( 3, $gate->take( '/a/', 3 ) );
		$this->assertSame( 2, $gate->take( '/a/', 3 ), 'Page cap of 5.' );
		$this->assertSame( 0, $gate->take( '/a/', 1 ) );
		$this->assertSame( 2, $gate->take( '/b/', 4 ), 'Site cap of 7.' );
		$this->assertSame( 0, $gate->take( '/c/', 1 ) );
	}

	public function test_bot_detection(): void {
		$this->assertTrue( DiscoveryGate::isBot( 'Mozilla/5.0 (compatible; bingbot/2.0)' ) );
		$this->assertTrue( DiscoveryGate::isBot( 'facebookexternalhit/1.1' ) );
		$this->assertTrue( DiscoveryGate::isBot( 'curl/8.5.0' ) );
		$this->assertFalse( DiscoveryGate::isBot( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Safari/604.1' ) );
	}
}

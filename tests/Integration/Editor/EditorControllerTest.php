<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Editor;

use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use WST\Access;
use WST\Database\Schema;
use WST\Editor\EditorRequest;
use WST\Editor\PageTarget;
use WST\Editor\Tokens;
use WST\Languages\Registry;
use WST\Modes\Resolver;
use WST\Rest\EditorController;
use WST\Routing\LanguageUrls;
use WST\Routing\Urls;
use WST\Settings;
use WST\Storage\StringStore;

final class EditorControllerTest extends WP_UnitTestCase {

	private int $postId;

	public function set_up(): void {
		parent::set_up();
		global $wpdb, $wp_rest_server;
		$this->set_permalink_structure( '/%postname%/' );
		$this->postId = self::factory()->post->create(
			array(
				'post_name'  => 'editor-page',
				'post_title' => 'Editor &amp; page',
			)
		);
		Access::install();
		$settings = new Settings( array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() );
		$target   = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$urls     = Urls::fromHome( (string) get_option( 'home' ), 'wp-json' );
		$pages    = new PageTarget( $urls, new LanguageUrls( $urls, $target, 'http://example.org' ), $target, new Resolver( $settings, $urls, $target ), new StringStore( $wpdb, new Schema( $wpdb ) ) );

		remove_all_actions( 'rest_api_init' );
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.
		( new EditorController( $pages ) )->boot();
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $params Parameters.
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

	private function editor(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	public function test_routes_need_the_translate_capability(): void {
		foreach ( array( array( 'GET', '/editor/page' ), array( 'POST', '/scan/register' ), array( 'POST', '/scan/loopback' ), array( 'POST', '/preview/register' ) ) as [ $method, $route ] ) {
			$this->assertSame( 401, $this->call( $method, $route, array( 'post_id' => $this->postId ) )->get_status(), $route );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 403, $this->call( 'POST', '/scan/register', array( 'post_id' => $this->postId ) )->get_status() );
	}

	public function test_page_facts_for_a_post_and_for_a_path(): void {
		$this->editor();

		$byPost = $this->call( 'GET', '/editor/page', array( 'post_id' => $this->postId ) )->get_data();
		$byPath = $this->call( 'GET', '/editor/page', array( 'path' => '/bn/editor-page/' ) )->get_data();

		$this->assertSame( '/editor-page/', $byPost['path'] );
		$this->assertSame( 'Editor & page', $byPost['title'] );
		$this->assertSame( 'http://example.org/bn/editor-page/', $byPost['target_url'] );
		$this->assertSame( 'auto', $byPost['mode'] );
		$this->assertNull( $byPost['last_scan'] );
		$this->assertSame( $byPost, $byPath, 'A path with or without the prefix names the same page.' );
		$this->assertSame( 'Home page', $this->call( 'GET', '/editor/page', array( 'path' => '/' ) )->get_data()['title'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function badPathProvider(): array {
		return array(
			'other host'      => array( 'http://evil.example/x/' ),
			'protocol-rel'    => array( '//evil.example/x/' ),
			'parent segments' => array( '/a/../wp-admin/' ),
			'admin'           => array( '/wp-admin/' ),
			'rest'            => array( '/wp-json/wst/v1/settings' ),
			'query string'    => array( '/x/?a=1' ),
			'not a path'      => array( 'x' ),
		);
	}

	/**
	 * @dataProvider badPathProvider
	 */
	public function test_only_paths_of_this_site_are_accepted( string $path ): void {
		$this->editor();

		$response = $this->call( 'POST', '/scan/register', array( 'path' => $path ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_unpublished_posts_cannot_be_scanned(): void {
		$this->editor();
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$response = $this->call( 'POST', '/scan/register', array( 'post_id' => $draft ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'not published yet', $response->get_data()['message'], 'Drafts get a message that says what to do.' );
		$private = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$this->assertStringContainsString( 'Only published, public posts', $this->call( 'POST', '/scan/register', array( 'post_id' => $private ) )->get_data()['message'] );
		$this->assertSame( 400, $this->call( 'POST', '/scan/register', array() )->get_status(), 'post_id or path is required.' );
	}

	public function test_scan_register_returns_a_same_origin_url_with_a_page_bound_token(): void {
		$this->editor();

		$data = $this->call(
			'POST',
			'/scan/register',
			array(
				'post_id'        => $this->postId,
				'allow_personal' => true,
			)
		)->get_data();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $data['token'] );
		$this->assertSame( 'http://example.org/bn/editor-page/?wst_scan=' . $data['token'], $data['url'] );
		$record = Tokens::read( $data['token'], Tokens::SCAN );
		$this->assertSame( '/editor-page/', $record['path'] ?? null );
		$this->assertTrue( $record['data']['allow_personal'] ?? null );
		$this->assertNull( Tokens::read( $data['token'], Tokens::PREVIEW ), 'A scan token is not a preview token.' );
		$this->assertStringNotContainsString( $data['token'], (string) wp_json_encode( get_option( '_transient_wst_tok_' . substr( hash( 'sha256', $data['token'] ), 0, 40 ) ) ), 'Only a hash names the stored token.' );
	}

	public function test_loopback_scan_returns_the_summary(): void {
		$this->editor();
		$requested = array();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$requested ) {
				$requested[] = array( $url, $args['headers']['Accept'] ?? '' );
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode(
						array(
							'scan_id' => $query[ EditorRequest::SCAN_PARAM ],
							'strings' => 12,
						)
					),
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
				);
			},
			10,
			3
		);

		$data = $this->call( 'POST', '/scan/loopback', array( 'post_id' => $this->postId ) )->get_data();

		$this->assertSame( 12, $data['strings'] );
		$this->assertSame( 'server', $data['via'] );
		$this->assertStringStartsWith( 'http://example.org/bn/editor-page/?wst_scan=', $requested[0][0] );
		$this->assertSame( 'application/json', $requested[0][1] );
	}

	public function test_loopback_detects_a_cached_copy(): void {
		$this->editor();
		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array(),
				'body'     => '{"scan_id":"0123456789abcdef0123456789abcdef","strings":3}',
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
			)
		);

		$response = $this->call( 'POST', '/scan/loopback', array( 'post_id' => $this->postId ) );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'wst_scan_cached', $response->get_data()['code'] );
	}

	public function test_loopback_explains_pages_that_redirect_visitors(): void {
		$this->editor();
		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array( 'location' => 'http://example.org/bn/cart/' ),
				'body'     => '',
				'response' => array( 'code' => 302 ),
				'cookies'  => array(),
			)
		);

		$response = $this->call( 'POST', '/scan/loopback', array( 'post_id' => $this->postId ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'wst_scan_redirected', $response->get_data()['code'] );
		$this->assertStringContainsString( 'HTTP 302', $response->get_data()['message'] );
		$this->assertStringContainsString( 'checkout', $response->get_data()['message'] );
	}

	public function test_preview_register(): void {
		$this->editor();

		$data = $this->call(
			'POST',
			'/preview/register',
			array(
				'post_id' => $this->postId,
				'scripts' => true,
			)
		)->get_data();

		$this->assertSame( 'http://example.org/bn/editor-page/?wst_preview=' . $data['token'], $data['url'] );
		$this->assertTrue( Tokens::read( $data['token'], Tokens::PREVIEW )['data']['scripts'] ?? null );
	}
}

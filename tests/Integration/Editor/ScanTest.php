<?php
/**
 * Plan §11 scan flow: token, visitor view, recording without caps, summary.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Editor;

use WP_UnitTestCase;
use WPDieException;
use WST\Database\Schema;
use WST\Editor\EditorRequest;
use WST\Editor\Preview;
use WST\Editor\Tokens;
use WST\Languages\Current;
use WST\Languages\Registry;
use WST\Log\Logger;
use WST\Modes\OffPages;
use WST\Modes\Resolver;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\Selector;
use WST\Queue\AutoQueue;
use WST\Queue\Queue;
use WST\Queue\Scheduler;
use WST\Render\DiscoveryGate;
use WST\Render\Pipeline;
use WST\Routing\LanguageUrls;
use WST\Routing\Router;
use WST\Routing\Urls;
use WST\Settings;
use WST\Storage\StringStore;
use WST\Tests\Support\FakeProvider;

final class ScanTest extends WP_UnitTestCase {

	private const PAGE = '<html lang="en-US"><body><p>Known elsewhere</p><p>Scan one</p><p>Scan two</p><p>Scan three</p></body></html>';

	private int $postId;

	private int $admin;

	private StringStore $store;

	private Queue $queue;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->set_permalink_structure( '/%postname%/' );
		$this->postId = self::factory()->post->create( array( 'post_name' => 'scan-page' ) );
		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$schema       = new Schema( $wpdb );
		$this->store  = new StringStore( $wpdb, $schema );
		$this->queue  = new Queue( $wpdb, $schema );
		// Seen on another page first: visits never record it for this page.
		$this->store->recordOnPage( array( 'Known elsewhere' => 'text' ), '/other/', null );
		delete_option( ProviderState::OPTION );
		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0';
		wp_cache_flush();
	}

	public function tear_down(): void {
		Current::reset();
		EditorRequest::reset();
		unset( $_SERVER['HTTP_USER_AGENT'], $_GET[ EditorRequest::SCAN_PARAM ] );
		remove_all_filters( 'show_admin_bar' );
		wp_clear_scheduled_hook( Scheduler::HOOK );
		parent::tear_down();
	}

	/**
	 * Request $uri with a scan token and return the pipeline's answer.
	 *
	 * @param string               $uri    Target-language URI including ?wst_scan=.
	 * @param array<string, mixed> $values Settings.
	 * @return array<string, mixed> Decoded summary.
	 */
	private function scan( string $uri, array $values = array() ): array {
		$summary = json_decode( $this->render( $uri, $values ), true );
		$this->assertIsArray( $summary );

		return $summary;
	}

	/**
	 * Request $uri (with a scan or preview token) and return the pipeline output.
	 *
	 * @param string               $uri    Target-language URI.
	 * @param array<string, mixed> $values Settings.
	 * @param string               $page   Buffered page.
	 */
	private function render( string $uri, array $values = array(), string $page = self::PAGE ): string {
		global $wpdb;
		$settings = new Settings(
			$values + array(
				'target_language'         => 'bn_BD',
				'provider'                => 'translatex',
				'discovery_cap_page_hour' => 1,
			),
			'en_US',
			new Registry()
		);
		$target   = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$urls     = Urls::fromHome( (string) get_option( 'home' ), 'wp-json' );
		$logger   = new Logger( $wpdb, new Schema( $wpdb ) );
		$registry = new ProviderRegistry( array( 'translatex' => new FakeProvider( 'translatex' ) ) );
		$schedule = new Scheduler(
			$this->queue,
			static function (): \WST\Queue\Worker {
				throw new \LogicException( 'Scans must not run the worker.' );
			}
		);
		$modes    = new Resolver( $settings, $urls, $target );
		$pipeline = new Pipeline( $settings, $target, $this->store, new DiscoveryGate( $settings, $logger ), $logger, $urls, new AutoQueue( $settings, new Selector( $settings, $registry, new ProviderState() ), $this->queue, $schedule ), $modes, new Preview( 'http://example.org/preview.js' ) );
		$this->unhookRouters();
		// The Router detects the language from REQUEST_URI when it boots.
		$_SERVER['REQUEST_URI'] = $uri;
		( new Router( $settings, $target, $urls ) )->boot();
		// go_to() fills $_GET only for absolute URLs; home_url() would get the prefix from the Router.
		$this->go_to( 'http://example.org' . $uri );
		$editor = new EditorRequest( $urls, $target );
		$editor->boot();
		$editor->verify();
		( new OffPages( $settings, $modes, new LanguageUrls( $urls, $target, 'http://example.org' ) ) )->handle();

		$pipeline->start();
		ob_end_clean();
		$context = $pipeline->context() ?? throw new \LogicException( 'No context.' );
		return $pipeline->process( $page, $context );
	}

	/**
	 * Remove the hooks of a Router booted by an earlier scan() in the same test.
	 */
	private function unhookRouters(): void {
		global $wp_filter;
		foreach ( array( 'locale', 'gettext_with_context', 'do_parse_request', 'parse_request', 'home_url', 'wp_redirect' ) as $hook ) {
			foreach ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array() as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Router ) {
						remove_filter( $hook, $callback['function'], $priority );
					}
				}
			}
		}
		Current::reset();
	}

	private function token( string $path = '/scan-page/', array $data = array() ): string {
		wp_set_current_user( $this->admin );

		return Tokens::issue( Tokens::SCAN, $path, $data );
	}

	/**
	 * @return list<string>
	 */
	private function occurrences(): array {
		global $wpdb;
		$schema = new Schema( $wpdb );

		return array_map(
			'strval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT s.original FROM %i o JOIN %i s ON s.id = o.string_id WHERE o.page_key = %s ORDER BY s.original',
					$schema->table( 'occurrences' ),
					$schema->table( 'strings' ),
					StringStore::pageKey( '/scan-page/' )
				)
			)
		);
	}

	public function test_scan_records_every_string_without_caps_and_queues_on_auto_pages(): void {
		$token = $this->token();

		$summary = $this->scan( '/bn/scan-page/?wst_scan=' . $token );

		$this->assertSame( $token, $summary['scan_id'] );
		$this->assertSame( '/scan-page/', $summary['path'] );
		$this->assertSame( $this->postId, $summary['post_id'] );
		$this->assertSame( 'auto', $summary['mode'] );
		$this->assertSame( array( 4, 3, 0, 4, 4 ), array( $summary['strings'], $summary['new'], $summary['translated'], $summary['untranslated'], $summary['queued'] ), 'The hourly cap of 1 does not apply to scans.' );
		$this->assertSame( array( 'Known elsewhere', 'Scan one', 'Scan three', 'Scan two' ), $this->occurrences(), 'Strings first seen elsewhere are recorded for this page too.' );
		$this->assertNotNull( $this->store->pageTimes( '/scan-page/' )['last_scan'] );
		$this->assertSame( 0, get_current_user_id(), 'The page is rendered as a visitor sees it.' );
		$this->assertFalse( apply_filters( 'show_admin_bar', true ), 'No admin bar strings.' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
	}

	public function test_rescan_removes_strings_no_longer_on_the_page(): void {
		$this->store->recordOnPage( array( 'Removed since' => 'text' ), '/scan-page/', $this->postId );

		$this->scan( '/bn/scan-page/?wst_scan=' . $this->token() );

		$this->assertNotContains( 'Removed since', $this->occurrences() );
	}

	public function test_manual_page_records_but_never_queues(): void {
		update_post_meta( $this->postId, Resolver::META_KEY, 'manual' );

		$summary = $this->scan( '/bn/scan-page/?wst_scan=' . $this->token() );

		$this->assertSame( array( 'manual', 4, 0 ), array( $summary['mode'], $summary['strings'], $summary['queued'] ) );
		$this->assertSame( 0, $this->queue->stats()['pending'] );
	}

	public function test_off_page_answers_without_recording_or_redirecting(): void {
		update_post_meta( $this->postId, Resolver::META_KEY, 'off' );
		$redirects = 0;
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$redirects ) {
				++$redirects;

				return $location;
			}
		);

		$summary = $this->scan( '/bn/scan-page/?wst_scan=' . $this->token() );

		$this->assertSame( array( 'off', 0 ), array( $summary['mode'], $summary['strings'] ) );
		$this->assertSame( array(), $this->occurrences() );
		$this->assertSame( 0, $redirects );
	}

	public function test_personal_page_asks_first_then_records_without_queueing(): void {
		$summary = $this->scan( '/bn/?s=order&wst_scan=' . $this->token( '/' ) );
		$this->assertTrue( $summary['personal'] );
		$this->assertTrue( $summary['needs_confirmation'] );
		$this->assertNull( $this->store->find( 'Scan one', 'bn_BD' ), 'Nothing recorded before confirmation.' );

		$summary = $this->scan( '/bn/?s=order&wst_scan=' . $this->token( '/', array( 'allow_personal' => true ) ) );

		$this->assertSame( array( 4, 0 ), array( $summary['strings'], $summary['queued'] ) );
		$this->assertSame( 0, $this->queue->stats()['pending'], 'Personal pages are never auto-queued.' );
	}

	public function test_scan_token_works_once(): void {
		$token = $this->token();
		$this->scan( '/bn/scan-page/?wst_scan=' . $token );

		$this->expectException( WPDieException::class );
		$this->scan( '/bn/scan-page/?wst_scan=' . $token );
	}

	public function test_token_is_bound_to_its_page(): void {
		$this->expectException( WPDieException::class );
		$this->scan( '/bn/scan-page/?wst_scan=' . $this->token( '/other/' ) );
	}

	public function test_unknown_token_is_refused(): void {
		$this->expectException( WPDieException::class );
		$this->scan( '/bn/scan-page/?wst_scan=' . str_repeat( 'a', 32 ) );
	}

	public function test_default_language_url_is_refused(): void {
		$this->expectException( WPDieException::class );
		$this->scan( '/scan-page/?wst_scan=' . $this->token() );
	}

	public function test_preview_shows_translations_without_page_scripts_or_discovery(): void {
		$this->store->saveManual( 'Scan one', 'text', 'bn_BD', 'স্ক্যান এক', 0 );
		wp_set_current_user( $this->admin );
		$token = Tokens::issue( Tokens::PREVIEW, '/scan-page/', array( 'scripts' => false ) );
		$page  = '<html lang="en-US"><body><p>Scan one</p><p>Brand new preview text</p><script>throw new Error("hostile")</script></body></html>';

		$html = $this->render( '/bn/scan-page/?wst_preview=' . $token, array(), $page );
		$this->assertStringContainsString( '<p>স্ক্যান এক</p>', $html );
		$this->assertStringNotContainsString( 'hostile', $html );
		$this->assertStringContainsString( 'id="wst-preview-js"', $html );
		$this->assertNull( $this->store->find( 'Brand new preview text', 'bn_BD' ), 'Previews never record strings.' );

		$again = $this->render( '/bn/scan-page/?wst_preview=' . $token, array(), $page );
		$this->assertStringContainsString( '<p>স্ক্যান এক</p>', $again, 'A preview token works until it expires (the frame may reload).' );
	}

	public function test_preview_of_an_off_page_shows_the_original(): void {
		update_post_meta( $this->postId, Resolver::META_KEY, 'off' );
		$this->store->saveManual( 'Scan one', 'text', 'bn_BD', 'স্ক্যান এক', 0 );
		wp_set_current_user( $this->admin );

		$html = $this->render( '/bn/scan-page/?wst_preview=' . Tokens::issue( Tokens::PREVIEW, '/scan-page/' ) );

		$this->assertStringContainsString( '<p>Scan one</p>', $html );
		$this->assertStringContainsString( 'id="wst-preview-js"', $html );
	}
}

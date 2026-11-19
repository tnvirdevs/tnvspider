<?php
/**
 * Automatic translation of every page: the background scan walks the page
 * list, scans each eligible page once through the server, rescans changed
 * posts, queues known strings of pages scanned earlier (automatic pages
 * only), and stays idle when it cannot translate.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Site;

use WP_UnitTestCase;
use WST\Database\Schema;
use WST\Editor\EditorRequest;
use WST\Editor\LoopbackScan;
use WST\Editor\PageTarget;
use WST\Languages\Registry;
use WST\Modes\Resolver;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\Selector;
use WST\Queue\AutoQueue;
use WST\Queue\Queue;
use WST\Queue\Scheduler;
use WST\Routing\LanguageUrls;
use WST\Routing\Urls;
use WST\Settings;
use WST\Site\BackgroundScan;
use WST\Site\SitePages;
use WST\Storage\StringStore;
use WST\Tests\Support\FakeProvider;

final class BackgroundScanTest extends WP_UnitTestCase {

	private StringStore $store;

	private Queue $queue;

	/** @var array<string, int> */
	private array $posts = array();

	/**
	 * Paths the server-side scans requested, in order.
	 *
	 * @var list<string>
	 */
	private array $scanned = array();

	public function set_up(): void {
		global $wpdb;
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();
		flush_rewrite_rules( false );
		$schema      = new Schema( $wpdb );
		$this->store = new StringStore( $wpdb, $schema );
		$this->queue = new Queue( $wpdb, $schema );
		delete_option( ProviderState::OPTION );
		delete_option( BackgroundScan::OPTION );
		update_option( 'show_on_front', 'posts' );

		$this->posts['hello']  = self::factory()->post->create( array( 'post_name' => 'hello' ) );
		$this->posts['about']  = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$this->posts['legal']  = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'legal',
			)
		);
		$this->posts['secret'] = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_name'     => 'secret',
				'post_password' => 'pw',
			)
		);
		update_post_meta( $this->posts['legal'], Resolver::META_KEY, 'off' );

		// The scan request: answer as the scanned page would, and record one string on it.
		$this->scanned = array();
		add_filter( 'pre_http_request', array( $this, 'answerScan' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'answerScan' ), 10 );
		wp_clear_scheduled_hook( BackgroundScan::HOOK );
		wp_clear_scheduled_hook( BackgroundScan::DAILY_HOOK );
		wp_clear_scheduled_hook( Scheduler::HOOK );
		parent::tear_down();
	}

	/**
	 * Stand-in for the page's scan response.
	 *
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $args Request arguments.
	 * @param string               $url  URL.
	 * @return mixed
	 */
	public function answerScan( $pre, $args, $url ) {
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		if ( ! isset( $query[ EditorRequest::SCAN_PARAM ] ) ) {
			return $pre;
		}
		$urls            = Urls::fromHome( (string) get_option( 'home' ), 'wp-json' );
		$path            = (string) $urls->unprefixedPath( (string) wp_parse_url( $url, PHP_URL_PATH ), 'bn' );
		$this->scanned[] = $path;
		$this->store->recordScan( array( 'Text of ' . $path => 'text' ), $path, null );

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode(
				array(
					'scan_id' => $query[ EditorRequest::SCAN_PARAM ],
					'strings' => 1,
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * @param array<string, mixed> $values Settings.
	 * @param bool                 $withProvider Whether a provider can translate.
	 */
	private function scanner( array $values = array(), bool $withProvider = true ): BackgroundScan {
		global $wpdb;
		$settings  = new Settings( $values + array( 'target_language' => 'bn_BD' ) + ( $withProvider ? array( 'provider' => 'translatex' ) : array() ), 'en_US', new Registry() );
		$target    = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$urls      = Urls::fromHome( (string) get_option( 'home' ), 'wp-json' );
		$modes     = new Resolver( $settings, $urls, $target );
		$selector  = new Selector( $settings, new ProviderRegistry( array( 'translatex' => new FakeProvider( 'translatex' ) ) ), new ProviderState() );
		$scheduler = new Scheduler(
			$this->queue,
			static function (): \WST\Queue\Worker {
				throw new \LogicException( 'The scan must not run the worker.' );
			}
		);
		$pages     = new PageTarget( $urls, new LanguageUrls( $urls, $target, LanguageUrls::origin( (string) get_option( 'home' ) ) ), $target, $modes, $this->store );

		return new BackgroundScan( $settings, new SitePages( $settings, $modes, $urls, $target ), $pages, $this->store, new AutoQueue( $settings, $selector, $this->queue, $scheduler ), $target, new LoopbackScan(), $modes, $wpdb );
	}

	public function test_scans_every_eligible_page_once_then_finishes(): void {
		$scan = $this->scanner();
		$scan->schedule();
		$this->assertNotFalse( wp_next_scheduled( BackgroundScan::HOOK ), 'A pass is due on a fresh site.' );
		$this->assertNotFalse( wp_next_scheduled( BackgroundScan::DAILY_HOOK ) );

		$scan->run();

		$this->assertEqualsCanonicalizing( array( '/', '/hello/', '/about/', '/category/uncategorized/' ), $this->scanned, 'Off and password-protected pages are not scanned.' );
		$status = $scan->status();
		$this->assertSame( array( 4, 0 ), array( $status['scanned'], $status['failed'] ) );
		$this->assertGreaterThan( 0, $status['finished'] );
		$this->assertSame( $status['total'], $status['checked'] );
		$this->assertFalse( wp_next_scheduled( BackgroundScan::HOOK ), 'Nothing left: the every-minute run stops.' );
	}

	public function test_a_new_pass_rescans_nothing_unchanged_but_queues_known_strings_of_automatic_pages(): void {
		$scan = $this->scanner();
		$scan->run();
		update_post_meta( $this->posts['about'], Resolver::META_KEY, 'manual' );
		$this->scanned = array();

		$scan->restart();
		$scan->run();

		$this->assertSame( array(), $this->scanned, 'Pages scanned today and posts not changed since are not scanned again.' );
		$queued = $this->queue->stats();
		$this->assertSame( 3, $queued['pending'], 'Known untranslated strings of the home page, the post and the category are queued; the manual page is not.' );
	}

	public function test_published_or_updated_posts_are_rescanned_first(): void {
		$scan = $this->scanner();
		$scan->run();
		$this->scanned = array();

		$scan->postChanged( 'publish', 'publish', get_post( $this->posts['hello'] ) );
		$scan->postChanged( 'draft', 'publish', get_post( $this->posts['about'] ) );
		$this->assertSame( 1, $scan->status()['changed'], 'Only published, public posts are remembered.' );
		$this->assertNotFalse( wp_next_scheduled( BackgroundScan::HOOK ) );
		$scan->run();

		$this->assertSame( array( '/hello/' ), $this->scanned );
		$this->assertSame( 0, $scan->status()['changed'] );
	}

	public function test_idle_when_off_in_manual_site_mode_or_without_a_provider(): void {
		$off = $this->scanner( array( 'auto_site_scan' => false ) );
		$off->schedule();
		$off->run();
		$this->assertFalse( wp_next_scheduled( BackgroundScan::HOOK ) );
		$this->assertFalse( wp_next_scheduled( BackgroundScan::DAILY_HOOK ) );
		$this->assertFalse( $off->status()['on'] );

		$manual = $this->scanner( array( 'site_mode' => Settings::MODE_MANUAL ) );
		$manual->run();
		$this->assertSame( 'manual_site', $manual->status()['blocked'] );

		$none = $this->scanner( array(), false );
		$none->run();
		$this->assertSame( 'no_provider', $none->status()['blocked'] );
		$this->assertSame( array(), $this->scanned, 'Nothing is scanned while the scan cannot lead to translations.' );
	}

	public function test_on_by_default(): void {
		$this->assertTrue( ( new Settings( array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() ) )->flag( 'auto_site_scan' ) );
	}
}

<?php
/**
 * "Translate entire site" (plan §8, §6A): the URL list with exclusions,
 * the estimate against the monthly budget, and queueing at bulk priority.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Site;

use WP_UnitTestCase;
use WST\Database\Schema;
use WST\Languages\Registry;
use WST\Modes\Resolver;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\Secrets;
use WST\Providers\Selector;
use WST\Providers\StatusReport;
use WST\Providers\Tester;
use WST\Queue\Queue;
use WST\Queue\Requests;
use WST\Queue\Scheduler;
use WST\Queue\Usage;
use WST\Rest\SiteController;
use WST\Routing\Urls;
use WST\Settings;
use WST\Site\SitePages;
use WST\Storage\StringStore;
use WST\Tests\Support\FakeProvider;

final class SiteTranslationTest extends WP_UnitTestCase {

	private StringStore $store;

	private Queue $queue;

	private Schema $schema;

	/** @var array<string, int> */
	private array $posts = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->set_permalink_structure( '/%postname%/' );
		// Registered after pretty permalinks are on, so it and the categories get rewrite rules.
		register_post_type(
			'product',
			array(
				'public'      => true,
				'has_archive' => 'shop',
				'label'       => 'Products',
			)
		);
		register_post_type( 'private_thing', array( 'public' => false ) );
		create_initial_taxonomies();
		flush_rewrite_rules( false );
		$this->schema = new Schema( $wpdb );
		$this->store  = new StringStore( $wpdb, $this->schema );
		$this->queue  = new Queue( $wpdb, $this->schema );
		delete_option( ProviderState::OPTION );

		$this->posts['front']    = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_name'  => 'welcome',
				'post_title' => 'Welcome',
			)
		);
		$this->posts['about']    = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_name'  => 'about',
				'post_title' => 'About &amp; us',
			)
		);
		$this->posts['off']      = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'legal',
			)
		);
		$this->posts['manual']   = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'handmade',
			)
		);
		$this->posts['secret']   = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_name'     => 'secret',
				'post_password' => 'pw',
			)
		);
		$this->posts['cart']     = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'cart',
			)
		);
		$this->posts['internal'] = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'internal',
			)
		);
		$this->posts['draft']    = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'draft',
				'post_status' => 'draft',
			)
		);
		$this->posts['product']  = self::factory()->post->create(
			array(
				'post_type' => 'product',
				'post_name' => 'widget',
			)
		);
		$this->posts['hidden']   = self::factory()->post->create( array( 'post_type' => 'private_thing' ) );
		$this->posts['post']     = self::factory()->post->create( array( 'post_name' => 'hello' ) );
		$news                    = self::factory()->category->create(
			array(
				'slug' => 'news',
				'name' => 'News',
			)
		);
		self::factory()->category->create( array( 'slug' => 'empty' ) );
		wp_set_post_categories( $this->posts['post'], array( $news ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->posts['front'] );
		update_post_meta( $this->posts['off'], Resolver::META_KEY, 'off' );
		update_post_meta( $this->posts['manual'], Resolver::META_KEY, 'manual' );
		$cart = $this->posts['cart'];
		add_filter( 'wst_personal_post_ids', static fn( array $ids ): array => array_merge( $ids, array( $cart ) ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global: later tests get a fresh one.
		unregister_post_type( 'product' );
		unregister_post_type( 'private_thing' );
		wp_clear_scheduled_hook( Scheduler::HOOK );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $values Settings.
	 */
	private function settings( array $values = array() ): Settings {
		return new Settings(
			$values + array(
				'target_language'      => 'bn_BD',
				'provider'             => 'translatex',
				'never_discover_paths' => array( '/internal/*' ),
			),
			'en_US',
			new Registry()
		);
	}

	private function sitePages( Settings $settings ): SitePages {
		$target = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$urls   = Urls::fromHome( (string) get_option( 'home' ), 'wp-json' );

		return new SitePages( $settings, new Resolver( $settings, $urls, $target ), $urls, $target );
	}

	/**
	 * Every entry of the list, keyed by path.
	 *
	 * @return array<string, array{path: string, post_id: int|null, title: string, type: string, skip: string}>
	 */
	private function all( SitePages $pages ): array {
		$first = $pages->slice( 1 );
		$items = $first['items'];
		for ( $page = 2; $page <= $first['pages']; $page++ ) {
			$items = array_merge( $items, $pages->slice( $page )['items'] );
		}

		return array_column( $items, null, 'path' );
	}

	public function test_list_has_home_archives_posts_and_terms_with_exclusions(): void {
		$list = $this->all( $this->sitePages( $this->settings( array( 'editor_mt_on_manual' => false ) ) ) );

		$this->assertSame( array( '/', '/shop/', '/about/', '/legal/', '/handmade/', '/secret/', '/cart/', '/internal/', '/product/widget/', '/hello/', '/category/news/' ), array_keys( $list ) );
		$this->assertSame( $this->posts['front'], $list['/']['post_id'], 'The static front page is the home entry, not listed twice.' );
		$this->assertSame( 'home', $list['/']['type'] );
		$this->assertSame( array( 'archive', 'Products' ), array( $list['/shop/']['type'], $list['/shop/']['title'] ) );
		$this->assertSame( 'About & us', $list['/about/']['title'] );
		$this->assertSame( 'category', $list['/category/news/']['type'] );
		$skips = array_map( static fn( array $item ): string => $item['skip'], $list );
		$this->assertSame(
			array(
				'/'                => '',
				'/shop/'           => '',
				'/about/'          => '',
				'/legal/'          => SitePages::SKIP_OFF,
				'/handmade/'       => SitePages::SKIP_MANUAL,
				'/secret/'         => SitePages::SKIP_PASSWORD,
				'/cart/'           => SitePages::SKIP_PERSONAL,
				'/internal/'       => SitePages::SKIP_NEVER,
				'/product/widget/' => '',
				'/hello/'          => '',
				'/category/news/'  => '',
			),
			$skips
		);
		$this->assertSame( '', $this->sitePages( $this->settings( array( 'editor_mt_on_manual' => true ) ) )->skipReason( $this->posts['manual'], '/handmade/' ), 'Manual pages are included when machine translation is allowed on them.' );
	}

	public function test_slices_are_stable_across_sources(): void {
		self::factory()->post->create_many( SitePages::PER_PAGE, array( 'post_type' => 'product' ) );
		$pages = $this->sitePages( $this->settings() );

		$first  = $pages->slice( 1 );
		$second = $pages->slice( 2 );

		$this->assertSame( 2, $first['pages'] );
		$this->assertSame( 11 + SitePages::PER_PAGE, $first['total'] );
		$this->assertCount( SitePages::PER_PAGE, $first['items'] );
		$this->assertCount( 11, $second['items'] );
		$this->assertSame( '/category/news/', end( $second['items'] )['path'], 'Terms come last.' );
		$paths = array_merge( array_column( $first['items'], 'path' ), array_column( $second['items'], 'path' ) );
		$this->assertSame( $paths, array_values( array_unique( $paths ) ), 'No page twice.' );
	}

	/**
	 * Controller with a fake provider; returns a REST caller.
	 *
	 * @param array<string, mixed> $values Settings.
	 * @return callable(string, string, array<string, mixed>): \WP_REST_Response
	 */
	private function rest( array $values = array() ): callable {
		global $wpdb, $wp_rest_server;
		$settings  = $this->settings( $values );
		$target    = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$urls      = Urls::fromHome( (string) get_option( 'home' ), 'wp-json' );
		$modes     = new Resolver( $settings, $urls, $target );
		$registry  = new ProviderRegistry( array( 'translatex' => new FakeProvider( 'translatex' ) ) );
		$state     = new ProviderState();
		$secrets   = new Secrets();
		$selector  = new Selector( $settings, $registry, $state );
		$scheduler = new Scheduler(
			$this->queue,
			static function (): \WST\Queue\Worker {
				throw new \LogicException( 'Queueing must not run the worker.' );
			}
		);
		$requests  = new Requests( $settings, $this->store, $this->queue, $selector, $scheduler, $modes );
		$status    = new StatusReport( $settings, $secrets, $registry, $state, new Tester( $settings, $registry, $state, $secrets ), new Usage( $wpdb, $this->schema ), $this->queue );

		remove_all_actions( 'rest_api_init' );
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.
		( new SiteController( new SitePages( $settings, $modes, $urls, $target ), $this->store, $requests, $this->queue, $status, $target ) )->boot();
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		return static function ( string $method, string $route, array $params ): \WP_REST_Response {
			$request = new \WP_REST_Request( $method, '/wst/v1/site/' . $route );
			if ( 'GET' === $method ) {
				$request->set_query_params( $params );
			} else {
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( (string) wp_json_encode( $params ) );
			}

			return rest_do_request( $request );
		};
	}

	/**
	 * Strings recorded by scans: about (3 strings), legal (off), plus one
	 * already translated, one already queued and one site-wide string.
	 *
	 * @return array<string, int> Original => id.
	 */
	private function scanned(): array {
		$ids  = $this->store->recordScan(
			array(
				'About text'       => 'text',
				'Done already'     => 'text',
				'Waiting in queue' => 'text',
			),
			'/about/',
			$this->posts['about']
		);
		$ids += $this->store->recordScan( array( 'Legal text' => 'text' ), '/legal/', $this->posts['off'] );
		$ids += $this->store->recordOnPage( array( 'Footer line' => 'text' ), '/elsewhere/', null );
		global $wpdb;
		$wpdb->update( $this->schema->table( 'strings' ), array( 'is_global' => 1 ), array( 'id' => $ids['Footer line'] ) );
		$this->store->saveManual( 'Done already', 'text', 'bn_BD', 'হয়ে গেছে', 1 );
		$this->queue->enqueue( array( $ids['Waiting in queue'] ), 'bn_BD', 'translatex', Queue::PRIORITY_VISITOR, time() );

		return $ids;
	}

	public function test_estimate_counts_untranslated_strings_of_allowed_pages_and_the_budget(): void {
		$ids  = $this->scanned();
		$call = $this->rest( array( 'providers' => array( 'translatex' => array( 'monthly_char_cap' => 1000 ) ) ) );
		( new Usage( $GLOBALS['wpdb'], $this->schema ) )->add( 'translatex', 300, 1, Usage::period() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $call( 'POST', 'estimate', array( 'page_keys' => array( StringStore::pageKey( '/about/' ), StringStore::pageKey( '/legal/' ), md5( 'unknown' ) ) ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame(
			array( array( $ids['About text'], 10 ), array( $ids['Footer line'], 11 ) ),
			$data['strings'],
			'Translated, queued and off-page strings are left out; site-wide strings are in.'
		);
		$this->assertSame( 2, $data['skipped_pages'], 'The off page and the unknown key.' );
		$provider = $data['provider'];
		$this->assertSame( array( 'translatex', 1000, 300, 16, 684 ), array( $provider['id'], $provider['cap'], $provider['used'], $provider['waiting'], $provider['remaining'] ) );
		$this->assertSame( ( new \DateTimeImmutable( 'first day of next month', wp_timezone() ) )->format( 'Y-m-d' ), $provider['resets_on'] );
	}

	public function test_queue_uses_bulk_priority_and_rechecks_the_pages(): void {
		$ids  = $this->scanned();
		$call = $this->rest();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$data = $call( 'POST', 'queue', array( 'page_keys' => array( StringStore::pageKey( '/about/' ), StringStore::pageKey( '/legal/' ) ) ) )->get_data();

		$this->assertSame( array( $ids['About text'], $ids['Footer line'] ), $data['ids'] );
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT string_id, priority FROM %i ORDER BY string_id', $this->schema->table( 'queue' ) ), ARRAY_A );
		$this->assertContains(
			array(
				'string_id' => (string) $ids['About text'],
				'priority'  => (string) Queue::PRIORITY_BULK,
			),
			$rows
		);
		$this->assertContains(
			array(
				'string_id' => (string) $ids['Waiting in queue'],
				'priority'  => (string) Queue::PRIORITY_VISITOR,
			),
			$rows,
			'Rows already queued keep their priority.'
		);
		$this->assertNotContains( (string) $ids['Legal text'], array_column( $rows, 'string_id' ), 'Off pages are never queued.' );
		$this->assertSame( array(), $call( 'POST', 'queue', array( 'page_keys' => array( StringStore::pageKey( '/about/' ) ) ) )->get_data()['ids'], 'A second run finds nothing new.' );
	}

	public function test_queue_is_refused_without_a_provider_and_routes_need_manage_options(): void {
		$this->scanned();
		$call = $this->rest( array( 'provider' => '' ) );
		$keys = array( 'page_keys' => array( StringStore::pageKey( '/about/' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $call( 'GET', 'pages', array() )->get_status() );
		$this->assertSame( 403, $call( 'POST', 'estimate', $keys )->get_status() );
		$this->assertSame( 403, $call( 'POST', 'queue', $keys )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 'No translation provider is selected.', $call( 'POST', 'estimate', $keys )->get_data()['provider']['problem'] );
		$this->assertSame( 409, $call( 'POST', 'queue', $keys )->get_status() );
		$this->assertSame( 400, $call( 'POST', 'queue', array( 'page_keys' => array( 'not-a-key' ) ) )->get_status() );
		$this->assertSame( 400, $call( 'POST', 'queue', array( 'page_keys' => array_fill( 0, SiteController::MAX_PAGES + 1, md5( 'x' ) ) ) )->get_status() );
		$this->assertSame( 0, $this->queue->stats()['pending'] - 1, 'Only the row queued before.' );
		$this->assertSame( 11, $call( 'GET', 'pages', array( 'page' => 1 ) )->get_data()['total'] );
	}
}

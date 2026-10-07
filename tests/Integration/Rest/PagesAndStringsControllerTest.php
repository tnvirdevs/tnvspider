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
use WST\Editor\PageTarget;
use WST\Routing\LanguageUrls;
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

		$this->boot();
	}

	/**
	 * Boot the controllers with these settings.
	 *
	 * @param array<string, mixed> $values Extra settings.
	 */
	private function boot( array $values = array() ): void {
		global $wpdb, $wp_rest_server;
		$schema    = new Schema( $wpdb );
		$settings  = new Settings(
			$values + array(
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
		$pages          = new PageTarget( $urls, new LanguageUrls( $urls, $target, 'http://example.org' ), $target, $modes, $this->store );
		( new StringsController( $requests, $target, $scheduler, $worker, $this->store, $pages ) )->boot();
		$purger = new Purger( $this->store, $urls, $target, 'http://example.org' );
		$purger->boot();
		( new PagesController( $this->store, $modes, $target, $purger ) )->boot();
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

	private function editorUser(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * @param array<string, mixed> $params Query.
	 * @return array{items: list<array<string, mixed>>, counts: array<string, int>}
	 */
	private function strings( array $params = array() ): array {
		$response = $this->call( 'GET', '/strings', $params + array( 'page_key' => StringStore::pageKey( '/handmade-soap/' ) ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	private function stringId( string $original ): int {
		return (int) ( $this->store->find( $original, 'bn_BD' )['id'] ?? 0 );
	}

	public function test_string_list_for_a_page_with_site_wide_strings(): void {
		global $wpdb;
		$this->editorUser();
		$ids = $this->store->recordOnPage( array( 'Site menu' => 'text' ), '/elsewhere/', null );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET is_global = 1 WHERE id = %d', ( new Schema( $wpdb ) )->table( 'strings' ), $ids['Site menu'] ) );

		$data = $this->strings();

		$this->assertSame( array( 'Soap intro', 'Soap price', 'Site menu' ), array_column( $data['items'], 'original' ) );
		$this->assertSame( array( 'none', 'manual', 'none' ), array_column( $data['items'], 'status' ) );
		$this->assertSame( array( false, false, true ), array_column( $data['items'], 'global' ) );
		$this->assertSame(
			array(
				'all'          => 3,
				'untranslated' => 2,
				'machine'      => 0,
				'manual'       => 1,
				'warning'      => 0,
			),
			$data['counts']
		);
		$this->assertSame( array( 'Soap intro', 'Soap price' ), array_column( $this->strings( array( 'include_global' => false ) )['items'], 'original' ) );
		$this->assertSame( array( 'Soap price' ), array_column( $this->strings( array( 'filter' => 'manual' ) )['items'], 'original' ) );
		$this->assertSame( array( 'Soap price' ), array_column( $this->strings( array( 'search' => 'সাবান' ) )['items'], 'original' ), 'Search covers translations.' );
		$this->assertSame( 400, $this->call( 'GET', '/strings', array( 'page_key' => 'not-a-key' ) )->get_status() );
	}

	public function test_warnings_show_failed_rows_and_segmented_translations(): void {
		$this->editorUser();
		$intro = $this->stringId( 'Soap intro' );
		$this->queue->enqueue( array( $intro ), 'bn_BD', 'translatex', Queue::PRIORITY_EDITOR, time() );
		$row = $this->queue->claim( 'translatex', 'bn_BD', 1, 1000, 60, time() )[0];
		$this->queue->fail( $row, 'Tags in the translation do not match the original.', 1, '', time() );

		$item = $this->strings( array( 'filter' => 'warning' ) )['items'][0];

		$this->assertSame( 'Soap intro', $item['original'] );
		$this->assertSame( array( 'failed' ), $item['warnings'] );
		$this->assertSame( 'Tags in the translation do not match the original.', $item['error'] );
	}

	public function test_manual_save_validates_purges_and_dequeues(): void {
		$this->editorUser();
		$intro = $this->stringId( 'Soap intro' );
		$this->queue->enqueue( array( $intro ), 'bn_BD', 'translatex', Queue::PRIORITY_VISITOR, time() );

		$saved = $this->call( 'POST', '/strings/' . $intro . '/translation', array( 'translation' => 'সাবানের পরিচয়' ) );

		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( array( 'সাবানের পরিচয়', 'manual', null ), array( $saved->get_data()['translation'], $saved->get_data()['status'], $saved->get_data()['queue'] ) );
		$this->assertSame( 0, $this->queue->stats()['pending'], 'A manual translation is never machine-translated.' );
		$this->assertContains( 'http://example.org/bn/handmade-soap/', $this->purged, 'The cached page is purged.' );
		$this->assertSame( 400, $this->call( 'POST', '/strings/' . $intro . '/translation', array( 'translation' => '  ' ) )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/strings/999999/translation', array( 'translation' => 'x' ) )->get_status() );
	}

	public function test_inline_save_must_keep_the_markup(): void {
		$this->editorUser();
		$ids = $this->store->recordOnPage( array( 'Read <a href="/story/">our story</a>' => 'inline' ), '/handmade-soap/', $this->manualPost );
		$id  = $ids['Read <a href="/story/">our story</a>'];

		$bad  = $this->call( 'POST', '/strings/' . $id . '/translation', array( 'translation' => 'পড়ুন <a href="/evil/">গল্প</a>' ) );
		$good = $this->call( 'POST', '/strings/' . $id . '/translation', array( 'translation' => '<a href="/story/">আমাদের গল্প</a> পড়ুন' ) );

		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'wst_invalid_translation', $bad->get_data()['code'] );
		$this->assertSame( 200, $good->get_status() );
	}

	public function test_mark_manual_and_remove(): void {
		$this->editorUser();
		$intro = $this->stringId( 'Soap intro' );
		$this->assertSame( 409, $this->call( 'POST', '/strings/' . $intro . '/manual' )->get_status(), 'Nothing to keep yet.' );
		$this->store->saveMachine( $intro, 'Soap intro', 'bn_BD', 'যন্ত্রের অনুবাদ', 'translatex', 0 );

		$marked = $this->call( 'POST', '/strings/' . $intro . '/manual' )->get_data();
		$this->assertSame( array( 'যন্ত্রের অনুবাদ', 'manual' ), array( $marked['translation'], $marked['status'] ) );
		$this->assertFalse( $this->store->saveMachine( $intro, 'Soap intro', 'bn_BD', 'নতুন', 'translatex', 0 ), 'Machine runs no longer replace it.' );

		$removed = $this->call( 'DELETE', '/strings/' . $intro . '/translation' )->get_data();
		$this->assertSame( array( null, 'none' ), array( $removed['translation'], $removed['status'] ) );
		$this->assertSame( 409, $this->call( 'DELETE', '/strings/' . $intro . '/translation' )->get_status() );
	}

	public function test_editor_string_routes_need_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$intro = $this->stringId( 'Soap intro' );

		$this->assertSame( 403, $this->call( 'GET', '/strings' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/strings/' . $intro . '/translation', array( 'translation' => 'x' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'DELETE', '/strings/' . $intro . '/translation' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/strings/' . $intro . '/manual' )->get_status() );
	}

	public function test_machine_translation_on_manual_pages_can_be_turned_off(): void {
		$this->editorUser();
		$intro = $this->stringId( 'Soap intro' );
		$this->assertSame(
			200,
			$this->call(
				'POST',
				'/strings/translate',
				array(
					'ids'  => array( $intro ),
					'path' => '/handmade-soap/',
				)
			)->get_status(),
			'Allowed by default (plan §9).'
		);

		$this->boot( array( 'editor_mt_on_manual' => false ) );
		$byIds  = $this->call(
			'POST',
			'/strings/translate',
			array(
				'ids'  => array( $intro ),
				'path' => '/handmade-soap/',
			)
		);
		$byPost = $this->call( 'POST', '/strings/translate', array( 'post_id' => $this->manualPost ) );

		$this->assertSame( array( 409, 409 ), array( $byIds->get_status(), $byPost->get_status() ) );
		$this->assertStringContainsString( 'turned off for manual pages', $byIds->get_data()['message'] );
		$this->assertSame(
			200,
			$this->call(
				'POST',
				'/strings/translate',
				array(
					'ids'  => array( $intro ),
					'path' => '/about-us/',
				)
			)->get_status(),
			'Auto pages are not affected.'
		);
	}
}

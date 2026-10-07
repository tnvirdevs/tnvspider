<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Admin;

use WP_UnitTestCase;
use WST\Access;
use WST\Admin\PostPanel;
use WST\Database\Schema;
use WST\Languages\Registry;
use WST\Modes\Resolver;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\Selector;
use WST\Queue\Queue;
use WST\Queue\Requests;
use WST\Queue\Scheduler;
use WST\Routing\Urls;
use WST\Settings;
use WST\Storage\StringStore;
use WST\Tests\Support\FakeProvider;

final class PostPanelTest extends WP_UnitTestCase {

	private StringStore $store;

	private Queue $queue;

	private int $postId;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->set_permalink_structure( '/%postname%/' );
		$schema       = new Schema( $wpdb );
		$this->store  = new StringStore( $wpdb, $schema );
		$this->queue  = new Queue( $wpdb, $schema );
		$this->postId = self::factory()->post->create( array( 'post_name' => 'soap' ) );
		$this->store->recordOnPage(
			array(
				'Soap text' => 'text',
				'Soap more' => 'text',
			),
			'/soap/',
			$this->postId
		);
		$this->store->saveManual( 'Soap more', 'text', 'bn_BD', 'আরও সাবান', 0 );
		delete_option( ProviderState::OPTION );
		Access::install();
	}

	public function tear_down(): void {
		$_POST = array();
		$_GET  = array();
		unset( $_REQUEST['_wpnonce'] );
		wp_clear_scheduled_hook( Scheduler::HOOK );
		parent::tear_down();
	}

	private function panel( string $pluginFile = '' ): PostPanel {
		$settings  = new Settings(
			array(
				'target_language' => 'bn_BD',
				'provider'        => 'translatex',
			),
			'en_US',
			new Registry()
		);
		$target    = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$modes     = new Resolver( $settings, Urls::fromHome( (string) get_option( 'home' ), 'wp-json' ), $target );
		$registry  = new ProviderRegistry( array( 'translatex' => new FakeProvider( 'translatex' ) ) );
		$scheduler = new Scheduler(
			$this->queue,
			static function (): \WST\Queue\Worker {
				throw new \LogicException( 'Not run here.' );
			}
		);
		$scheduler->boot();

		return new PostPanel( $this->store, $modes, new Requests( $settings, $this->store, $this->queue, new Selector( $settings, $registry, new ProviderState() ), $scheduler, $modes ), $target, '' === $pluginFile ? dirname( __DIR__, 3 ) . '/wp-site-translator.php' : $pluginFile );
	}

	private function render(): string {
		ob_start();
		$this->panel()->renderMetaBox( get_post( $this->postId ) );

		return (string) ob_get_clean();
	}

	public function test_classic_meta_box_shows_mode_effective_mode_coverage_and_action(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_post_meta( $this->postId, Resolver::META_KEY, Settings::MODE_MANUAL );

		$html = $this->render();

		$this->assertStringContainsString( '<option value="manual" selected=\'selected\'>', $html );
		$this->assertStringContainsString( 'Applies now: Manual only (this page).', $html );
		$this->assertStringContainsString( 'Translated: 1 of 2 strings (50%).', $html );
		$this->assertStringContainsString( 'action=wst_translate_post', $html, 'Manual pages can still be machine-translated on request.' );
	}

	public function test_no_action_on_off_pages_or_for_users_without_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_post_meta( $this->postId, Resolver::META_KEY, Settings::MODE_OFF );
		$this->assertStringNotContainsString( 'action=wst_translate_post', $this->render() );

		update_post_meta( $this->postId, Resolver::META_KEY, Settings::MODE_AUTO );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$html = $this->render();
		$this->assertStringContainsString( 'wst-mode', $html, 'Authors still set the mode of posts they edit.' );
		$this->assertStringNotContainsString( 'Translated:', $html );
		$this->assertStringNotContainsString( 'action=wst_translate_post', $html );
	}

	public function test_save_needs_nonce_and_permission_and_valid_value(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$panel = $this->panel();
		$_POST = array( 'wst_mode' => 'off' );
		$panel->save( $this->postId );
		$this->assertSame( Resolver::INHERIT, Resolver::pageMode( $this->postId ), 'No nonce: ignored.' );

		$_POST[ PostPanel::NONCE_FIELD ] = wp_create_nonce( PostPanel::NONCE_ACTION );
		$panel->save( $this->postId );
		$this->assertSame( 'off', Resolver::pageMode( $this->postId ) );

		$_POST['wst_mode'] = 'sometimes';
		$panel->save( $this->postId );
		$this->assertSame( 'off', Resolver::pageMode( $this->postId ), 'Invalid value: ignored.' );

		$_POST['wst_mode'] = 'inherit';
		$panel->save( $this->postId );
		$this->assertSame( '', get_post_meta( $this->postId, Resolver::META_KEY, true ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$_POST = array(
			'wst_mode'             => 'off',
			PostPanel::NONCE_FIELD => wp_create_nonce( PostPanel::NONCE_ACTION ),
		);
		$panel->save( $this->postId );
		$this->assertSame( Resolver::INHERIT, Resolver::pageMode( $this->postId ), 'Cannot edit the post: ignored.' );
	}

	public function test_translate_now_queues_the_page_and_reports_once(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_post_meta( $this->postId, Resolver::META_KEY, Settings::MODE_MANUAL );
		$_GET['post_id']      = (string) $this->postId;
		$_REQUEST['_wpnonce'] = wp_create_nonce( PostPanel::TRANSLATE_HOOK . '_' . $this->postId );
		$location             = '';
		add_filter(
			'wp_redirect',
			static function ( $to ) use ( &$location ) {
				$location = $to;
				throw new \RuntimeException( 'redirected' );
			}
		);

		try {
			$this->panel()->translateNow();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertStringContainsString( 'post.php?post=' . $this->postId, $location );
		$this->assertSame( 1, $this->queue->stats()['pending'] );
		ob_start();
		$this->panel()->notice();
		$this->assertStringContainsString( '1 string queued for translation.', (string) ob_get_clean() );
		ob_start();
		$this->panel()->notice();
		$this->assertSame( '', ob_get_clean(), 'Shown once.' );
	}

	public function test_block_editor_panel_script_is_enqueued_with_its_built_dependencies(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		set_current_screen( 'post' );

		$this->panel()->enqueueBlockEditor();

		$this->assertTrue( wp_script_is( PostPanel::SCRIPT_HANDLE, 'enqueued' ) );
		$script = wp_scripts()->registered[ PostPanel::SCRIPT_HANDLE ];
		$this->assertContains( 'wp-editor', $script->deps );
		$this->assertStringContainsString( '"canTranslate":true', implode( '', $script->extra['before'] ?? array() ) );
		wp_dequeue_script( PostPanel::SCRIPT_HANDLE );
		wp_deregister_script( PostPanel::SCRIPT_HANDLE );
	}

	public function test_missing_build_is_reported_not_silent(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'post' );

		$this->panel( '/nonexistent/wp-site-translator.php' )->enqueueBlockEditor();

		$this->assertFalse( wp_script_is( PostPanel::SCRIPT_HANDLE, 'enqueued' ) );
		ob_start();
		do_action( 'admin_notices' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$this->assertStringContainsString( 'npm run build', (string) ob_get_clean() );
	}
}

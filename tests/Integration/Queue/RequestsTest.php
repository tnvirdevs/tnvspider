<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Queue;

use WP_UnitTestCase;
use WST\Database\Schema;
use WST\Languages\Language;
use WST\Languages\Registry;
use WST\Modes\Resolver;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\Selector;
use WST\Queue\Queue;
use WST\Queue\RequestRefused;
use WST\Queue\Requests;
use WST\Queue\Scheduler;
use WST\Routing\Urls;
use WST\Settings;
use WST\Storage\StringStore;
use WST\Tests\Support\FakeProvider;

final class RequestsTest extends WP_UnitTestCase {

	private StringStore $store;

	private Queue $queue;

	private Language $target;

	private int $postId;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->set_permalink_structure( '/%postname%/' );
		$schema       = new Schema( $wpdb );
		$this->store  = new StringStore( $wpdb, $schema );
		$this->queue  = new Queue( $wpdb, $schema );
		$this->target = ( new Registry() )->get( 'bn_BD' );
		$this->postId = self::factory()->post->create( array( 'post_name' => 'manual-page' ) );
		update_post_meta( $this->postId, Resolver::META_KEY, Settings::MODE_MANUAL );
		delete_option( ProviderState::OPTION );
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( Scheduler::HOOK );
		parent::tear_down();
	}

	private function requests( string $provider = 'translatex' ): Requests {
		$settings  = new Settings(
			array(
				'target_language' => 'bn_BD',
				'provider'        => $provider,
			),
			'en_US',
			new Registry()
		);
		$registry  = new ProviderRegistry( array( 'translatex' => new FakeProvider( 'translatex' ) ) );
		$scheduler = new Scheduler(
			$this->queue,
			static function (): \WST\Queue\Worker {
				throw new \LogicException( 'Not run here.' );
			}
		);
		$scheduler->boot();

		return new Requests( $settings, $this->store, $this->queue, new Selector( $settings, $registry, new ProviderState() ), $scheduler, new Resolver( $settings, Urls::fromHome( (string) get_option( 'home' ), 'wp-json' ), $this->target ) );
	}

	/**
	 * Queue rows: string id => priority.
	 *
	 * @return array<int, int>
	 */
	private function queued(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT string_id, priority FROM %i ORDER BY string_id', ( new Schema( $wpdb ) )->table( 'queue' ) ) );

		return array_combine( array_map( 'intval', array_column( $rows, 'string_id' ) ), array_map( 'intval', array_column( $rows, 'priority' ) ) );
	}

	public function test_translate_page_now_works_on_a_manual_page_and_skips_translated_strings(): void {
		$ids = $this->store->recordOnPage(
			array(
				'Manual intro'  => 'text',
				'Manual detail' => 'text',
				'Done already'  => 'text',
			),
			'/manual-page/',
			$this->postId
		);
		$this->store->saveManual( 'Done already', 'text', 'bn_BD', 'হয়ে গেছে', 0 );

		$result = $this->requests()->post( $this->postId, $this->target );

		$this->assertSame( 2, $result['queued'] );
		$this->assertSame( 'translatex', $result['provider'] );
		$this->assertSame(
			array(
				$ids['Manual intro']  => Queue::PRIORITY_PAGE_NOW,
				$ids['Manual detail'] => Queue::PRIORITY_PAGE_NOW,
			),
			$this->queued()
		);
		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK ) );
	}

	public function test_global_strings_count_for_every_seen_page(): void {
		global $wpdb;
		$this->store->recordOnPage( array( 'Page text' => 'text' ), '/manual-page/', $this->postId );
		$global = $this->store->recordOnPage( array( 'Footer text' => 'text' ), '/other/', null );
		$wpdb->update( ( new Schema( $wpdb ) )->table( 'strings' ), array( 'is_global' => 1 ), array( 'id' => $global['Footer text'] ) );

		$this->assertSame( 2, $this->store->postCoverage( $this->postId, 'bn_BD' )['total'] );
		$this->requests()->post( $this->postId, $this->target );
		$this->assertArrayHasKey( $global['Footer text'], $this->queued() );
	}

	public function test_off_unseen_and_providerless_requests_are_refused_with_a_reason(): void {
		$this->store->recordOnPage( array( 'Page text' => 'text' ), '/manual-page/', $this->postId );
		$unseen = self::factory()->post->create();

		foreach (
			array(
				'never seen'  => array( $unseen, 'translatex', 'No strings are known' ),
				'no provider' => array( $this->postId, '', 'No translation provider is selected.' ),
			) as $label => [ $post, $provider, $message ]
		) {
			try {
				$this->requests( $provider )->post( $post, $this->target );
				$this->fail( 'Expected a refusal: ' . $label );
			} catch ( RequestRefused $e ) {
				$this->assertStringContainsString( $message, $e->getMessage(), $label );
			}
		}

		update_post_meta( $this->postId, Resolver::META_KEY, Settings::MODE_OFF );
		$this->expectException( RequestRefused::class );
		$this->expectExceptionMessage( '"off"' );
		$this->requests()->post( $this->postId, $this->target );
	}

	public function test_editor_strings_go_first_and_never_resend_manual_translations(): void {
		$ids = $this->store->recordOnPage(
			array(
				'Row one' => 'text',
				'Row two' => 'text',
			),
			'/manual-page/',
			$this->postId
		);
		$this->store->saveManual( 'Row two', 'text', 'bn_BD', 'সারি দুই', 0 );

		$result = $this->requests()->strings( array( $ids['Row one'], $ids['Row two'], 999999 ), $this->target );

		$this->assertSame( 1, $result['queued'] );
		$this->assertSame( 2, $result['skipped'], 'The manual one and the unknown id.' );
		$this->assertSame( array( $ids['Row one'] => Queue::PRIORITY_EDITOR ), $this->queued() );
	}
}

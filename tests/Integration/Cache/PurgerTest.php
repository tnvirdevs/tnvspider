<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Cache;

use WP_UnitTestCase;
use WST\Cache\Purger;
use WST\Database\Schema;
use WST\Languages\Registry;
use WST\Queue\Queue;
use WST\Routing\Urls;
use WST\Storage\StringStore;

final class PurgerTest extends WP_UnitTestCase {

	private StringStore $store;

	private Queue $queue;

	/**
	 * URLs received per hook.
	 *
	 * @var array<string, list<string>>
	 */
	private array $purged = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->set_permalink_structure( '/%postname%/' );
		$schema      = new Schema( $wpdb );
		$this->store = new StringStore( $wpdb, $schema );
		$this->queue = new Queue( $wpdb, $schema );
		foreach ( array( 'litespeed_purge_url', 'wst_purge_url' ) as $hook ) {
			add_action(
				$hook,
				function ( string $url ) use ( $hook ): void {
					$this->purged[ $hook ][] = $url;
				}
			);
		}
	}

	private function purger(): Purger {
		$target = ( new Registry() )->get( 'bn_BD' );

		return new Purger( $this->store, Urls::fromHome( 'http://example.org', 'wp-json' ), $target, 'http://example.org' );
	}

	public function test_purges_pages_whose_queued_strings_are_all_done(): void {
		$shop  = $this->store->recordOnPage(
			array(
				'Shop title' => 'text',
				'Shop body'  => 'text',
			),
			'/shop/',
			null
		);
		$about = $this->store->recordOnPage( array( 'Shop title' => 'text' ), '/about/', null );
		$this->store->recordOnPage( array( 'Home hello' => 'text' ), '/', null );
		$this->queue->enqueue( array( $shop['Shop body'] ), 'bn_BD', 'translatex', Queue::PRIORITY_VISITOR, time() );

		$urls = $this->purger()->purgeStrings( array( $about['Shop title'] ), 'bn_BD' );

		$this->assertSame( array( 'http://example.org/bn/about/' ), $urls, '/shop/ still has a queued string.' );
		$this->assertSame( $urls, $this->purged['litespeed_purge_url'] ?? array() );
		$this->assertSame( $urls, $this->purged['wst_purge_url'] ?? array() );
	}

	public function test_home_page_and_other_languages(): void {
		$home = $this->store->recordOnPage( array( 'Home hello' => 'text' ), '/', null );

		$this->assertSame( array(), $this->purger()->purgeStrings( array( $home['Home hello'] ), 'ar' ), 'Not the target language.' );
		$this->assertSame( array( 'http://example.org/bn/' ), $this->purger()->purgeStrings( array( $home['Home hello'] ), 'bn_BD' ) );
	}

	public function test_nothing_changed_purges_nothing(): void {
		$this->assertSame( array(), $this->purger()->purgeStrings( array(), 'bn_BD' ) );
		$this->assertSame( array(), $this->purged );
	}

	public function test_boot_listens_to_stored_translations(): void {
		$ids = $this->store->recordOnPage( array( 'Contact us' => 'text' ), '/contact/', null );
		$this->purger()->boot();

		do_action( 'wst_strings_translated', array( $ids['Contact us'] ), 'bn_BD' );

		$this->assertSame( array( 'http://example.org/bn/contact/' ), $this->purged['wst_purge_url'] ?? array() );
	}
}

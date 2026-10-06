<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Storage;

use WP_UnitTestCase;
use WST\Database\Schema;
use WST\Storage\StringStore;

final class StringStoreTest extends WP_UnitTestCase {

	private StringStore $store;

	private Schema $schema;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->schema = new Schema( $wpdb );
		$this->store  = new StringStore( $wpdb, $this->schema );
		wp_cache_flush();
	}

	public function test_manual_translation_round_trips_through_lookup(): void {
		$id = $this->store->saveManual( "  Shop\n now ", 'text', 'bn_BD', 'এখনই কিনুন', 0 );

		$result = $this->store->lookup( array( 'Shop now', 'Unknown' ), 'bn_BD' );

		$this->assertSame(
			array(
				'Shop now' => array(
					'id'         => $id,
					'translated' => 'এখনই কিনুন',
					'status'     => StringStore::STATUS_MANUAL,
				),
			),
			$result
		);
		$this->assertNull( $this->store->lookup( array( 'Shop now' ), 'ar' )['Shop now']['translated'], 'Known string without a translation into ar.' );
	}

	public function test_saving_again_updates_and_clears_the_cache(): void {
		$this->store->saveManual( 'Cart', 'text', 'bn_BD', 'কার্ট', 0 );
		$this->store->lookup( array( 'Cart' ), 'bn_BD' );
		$this->store->saveManual( 'Cart', 'text', 'bn_BD', 'ঝুড়ি', 0 );

		$this->assertSame( 'ঝুড়ি', $this->store->lookup( array( 'Cart' ), 'bn_BD' )['Cart']['translated'] );
	}

	public function test_inline_translation_must_keep_the_markup(): void {
		$original = 'Read <a href="/story/">our story</a> today';

		$this->store->saveManual( $original, 'inline', 'bn_BD', 'আজই <a href="/story/">আমাদের গল্প</a> পড়ুন', 0 );
		$this->assertSame( 'আজই <a href="/story/">আমাদের গল্প</a> পড়ুন', $this->store->find( $original, 'bn_BD' )['translated'] ?? null );

		$this->expectException( \InvalidArgumentException::class );
		$this->store->saveManual( $original, 'inline', 'bn_BD', 'আজই <a href="https://evil.example/">আমাদের গল্প</a> পড়ুন', 0 );
	}

	public function test_inline_translation_with_extra_markup_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->store->saveManual( 'A <b>bold</b> move', 'inline', 'bn_BD', 'এক <b onclick="x()">সাহসী</b> পদক্ষেপ<script>alert(1)</script>', 0 );
	}

	public function test_record_on_page_inserts_strings_occurrences_and_page(): void {
		global $wpdb;
		$post = self::factory()->post->create();

		$ids = $this->store->recordOnPage(
			array(
				'Hello'            => 'text',
				'Read <b>more</b>' => 'inline',
			),
			'/bn-free/path/?x=1',
			$post
		);

		$this->assertCount( 2, $ids );
		$this->assertSame( 'inline', $wpdb->get_var( $wpdb->prepare( 'SELECT kind FROM %i WHERE id = %d', $this->schema->table( 'strings' ), $ids['Read <b>more</b>'] ) ) );
		$this->assertSame( '2', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE page_key = %s AND post_id = %d', $this->schema->table( 'occurrences' ), StringStore::pageKey( '/bn-free/path' ), $post ) ) );
		$this->assertSame( '/bn-free/path', $wpdb->get_var( $wpdb->prepare( 'SELECT path FROM %i WHERE page_key = %s', $this->schema->table( 'pages' ), StringStore::pageKey( '/bn-free/path/' ) ) ) );
	}

	public function test_record_on_page_without_post_stores_null(): void {
		global $wpdb;
		$this->store->recordOnPage( array( 'Archive' => 'text' ), '/category/news/', null );

		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM %i WHERE page_key = %s', $this->schema->table( 'pages' ), StringStore::pageKey( '/category/news' ) ) ) );
	}

	public function test_string_becomes_global_after_threshold_pages(): void {
		global $wpdb;
		for ( $i = 1; $i <= StringStore::GLOBAL_THRESHOLD + 3; $i++ ) {
			$ids = $this->store->recordOnPage( array( 'Menu' => 'text' ), '/page-' . $i . '/', null );
		}

		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT is_global FROM %i WHERE id = %d', $this->schema->table( 'strings' ), $ids['Menu'] ) ) );
		$this->assertSame( (string) StringStore::GLOBAL_THRESHOLD, $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE string_id = %d', $this->schema->table( 'occurrences' ), $ids['Menu'] ) ) );
	}

	public function test_search_filters_by_status(): void {
		$this->store->saveManual( 'Alpha', 'text', 'bn_BD', 'আলফা', 0 );
		$this->store->recordOnPage( array( 'Beta' => 'text' ), '/', null );

		$this->assertSame( array( 'Alpha' ), array_column( $this->store->search( 'bn_BD', 'manual', '', 10 ), 'original' ) );
		$this->assertContains( 'Beta', array_column( $this->store->search( 'bn_BD', 'untranslated', '', 10 ), 'original' ) );
		$this->assertNotContains( 'Alpha', array_column( $this->store->search( 'bn_BD', 'untranslated', '', 10 ), 'original' ) );
		$this->assertSame( array( 'Beta' ), array_column( $this->store->search( 'bn_BD', null, 'Bet', 10 ), 'original' ) );
	}

	public function test_pending_count_ignores_failed_and_stale_rows(): void {
		global $wpdb;
		$ids   = $this->store->recordOnPage(
			array(
				'One'   => 'text',
				'Two'   => 'text',
				'Three' => 'text',
			),
			'/',
			null
		);
		$queue = $this->schema->table( 'queue' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$old   = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		foreach ( array( array( 'One', 'pending', $now ), array( 'Two', 'failed', $now ), array( 'Three', 'pending', $old ) ) as [ $text, $state, $created ] ) {
			$wpdb->insert(
				$queue,
				array(
					'string_id'       => $ids[ $text ],
					'lang'            => 'bn_BD',
					'provider'        => 'fake',
					'state'           => $state,
					'next_attempt_at' => $now,
					'created_at'      => $created,
				)
			);
		}

		$this->assertSame( 1, $this->store->pendingCount( array_values( $ids ), 'bn_BD', DAY_IN_SECONDS ) );
		$this->assertSame( 0, $this->store->pendingCount( array_values( $ids ), 'ar', DAY_IN_SECONDS ) );
	}

	public function test_page_key_normalisation(): void {
		$this->assertSame( StringStore::pageKey( '/shop' ), StringStore::pageKey( 'shop/?orderby=price' ) );
		$this->assertSame( '/', StringStore::normalizePath( '/' ) );
	}
}

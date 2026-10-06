<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Render;

use WP_HTML_Tag_Processor;
use WP_UnitTestCase;
use WST\Database\Schema;
use WST\Languages\Registry;
use WST\Log\Logger;
use WST\Render\DiscoveryGate;
use WST\Render\PageContext;
use WST\Render\Pipeline;
use WST\Routing\Urls;
use WST\Settings;
use WST\Storage\StringStore;

final class PipelineTest extends WP_UnitTestCase {

	private StringStore $store;

	private Schema $schema;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->schema = new Schema( $wpdb );
		$this->store  = new StringStore( $wpdb, $this->schema );
		wp_cache_flush();
	}

	/**
	 * @param array<string, mixed> $settings Extra settings.
	 */
	private function pipeline( array $settings = array(), string $target = 'bn_BD', string $home = 'http://example.org' ): Pipeline {
		global $wpdb;
		$settings = new Settings( array( 'target_language' => $target ) + $settings, 'en_US', new Registry() );
		$logger   = new Logger( $wpdb, $this->schema );

		return new Pipeline(
			$settings,
			$settings->targetLanguage() ?? throw new \LogicException( 'No target.' ),
			$this->store,
			new DiscoveryGate( $settings, $logger ),
			$logger,
			Urls::fromHome( $home, 'wp-json' )
		);
	}

	private function page( bool $discoverable = false ): PageContext {
		return new PageContext( '/shop/', null, $discoverable );
	}

	public function test_stored_translations_replace_text_inline_attributes_and_title(): void {
		$this->store->saveManual( 'Shop', 'title', 'bn_BD', 'দোকান', 0 );
		$this->store->saveManual( 'Natural soap', 'text', 'bn_BD', 'প্রাকৃতিক সাবান', 0 );
		$this->store->saveManual( 'Read <a href="/story/">our story</a>', 'inline', 'bn_BD', '<a href="/story/">আমাদের গল্প</a> পড়ুন', 0 );
		$this->store->saveManual( 'Search', 'attr', 'bn_BD', 'খুঁজুন', 0 );

		$html = '<html lang="en-US"><head><title>Shop</title></head><body><h1>Natural soap</h1>'
			. '<p>Read <a href="/story/">our story</a></p><input placeholder="Search"><p>Untranslated</p></body></html>';
		$out  = $this->pipeline()->process( $html, $this->page() );

		$this->assertStringContainsString( '<title>দোকান</title>', $out );
		$this->assertStringContainsString( '<h1>প্রাকৃতিক সাবান</h1>', $out );
		$this->assertStringContainsString( '<p><a href="/bn/story/">আমাদের গল্প</a> পড়ুন</p>', $out );
		$this->assertStringContainsString( 'placeholder="খুঁজুন"', $out );
		$this->assertStringContainsString( '<p>Untranslated</p>', $out );
		$this->assertSame( array( 'bn-BD' ), $this->attributeValues( $out, 'HTML', 'lang' ) );
		$this->assertSame( array( 'ltr' ), $this->attributeValues( $out, 'HTML', 'dir' ) );
	}

	public function test_inline_originals_are_stored_without_the_language_prefix(): void {
		$page = '<p>Read <a href="http://example.org/bn/story/">our story</a> now</p>';

		$this->pipeline()->process( $page, $this->page( true ) );
		$this->assertNotNull( $this->store->find( 'Read <a href="http://example.org/story/">our story</a> now', 'bn_BD' ) );
		$this->assertNull( $this->store->find( 'Read <a href="http://example.org/bn/story/">our story</a> now', 'bn_BD' ) );

		$this->store->saveManual( 'Read <a href="http://example.org/story/">our story</a> now', 'inline', 'bn_BD', 'এখন <a href="http://example.org/story/">আমাদের গল্প</a> পড়ুন', 0 );
		$out = $this->pipeline()->process( $page, $this->page() );

		$this->assertSame( '<p>এখন <a href="http://example.org/bn/story/">আমাদের গল্প</a> পড়ুন</p>', $out );
	}

	public function test_internal_links_are_prefixed_and_others_are_not(): void {
		$html = '<body><a href="/about/">A</a><a href="http://example.org/shop/?x=1">B</a><a href="https://other.example/">C</a>'
			. '<a href="/wp-admin/">D</a><a href="/bn/already/">E</a><a href="/" hreflang="en-US">F</a>'
			. '<form action="/search/"></form><a href="#top">G</a></body>';
		$out  = $this->pipeline()->process( $html, $this->page() );

		$this->assertSame(
			array( '/bn/about/', 'http://example.org/bn/shop/?x=1', 'https://other.example/', '/wp-admin/', '/bn/already/', '/', '#top' ),
			$this->attributeValues( $out, 'A', 'href' )
		);
		$this->assertSame( array( '/bn/search/' ), $this->attributeValues( $out, 'FORM', 'action' ) );
	}

	public function test_link_rewriting_can_be_turned_off(): void {
		$out = $this->pipeline( array( 'force_language_links' => false ) )->process( '<body><a href="/about/">A</a></body>', $this->page() );

		$this->assertSame( array( '/about/' ), $this->attributeValues( $out, 'A', 'href' ) );
	}

	public function test_rtl_target_sets_document_direction(): void {
		$out = $this->pipeline( array(), 'ar' )->process( '<!DOCTYPE html><html lang="en-US"><body>x</body></html>', $this->page() );

		$this->assertSame( array( 'ar' ), $this->attributeValues( $out, 'HTML', 'lang' ) );
		$this->assertSame( array( 'rtl' ), $this->attributeValues( $out, 'HTML', 'dir' ) );
	}

	public function test_inline_translation_with_changed_markup_is_not_used(): void {
		global $wpdb;
		$hash = StringStore::hash( 'Go <b>now</b>' );
		$this->store->saveManual( 'Go <b>now</b>', 'inline', 'bn_BD', 'এখন <b>যান</b>', 0 );
		// Simulate a bad stored value, e.g. from an old import.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i t JOIN %i s ON s.id = t.string_id SET t.translated = %s WHERE s.hash = %s', $this->schema->table( 'translations' ), $this->schema->table( 'strings' ), 'এখন <i>যান</i>', $hash ) );
		wp_cache_flush();

		$out = $this->pipeline()->process( '<p>Go <b>now</b></p>', $this->page() );

		$this->assertSame( '<p>Go <b>now</b></p>', $out );
	}

	public function test_discoverable_page_records_unknown_strings(): void {
		$this->pipeline()->process( '<body><p>New string</p><p>Mixed <b>content</b></p><img alt="Photo"></body>', $this->page( true ) );

		$this->assertSame( 'text', $this->store->find( 'New string', 'bn_BD' )['kind'] ?? null );
		$this->assertSame( 'inline', $this->store->find( 'Mixed <b>content</b>', 'bn_BD' )['kind'] ?? null );
		$this->assertSame( 'attr', $this->store->find( 'Photo', 'bn_BD' )['kind'] ?? null );
	}

	public function test_non_discoverable_page_records_nothing(): void {
		$this->pipeline()->process( '<body><p>Private name</p></body>', $this->page( false ) );

		$this->assertNull( $this->store->find( 'Private name', 'bn_BD' ) );
	}

	public function test_discovery_respects_page_cap_and_max_length(): void {
		$long = str_repeat( 'Long text ', 30 );
		$this->pipeline(
			array(
				'discovery_cap_page_hour' => 2,
				'max_string_length'       => 100,
			)
		)
			->process( '<body><p>One</p><p>Two</p><p>Three</p><p>' . $long . '</p></body>', $this->page( true ) );

		$this->assertNotNull( $this->store->find( 'One', 'bn_BD' ) );
		$this->assertNotNull( $this->store->find( 'Two', 'bn_BD' ) );
		$this->assertNull( $this->store->find( 'Three', 'bn_BD' ) );
		$this->assertNull( $this->store->find( trim( $long ), 'bn_BD' ) );
	}

	public function test_pending_queue_rows_mark_the_page_uncacheable(): void {
		global $wpdb;
		$ids = $this->store->recordOnPage(
			array(
				'Waiting' => 'text',
				'Broken'  => 'text',
			),
			'/shop/',
			null
		);
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$this->schema->table( 'queue' ),
			array(
				'string_id'       => $ids['Waiting'],
				'lang'            => 'bn_BD',
				'provider'        => 'fake',
				'state'           => 'pending',
				'next_attempt_at' => $now,
				'created_at'      => $now,
			)
		);
		$wpdb->insert(
			$this->schema->table( 'queue' ),
			array(
				'string_id'       => $ids['Broken'],
				'lang'            => 'bn_BD',
				'provider'        => 'fake',
				'state'           => 'failed',
				'next_attempt_at' => $now,
				'created_at'      => $now,
			)
		);

		$pipeline = $this->pipeline();
		$pipeline->process( '<body><p>Waiting</p><p>Broken</p></body>', $this->page() );
		$this->assertSame( 1, $pipeline->pending() );

		$pipeline->process( '<body><p>Broken</p></body>', $this->page() );
		$this->assertSame( 0, $pipeline->pending(), 'Failed rows never make a page uncacheable.' );
	}

	public function test_failure_is_logged_and_rethrown_under_wp_debug(): void {
		global $wpdb;
		$this->assertTrue( WP_DEBUG );
		$this->set_permalink_structure( '/%postname%/' );
		\WST\Languages\Current::set( ( new Registry() )->get( 'bn_BD' ), true );
		$this->go_to( '/' );
		\WST\Languages\Current::set( ( new Registry() )->get( 'bn_BD' ), true );
		$pipeline = $this->pipeline();
		$pipeline->start();
		ob_end_clean(); // The test drives finish() directly.

		$break = static fn( string $query ): string => str_contains( $query, 'LEFT JOIN' ) ? 'SELECT broken FROM nowhere' : $query;
		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );
		try {
			$pipeline->finish( '<p>Hello</p>' );
			$this->fail( 'Expected the failure to be rethrown under WP_DEBUG.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Database read failed', $e->getMessage() );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( false );
			\WST\Languages\Current::reset();
		}

		$this->assertSame( 'render', $wpdb->get_var( $wpdb->prepare( 'SELECT source FROM %i ORDER BY id DESC LIMIT 1', $this->schema->table( 'log' ) ) ) );
	}

	/**
	 * @dataProvider pageProvider
	 */
	public function test_saved_pages_keep_every_byte_without_translations( string $file ): void {
		$html = (string) file_get_contents( $file );
		$out  = $this->pipeline( array( 'force_language_links' => false ) )->process( $html, $this->page() );

		// The only intentional change is the html element's lang/dir.
		$pattern = '/<html\b[^>]*>/i';
		$this->assertSame( preg_replace( $pattern, '<html>', $html, 1 ), preg_replace( $pattern, '<html>', $out, 1 ) );
	}

	/**
	 * @dataProvider pageProvider
	 */
	public function test_saved_pages_only_change_links_with_rewriting_on( string $file ): void {
		$html = (string) file_get_contents( $file );
		// The fixtures were captured from this host.
		$out = $this->pipeline( array(), 'bn_BD', 'http://127.0.0.1:8899' )->process( $html, $this->page() );

		// Blank every link value and the html element: what remains must be identical.
		$blank = static fn( string $doc ): string => (string) preg_replace(
			array( '/<html\b[^>]*>/i', '/\b(href|action)=("[^"]*"|\'[^\']*\'|[^\s>]+)/i' ),
			array( '<html>', '$1=""' ),
			$doc
		);
		$this->assertSame( $blank( $html ), $blank( $out ) );
		$this->assertStringContainsString( 'http://127.0.0.1:8899/bn/', $out, 'Internal links are prefixed.' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function pageProvider(): array {
		$cases = array();
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/fixtures/pages/*.html' ) as $file ) {
			$cases[ basename( (string) $file ) ] = array( (string) $file );
		}

		return $cases;
	}

	/**
	 * @return list<string>
	 */
	private function attributeValues( string $html, string $tag, string $attribute ): array {
		$values    = array();
		$processor = new WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( array( 'tag_name' => $tag ) ) ) {
			$values[] = (string) $processor->get_attribute( $attribute );
		}

		return $values;
	}
}

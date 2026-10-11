<?php
/**
 * Plan §13A.8 / §16 Phase 6f: the target-language sitemap lists the
 * translated URL of every public page, leaves out "off" and noindex pages
 * and drafts, appears in the sitemap index and is valid sitemap XML.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Sitemap;

use RankMath\Helper as RankMathHelper;
use WP_UnitTestCase;
use WST\Languages\Registry;
use WST\Modes\Resolver;
use WST\Routing\LanguageUrls;
use WST\Routing\Urls;
use WST\Settings;
use WST\Sitemap\SeoPlugins;
use WST\Sitemap\Sitemaps;
use WST\Sitemap\TargetProvider;

final class SitemapTest extends WP_UnitTestCase {

	private const FIXTURES = __DIR__ . '/../../fixtures/sitemaps/';

	/** @var array<string, int> */
	private array $ids = array();

	public static function wpSetUpBeforeClass(): void {
		require_once self::FIXTURES . 'rank-math-helper.php';
	}

	public function set_up(): void {
		global $wpdb;
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();
		flush_rewrite_rules( false );
		update_option( 'show_on_front', 'posts' );
		update_option( 'blog_public', '1' );

		$news                = self::factory()->category->create( array( 'slug' => 'news' ) );
		$this->ids['news']   = $news;
		$this->ids['hello']  = self::factory()->post->create(
			array(
				'post_name'     => 'hello',
				'post_category' => array( $news ),
			)
		);
		$this->ids['about']  = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$this->ids['legal']  = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'legal',
			)
		);
		$this->ids['secret'] = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'secret-plan',
			)
		);
		$this->ids['draft']  = self::factory()->post->create(
			array(
				'post_name'   => 'draft-one',
				'post_status' => 'draft',
			)
		);
		update_post_meta( $this->ids['legal'], Resolver::META_KEY, Settings::MODE_OFF );
		// wp_insert_post() always stamps "now"; set a known modification time.
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2026-09-01 10:20:30' ), array( 'ID' => $this->ids['hello'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		clean_post_cache( $this->ids['hello'] );
		RankMathHelper::$noindexPosts = array();
		RankMathHelper::$noindexTerms = array();
		RankMathHelper::$noindexTypes = array();
	}

	public function tear_down(): void {
		global $wp_sitemaps;
		$wp_sitemaps = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's sitemaps global; drops the provider a test registered.
		parent::tear_down();
	}

	private function provider( string $seo = '' ): TargetProvider {
		$settings = new Settings(
			array(
				'target_language' => 'bn_BD',
				'path_rules'      => array(
					array(
						'path' => '/secret-*',
						'mode' => Settings::MODE_OFF,
					),
				),
			),
			'en_US',
			new Registry()
		);
		$target   = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$urls     = Urls::fromHome( 'http://example.org', 'wp-json' );

		return new TargetProvider( new LanguageUrls( $urls, $target, 'http://example.org' ), $urls, new Resolver( $settings, $urls, $target ), new SeoPlugins( $seo ) );
	}

	/**
	 * Locations of a URL list.
	 *
	 * @param array<int, array<string, string>> $entries URL list.
	 * @return list<string>
	 */
	private static function locs( array $entries ): array {
		return array_values( array_map( static fn( array $entry ): string => $entry['loc'], $entries ) );
	}

	private static function assertValid( string $xml, string $schema ): void {
		$document = new \DOMDocument();
		self::assertTrue( $document->loadXML( $xml ), 'Well-formed XML.' );
		$previous = libxml_use_internal_errors( true );
		$valid    = $document->schemaValidate( self::FIXTURES . $schema );
		$errors   = implode( ' | ', array_map( static fn( \LibXMLError $error ): string => trim( $error->message ), libxml_get_errors() ) );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		self::assertTrue( $valid, 'Valid against ' . $schema . ': ' . $errors );
	}

	public function test_lists_target_urls_of_published_pages_except_off_ones(): void {
		$provider = $this->provider();

		$this->assertSame( array( 'posts-post', 'posts-page', 'terms-category', 'terms-post_tag', 'terms-post_format' ), array_keys( $provider->get_object_subtypes() ) );
		$this->assertSame( array( 'http://example.org/bn/', 'http://example.org/bn/about/' ), self::locs( $provider->get_url_list( 1, 'posts-page' ) ), 'Home first (latest posts on front); "off" by page and by path rule left out.' );
		$this->assertSame(
			array(
				array(
					'loc'     => 'http://example.org/bn/hello/',
					'lastmod' => '2026-09-01T10:20:30+00:00',
				),
			),
			$provider->get_url_list( 1, 'posts-post' ),
			'Drafts left out; lastmod from the post.'
		);
		$this->assertSame( array( 'http://example.org/bn/category/news/' ), self::locs( $provider->get_url_list( 1, 'terms-category' ) ) );
		$this->assertSame( array(), $provider->get_url_list( 1, 'posts-nope' ) );
		$this->assertSame( 1, $provider->get_max_num_pages( 'posts-page' ) );
	}

	public function test_core_index_lists_the_sitemap_and_the_xml_is_valid(): void {
		$provider = $this->provider();
		( new Sitemaps( $provider ) )->register();
		$index = wp_sitemaps_get_server()->index->get_sitemap_list();

		$this->assertContains( array( 'loc' => 'http://example.org/wp-sitemap-wst-posts-page-1.xml' ), $index );
		$this->assertContains( array( 'loc' => 'http://example.org/wp-sitemap-wst-terms-category-1.xml' ), $index );
		$renderer = new \WP_Sitemaps_Renderer();
		self::assertValid( $renderer->get_sitemap_index_xml( $index ), 'siteindex.xsd' );
		foreach ( array( 'posts-post', 'posts-page', 'terms-category' ) as $subtype ) {
			self::assertValid( $renderer->get_sitemap_xml( $provider->get_url_list( 1, $subtype ) ), 'sitemap.xsd' );
		}
	}

	public function test_served_xml_has_no_core_stylesheet_and_empty_pages_are_null(): void {
		$sitemaps = new Sitemaps( $this->provider() );
		$xml      = (string) $sitemaps->xml( 'posts-post', 1 );

		self::assertValid( $xml, 'sitemap.xsd' );
		$this->assertStringNotContainsString( 'xml-stylesheet', $xml, 'Core stylesheet URL answers 404 while core sitemaps are off.' );
		$this->assertStringContainsString( '<loc>http://example.org/bn/hello/</loc>', $xml );
		$this->assertNull( $sitemaps->xml( 'posts-post', 2 ) );
		$this->assertStringContainsString( 'xml-stylesheet', ( new \WP_Sitemaps_Renderer() )->get_sitemap_xml( array( array( 'loc' => 'http://example.org/' ) ) ), 'The filter is removed again.' );
	}

	public function test_seo_plugin_noindex_is_left_out(): void {
		RankMathHelper::$noindexPosts = array( $this->ids['about'] );
		RankMathHelper::$noindexTerms = array( $this->ids['news'] );
		RankMathHelper::$noindexTypes = array( 'post_tag' );
		$provider                     = $this->provider( SeoPlugins::RANK_MATH );

		$this->assertSame( array( 'posts-post', 'posts-page', 'terms-category', 'terms-post_format' ), array_keys( $provider->get_object_subtypes() ), 'Noindexed taxonomy has no sitemap.' );
		$this->assertSame( array( 'http://example.org/bn/' ), self::locs( $provider->get_url_list( 1, 'posts-page' ) ) );
		$this->assertSame( array(), $provider->get_url_list( 1, 'terms-category' ) );
		$this->assertSame( array( 'http://example.org/bn/hello/' ), self::locs( $provider->get_url_list( 1, 'posts-post' ) ) );
	}

	public function test_seo_plugin_index_is_untouched_without_one(): void {
		$sitemaps = new Sitemaps( $this->provider() );

		$this->assertSame( '<sitemap><loc>x</loc></sitemap>', $sitemaps->addToIndex( '<sitemap><loc>x</loc></sitemap>' ), 'Core sitemaps are on: the entry is in the core index instead.' );
		$this->assertFalse( Sitemaps::servedHere() );
	}

	public function test_setting_defaults_to_on(): void {
		$registry = new Registry();

		$this->assertTrue( ( new Settings( array( 'target_language' => 'bn_BD' ), 'en_US', $registry ) )->flag( 'sitemap_alternates' ) );
		$this->assertFalse(
			( new Settings(
				array(
					'target_language'    => 'bn_BD',
					'sitemap_alternates' => '0',
				),
				'en_US',
				$registry
			) )->flag( 'sitemap_alternates' )
		);
	}
}

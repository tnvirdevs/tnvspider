<?php
/**
 * Target-language sitemap (plan §13A.8).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Sitemap;

use WST\Modes\Resolver;
use WST\Routing\LanguageUrls;
use WST\Routing\Urls;
use WST\Settings;

/**
 * Lists the target-language URL of every page the core posts and taxonomies
 * sitemaps list (home included), with the post's lastmod. Pages whose mode
 * is "off" have no translated equivalent and pages the SEO plugin marks
 * noindex are left out. Core sitemaps cannot carry xhtml:link alternates,
 * so this is a separate sitemap in the index; the <head> hreflang tags stay
 * the baseline. Subtypes: "posts-{post type}" and "terms-{taxonomy}", e.g.
 * /wp-sitemap-wst-posts-page-1.xml.
 */
final class TargetProvider extends \WP_Sitemaps_Provider {

	public const NAME = 'wst';

	private const POSTS = 'posts-';
	private const TERMS = 'terms-';

	/**
	 * Core posts queries.
	 *
	 * @var PostQueries
	 */
	private PostQueries $posts;

	/**
	 * Core taxonomy queries.
	 *
	 * @var TermQueries
	 */
	private TermQueries $terms;

	/**
	 * Create the provider.
	 *
	 * @param LanguageUrls $byLang Per-language URLs.
	 * @param Urls         $urls   URL helper.
	 * @param Resolver     $modes  Page modes.
	 * @param SeoPlugins   $seo    Indexing rules of the SEO plugin.
	 */
	public function __construct(
		private LanguageUrls $byLang,
		private Urls $urls,
		private Resolver $modes,
		private SeoPlugins $seo
	) {
		$this->name        = self::NAME;
		$this->object_type = self::NAME;
		$this->posts       = new PostQueries();
		$this->terms       = new TermQueries();
	}

	/**
	 * Subtypes: indexable post types and taxonomies of the core sitemaps.
	 *
	 * @return array<string, object>
	 */
	public function get_object_subtypes() {
		$subtypes = array();
		foreach ( $this->posts->get_object_subtypes() as $name => $type ) {
			if ( $this->seo->postTypeIndexable( (string) $name ) ) {
				$subtypes[ self::POSTS . $name ] = $type;
			}
		}
		foreach ( $this->terms->get_object_subtypes() as $name => $taxonomy ) {
			if ( $this->seo->taxonomyIndexable( (string) $name ) ) {
				$subtypes[ self::TERMS . $name ] = $taxonomy;
			}
		}

		return $subtypes;
	}

	/**
	 * Pages of a subtype: the same as the core sitemap's.
	 *
	 * @param string $object_subtype Subtype.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core signature.
		$subtype = (string) $object_subtype; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core signature.
		if ( ! isset( $this->get_object_subtypes()[ $subtype ] ) ) {
			return 0;
		}

		return str_starts_with( $subtype, self::POSTS )
			? (int) $this->posts->get_max_num_pages( substr( $subtype, strlen( self::POSTS ) ) )
			: (int) $this->terms->get_max_num_pages( substr( $subtype, strlen( self::TERMS ) ) );
	}

	/**
	 * Target-language URLs of one page of a subtype.
	 *
	 * @param int    $page_num       1-based page.
	 * @param string $object_subtype Subtype.
	 * @return list<array{loc: string, lastmod?: string}>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core signature.
		$page    = max( 1, (int) $page_num ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core signature.
		$subtype = (string) $object_subtype; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core signature.
		if ( ! isset( $this->get_object_subtypes()[ $subtype ] ) ) {
			return array();
		}

		return str_starts_with( $subtype, self::POSTS )
			? $this->postUrls( substr( $subtype, strlen( self::POSTS ) ), $page )
			: $this->termUrls( substr( $subtype, strlen( self::TERMS ) ), $page );
	}

	/**
	 * Entries for a post type page, with the home page first on page 1 of
	 * "page" when the front page shows the latest posts (as in core).
	 *
	 * @param string $postType Post type.
	 * @param int    $page     1-based page.
	 * @return list<array{loc: string, lastmod?: string}>
	 */
	private function postUrls( string $postType, int $page ): array {
		$list = array();
		if ( 'page' === $postType && 1 === $page && 'posts' === get_option( 'show_on_front' ) ) {
			$home  = home_url( '/' );
			$entry = $this->entry( $home, null, $this->latestModified() );
			if ( null !== $entry && $this->seo->homeIndexable( $home ) ) {
				$list[] = $entry;
			}
		}
		$query = new \WP_Query( $this->posts->args( $postType, $page ) );
		foreach ( (array) $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post || ! $this->seo->postIndexable( $post->ID ) ) {
				continue;
			}
			$link  = get_permalink( $post );
			$entry = false === $link ? null : $this->entry( $link, $post->ID, $post->post_modified_gmt );
			if ( null !== $entry ) {
				$list[] = $entry;
			}
		}

		return $list;
	}

	/**
	 * Entries for a taxonomy page.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $page     1-based page.
	 * @return list<array{loc: string, lastmod?: string}>
	 */
	private function termUrls( string $taxonomy, int $page ): array {
		$list  = array();
		$query = new \WP_Term_Query( $this->terms->args( $taxonomy, $page ) );
		foreach ( (array) $query->terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$link = get_term_link( $term, $taxonomy );
			if ( ! is_string( $link ) || ! $this->seo->termIndexable( $term, $link ) ) {
				continue;
			}
			$entry = $this->entry( $link, null, '' );
			if ( null !== $entry ) {
				$list[] = $entry;
			}
		}

		return $list;
	}

	/**
	 * Sitemap entry for a default-language link, or null when the page is
	 * "off" (no translated equivalent) or outside the routed site.
	 *
	 * @param string   $link        Default-language URL.
	 * @param int|null $postId      Post shown there, if any.
	 * @param string   $modifiedGmt Last modification (GMT, MySQL format) or ''.
	 * @return array{loc: string, lastmod?: string}|null
	 */
	private function entry( string $link, ?int $postId, string $modifiedGmt ): ?array {
		$path = (string) wp_parse_url( $link, PHP_URL_PATH );
		$both = $this->byLang->forUri( '' === $path ? '/' : $path, false );
		$site = $this->urls->unprefixedPath( '' === $path ? '/' : $path );
		if ( null === $both || null === $site || Settings::MODE_OFF === $this->modes->resolve( $postId, $site )['mode'] ) {
			return null;
		}
		$entry = array( 'loc' => $both['target'] );
		if ( '' !== $modifiedGmt && '0000-00-00 00:00:00' !== $modifiedGmt ) {
			$time = strtotime( $modifiedGmt . ' UTC' );
			$date = false === $time ? false : wp_date( DATE_W3C, $time );
			if ( false !== $date ) {
				$entry['lastmod'] = $date;
			}
		}

		return $entry;
	}

	/**
	 * Last modification of the latest posts shown on the home page.
	 */
	private function latestModified(): string {
		$latest = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'numberposts'      => 1,
				'suppress_filters' => false,
			)
		);

		return isset( $latest[0] ) && $latest[0] instanceof \WP_Post ? $latest[0]->post_modified_gmt : '';
	}
}

<?php
/**
 * Target-language sitemap in the core, Yoast SEO and Rank Math indexes (plan §13A.8).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Sitemap;

/**
 * Registers the target-language sitemap with core sitemaps. Yoast SEO and
 * Rank Math switch core sitemaps off and serve their own index; then this
 * class serves the same sitemap URLs itself (core's rewrite rules stay in
 * place) and adds them to that index through the documented index filters
 * wpseo_sitemap_index and rank_math/sitemap/index. Neither plugin documents
 * a way to add xhtml:link alternates to its own entries, so none are added;
 * the <head> hreflang tags stay the baseline. Search engines that are
 * blocked (Settings > Reading) get no sitemap at all, as in core.
 */
final class Sitemaps {

	/**
	 * Create the integration.
	 *
	 * @param TargetProvider $provider Target-language sitemap.
	 */
	public function __construct( private TargetProvider $provider ) {
	}

	/**
	 * Hook into WordPress.
	 */
	public function boot(): void {
		add_action( 'wp_sitemaps_init', array( $this, 'register' ) );
		add_action( 'template_redirect', array( $this, 'serve' ), 0 );
		add_filter( 'wpseo_sitemap_index', array( $this, 'addToIndex' ) );
		add_filter( 'rank_math/sitemap/index', array( $this, 'addToIndex' ), 11 );
	}

	/**
	 * Add the provider to the core sitemap index.
	 */
	public function register(): void {
		wp_register_sitemap_provider( TargetProvider::NAME, $this->provider );
	}

	/**
	 * Whether this class serves the sitemap itself: core sitemaps are off
	 * because Yoast SEO or Rank Math replaced them, on a public site.
	 */
	public static function servedHere(): bool {
		return '' !== SeoPlugins::detect() && (bool) get_option( 'blog_public' ) && ! wp_sitemaps_get_server()->sitemaps_enabled();
	}

	/**
	 * Index entries for the SEO plugin's sitemap index.
	 *
	 * @param mixed $xml XML of extra <sitemap> entries.
	 * @return string
	 */
	public function addToIndex( $xml ) {
		$xml = is_string( $xml ) ? $xml : '';
		if ( ! self::servedHere() ) {
			return $xml;
		}
		foreach ( $this->provider->get_sitemap_entries() as $entry ) {
			$xml .= "\n<sitemap>\n\t<loc>" . esc_xml( esc_url_raw( (string) $entry['loc'] ) ) . "</loc>\n</sitemap>";
		}

		return $xml;
	}

	/**
	 * Serve /wp-sitemap-wst-*.xml while core sitemaps are off.
	 */
	public function serve(): void {
		if ( TargetProvider::NAME !== get_query_var( 'sitemap' ) || ! self::servedHere() ) {
			return;
		}
		$xml = $this->xml( (string) get_query_var( 'sitemap-subtype' ), max( 1, absint( get_query_var( 'paged' ) ) ) );
		if ( null === $xml ) {
			return; // Core answers 404 (its sitemaps are off).
		}
		status_header( 200 ); // The main query found no posts for this URL.
		header( 'Content-Type: application/xml; charset=UTF-8' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by WP_Sitemaps_Renderer, which escapes every value.
		exit;
	}

	/**
	 * Sitemap XML for a subtype page, or null when it has no URLs. Core's
	 * stylesheet is not linked: its URL answers 404 while core sitemaps are off.
	 *
	 * @param string $subtype Subtype.
	 * @param int    $page    1-based page.
	 */
	public function xml( string $subtype, int $page ): ?string {
		$list = $this->provider->get_url_list( $page, $subtype );
		if ( array() === $list ) {
			return null;
		}
		add_filter( 'wp_sitemaps_stylesheet_url', '__return_empty_string' );
		$xml = ( new \WP_Sitemaps_Renderer() )->get_sitemap_xml( $list );
		remove_filter( 'wp_sitemaps_stylesheet_url', '__return_empty_string' );

		return is_string( $xml ) ? $xml : null;
	}
}

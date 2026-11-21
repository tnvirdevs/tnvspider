<?php
/**
 * Core sitemap queries, reused for the target-language sitemap.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Sitemap;

/**
 * Gives access to the query arguments of the core posts and taxonomies
 * providers, so the target-language sitemap lists exactly what the default
 * sitemap lists (same post types, statuses, order, page size and every
 * wp_sitemaps_* filter other plugins added). Never registered as a provider.
 */
final class PostQueries extends \WP_Sitemaps_Posts {

	/**
	 * WP_Query arguments for one page of a post type.
	 *
	 * @param string $postType Post type.
	 * @param int    $page     1-based page.
	 * @return array<string, mixed>
	 */
	public function args( string $postType, int $page ): array {
		$args          = (array) $this->get_posts_query_args( $postType );
		$args['paged'] = $page;

		return $args;
	}
}

<?php
/**
 * Core taxonomy sitemap queries, reused for the target-language sitemap.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Sitemap;

/**
 * Query arguments of the core taxonomies provider (see PostQueries).
 * Never registered as a provider.
 */
final class TermQueries extends \WP_Sitemaps_Taxonomies {

	/**
	 * WP_Term_Query arguments for one page of a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $page     1-based page.
	 * @return array<string, mixed>
	 */
	public function args( string $taxonomy, int $page ): array {
		$args           = (array) $this->get_taxonomies_query_args( $taxonomy );
		$args['fields'] = 'all';
		$args['offset'] = ( $page - 1 ) * wp_sitemaps_get_max_urls( $this->object_type );

		return $args;
	}
}

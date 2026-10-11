<?php
/**
 * Pages of the whole site for "Translate entire site" (plan §8).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Site;

use WST\Languages\Language;
use WST\Modes\Resolver;
use WST\Routing\PathRules;
use WST\Routing\Urls;
use WST\Settings;

/**
 * The URL list: home page, public post type archives, every published post
 * of a public post type and every non-empty term of a public taxonomy, in a
 * stable order, a slice at a time. Each page carries the reason it is left
 * out, if any (plan §6A, §9): "off" pages, manual pages when machine
 * translation is turned off for them, personal pages (cart, checkout,
 * account), password-protected posts and "never discover" paths.
 */
final class SitePages {

	/** Pages per slice. */
	public const PER_PAGE = 500;

	public const SKIP_OFF       = 'off';
	public const SKIP_MANUAL    = 'manual';
	public const SKIP_PERSONAL  = 'personal';
	public const SKIP_PASSWORD  = 'password';
	public const SKIP_NEVER     = 'never_discover';
	public const SKIP_OTHER_URL = 'other_url';

	/**
	 * Create the list.
	 *
	 * @param Settings $settings Settings.
	 * @param Resolver $modes    Page modes.
	 * @param Urls     $urls     URL helper.
	 * @param Language $target   Target language.
	 */
	public function __construct(
		private Settings $settings,
		private Resolver $modes,
		private Urls $urls,
		private Language $target
	) {
	}

	/**
	 * One slice of the list.
	 *
	 * @param int $page 1-based slice number.
	 * @return array{items: list<array{path: string, post_id: int|null, title: string, type: string, skip: string}>, total: int, pages: int}
	 */
	public function slice( int $page ): array {
		$page   = max( 1, $page );
		$offset = ( $page - 1 ) * self::PER_PAGE;
		$fixed  = $this->fixed();
		$posts  = $this->postCount();
		$terms  = $this->termCount();
		$total  = count( $fixed ) + $posts + $terms;
		$items  = array();
		$want   = self::PER_PAGE;

		if ( $offset < count( $fixed ) ) {
			$items = array_slice( $fixed, $offset, $want );
			$want -= count( $items );
		}
		$offset = max( 0, $offset - count( $fixed ) );
		if ( $want > 0 && $offset < $posts ) {
			$found = $this->posts( $offset, $want );
			$items = array_merge( $items, $found );
			$want -= count( $found );
		}
		$offset = max( 0, $offset - $posts );
		if ( $want > 0 && $offset < $terms ) {
			$items = array_merge( $items, $this->terms( $offset, $want ) );
		}

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => (int) max( 1, ceil( $total / self::PER_PAGE ) ),
		);
	}

	/**
	 * Why a page is left out of "Translate entire site", or ''.
	 *
	 * @param int|null $postId Post shown at the path, if any.
	 * @param string   $path   Site path without language prefix.
	 */
	public function skipReason( ?int $postId, string $path ): string {
		$mode = $this->modes->resolve( $postId, $path )['mode'];
		if ( Settings::MODE_OFF === $mode ) {
			return self::SKIP_OFF;
		}
		if ( Settings::MODE_MANUAL === $mode && ! $this->settings->flag( 'editor_mt_on_manual' ) ) {
			return self::SKIP_MANUAL;
		}
		if ( null !== $postId && in_array( $postId, self::personalPostIds(), true ) ) {
			return self::SKIP_PERSONAL;
		}
		if ( null !== $postId && post_password_required( $postId ) ) {
			return self::SKIP_PASSWORD;
		}
		if ( PathRules::matchesAny( $path, $this->settings->neverDiscoverPaths() ) ) {
			return self::SKIP_NEVER;
		}

		return '';
	}

	/**
	 * Posts whose pages may show personal data (WooCommerce cart, checkout
	 * with order received, account), plan §6A.
	 *
	 * @return list<int>
	 */
	public static function personalPostIds(): array {
		$ids = array();
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$id = (int) wc_get_page_id( $page );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		/**
		 * Filters the posts never scanned by "Translate entire site" because
		 * they may show personal data. The front-end check is
		 * DiscoveryGate::isPersonalPage().
		 *
		 * @param list<int> $ids Post ids.
		 */
		$filtered = apply_filters( 'wst_personal_post_ids', $ids );

		return is_array( $filtered ) ? array_values( array_map( 'intval', $filtered ) ) : $ids;
	}

	/**
	 * Home page and post type archives.
	 *
	 * @return list<array{path: string, post_id: int|null, title: string, type: string, skip: string}>
	 */
	private function fixed(): array {
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$items = array( $this->item( home_url( '/' ), $front > 0 ? $front : null, __( 'Home page', 'wp-site-translator' ), 'home' ) );
		foreach ( $this->postTypes() as $type ) {
			$link   = get_post_type_archive_link( $type );
			$object = get_post_type_object( $type );
			if ( false !== $link && null !== $object && $object->has_archive ) {
				$items[] = $this->item( $link, null, (string) $object->labels->name, 'archive' );
			}
		}

		return array_values( array_filter( $items ) );
	}

	/**
	 * Published posts of public types, oldest first, without the static front page.
	 *
	 * @param int $offset Posts to skip.
	 * @param int $limit  Posts to return.
	 * @return list<array{path: string, post_id: int|null, title: string, type: string, skip: string}>
	 */
	private function posts( int $offset, int $limit ): array {
		$query = new \WP_Query(
			$this->postArgs() + array(
				'offset'         => $offset,
				'posts_per_page' => $limit,
			)
		);
		$items = array();
		foreach ( $query->posts ?? array() as $post ) {
			if ( $post instanceof \WP_Post ) {
				$link    = get_permalink( $post );
				$items[] = false === $link ? null : $this->item( $link, $post->ID, html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ), $post->post_type );
			}
		}

		return array_values( array_filter( $items ) );
	}

	/**
	 * Number of posts in the list.
	 */
	private function postCount(): int {
		$query = new \WP_Query(
			$this->postArgs() + array(
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Query arguments for the posts in the list.
	 *
	 * @return array<string, mixed>
	 */
	private function postArgs(): array {
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;

		return array(
			'post_type'              => $this->postTypes(),
			'post_status'            => 'publish',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'post__not_in'           => $front > 0 ? array( $front ) : array(),
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => true,
		);
	}

	/**
	 * Non-empty terms of public taxonomies, oldest first.
	 *
	 * @param int $offset Terms to skip.
	 * @param int $limit  Terms to return.
	 * @return list<array{path: string, post_id: int|null, title: string, type: string, skip: string}>
	 */
	private function terms( int $offset, int $limit ): array {
		$terms = get_terms(
			$this->termArgs() + array(
				'offset' => $offset,
				'number' => $limit,
			)
		);
		$items = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( $term instanceof \WP_Term ) {
				$link    = get_term_link( $term );
				$items[] = is_string( $link ) ? $this->item( $link, null, html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ), $term->taxonomy ) : null;
			}
		}

		return array_values( array_filter( $items ) );
	}

	/**
	 * Number of terms in the list.
	 */
	private function termCount(): int {
		if ( array() === $this->taxonomies() ) {
			return 0;
		}
		$count = wp_count_terms( $this->termArgs() );

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Query arguments for the terms in the list.
	 *
	 * @return array<string, mixed>
	 */
	private function termArgs(): array {
		return array(
			'taxonomy'   => $this->taxonomies(),
			'hide_empty' => true,
			'orderby'    => 'term_id',
			'order'      => 'ASC',
		);
	}

	/**
	 * Public post types, without attachments.
	 *
	 * @return list<string>
	 */
	private function postTypes(): array {
		return array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
	}

	/**
	 * Public taxonomies with term pages, without post formats.
	 *
	 * @return list<string>
	 */
	private function taxonomies(): array {
		return array_values( array_diff( get_taxonomies( array( 'public' => true ) ), array( 'post_format' ) ) );
	}

	/**
	 * A list entry for a link of this site.
	 *
	 * @param string   $link   Absolute URL.
	 * @param int|null $postId Post shown there.
	 * @param string   $title  Label.
	 * @param string   $type   Post type, taxonomy, "home" or "archive".
	 * @return array{path: string, post_id: int|null, title: string, type: string, skip: string}
	 */
	private function item( string $link, ?int $postId, string $title, string $type ): array {
		$path = $this->urls->unprefixedPath( (string) wp_parse_url( $link, PHP_URL_PATH ), $this->target->slug() );
		$host = wp_parse_url( $link, PHP_URL_HOST );

		return array(
			'path'    => $path ?? (string) wp_parse_url( $link, PHP_URL_PATH ),
			'post_id' => $postId,
			'title'   => '' === $title ? ( $path ?? $link ) : $title,
			'type'    => $type,
			'skip'    => null === $path || ( is_string( $host ) && wp_parse_url( home_url(), PHP_URL_HOST ) !== $host ) ? self::SKIP_OTHER_URL : $this->skipReason( $postId, $path ),
		);
	}
}

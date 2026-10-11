<?php
/**
 * What Yoast SEO and Rank Math say about indexing (plan §13A.8).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Sitemap;

/**
 * Asks the active SEO plugin whether a page may be indexed, so the
 * target-language sitemap leaves out what the plugin marks noindex or
 * excludes. Yoast SEO: the documented Surfaces API (YoastSEO()->meta,
 * YoastSEO()->helpers) and the documented wpseo_exclude_from_sitemap_by_post_ids
 * filter. Rank Math: its public RankMath\Helper indexability checks (the same
 * ones its own sitemap uses). Without either plugin WordPress has no
 * per-page noindex, so everything is indexable.
 */
final class SeoPlugins {

	public const YOAST     = 'yoast';
	public const RANK_MATH = 'rankmath';

	private const RANK_MATH_HELPER = 'RankMath\\Helper';

	/**
	 * The SEO plugin consulted: YOAST, RANK_MATH or ''.
	 *
	 * @var string
	 */
	private string $plugin;

	/**
	 * Post ids Yoast excludes from sitemaps, loaded once.
	 *
	 * @var list<int>|null
	 */
	private ?array $yoastExcluded = null;

	/**
	 * Create the helper.
	 *
	 * @param string|null $plugin SEO plugin to consult; null detects the active one.
	 */
	public function __construct( ?string $plugin = null ) {
		$this->plugin = $plugin ?? self::detect();
	}

	/**
	 * The active SEO plugin with its own sitemaps, or ''.
	 */
	public static function detect(): string {
		if ( function_exists( 'YoastSEO' ) ) {
			return self::YOAST;
		}
		if ( defined( 'RANK_MATH_VERSION' ) && is_callable( array( self::RANK_MATH_HELPER, 'is_post_indexable' ) ) ) {
			return self::RANK_MATH;
		}

		return '';
	}

	/**
	 * Whether pages of a post type may be indexed.
	 *
	 * @param string $postType Post type.
	 */
	public function postTypeIndexable( string $postType ): bool {
		switch ( $this->plugin ) {
			case self::YOAST:
				return (bool) self::yoast( array( 'helpers', 'post_type' ), 'is_indexable', $postType );
			case self::RANK_MATH:
				return self::rankMath( 'is_post_type_indexable', $postType );
		}

		return true;
	}

	/**
	 * Whether term archives of a taxonomy may be indexed.
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public function taxonomyIndexable( string $taxonomy ): bool {
		switch ( $this->plugin ) {
			case self::YOAST:
				return (bool) self::yoast( array( 'helpers', 'taxonomy' ), 'is_indexable', $taxonomy );
			case self::RANK_MATH:
				return self::rankMath( 'is_taxonomy_indexable', $taxonomy );
		}

		return true;
	}

	/**
	 * Whether a post may be indexed.
	 *
	 * @param int $postId Post id.
	 */
	public function postIndexable( int $postId ): bool {
		switch ( $this->plugin ) {
			case self::YOAST:
				if ( null === $this->yoastExcluded ) {
					// Yoast SEO's documented filter (XML sitemaps API), read the way Yoast reads it.
					$this->yoastExcluded = array_values( array_map( 'intval', (array) apply_filters( 'wpseo_exclude_from_sitemap_by_post_ids', array() ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook.
				}
				if ( in_array( $postId, $this->yoastExcluded, true ) ) {
					return false;
				}

				return self::yoastIndexable( self::yoast( array( 'meta' ), 'for_post', $postId ) );
			case self::RANK_MATH:
				return self::rankMath( 'is_post_indexable', $postId );
		}

		return true;
	}

	/**
	 * Whether a term archive may be indexed.
	 *
	 * @param \WP_Term $term Term.
	 * @param string   $link Its (default-language) link.
	 */
	public function termIndexable( \WP_Term $term, string $link ): bool {
		switch ( $this->plugin ) {
			case self::YOAST:
				return self::yoastIndexable( self::yoast( array( 'meta' ), 'for_url', $link ) );
			case self::RANK_MATH:
				return self::rankMath( 'is_term_indexable', $term );
		}

		return true;
	}

	/**
	 * Whether the home page (latest posts) may be indexed.
	 *
	 * @param string $link Its (default-language) link.
	 */
	public function homeIndexable( string $link ): bool {
		return self::YOAST === $this->plugin ? self::yoastIndexable( self::yoast( array( 'meta' ), 'for_url', $link ) ) : true;
	}

	/**
	 * Call a Yoast SEO Surfaces API method, e.g. YoastSEO()->meta->for_post( 2 ).
	 *
	 * @param string[] $path   Surface properties below YoastSEO().
	 * @phpstan-param list<string> $path
	 * @param string   $method Method.
	 * @param mixed    $arg    Argument.
	 * @return mixed
	 * @throws \LogicException When the API is missing (Yoast SEO changed or was unloaded).
	 */
	private static function yoast( array $path, string $method, $arg ) {
		$object = \YoastSEO();
		foreach ( $path as $name ) {
			$object = self::property( $object, $name );
		}
		$callback = array( $object, $method );
		if ( ! is_callable( $callback ) ) {
			throw new \LogicException( 'Yoast SEO API missing: ' . esc_html( implode( '->', $path ) . '->' . $method ) . '().' );
		}

		return $callback( $arg );
	}

	/**
	 * Call a public Rank Math indexability check.
	 *
	 * @param string $method RankMath\Helper method.
	 * @param mixed  $arg    Argument.
	 * @throws \LogicException When the method is missing.
	 */
	private static function rankMath( string $method, $arg ): bool {
		$callback = array( self::RANK_MATH_HELPER, $method );
		if ( ! is_callable( $callback ) ) {
			throw new \LogicException( 'Rank Math API missing: ' . esc_html( $method ) . '().' );
		}

		return (bool) $callback( $arg );
	}

	/**
	 * Whether Yoast meta allows indexing. Pages Yoast does not know (false)
	 * keep the WordPress default: indexable.
	 *
	 * @param mixed $meta Meta surface result.
	 */
	private static function yoastIndexable( $meta ): bool {
		if ( ! is_object( $meta ) ) {
			return true;
		}
		$robots = self::property( $meta, 'robots' );

		return ! ( is_array( $robots ) && in_array( 'noindex', $robots, true ) );
	}

	/**
	 * A (possibly magic) property of a Yoast SEO surface object, or null.
	 *
	 * @param mixed  $source Object.
	 * @param string $name   Property.
	 * @return mixed
	 */
	private static function property( $source, string $name ) {
		return is_object( $source ) ? $source->{$name} : null;
	}
}

<?php
/**
 * Stand-in for Rank Math's public indexability checks (tests only).
 *
 * @package WST
 */

declare(strict_types=1);

namespace RankMath;

/**
 * Answers from static lists the test fills.
 */
final class Helper {

	/**
	 * Noindexed post ids.
	 *
	 * @var list<int>
	 */
	public static array $noindexPosts = array();

	/**
	 * Noindexed term ids.
	 *
	 * @var list<int>
	 */
	public static array $noindexTerms = array();

	/**
	 * Noindexed post types and taxonomies.
	 *
	 * @var list<string>
	 */
	public static array $noindexTypes = array();

	public static function is_post_indexable( $post_id ): bool {
		return ! in_array( (int) $post_id, self::$noindexPosts, true );
	}

	public static function is_term_indexable( $term ): bool {
		return ! in_array( (int) $term->term_id, self::$noindexTerms, true );
	}

	public static function is_post_type_indexable( $post_type ): bool {
		return ! in_array( $post_type, self::$noindexTypes, true );
	}

	public static function is_taxonomy_indexable( $taxonomy ): bool {
		return ! in_array( $taxonomy, self::$noindexTypes, true );
	}
}

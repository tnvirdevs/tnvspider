<?php
/**
 * Page-cache purge when a page's translations are ready (plan §6A).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Cache;

use WST\Languages\Language;
use WST\Routing\Urls;
use WST\Storage\StringStore;

/**
 * After translations are stored, purges the target-language URL of every
 * page that contains them and has nothing left in the queue. Uses the
 * LiteSpeed Cache action `litespeed_purge_url` and WP Rocket's
 * `rocket_clean_files()` (both checked in their source: LiteSpeed Cache
 * 7.9.1 src/api.cls.php, WP Rocket 3.23.5.1 inc/functions/files.php), and
 * fires `wst_purge_url` for other caches. Purges nothing when nothing changed.
 */
final class Purger {

	/**
	 * Create the purger.
	 *
	 * @param StringStore $store  Strings and pages.
	 * @param Urls        $urls   URL helper.
	 * @param Language    $target Target language.
	 * @param string      $origin Scheme and host of the home URL.
	 */
	public function __construct(
		private StringStore $store,
		private Urls $urls,
		private Language $target,
		private string $origin
	) {
	}

	/**
	 * Register the hook.
	 */
	public function boot(): void {
		add_action( 'wst_strings_translated', array( $this, 'onStringsTranslated' ), 10, 2 );
	}

	/**
	 * Action callback for wst_strings_translated.
	 *
	 * @param mixed $stringIds String ids.
	 * @param mixed $lang      Target locale.
	 */
	public function onStringsTranslated( $stringIds, $lang ): void {
		if ( is_array( $stringIds ) && is_string( $lang ) ) {
			$this->purgeStrings( array_values( array_map( 'intval', $stringIds ) ), $lang );
		}
	}

	/**
	 * Purge the pages these strings appear on, when they are finished.
	 *
	 * @param int[]  $stringIds String ids.
	 * @param string $lang      Target locale.
	 * @phpstan-param list<int> $stringIds
	 * @return list<string> Purged URLs.
	 */
	public function purgeStrings( array $stringIds, string $lang ): array {
		if ( $lang !== $this->target->locale() ) {
			return array();
		}
		$urls = array();
		foreach ( $this->store->finishedPages( $stringIds, $lang ) as $path ) {
			$url = $this->urls->addPrefix( $this->origin . ( '/' === $path ? '/' : user_trailingslashit( $path ) ), $this->target->slug() );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
		$this->purge( $urls );

		return $urls;
	}

	/**
	 * Purge the target-language URLs of posts whose output changed (e.g. a
	 * new page mode).
	 *
	 * @param int[] $postIds Post ids.
	 * @phpstan-param list<int> $postIds
	 * @return list<string> Purged URLs.
	 */
	public function purgePosts( array $postIds ): array {
		$urls = array();
		foreach ( $postIds as $postId ) {
			$link = get_permalink( $postId );
			if ( false !== $link ) {
				$urls[] = $this->urls->addPrefix( $link, $this->target->slug() );
			}
		}
		$this->purge( $urls );

		return $urls;
	}

	/**
	 * Ask the known page caches to drop every page, after a settings change
	 * that alters all output (slugs, switcher, link and hreflang options).
	 */
	public static function purgeAll(): void {
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's public purge action.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain(); // WP Rocket 3.23.5.1 inc/functions/files.php; LiteSpeed's litespeed_purge_all: src/api.cls.php.
		}

		/**
		 * Fires when every cached page is outdated.
		 */
		do_action( 'wst_purge_all' );
	}

	/**
	 * Ask the known page caches to drop these URLs.
	 *
	 * @param string[] $urls Absolute URLs.
	 * @phpstan-param list<string> $urls
	 */
	private function purge( array $urls ): void {
		if ( array() === $urls ) {
			return;
		}
		foreach ( $urls as $url ) {
			do_action( 'litespeed_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's public purge action.

			/**
			 * Fires for each target-language URL whose cached copy is outdated.
			 *
			 * @param string $url Absolute URL.
			 */
			do_action( 'wst_purge_url', $url );
		}
		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( $urls );
		}
	}
}

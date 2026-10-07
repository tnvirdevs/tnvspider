<?php
/**
 * Which page the editor works on (plan §11).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Editor;

use WST\Languages\Language;
use WST\Modes\Resolver;
use WST\Routing\LanguageUrls;
use WST\Routing\Urls;
use WST\Storage\StringStore;

/**
 * Turns a post id or a site path into the page facts the editor needs. Only
 * paths of this site are accepted and every URL is built from the home URL,
 * so scans and previews can never be pointed at another host (SSRF guard).
 */
final class PageTarget {

	/**
	 * Create the resolver.
	 *
	 * @param Urls         $urls   URL helper.
	 * @param LanguageUrls $byLang Per-language URLs.
	 * @param Language     $target Target language.
	 * @param Resolver     $modes  Page modes.
	 * @param StringStore  $store  Strings (page times).
	 */
	public function __construct(
		private Urls $urls,
		private LanguageUrls $byLang,
		private Language $target,
		private Resolver $modes,
		private StringStore $store
	) {
	}

	/**
	 * Page of a published post.
	 *
	 * @param int $postId Post id.
	 * @return array{path: string, post_id: int|null, title: string, page_key: string, mode: string, source: string, target_url: string, default_url: string, last_seen: string|null, last_scan: string|null}
	 * @throws \InvalidArgumentException When the post is missing or not public.
	 */
	public function forPost( int $postId ): array {
		$post = get_post( $postId );
		if ( null === $post || ! is_post_publicly_viewable( $post ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Only published, public posts can be translated in the editor.', 'wp-site-translator' ) );
		}
		$link = get_permalink( $post );
		$path = false === $link ? null : $this->urls->unprefixedPath( (string) wp_parse_url( $link, PHP_URL_PATH ), $this->target->slug() );
		if ( null === $path ) {
			throw new \InvalidArgumentException( esc_html__( 'This post has no address on the site.', 'wp-site-translator' ) );
		}

		return $this->describe( $path, $postId, html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Page of a site path (with or without language prefix).
	 *
	 * @param string $path Root-relative path, e.g. /shop/.
	 * @return array{path: string, post_id: int|null, title: string, page_key: string, mode: string, source: string, target_url: string, default_url: string, last_seen: string|null, last_scan: string|null}
	 * @throws \InvalidArgumentException For anything that is not a path of this site.
	 */
	public function forPath( string $path ): array {
		if ( 1 !== preg_match( '#^/(?!/)[^\s?\#\\\\]{0,254}$#', $path ) || str_contains( $path, '..' ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Give a path of this site starting with /, without query string.', 'wp-site-translator' ) );
		}
		$clean = $this->urls->unprefixedPath( $path, $this->target->slug() );
		if ( null === $clean ) {
			throw new \InvalidArgumentException( esc_html__( 'This address is not a translatable page of the site.', 'wp-site-translator' ) );
		}
		$urls   = $this->byLang->forUri( $clean, false );
		$postId = null === $urls ? 0 : url_to_postid( $urls['default'] );
		if ( $postId > 0 ) {
			$post = get_post( $postId );
			if ( null !== $post && is_post_publicly_viewable( $post ) ) {
				return $this->describe( $clean, $postId, html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) );
			}
		}

		return $this->describe( $clean, null, '/' === $clean ? __( 'Home page', 'wp-site-translator' ) : $clean );
	}

	/**
	 * Page facts.
	 *
	 * @param string   $path   Site path without language prefix.
	 * @param int|null $postId Post, if any.
	 * @param string   $title  Display title.
	 * @return array{path: string, post_id: int|null, title: string, page_key: string, mode: string, source: string, target_url: string, default_url: string, last_seen: string|null, last_scan: string|null}
	 * @throws \InvalidArgumentException When no URL can be built for the path.
	 */
	private function describe( string $path, ?int $postId, string $title ): array {
		$urls = $this->byLang->forUri( $path, false );
		if ( null === $urls ) {
			throw new \InvalidArgumentException( esc_html__( 'This address is not a translatable page of the site.', 'wp-site-translator' ) );
		}
		$mode = $this->modes->resolve( $postId, $path );

		return array(
			'path'        => $path,
			'post_id'     => $postId,
			'title'       => '' === $title ? $path : $title,
			'page_key'    => StringStore::pageKey( $path ),
			'mode'        => $mode['mode'],
			'source'      => $mode['source'],
			'target_url'  => $urls['target'],
			'default_url' => $urls['default'],
		) + $this->store->pageTimes( $path );
	}
}

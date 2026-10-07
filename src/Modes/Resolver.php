<?php
/**
 * Translation mode of a page (plan §9).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Modes;

use WST\Languages\Language;
use WST\Routing\PathRules;
use WST\Routing\Urls;
use WST\Settings;

/**
 * First match wins: the page's own setting (post meta, singular views), then
 * the path rules in order, then the site mode. The result decides discovery
 * and automatic queueing only; translations already stored apply site-wide.
 */
final class Resolver {

	/** Post meta holding a page's mode. */
	public const META_KEY = '_wst_mode';

	/** Meta value meaning "use the path rules and the site mode". */
	public const INHERIT = 'inherit';

	/** Values the post meta accepts. */
	public const PAGE_VALUES = array( self::INHERIT, Settings::MODE_AUTO, Settings::MODE_MANUAL, Settings::MODE_OFF );

	/** Where a resolved mode came from. */
	public const SOURCE_PAGE = 'page';
	public const SOURCE_PATH = 'path';
	public const SOURCE_SITE = 'site';

	/**
	 * Mode of the current request, once known.
	 *
	 * @var array{mode: string, source: string}|null
	 */
	private ?array $current = null;

	/**
	 * Create the resolver.
	 *
	 * @param Settings $settings Settings.
	 * @param Urls     $urls     URL helper.
	 * @param Language $target   Target language (for stripping its prefix).
	 */
	public function __construct(
		private Settings $settings,
		private Urls $urls,
		private Language $target
	) {
	}

	/**
	 * Mode for a page.
	 *
	 * @param int|null $postId Post of a singular view, or null.
	 * @param string   $path   Site path without language prefix.
	 * @return array{mode: string, source: string}
	 */
	public function resolve( ?int $postId, string $path ): array {
		if ( null !== $postId ) {
			$own = self::pageMode( $postId );
			if ( self::INHERIT !== $own ) {
				return array(
					'mode'   => $own,
					'source' => self::SOURCE_PAGE,
				);
			}
		}
		foreach ( $this->settings->pathRules() as $rule ) {
			if ( PathRules::matches( $path, $rule['path'] ) ) {
				return array(
					'mode'   => $rule['mode'],
					'source' => self::SOURCE_PATH,
				);
			}
		}

		return array(
			'mode'   => $this->settings->siteMode(),
			'source' => self::SOURCE_SITE,
		);
	}

	/**
	 * Mode of a post at its permalink (admin screens, REST, explicit requests).
	 *
	 * @param int $postId Post id.
	 * @return array{mode: string, source: string}
	 */
	public function resolvePost( int $postId ): array {
		$link = get_permalink( $postId );
		$path = false === $link ? '' : (string) wp_parse_url( $link, PHP_URL_PATH );

		return $this->resolve( $postId, '' === $path ? '/' : $path );
	}

	/**
	 * Mode of the current front-end request. Call after the main query ran.
	 *
	 * @return array{mode: string, source: string}
	 */
	public function current(): array {
		if ( null === $this->current ) {
			$uri           = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only parsed.
			$this->current = $this->resolve(
				is_singular() ? (int) get_queried_object_id() : null,
				$this->urls->unprefixedPath( $uri, $this->target->slug() ) ?? '/'
			);
		}

		return $this->current;
	}

	/**
	 * Whether the current request is a page set to "off".
	 */
	public function currentIsOff(): bool {
		return Settings::MODE_OFF === $this->current()['mode'];
	}

	/**
	 * A post's own setting: inherit, auto, manual or off.
	 *
	 * @param int $postId Post id.
	 */
	public static function pageMode( int $postId ): string {
		$value = get_post_meta( $postId, self::META_KEY, true );

		return is_string( $value ) && in_array( $value, self::PAGE_VALUES, true ) ? $value : self::INHERIT;
	}

	/**
	 * Register the post meta for every post type (REST-visible, so the block
	 * editor can edit it; only users who may edit the post can change it).
	 */
	public static function registerMeta(): void {
		register_post_meta(
			'',
			self::META_KEY,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => self::INHERIT,
				'sanitize_callback' => static fn( $value ): string => is_string( $value ) && in_array( $value, self::PAGE_VALUES, true ) ? $value : self::INHERIT,
				'auth_callback'     => static fn( $allowed, $metaKey, $postId ): bool => current_user_can( 'edit_post', (int) $postId ),
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => self::PAGE_VALUES,
					),
				),
			)
		);
	}
}

<?php
/**
 * Language routing (plan §5).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Routing;

use WST\Languages\Current;
use WST\Languages\Language;
use WST\Settings;

/**
 * Resolves the language before WordPress parses the request: a /{slug}/
 * prefix is stripped from REQUEST_URI for routing and restored afterwards,
 * so WordPress routes normally and canonical redirects keep the prefix.
 * Default-language requests only pay for the prefix check.
 */
final class Router {

	/**
	 * Request URI as received, while the stripped one is in $_SERVER.
	 *
	 * @var string|null
	 */
	private ?string $originalUri = null;

	/**
	 * Create the router.
	 *
	 * @param Settings $settings Plugin settings.
	 * @param Language $target   Target language.
	 * @param Urls     $urls     URL helper for this site.
	 */
	public function __construct(
		private Settings $settings,
		private Language $target,
		private Urls $urls
	) {
	}

	/**
	 * Router for the configured site, or null while no target language is set.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public static function forSite( Settings $settings ): ?self {
		$target = $settings->targetLanguage();
		if ( null === $target ) {
			return null;
		}

		return new self( $settings, $target, Urls::fromHome( (string) get_option( 'home' ), rest_get_url_prefix() ) );
	}

	/**
	 * Detect the language of this request and register the hooks. Runs when
	 * the plugin file loads, before the locale and the theme are set up.
	 */
	public function boot(): void {
		$this->detect();

		add_filter( 'locale', array( $this, 'filterLocale' ) );
		add_filter( 'gettext_with_context', array( $this, 'filterTextDirection' ), 10, 4 );
		add_filter( 'do_parse_request', array( $this, 'beforeParseRequest' ), 1 );
		add_action( 'parse_request', array( $this, 'afterParseRequest' ), 0 );
		add_filter( 'home_url', array( $this, 'filterHomeUrl' ), 10, 4 );
		add_filter( 'wp_redirect', array( $this, 'filterRedirect' ) );
	}

	/**
	 * Set Current from the request: the URL prefix for front-end requests;
	 * for AJAX and REST requests the wst_lang parameter, else the referer.
	 */
	public function detect(): void {
		$uri      = $this->serverString( 'REQUEST_URI' );
		$relative = $this->urls->relativePath( '' === $uri ? '/' : $uri );
		$isTarget = false;

		if ( null !== $relative && ! $this->urls->isExempt( $relative ) ) {
			$isTarget = $this->urls->hasPrefix( $relative, $this->target->slug() );
		} elseif ( $this->isAjaxOrRest( (string) $relative ) ) {
			$isTarget = $this->targetFromAjax();
		}

		Current::set( $isTarget ? $this->target : $this->settings->defaultLanguage(), $isTarget );
	}

	/**
	 * Strip the prefix just before WordPress parses the request.
	 *
	 * @param bool $parse Whether WordPress should parse the request.
	 * @return bool Unchanged.
	 */
	public function beforeParseRequest( $parse ) {
		$this->detect();
		if ( Current::isTarget() ) {
			$uri                    = $this->serverString( 'REQUEST_URI' );
			$this->originalUri      = $uri;
			$_SERVER['REQUEST_URI'] = $this->urls->stripPrefix( $uri, $this->target->slug() );
		}

		return $parse;
	}

	/**
	 * Put the prefixed URI back once WordPress has parsed the request.
	 */
	public function afterParseRequest(): void {
		if ( null !== $this->originalUri ) {
			$_SERVER['REQUEST_URI'] = $this->originalUri;
			$this->originalUri      = null;
		}
	}

	/**
	 * Use the target locale on target requests, so gettext, is_rtl() and the
	 * html lang attribute follow the language.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public function filterLocale( $locale ) {
		return Current::isTarget() ? $this->target->locale() : $locale;
	}

	/**
	 * WP_Locale reads the text direction from the core translation of "ltr".
	 * Force the target's direction even when no core language pack is installed.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Original text.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filterTextDirection( $translation, $text, $context, $domain ) {
		if ( Current::isTarget() && 'ltr' === $text && 'text direction' === $context && 'default' === $domain ) {
			return $this->target->dir();
		}

		return $translation;
	}

	/**
	 * Prefix front-end URLs built with home_url() on target requests, so
	 * menus, permalinks, canonical URLs and forms stay in the language.
	 *
	 * @param string      $url        Home URL with path.
	 * @param string      $path       Path argument.
	 * @param string|null $origScheme Scheme argument.
	 * @param int|null    $blogId     Site id.
	 * @return string
	 */
	public function filterHomeUrl( $url, $path = '', $origScheme = null, $blogId = null ) {
		unset( $path, $blogId );
		if ( ! Current::isTarget() || 'rest' === $origScheme ) {
			return $url;
		}

		return $this->urls->addPrefix( $url, $this->target->slug() );
	}

	/**
	 * Keep internal redirects in the target language.
	 *
	 * @param string $location Redirect target.
	 * @return string
	 */
	public function filterRedirect( $location ) {
		if ( ! Current::isTarget() || ! is_string( $location ) ) {
			return $location;
		}

		return $this->urls->addPrefix( $location, $this->target->slug() );
	}

	/**
	 * Whether the request goes to admin-ajax.php, the REST API or wc-ajax.
	 *
	 * @param string $relative Relative path.
	 */
	private function isAjaxOrRest( string $relative ): bool {
		if ( str_starts_with( $relative, 'wp-admin/admin-ajax.php' ) || str_starts_with( $relative, rest_get_url_prefix() . '/' ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing hints.
		return isset( $_GET['rest_route'] ) || isset( $_GET['wc-ajax'] );
	}

	/**
	 * Language of an AJAX/REST request: explicit wst_lang, else the referer prefix.
	 */
	private function targetFromAjax(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Read-only language hint.
		$explicit = isset( $_REQUEST['wst_lang'] ) && is_string( $_REQUEST['wst_lang'] ) ? sanitize_key( wp_unslash( $_REQUEST['wst_lang'] ) ) : '';
		if ( '' !== $explicit ) {
			return $explicit === $this->target->slug() || strtolower( $this->target->locale() ) === $explicit;
		}

		$referer = $this->serverString( 'HTTP_REFERER' );
		if ( '' === $referer ) {
			return false;
		}
		$parts = wp_parse_url( $referer );
		if ( ! is_array( $parts ) || ! isset( $parts['path'] ) ) {
			return false;
		}
		$relative = $this->urls->relativePath( $parts['path'] );

		return null !== $relative && $this->urls->hasPrefix( $relative, $this->target->slug() );
	}

	/**
	 * A $_SERVER string value, unslashed.
	 *
	 * @param string $key Key.
	 */
	private function serverString( string $key ): string {
		return isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? wp_unslash( $_SERVER[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw URI is needed for routing; it is only parsed, never output.
	}
}

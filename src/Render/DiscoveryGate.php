<?php
/**
 * Who may create strings from a visit (plan §6A).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Render;

use WST\Config;
use WST\Log\Logger;
use WST\Routing\PathRules;
use WST\Settings;
use WST\Storage\StringStore;

/**
 * Decides whether a visit may record new strings, and enforces the hourly
 * caps. Reading translations is never restricted.
 */
final class DiscoveryGate {

	/** User agents treated as crawlers. Empty user agents count too. */
	private const BOT_PATTERN = '/bot|crawl|spider|slurp|archiver|bingpreview|facebookexternalhit|embedly|preview|validator|lighthouse|headless|python-requests|python-urllib|curl\/|wget|httpclient|okhttp|go-http-client|scrapy/i';

	/**
	 * Create the gate.
	 *
	 * @param Settings $settings Plugin settings.
	 * @param Logger   $logger   Plugin log.
	 */
	public function __construct(
		private Settings $settings,
		private Logger $logger
	) {
	}

	/**
	 * Whether the current request may discover strings. Call after the main
	 * query has run (template_redirect). The HTTP status is checked separately
	 * when the response is complete.
	 *
	 * @param string $path Site path without language prefix.
	 */
	public function allowsRequest( string $path ): bool {
		$allowed = $this->settings->flag( 'discover_on_visit' )
			&& Settings::MODE_AUTO === $this->settings->siteMode()
			&& 'GET' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' )
			&& ! is_user_logged_in()
			&& ! ( $this->settings->flag( 'block_crawlers' ) && self::isBot( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' ) )
			&& $this->queryArgsAllowed()
			&& ! is_search()
			&& ! is_404()
			&& ! is_feed()
			&& ! is_preview()
			&& ! is_customize_preview()
			&& ! ( is_singular() && post_password_required() )
			&& ! $this->isPersonalShopPage()
			&& ! PathRules::matchesAny( $path, $this->settings->neverDiscoverPaths() );

		/**
		 * Filters whether the current visit may record new strings.
		 *
		 * @param bool   $allowed Decision of the built-in rules.
		 * @param string $path    Site path without language prefix.
		 */
		return (bool) apply_filters( 'wst_discovery_allowed', $allowed, $path );
	}

	/**
	 * How many of $wanted new strings may be recorded now, within the
	 * per-page and site-wide hourly caps, and count them as used.
	 *
	 * @param string $path   Site path without language prefix.
	 * @param int    $wanted Number of new strings found.
	 */
	public function take( string $path, int $wanted ): int {
		$hour     = gmdate( 'YmdH' );
		$pageKey  = Config::PREFIX . 'disc_p_' . $hour . '_' . StringStore::pageKey( $path );
		$siteKey  = Config::PREFIX . 'disc_s_' . $hour;
		$pageUsed = (int) get_transient( $pageKey );
		$siteUsed = (int) get_transient( $siteKey );

		$allowed = max( 0, min( $wanted, $this->settings->number( 'discovery_cap_page_hour' ) - $pageUsed, $this->settings->number( 'discovery_cap_site_hour' ) - $siteUsed ) );
		if ( $allowed > 0 ) {
			set_transient( $pageKey, $pageUsed + $allowed, 2 * HOUR_IN_SECONDS );
			set_transient( $siteKey, $siteUsed + $allowed, 2 * HOUR_IN_SECONDS );
		}
		if ( $allowed < $wanted ) {
			$flag = Config::PREFIX . 'disc_capped_' . $hour;
			if ( false === get_transient( $flag ) ) {
				set_transient( $flag, 1, 2 * HOUR_IN_SECONDS );
				$this->logger->warning( 'discovery', 'Discovery cap reached; new strings are skipped until the next hour.', array( 'path' => $path ) );
			}
		}

		return $allowed;
	}

	/**
	 * Whether a user agent belongs to a crawler.
	 *
	 * @param string $userAgent User agent.
	 */
	public static function isBot( string $userAgent ): bool {
		return '' === trim( $userAgent ) || 1 === preg_match( self::BOT_PATTERN, $userAgent );
	}

	/**
	 * Whether every query argument is in the allowed list.
	 */
	private function queryArgsAllowed(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only the argument names are inspected.
		$names = array_map( 'strval', array_keys( $_GET ) );

		return array() === array_diff( $names, $this->settings->discoveryQueryArgs() );
	}

	/**
	 * WooCommerce cart, checkout (including order received) and account pages.
	 */
	private function isPersonalShopPage(): bool {
		return ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() );
	}
}

<?php
/**
 * Target-language URLs of pages set to "off" (plan §9).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Modes;

use WST\Languages\Current;
use WST\Routing\LanguageUrls;
use WST\Settings;

/**
 * Redirects a target-language URL of an "off" page to its original URL
 * (302, because the mode can change), or, when the owner prefers to show the
 * original text there, points its canonical URL at the original page.
 */
final class OffPages {

	/**
	 * Create the handler.
	 *
	 * @param Settings     $settings Settings.
	 * @param Resolver     $modes    Page modes.
	 * @param LanguageUrls $urls     Per-language URLs.
	 */
	public function __construct(
		private Settings $settings,
		private Resolver $modes,
		private LanguageUrls $urls
	) {
	}

	/**
	 * Register the hook: before the render pipeline starts buffering.
	 */
	public function boot(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), 0 );
	}

	/**
	 * Redirect, or set the canonical URL, for an "off" target page.
	 */
	public function handle(): void {
		if ( ! Current::isTarget() || ! $this->modes->currentIsOff() ) {
			return;
		}
		$urls = $this->urls->forCurrentRequest( true );
		if ( null === $urls ) {
			return;
		}
		if ( Settings::OFF_ORIGINAL === $this->settings->offBehavior() ) {
			$canonical = $this->urls->forCurrentRequest( false )['default'] ?? $urls['default'];
			add_filter( 'get_canonical_url', static fn(): string => $canonical );

			return;
		}
		// This response leaves the target language: without this the Router's
		// wp_redirect filter would add the prefix back and loop.
		Current::set( $this->settings->defaultLanguage(), false );
		if ( wp_safe_redirect( $urls['default'], 302, 'WP Site Translator' ) ) {
			exit;
		}
	}
}

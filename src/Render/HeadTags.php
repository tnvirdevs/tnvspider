<?php
/**
 * Language alternates in <head> and the html lang attribute (plan §5).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Render;

use WST\Languages\Current;
use WST\Languages\Language;
use WST\Routing\LanguageUrls;
use WST\Modes\Resolver;
use WST\Settings;

/**
 * Prints hreflang alternates for both languages (and x-default) on every
 * front-end page, and applies the "drop region" option to the lang attribute.
 * Canonical URLs need no work: core builds them with home_url(), which the
 * Router prefixes on target requests.
 */
final class HeadTags {

	/**
	 * Create the printer.
	 *
	 * @param Settings     $settings Plugin settings.
	 * @param Language     $defaultLanguage Default language.
	 * @param Language     $target   Target language.
	 * @param LanguageUrls $urls     Per-language URLs.
	 * @param Resolver     $modes    Page modes.
	 */
	public function __construct(
		private Settings $settings,
		private Language $defaultLanguage,
		private Language $target,
		private LanguageUrls $urls,
		private Resolver $modes
	) {
	}

	/**
	 * Register the hooks.
	 */
	public function boot(): void {
		add_action( 'wp_head', array( $this, 'printAlternates' ), 1 );
		if ( $this->settings->flag( 'hreflang_drop_region' ) ) {
			add_filter( 'language_attributes', array( $this, 'dropRegion' ) );
		}
	}

	/**
	 * Alternate URLs for the current request, or null where none apply.
	 *
	 * @return array<string, string>|null hreflang => URL.
	 */
	public function alternates(): ?array {
		// "Off" pages have no counterpart in the other language (plan §5).
		if ( is_404() || is_search() || is_preview() || $this->modes->currentIsOff() ) {
			return null;
		}
		$urls = $this->urls->forCurrentRequest( false );
		if ( null === $urls ) {
			return null;
		}

		$drop       = $this->settings->flag( 'hreflang_drop_region' );
		$alternates = array(
			$this->defaultLanguage->tag( $drop ) => $urls['default'],
			$this->target->tag( $drop )          => $urls['target'],
		);
		if ( $this->settings->flag( 'hreflang_x_default' ) ) {
			$alternates['x-default'] = $urls['default'];
		}

		return $alternates;
	}

	/**
	 * Print the alternate link tags.
	 */
	public function printAlternates(): void {
		foreach ( $this->alternates() ?? array() as $hreflang => $url ) {
			printf( '<link rel="alternate" hreflang="%s" href="%s" />' . "\n", esc_attr( $hreflang ), esc_url( $url ) );
		}
	}

	/**
	 * Replace lang="xx-YY" with lang="xx".
	 *
	 * @param string $output Attributes printed by language_attributes().
	 * @return string
	 */
	public function dropRegion( $output ) {
		$language = Current::isTarget() ? $this->target : $this->defaultLanguage;

		return (string) preg_replace( '/\blang="[^"]*"/', 'lang="' . esc_attr( $language->tag( true ) ) . '"', (string) $output );
	}
}

<?php
/**
 * Minimal language switcher: the [wst_switcher] shortcode (plan §12).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Switcher;

use WST\Config;
use WST\Languages\Current;
use WST\Languages\Language;
use WST\Routing\LanguageUrls;

/**
 * Plain links to the same page in each language. No JavaScript. The markup
 * is excluded from translation, and every link carries hreflang so link
 * rewriting leaves it alone.
 */
final class Switcher {

	public const SHORTCODE = Config::PREFIX . 'switcher';

	/**
	 * Create the switcher.
	 *
	 * @param Language     $defaultLanguage Default language.
	 * @param Language     $target  Target language.
	 * @param LanguageUrls $urls    Per-language URLs.
	 */
	public function __construct(
		private Language $defaultLanguage,
		private Language $target,
		private LanguageUrls $urls
	) {
	}

	/**
	 * Register the shortcode.
	 */
	public function boot(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * Switcher markup for the current request.
	 */
	public function render(): string {
		$urls = $this->urls->forCurrentRequest( true );
		if ( null === $urls ) {
			return '';
		}
		$current = Current::isTarget() ? $this->target : $this->defaultLanguage;
		$items   = '';
		foreach ( array( array( $this->defaultLanguage, $urls['default'] ), array( $this->target, $urls['target'] ) ) as [ $language, $url ] ) {
			$isCurrent = $language->locale() === $current->locale();
			$items    .= sprintf(
				'<li class="wst-switcher__item%s"><a href="%s" hreflang="%s" lang="%s"%s>%s</a></li>',
				$isCurrent ? ' is-current' : '',
				esc_url( $url ),
				esc_attr( $language->tag() ),
				esc_attr( $language->tag() ),
				$isCurrent ? ' aria-current="true"' : '',
				esc_html( $language->nativeName() )
			);
		}

		return sprintf(
			'<nav class="wst-switcher" data-wst-no-translate aria-label="%s"><ul class="wst-switcher__list">%s</ul></nav>',
			esc_attr__( 'Language', 'wp-site-translator' ),
			$items
		);
	}
}

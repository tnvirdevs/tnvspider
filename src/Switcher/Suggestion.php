<?php
/**
 * Browser-language suggestion (plan §13A.7).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Switcher;

use WST\Config;
use WST\Editor\EditorRequest;
use WST\Languages\Current;
use WST\Languages\Language;
use WST\Modes\Resolver;
use WST\Routing\LanguageUrls;
use WST\Settings;

/**
 * Loads assets/suggest.js with the page's facts: its language, the URL and
 * name of the same page in the other language and the bar text written in
 * that language. The data is the same for every visitor, so cached pages
 * stay identical; the browser decides from navigator.languages and the
 * wst_lang_choice cookie whether to show the bar (or, in redirect mode,
 * to go to the other page once). The server never reads the cookie. Pages
 * set to "off" have no translated equivalent and get nothing.
 */
final class Suggestion {

	public const HANDLE = 'wst-suggest';

	/**
	 * Create the suggestion.
	 *
	 * @param Settings     $settings   Settings.
	 * @param Language     $defaultLanguage Default language.
	 * @param Language     $target     Target language.
	 * @param LanguageUrls $byLang     Per-language URLs.
	 * @param Resolver     $modes      Page modes.
	 * @param string       $pluginFile Main plugin file.
	 */
	public function __construct(
		private Settings $settings,
		private Language $defaultLanguage,
		private Language $target,
		private LanguageUrls $byLang,
		private Resolver $modes,
		private string $pluginFile
	) {
	}

	/**
	 * Register the hook.
	 */
	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the script with this page's data.
	 */
	public function enqueue(): void {
		$data = $this->data();
		if ( null === $data ) {
			return;
		}
		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/suggest.js', $this->pluginFile ),
			array(),
			Config::VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script( self::HANDLE, 'window.wstSuggest = ' . (string) wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * The page's data, or null when nothing is suggested here.
	 *
	 * @return array<string, mixed>|null
	 */
	public function data(): ?array {
		$mode = $this->settings->choice( 'lang_suggestion' );
		if ( 'off' === $mode || is_admin() || null !== EditorRequest::current() || is_404() || is_feed() ) {
			return null;
		}
		if ( Settings::MODE_OFF === $this->modes->current()['mode'] ) {
			return null;
		}
		$urls = $this->byLang->forCurrentRequest( true );
		if ( null === $urls ) {
			return null;
		}
		$onTarget = Current::isTarget();
		$current  = $onTarget ? $this->target : $this->defaultLanguage;
		$other    = $onTarget ? $this->defaultLanguage : $this->target;
		$drop     = $this->settings->flag( 'hreflang_drop_region' );
		$text     = $this->settings->suggestionText( $onTarget ? 'default' : 'target' );

		return array(
			'mode'       => $mode,
			'position'   => $this->settings->choice( 'suggestion_position' ),
			'current'    => self::primary( $current ),
			'currentTag' => $current->tag( $drop ),
			'close'      => __( 'Close', 'wp-site-translator' ),
			'other'      => array(
				'lang'   => self::primary( $other ),
				'tag'    => $other->tag( $drop ),
				'url'    => $onTarget ? $urls['default'] : $urls['target'],
				'text'   => '' === $text ? $other->nativeName() . ' →' : $text,
				'region' => $other->nativeName(),
			),
		);
	}

	/**
	 * Primary language subtag, lower case ("bn" for bn_BD).
	 *
	 * @param Language $language Language.
	 */
	private static function primary( Language $language ): string {
		return strtolower( explode( '-', $language->tag() )[0] );
	}
}

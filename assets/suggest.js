/* WP Site Translator: browser-language suggestion (plan §13A.7). */
( function ( root ) {
	const COOKIE = 'wst_lang_choice';
	const BOT =
		/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly|headless|lighthouse|preview/i;

	/**
	 * What to do on this page: "none", "bar" or "redirect".
	 *
	 * @param {Object}   s            State.
	 * @param {string[]} s.languages  Browser languages, most preferred first.
	 * @param {string}   s.current    Primary subtag of the page language.
	 * @param {string}   s.other      Primary subtag of the other language.
	 * @param {string}   s.choice     Cookie value ('' when none).
	 * @param {boolean}  s.bot        Crawler or automated browser.
	 * @param {string}   s.mode       "bar" or "redirect".
	 * @param {boolean}  s.noRedirect ?wst_no_redirect=1 present.
	 */
	function decide( s ) {
		if ( s.choice || ! s.other ) {
			return 'none';
		}
		let best = '';
		for ( const tag of s.languages || [] ) {
			const primary = String( tag ).toLowerCase().split( '-' )[ 0 ];
			if ( primary === s.current || primary === s.other ) {
				best = primary;
				break;
			}
		}
		if ( best !== s.other ) {
			return 'none';
		}
		if ( s.mode === 'redirect' ) {
			return s.bot || s.noRedirect ? 'none' : 'redirect';
		}
		return s.bot ? 'none' : 'bar';
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = { decide };
		return;
	}

	const c = root.wstSuggest;
	const doc = root.document;
	if ( ! c ) {
		return;
	}
	const remember = ( lang ) => {
		doc.cookie =
			COOKIE +
			'=' +
			encodeURIComponent( lang ) +
			';path=/;max-age=15552000;SameSite=Lax';
	};
	const choice = ( doc.cookie.match( /(?:^|;\s*)wst_lang_choice=([^;]*)/ ) ||
		[] )[ 1 ];

	// A switcher click always wins.
	doc.addEventListener( 'click', ( event ) => {
		const link =
			event.target instanceof root.Element
				? event.target.closest( 'a[hreflang]' )
				: null;
		const lang = link
			? link.getAttribute( 'hreflang' ).toLowerCase().split( '-' )[ 0 ]
			: '';
		if ( lang === c.current || lang === c.other.lang ) {
			remember( lang );
		}
	} );

	const action = decide( {
		languages: root.navigator.languages || [ root.navigator.language ],
		current: c.current,
		other: c.other.lang,
		choice: choice ? decodeURIComponent( choice ) : '',
		bot:
			BOT.test( root.navigator.userAgent ) || !! root.navigator.webdriver,
		mode: c.mode,
		noRedirect: /[?&]wst_no_redirect=1(&|$)/.test( root.location.search ),
	} );
	if ( action === 'redirect' ) {
		remember( c.other.lang );
		root.location.replace( c.other.url );
		return;
	}
	if ( action !== 'bar' ) {
		return;
	}
	const show = () => {
		const bar = doc.createElement( 'div' );
		bar.className = 'wst-suggest wst-suggest--' + c.position;
		bar.setAttribute( 'role', 'region' );
		bar.setAttribute( 'aria-label', c.other.region );
		bar.setAttribute( 'lang', c.other.tag );
		bar.setAttribute( 'data-wst-no-translate', '' );
		bar.style.cssText =
			'position:fixed;inset-inline:0;' +
			( c.position === 'top'
				? 'inset-block-start:0'
				: 'inset-block-end:0' ) +
			';z-index:99998;display:flex;align-items:center;justify-content:center;gap:12px;padding:10px 16px;background:var(--wst-suggest-bg,#1e1e1e);color:var(--wst-suggest-text,#fff);font:15px/1.4 sans-serif';
		const text = doc.createElement( 'span' );
		const link = doc.createElement( 'a' );
		link.href = c.other.url;
		link.hreflang = c.other.tag;
		link.textContent = c.other.text;
		link.style.cssText =
			'color:var(--wst-suggest-link,#fff);font-weight:600';
		link.addEventListener( 'click', () => remember( c.other.lang ) );
		text.appendChild( link );
		const close = doc.createElement( 'button' );
		close.type = 'button';
		close.textContent = '×';
		close.setAttribute( 'aria-label', c.close );
		close.setAttribute( 'lang', c.currentTag );
		close.style.cssText =
			'all:revert;font:inherit;font-size:20px;line-height:1;background:none;border:0;color:inherit;cursor:pointer;padding:4px 8px';
		close.addEventListener( 'click', () => {
			remember( c.current );
			const hadFocus = bar.contains( doc.activeElement );
			bar.remove();
			if ( hadFocus ) {
				doc.body.setAttribute( 'tabindex', '-1' );
				doc.body.focus();
				doc.body.removeAttribute( 'tabindex' );
			}
		} );
		bar.appendChild( text );
		bar.appendChild( close );
		if ( c.position === 'top' ) {
			doc.body.prepend( bar );
		} else {
			doc.body.appendChild( bar );
		}
	};
	if ( doc.body ) {
		show();
	} else {
		doc.addEventListener( 'DOMContentLoaded', show );
	}
} )( typeof window === 'undefined' ? globalThis : window );

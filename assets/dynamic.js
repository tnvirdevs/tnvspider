/* WP Site Translator: text added by scripts (plan §13A.5b, c). */
( function () {
	const c = window.wstDynamic;
	if ( ! c || ! window.MutationObserver || ! window.fetch ) {
		return;
	}
	const BASE =
		'script,style,noscript,template,textarea,code,pre,svg,iframe,[contenteditable],[translate="no"],.notranslate,[data-wst-no-translate],[data-no-translation],#wpadminbar,#wst-dyn-bar';
	const SKIP = c.exclude.length ? BASE + ',' + c.exclude.join( ',' ) : BASE;
	const ATTRS = [
		'placeholder',
		'title',
		'alt',
		'aria-label',
		'aria-placeholder',
	];
	const done = new WeakSet();
	const cache = new Map();
	const reported = new Set();
	let waiting = new Map();
	let timer = 0;
	let pausedUntil = 0;
	let found = 0;
	let fresh = 0;
	let bar = null;

	const norm = ( t ) => t.replace( /\s+/g, ' ' ).trim();
	const usable = ( t ) => t.length <= c.maxLength && /\p{L}/u.test( t );
	const skipped = ( el ) => {
		try {
			return !! el.closest( SKIP );
		} catch {
			return !! el.closest( BASE );
		}
	};
	const hold = ( text, target ) => {
		const key = norm( text );
		if ( ! key || ! usable( key ) ) {
			return;
		}
		if ( ! waiting.has( key ) ) {
			waiting.set( key, [] );
		}
		waiting.get( key ).push( target );
	};
	const visit = ( node ) => {
		if ( node.nodeType === 3 ) {
			const parent = node.parentElement;
			if ( parent && ! done.has( node ) && ! skipped( parent ) ) {
				hold( node.nodeValue, { node } );
			}
			return;
		}
		if ( node.nodeType !== 1 || skipped( node ) ) {
			return;
		}
		[ node, ...node.querySelectorAll( '*' ) ].forEach( ( el ) => {
			if ( skipped( el ) ) {
				return;
			}
			ATTRS.forEach( ( name ) => {
				const value = el.getAttribute( name );
				if ( value ) {
					hold( value, { el, name } );
				}
			} );
			el.childNodes.forEach( ( child ) => {
				if ( child.nodeType === 3 && ! done.has( child ) ) {
					hold( child.nodeValue, { node: child } );
				}
			} );
		} );
	};
	const apply = ( key, targets ) => {
		const translation = cache.get( key );
		if ( ! translation ) {
			return;
		}
		targets.forEach( ( t ) => {
			if ( t.node ) {
				const value = t.node.nodeValue;
				if ( norm( value ) === key ) {
					t.node.nodeValue =
						value.match( /^\s*/ )[ 0 ] +
						translation +
						value.match( /\s*$/ )[ 0 ];
				}
				done.add( t.node );
			} else if ( norm( t.el.getAttribute( t.name ) || '' ) === key ) {
				t.el.setAttribute( t.name, translation );
			}
		} );
	};
	const post = ( url, body, nonce ) =>
		window.fetch( url, {
			method: 'POST',
			credentials: nonce ? 'same-origin' : 'omit',
			headers: Object.assign(
				{ 'Content-Type': 'application/json' },
				nonce ? { 'X-WP-Nonce': nonce } : {}
			),
			body: JSON.stringify( body ),
		} );
	const show = () => {
		if ( ! bar ) {
			return;
		}
		bar.firstChild.textContent = c.labels.status
			.replace( '%1$d', found )
			.replace( '%2$d', fresh );
	};
	const flush = async () => {
		timer = 0;
		const batch = waiting;
		waiting = new Map();
		const keys = Array.from( batch.keys() );
		const ask = keys.filter( ( k ) => ! cache.has( k ) );
		for ( let i = 0; c.lookup && i < ask.length; i += c.max ) {
			if ( Date.now() < pausedUntil ) {
				break;
			}
			const part = ask.slice( i, i + c.max );
			try {
				const res = await post( c.lookup, { strings: part } );
				if ( res.status === 429 ) {
					pausedUntil = Date.now() + 60000;
					break;
				}
				const data = res.ok ? await res.json() : null;
				part.forEach( ( k ) =>
					cache.set( k, ( data && data.translations[ k ] ) || '' )
				);
			} catch {
				break;
			}
		}
		batch.forEach( ( targets, key ) => apply( key, targets ) );
		const report = keys.filter( ( k ) => ! reported.has( k ) );
		for ( let i = 0; c.scan && i < report.length; i += c.max ) {
			const part = report.slice( i, i + c.max );
			part.forEach( ( k ) => reported.add( k ) );
			try {
				const res = await post(
					c.scan,
					{ token: c.token, strings: part },
					c.nonce
				);
				const data = res.ok ? await res.json() : null;
				if ( data ) {
					found += data.found;
					fresh += data.new;
					show();
				}
			} catch {
				break;
			}
		}
	};
	const observer = new window.MutationObserver( ( records ) => {
		records.forEach( ( r ) => r.addedNodes.forEach( visit ) );
		if ( waiting.size && ! timer ) {
			timer = window.setTimeout( flush, 100 );
		}
	} );
	const start = () => {
		if ( c.scan ) {
			bar = document.createElement( 'div' );
			bar.id = 'wst-dyn-bar';
			bar.setAttribute( 'role', 'status' );
			bar.style.cssText =
				'position:fixed;inset-inline-start:16px;inset-block-end:16px;z-index:100000;display:flex;align-items:center;gap:12px;background:#1e1e1e;color:#fff;padding:10px 14px;border-radius:4px;font:14px/1.4 sans-serif;max-inline-size:min(420px,calc(100vw - 32px))';
			bar.appendChild( document.createElement( 'span' ) );
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.textContent = c.labels.finish;
			button.style.cssText =
				'all:revert;font:inherit;line-height:1.4;padding:4px 10px;margin:0;border:0;border-radius:3px;background:#fff;color:#1e1e1e;cursor:pointer;flex:none';
			button.addEventListener( 'click', () => {
				observer.disconnect();
				bar.firstChild.textContent = c.labels.finished
					.replace( '%1$d', found )
					.replace( '%2$d', fresh );
				button.remove();
			} );
			bar.appendChild( button );
			document.body.appendChild( bar );
			show();
		}
		observer.observe( document.body, { childList: true, subtree: true } );
	};
	if ( document.body ) {
		start();
	} else {
		document.addEventListener( 'DOMContentLoaded', start );
	}
} )();

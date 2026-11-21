/**
 * WP Site Translator: editor preview frame helper (plan §11).
 *
 * Runs only in the editor's sandboxed preview frame. It tells the editor it
 * is ready, marks elements whose text matches a string of the list, outlines
 * them on hover with a pencil, reports clicks on them, highlights the element
 * the editor asks for and shows a saved translation at once. Links and forms
 * do nothing inside the frame.
 */
( function () {
	const parentWindow = window.parent;
	if ( ! parentWindow || parentWindow === window ) {
		return;
	}
	const ATTR = 'data-wst-id';
	const normalize = ( text ) => text.replace( /\s+/g, ' ' ).trim();
	let current = null;
	// Text each marked element shows now, by string id.
	const shown = new Map();

	const style = document.createElement( 'style' );
	style.textContent =
		'[' +
		ATTR +
		']{cursor:pointer}[' +
		ATTR +
		']:hover{outline:2px dashed #3858e9;outline-offset:2px}' +
		'.wst-pencil{position:absolute;z-index:2147483647;width:28px;height:28px;display:none;align-items:center;justify-content:center;background:#fff;border:1px solid #3858e9;border-radius:3px;box-shadow:0 1px 3px rgba(0,0,0,.2);pointer-events:none;font:16px/1 sans-serif;color:#3858e9}';
	const pencil = document.createElement( 'span' );
	pencil.className = 'wst-pencil';
	pencil.setAttribute( 'aria-hidden', 'true' );
	pencil.textContent = '✎';

	function showPencil( el ) {
		if ( ! el ) {
			pencil.style.display = 'none';
			return;
		}
		const box = el.getBoundingClientRect();
		pencil.style.top = window.scrollY + box.top + 'px';
		pencil.style.left =
			Math.max( 0, window.scrollX + box.left - 34 ) + 'px';
		pencil.style.display = 'flex';
	}

	/**
	 * Show a saved translation in its element without reloading the page.
	 *
	 * @param {Object} item String: id, text, and html for inline strings.
	 */
	function update( item ) {
		const id = String( item.id );
		const el = document.querySelector( '[' + ATTR + '="' + id + '"]' );
		const text = normalize( item.text || '' );
		if ( ! el || ! text || shown.get( id ) === text ) {
			return;
		}
		if ( item.html ) {
			// Stored translations are sanitised (wp_kses); this frame has an opaque origin.
			el.innerHTML = item.html;
		} else {
			el.textContent = item.text;
		}
		shown.set( id, text );
	}

	function send( message ) {
		// The sandboxed frame has an opaque origin; the editor checks the source window.
		parentWindow.postMessage( message, '*' );
	}

	function mark( items ) {
		const byText = new Map();
		items.forEach( ( item ) => {
			const text = normalize( item.text || '' );
			if ( text && ! byText.has( text ) ) {
				byText.set( text, String( item.id ) );
			}
		} );
		// Elements already marked keep their id: their text may change when a translation is saved.
		items.forEach( update );
		const kept = new Set();
		document.querySelectorAll( '[' + ATTR + ']' ).forEach( ( el ) => {
			if ( shown.has( el.getAttribute( ATTR ) ) ) {
				kept.add( el.getAttribute( ATTR ) );
			} else {
				el.removeAttribute( ATTR );
			}
		} );
		const elements = Array.from(
			document.body.querySelectorAll( '*' )
		).reverse();
		elements.forEach( ( el ) => {
			const text = normalize( el.textContent || '' );
			const id = byText.get( text );
			if (
				id &&
				! kept.has( id ) &&
				! el.querySelector( '[' + ATTR + '="' + id + '"]' )
			) {
				el.setAttribute( ATTR, id );
				shown.set( id, text );
			}
		} );
	}

	function highlight( el ) {
		if ( current ) {
			current.style.outline = '';
		}
		current = el;
		if ( el ) {
			el.style.outline = '3px solid #d63638';
			el.scrollIntoView( { block: 'center', behavior: 'smooth' } );
		}
	}

	window.addEventListener( 'message', ( event ) => {
		if (
			event.source !== parentWindow ||
			! event.data ||
			'object' !== typeof event.data
		) {
			return;
		}
		if (
			'wst-strings' === event.data.type &&
			Array.isArray( event.data.items )
		) {
			mark( event.data.items );
		} else if ( 'wst-highlight' === event.data.type ) {
			highlight(
				document.querySelector(
					'[' +
						ATTR +
						'="' +
						String( event.data.id ).replace( /[^0-9]/g, '' ) +
						'"]'
				)
			);
		}
	} );

	document.addEventListener(
		'click',
		( event ) => {
			event.preventDefault();
			const el =
				event.target instanceof window.Element
					? event.target.closest( '[' + ATTR + ']' )
					: null;
			if ( el ) {
				highlight( el );
				send( {
					type: 'wst-select',
					id: Number( el.getAttribute( ATTR ) ),
				} );
			}
		},
		true
	);
	document.addEventListener( 'mouseover', ( event ) => {
		showPencil(
			event.target instanceof window.Element
				? event.target.closest( '[' + ATTR + ']' )
				: null
		);
	} );
	document.addEventListener(
		'submit',
		( event ) => event.preventDefault(),
		true
	);

	function ready() {
		document.head.appendChild( style );
		document.body.appendChild( pencil );
		send( { type: 'wst-preview-ready' } );
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
} )();

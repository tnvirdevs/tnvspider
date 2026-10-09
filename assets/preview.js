/**
 * WP Site Translator: editor preview frame helper (plan §11).
 *
 * Runs only in the editor's sandboxed preview frame. It tells the editor it
 * is ready, marks elements whose text matches a string of the list, reports
 * clicks on them and highlights the element the editor asks for. Links and
 * forms do nothing inside the frame.
 */
( function () {
	const parentWindow = window.parent;
	if ( ! parentWindow || parentWindow === window ) {
		return;
	}
	const ATTR = 'data-wst-id';
	const normalize = ( text ) => text.replace( /\s+/g, ' ' ).trim();
	let current = null;

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
		document
			.querySelectorAll( '[' + ATTR + ']' )
			.forEach( ( el ) => el.removeAttribute( ATTR ) );
		const elements = Array.from(
			document.body.querySelectorAll( '*' )
		).reverse();
		elements.forEach( ( el ) => {
			const id = byText.get( normalize( el.textContent || '' ) );
			if ( id && ! el.querySelector( '[' + ATTR + '="' + id + '"]' ) ) {
				el.setAttribute( ATTR, id );
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
	document.addEventListener(
		'submit',
		( event ) => event.preventDefault(),
		true
	);

	function ready() {
		send( { type: 'wst-preview-ready' } );
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
} )();

/**
 * Text helpers that never execute markup.
 */

/**
 * Visible text of an HTML fragment (parsed inertly: no scripts, no loads).
 *
 * @param {string} html Fragment.
 * @return {string} Text.
 */
export function plainText( html ) {
	if ( ! html || html.indexOf( '<' ) === -1 ) {
		return ( html || '' ).replace( /\s+/g, ' ' ).trim();
	}
	const doc = new window.DOMParser().parseFromString( html, 'text/html' );
	return ( doc.body.textContent || '' ).replace( /\s+/g, ' ' ).trim();
}

/**
 * An inline original split into text and tags, for display.
 *
 * @param {string} html Inline HTML.
 * @return {Array<{tag: boolean, value: string}>} Parts.
 */
export function parts( html ) {
	return html
		.split( /(<[^>]*>)/ )
		.filter( ( part ) => part !== '' )
		.map( ( part ) => ( { tag: part.startsWith( '<' ), value: part } ) );
}

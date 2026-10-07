/**
 * REST helpers for wst/v1. apiFetch adds the REST nonce.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __ } from '@wordpress/i18n';

const BASE = '/wst/v1';

export function get( path, query = {} ) {
	return apiFetch( { path: addQueryArgs( BASE + path, query ) } );
}

export function post( path, data = {} ) {
	return apiFetch( { path: BASE + path, method: 'POST', data } );
}

export function del( path ) {
	return apiFetch( { path: BASE + path, method: 'DELETE' } );
}

/**
 * GET returning the raw Response (for pagination headers).
 *
 * @param {string} path  Route.
 * @param {Object} query Query arguments.
 */
export async function getWithTotal( path, query = {} ) {
	const response = await apiFetch( {
		path: addQueryArgs( BASE + path, query ),
		parse: false,
	} );
	return {
		items: await response.json(),
		total: parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 ),
		pages: parseInt( response.headers.get( 'X-WP-TotalPages' ) || '0', 10 ),
	};
}

/**
 * Human-readable message of a failed request.
 *
 * @param {unknown} error Rejection value of apiFetch.
 */
export function errorText( error ) {
	if ( error && typeof error.message === 'string' && error.message ) {
		return error.message;
	}
	return __(
		'The request failed. Check your connection and try again.',
		'wp-site-translator'
	);
}

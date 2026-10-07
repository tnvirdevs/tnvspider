/**
 * Scan a page (plan §11): the browser fetches the translated page with a
 * one-time token; if that is impossible the server does it (loopback).
 */
import { __ } from '@wordpress/i18n';
import { post } from '../admin/api';

class ScanError extends Error {
	constructor( message, final ) {
		super( message );
		this.final = final;
	}
}

/**
 * Fetch the scan URL; retry once with a cache-busting parameter when a
 * cached copy comes back (its scan id is not ours).
 *
 * @param {string} url   Scan URL.
 * @param {string} token Scan token.
 * @return {Promise<Object>} Summary.
 */
async function browserScan( url, token ) {
	for ( const bust of [ false, true ] ) {
		const target = bust ? `${ url }&wst_nocache=${ Date.now() }` : url;
		const response = await window.fetch( target, {
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { Accept: 'application/json' },
		} );
		let body;
		try {
			body = await response.json();
		} catch {
			throw new ScanError(
				__(
					'The page did not answer with a scan result.',
					'wp-site-translator'
				),
				false
			);
		}
		if ( body && ( body.error || ( body.code && body.message ) ) ) {
			throw new ScanError( body.error || body.message, true );
		}
		if ( body && body.scan_id === token ) {
			return { ...body, via: 'browser' };
		}
	}
	throw new ScanError(
		__(
			'A cached copy of the page came back instead of a scan.',
			'wp-site-translator'
		),
		false
	);
}

/**
 * Scan through the browser, falling back to the server.
 *
 * @param {Object}  ref           { post_id } or { path }.
 * @param {boolean} allowPersonal Whether a personal page may be recorded.
 * @return {Promise<Object>} Summary.
 */
export async function scanPage( ref, allowPersonal ) {
	const registered = await post( '/scan/register', {
		...ref,
		allow_personal: allowPersonal,
	} );
	try {
		return await browserScan( registered.url, registered.token );
	} catch ( error ) {
		if ( error instanceof ScanError && error.final ) {
			throw error;
		}
		// Network error, redirect, login wall or cache: let the server try.
		return post( '/scan/loopback', {
			...ref,
			allow_personal: allowPersonal,
		} );
	}
}

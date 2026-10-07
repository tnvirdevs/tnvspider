/**
 * Small display helpers.
 */
import { __, sprintf } from '@wordpress/i18n';
import { dateI18n, getSettings } from '@wordpress/date';

export function number( value ) {
	return new Intl.NumberFormat().format( value );
}

export function dateTime( unixSeconds ) {
	const { formats } = getSettings();
	return dateI18n( formats.datetime, unixSeconds * 1000 );
}

export function duration( seconds ) {
	if ( seconds === null || seconds === undefined ) {
		return __( 'unknown', 'wp-site-translator' );
	}
	if ( seconds < 60 ) {
		return __( 'less than a minute', 'wp-site-translator' );
	}
	const minutes = Math.ceil( seconds / 60 );
	if ( minutes < 120 ) {
		/* translators: %d: minutes */
		return sprintf( __( 'about %d min', 'wp-site-translator' ), minutes );
	}
	return sprintf(
		/* translators: %d: hours */
		__( 'about %d h', 'wp-site-translator' ),
		Math.ceil( minutes / 60 )
	);
}

export function languageName( language, style ) {
	if ( ! language ) {
		return '';
	}
	return style === 'english' ? language.english : language.native;
}

export function languageOptions( languages ) {
	return languages.map( ( language ) => ( {
		value: language.locale,
		label:
			language.english === language.native
				? `${ language.english } (${ language.locale })`
				: `${ language.english } — ${ language.native } (${ language.locale })`,
	} ) );
}

/**
 * Status chip: text plus a modifier class (never colour alone).
 *
 * @param {Object}                    props
 * @param {string}                    props.tone     ok|warning|error|neutral.
 * @param {import("react").ReactNode} props.children Label.
 */
export function Chip( { tone, children } ) {
	return (
		<span className={ `wst-chip wst-chip--${ tone }` }>{ children }</span>
	);
}

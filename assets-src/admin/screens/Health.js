/**
 * Health: requirements, database, provider, background processing,
 * rendering errors and an optional loopback test.
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { errorText, get } from '../api';
import { Section } from '../fields';
import { Chip } from '../format';

const config = window.wstAdmin || { serverCron: '' };

const STATUS = {
	ok: __( 'OK', 'wp-site-translator' ),
	warning: __( 'Warning', 'wp-site-translator' ),
	error: __( 'Problem', 'wp-site-translator' ),
};

export function CheckList( { checks } ) {
	return (
		<ul className="wst-checks">
			{ checks.map( ( check ) => (
				<li
					key={ check.id }
					className={ `wst-check wst-check--${ check.status }` }
				>
					<Chip tone={ check.status }>
						{ STATUS[ check.status ] }
					</Chip>
					<strong>{ check.label }</strong>
					<span>{ check.message }</span>
				</li>
			) ) }
		</ul>
	);
}

export default function Health() {
	const [ checks, setChecks ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ running, setRunning ] = useState( false );

	const load = useCallback( ( loopback = false ) => {
		setError( null );
		setRunning( loopback );
		return get( '/health', loopback ? { loopback: true } : {} )
			.then( ( response ) => setChecks( response.checks ) )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setRunning( false ) );
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	return (
		<Section title={ __( 'Health', 'wp-site-translator' ) }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					<p>{ error }</p>
					<Button variant="secondary" onClick={ () => load() }>
						{ __( 'Try again', 'wp-site-translator' ) }
					</Button>
				</Notice>
			) }
			{ ! checks && ! error && <Spinner /> }
			{ checks && <CheckList checks={ checks } /> }
			<p className="wst-toolbar">
				<Button
					variant="secondary"
					onClick={ () => load( true ) }
					isBusy={ running }
					disabled={ running }
				>
					{ __( 'Run loopback test', 'wp-site-translator' ) }
				</Button>
				<span className="wst-note">
					{ __(
						'Loads your home page from the server, as WP-Cron does.',
						'wp-site-translator'
					) }
				</span>
			</p>
			{ config.serverCron && (
				<div className="wst-field">
					<h3>
						{ __( 'Server cron command', 'wp-site-translator' ) }
					</h3>
					<p className="wst-note">
						{ __(
							'WP-Cron is disabled on this site. Add this line to the server crontab so the queue keeps running:',
							'wp-site-translator'
						) }
					</p>
					<pre className="wst-code">{ config.serverCron }</pre>
				</div>
			) }
		</Section>
	);
}

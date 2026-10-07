/**
 * Shell: header with queue and provider health, screen navigation, the
 * shared settings draft and the unsaved-changes bar.
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorText, get, post } from './api';
import { Chip, number } from './format';
import Overview from './screens/Overview';
import Languages from './screens/Languages';
import Translation from './screens/Translation';
import Switcher from './screens/Switcher';
import Pages from './screens/Pages';
import Advanced from './screens/Advanced';
import Health from './screens/Health';
import ImportExport from './screens/ImportExport';

const SCREENS = [
	[ 'overview', __( 'Overview', 'wp-site-translator' ), Overview ],
	[ 'languages', __( 'Languages', 'wp-site-translator' ), Languages ],
	[ 'translation', __( 'Translation', 'wp-site-translator' ), Translation ],
	[ 'switcher', __( 'Language switcher', 'wp-site-translator' ), Switcher ],
	[ 'pages', __( 'Pages', 'wp-site-translator' ), Pages ],
	[ 'advanced', __( 'Advanced', 'wp-site-translator' ), Advanced ],
	[
		'import-export',
		__( 'Import / Export', 'wp-site-translator' ),
		ImportExport,
	],
	[ 'health', __( 'Health', 'wp-site-translator' ), Health ],
];

const QUEUE_POLL_MS = 20000;

function currentRoute() {
	const route = window.location.hash.replace( /^#\/?/, '' );
	return SCREENS.some( ( [ id ] ) => id === route ) ? route : 'overview';
}

/**
 * A value as it is sent: lists of strings (edited one per line) trimmed,
 * without empty entries.
 *
 * @param {unknown} value Draft value.
 */
const normalize = ( value ) =>
	Array.isArray( value ) &&
	value.every( ( item ) => typeof item === 'string' )
		? value.map( ( item ) => item.trim() ).filter( ( item ) => item !== '' )
		: value;

const same = ( a, b ) =>
	JSON.stringify( normalize( a ) ) === JSON.stringify( normalize( b ) );

function ProviderHealth( { providers, settings } ) {
	if ( ! providers ) {
		return null;
	}
	const primary = providers.find( ( row ) => row.id === settings.provider );
	if ( ! primary ) {
		return (
			<Chip tone="neutral">
				{ __( 'No provider selected', 'wp-site-translator' ) }
			</Chip>
		);
	}
	if ( primary.unavailable ) {
		return (
			<Chip tone="error">
				{ sprintf(
					/* translators: %s: provider name */
					__( '%s paused', 'wp-site-translator' ),
					primary.label
				) }
			</Chip>
		);
	}
	if ( ! primary.configured ) {
		return (
			<Chip tone="error">
				{ sprintf(
					/* translators: %s: provider name */
					__( '%s: no key', 'wp-site-translator' ),
					primary.label
				) }
			</Chip>
		);
	}
	if ( null === primary.verified_at ) {
		return (
			<Chip tone="warning">
				{ sprintf(
					/* translators: %s: provider name */
					__( '%s: not verified yet', 'wp-site-translator' ),
					primary.label
				) }
			</Chip>
		);
	}
	return (
		<Chip tone="ok">
			{ sprintf(
				/* translators: %s: provider name */
				__( '%s ready', 'wp-site-translator' ),
				primary.label
			) }
		</Chip>
	);
}

function QueueHealth( { queue } ) {
	if ( ! queue ) {
		return null;
	}
	const waiting = queue.pending + queue.processing;
	return (
		<>
			<Chip tone={ waiting > 0 ? 'neutral' : 'ok' }>
				{ waiting > 0
					? sprintf(
							/* translators: %s: number of strings */
							_n(
								'%s string queued',
								'%s strings queued',
								waiting,
								'wp-site-translator'
							),
							number( waiting )
						)
					: __( 'Queue empty', 'wp-site-translator' ) }
			</Chip>
			{ queue.failed > 0 && (
				<Chip tone="error">
					{ sprintf(
						/* translators: %s: number of strings */
						_n(
							'%s failed',
							'%s failed',
							queue.failed,
							'wp-site-translator'
						),
						number( queue.failed )
					) }
				</Chip>
			) }
		</>
	);
}

export default function App() {
	const [ route, setRoute ] = useState( currentRoute );
	const [ data, setData ] = useState( null );
	const [ languages, setLanguages ] = useState( [] );
	const [ draft, setDraft ] = useState( null );
	const [ secrets, setSecrets ] = useState( {} );
	const [ errors, setErrors ] = useState( {} );
	const [ loadError, setLoadError ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ providers, setProviders ] = useState( null );
	const [ providersError, setProvidersError ] = useState( null );
	const [ queue, setQueue ] = useState( null );

	const loadSettings = useCallback( () => {
		setLoadError( null );
		return Promise.all( [ get( '/settings' ), get( '/languages' ) ] )
			.then( ( [ settings, list ] ) => {
				setData( settings );
				setDraft( settings.settings );
				setLanguages( list );
			} )
			.catch( ( error ) => setLoadError( errorText( error ) ) );
	}, [] );

	const refreshProviders = useCallback(
		() =>
			get( '/providers' )
				.then( ( rows ) => {
					setProviders( rows );
					setProvidersError( null );
				} )
				.catch( ( error ) => setProvidersError( errorText( error ) ) ),
		[]
	);

	const refreshQueue = useCallback(
		() =>
			get( '/queue' )
				.then( setQueue )
				.catch( () => setQueue( null ) ),
		[]
	);

	useEffect( () => {
		loadSettings();
		refreshProviders();
		refreshQueue();
		const timer = window.setInterval( () => {
			if ( ! document.hidden ) {
				refreshQueue();
			}
		}, QUEUE_POLL_MS );
		const onHash = () => setRoute( currentRoute() );
		window.addEventListener( 'hashchange', onHash );
		return () => {
			window.clearInterval( timer );
			window.removeEventListener( 'hashchange', onHash );
		};
	}, [ loadSettings, refreshProviders, refreshQueue ] );

	const changed = useMemo( () => {
		if ( ! data || ! draft ) {
			return [];
		}
		return Object.keys( draft ).filter(
			( key ) => ! same( draft[ key ], data.settings[ key ] )
		);
	}, [ data, draft ] );
	const dirty = changed.length > 0 || Object.keys( secrets ).length > 0;

	useEffect( () => {
		if ( ! dirty ) {
			return undefined;
		}
		const warn = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	const update = useCallback( ( key, value ) => {
		setDraft( ( current ) => ( { ...current, [ key ]: value } ) );
		setErrors( ( current ) => {
			if ( ! current[ key ] ) {
				return current;
			}
			const next = { ...current };
			delete next[ key ];
			return next;
		} );
	}, [] );

	const setSecret = useCallback( ( name, value ) => {
		setSecrets( ( current ) => {
			const next = { ...current };
			if ( value === undefined ) {
				delete next[ name ];
			} else {
				next[ name ] = value;
			}
			return next;
		} );
	}, [] );

	const save = () => {
		const settings = {};
		changed.forEach(
			( key ) => ( settings[ key ] = normalize( draft[ key ] ) )
		);
		setSaving( true );
		setNotice( null );
		post( '/settings', { settings, secrets } )
			.then( ( response ) => {
				setData( response );
				setDraft( response.settings );
				setSecrets( {} );
				setErrors( {} );
				setNotice( {
					status: 'success',
					text: __( 'Settings saved.', 'wp-site-translator' ),
				} );
				refreshProviders();
				refreshQueue();
			} )
			.catch( ( error ) => {
				if ( error && error.code === 'wst_invalid_settings' ) {
					setErrors( error.data.invalid || {} );
				}
				setNotice( { status: 'error', text: errorText( error ) } );
			} )
			.finally( () => setSaving( false ) );
	};

	const discard = () => {
		setDraft( data.settings );
		setSecrets( {} );
		setErrors( {} );
		setNotice( null );
	};

	if ( loadError ) {
		return (
			<div className="wst-admin">
				<h1>{ __( 'Translator', 'wp-site-translator' ) }</h1>
				<Notice status="error" isDismissible={ false }>
					<p>{ loadError }</p>
					<Button variant="secondary" onClick={ loadSettings }>
						{ __( 'Try again', 'wp-site-translator' ) }
					</Button>
				</Notice>
			</div>
		);
	}
	if ( ! data || ! draft ) {
		return (
			<div className="wst-admin wst-admin--loading">
				<Spinner />
				<span>{ __( 'Loading settings…', 'wp-site-translator' ) }</span>
			</div>
		);
	}

	const Screen = SCREENS.find( ( [ id ] ) => id === route )[ 2 ];
	const fieldErrors = Object.keys( errors );

	return (
		<div className={ `wst-admin${ dirty ? ' wst-admin--dirty' : '' }` }>
			<header className="wst-admin__header">
				<h1>{ __( 'Translator', 'wp-site-translator' ) }</h1>
				<div className="wst-admin__status" aria-live="polite">
					<QueueHealth queue={ queue } />
					<ProviderHealth
						providers={ providers }
						settings={ data.settings }
					/>
				</div>
			</header>
			<nav
				className="wst-admin__nav"
				aria-label={ __( 'Translator screens', 'wp-site-translator' ) }
			>
				<ul>
					{ SCREENS.map( ( [ id, label ] ) => (
						<li key={ id }>
							<a
								href={ `#/${ id }` }
								aria-current={
									id === route ? 'page' : undefined
								}
							>
								{ label }
							</a>
						</li>
					) ) }
				</ul>
			</nav>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					<p>{ notice.text }</p>
					{ fieldErrors.length > 0 && (
						<ul className="wst-error-list">
							{ fieldErrors.map( ( key ) => (
								<li key={ key }>
									<code>{ key }</code>: { errors[ key ] }
								</li>
							) ) }
						</ul>
					) }
				</Notice>
			) }
			<main className="wst-admin__screen">
				<Screen
					data={ data }
					draft={ draft }
					update={ update }
					errors={ errors }
					languages={ languages }
					secrets={ secrets }
					setSecret={ setSecret }
					changed={ changed }
					dirty={ dirty }
					providers={ providers }
					providersError={ providersError }
					refreshProviders={ refreshProviders }
					queue={ queue }
					refreshQueue={ refreshQueue }
				/>
			</main>
			{ dirty && (
				<div
					className="wst-admin__savebar"
					role="region"
					aria-label={ __( 'Unsaved changes', 'wp-site-translator' ) }
				>
					<span>
						{ __(
							'You have unsaved changes.',
							'wp-site-translator'
						) }
					</span>
					<Button
						variant="tertiary"
						onClick={ discard }
						disabled={ saving }
					>
						{ __( 'Discard', 'wp-site-translator' ) }
					</Button>
					<Button
						variant="primary"
						onClick={ save }
						isBusy={ saving }
						disabled={ saving }
					>
						{ __( 'Save changes', 'wp-site-translator' ) }
					</Button>
				</div>
			) }
		</div>
	);
}

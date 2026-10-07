/**
 * One provider: credentials (write-only), connection test, limits, usage.
 * Shows "Not verified yet" until Test connection has passed with the
 * current key and connection settings.
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorText, post } from '../api';
import { Chip, dateTime, number } from '../format';
import { FieldError } from '../fields';

const SECRET_LABELS = {
	WST_TRANSLATEX_KEY: __( 'API key', 'wp-site-translator' ),
	WST_AZURE_KEY: __( 'Subscription key', 'wp-site-translator' ),
	WST_AZURE_REGION: __(
		'Region (for example westeurope; empty for a global resource)',
		'wp-site-translator'
	),
	WST_GEMINI_KEY: __( 'API key', 'wp-site-translator' ),
};

const UNLIMITED = __( '0 = no limit.', 'wp-site-translator' );
const PROVIDER_MAX = __( '0 = the provider maximum.', 'wp-site-translator' );

const LIMITS = [
	[
		'requests_per_minute',
		__( 'Requests per minute', 'wp-site-translator' ),
		UNLIMITED,
	],
	[
		'requests_per_day',
		__( 'Requests per day', 'wp-site-translator' ),
		UNLIMITED,
	],
	[
		'chars_per_minute',
		__( 'Characters per minute', 'wp-site-translator' ),
		UNLIMITED,
	],
	[
		'max_items_per_request',
		__( 'Strings per request', 'wp-site-translator' ),
		PROVIDER_MAX,
	],
	[
		'max_chars_per_request',
		__( 'Characters per request', 'wp-site-translator' ),
		PROVIDER_MAX,
	],
	[
		'concurrency',
		__( 'Parallel requests (1–5)', 'wp-site-translator' ),
		'',
	],
	[
		'max_attempts',
		__(
			'Attempts before a string is marked failed (1–20)',
			'wp-site-translator'
		),
		'',
	],
	[
		'monthly_char_cap',
		__( 'Monthly character budget', 'wp-site-translator' ),
		__(
			'0 = no budget. A warning is logged at 80 percent of the budget; translation stops at 100 percent until next month.',
			'wp-site-translator'
		),
	],
	[
		'timeout',
		__( 'Request timeout in seconds (5–120)', 'wp-site-translator' ),
		'',
	],
];

const PLANS = [
	{ value: 'free', label: __( 'Free', 'wp-site-translator' ) },
	{ value: 'startup', label: __( 'Startup', 'wp-site-translator' ) },
	{ value: 'business', label: __( 'Business', 'wp-site-translator' ) },
	{ value: 'enterprise', label: __( 'Enterprise', 'wp-site-translator' ) },
];

function secretState( status ) {
	switch ( status.source ) {
		case 'constant':
			return __( 'Set in wp-config.php', 'wp-site-translator' );
		case 'env':
			return __( 'Set in the server environment', 'wp-site-translator' );
		case 'option':
			return __( 'Saved (hidden)', 'wp-site-translator' );
	}
	return __( 'Not set', 'wp-site-translator' );
}

function Secret( { name, status, value, setSecret, error } ) {
	const external = status.source === 'constant' || status.source === 'env';
	const removing = value === '';
	const id = `wst-secret-${ name }`;
	return (
		<div className="wst-field wst-secret">
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				id={ id }
				label={ SECRET_LABELS[ name ] || name }
				type={ name.endsWith( '_KEY' ) ? 'password' : 'text' }
				autoComplete="off"
				spellCheck={ false }
				disabled={ external }
				value={ removing ? '' : value || '' }
				placeholder={ status.set && ! removing ? '••••••••' : '' }
				onChange={ ( next ) =>
					setSecret( name, next === '' ? undefined : next )
				}
				help={
					external
						? sprintf(
								/* translators: %s: constant name */
								__(
									'Managed outside WordPress as %s; change it there.',
									'wp-site-translator'
								),
								name
							)
						: __(
								'Write-only: the saved value is never shown. Type a new value to replace it.',
								'wp-site-translator'
							)
				}
			/>
			<p className="wst-secret__state">
				<Chip tone={ status.set && ! removing ? 'ok' : 'neutral' }>
					{ removing
						? __(
								'Will be removed when you save',
								'wp-site-translator'
							)
						: secretState( status ) }
				</Chip>
				{ status.source === 'option' && ! removing && (
					<Button
						variant="link"
						isDestructive
						onClick={ () => setSecret( name, '' ) }
					>
						{ __( 'Remove saved key', 'wp-site-translator' ) }
					</Button>
				) }
				{ removing && (
					<Button
						variant="link"
						onClick={ () => setSecret( name, undefined ) }
					>
						{ __( 'Undo', 'wp-site-translator' ) }
					</Button>
				) }
			</p>
			<FieldError error={ error } />
		</div>
	);
}

function VerifiedState( { row } ) {
	if ( ! row.configured ) {
		return (
			<Chip tone="neutral">
				{ __( 'Not configured', 'wp-site-translator' ) }
			</Chip>
		);
	}
	if ( row.unavailable ) {
		return (
			<Chip tone="error">{ __( 'Paused', 'wp-site-translator' ) }</Chip>
		);
	}
	if ( null === row.verified_at ) {
		return (
			<Chip tone="warning">
				{ __( 'Not verified yet', 'wp-site-translator' ) }
			</Chip>
		);
	}
	return (
		<Chip tone="ok">
			{ sprintf(
				/* translators: %s: date and time */
				__( 'Verified %s', 'wp-site-translator' ),
				dateTime( row.verified_at )
			) }
		</Chip>
	);
}

export function Usage( { row } ) {
	const { chars, cap } = row.usage;
	if ( cap > 0 ) {
		const percent = Math.min( 100, Math.round( ( 100 * chars ) / cap ) );
		return (
			<div className="wst-usage">
				<label htmlFor={ `wst-usage-${ row.id }` }>
					{ sprintf(
						/* translators: 1: used characters, 2: budget, 3: percent */
						__(
							'This month: %1$s of %2$s characters (%3$d %%)',
							'wp-site-translator'
						),
						number( chars ),
						number( cap ),
						percent
					) }
				</label>
				<meter
					id={ `wst-usage-${ row.id }` }
					min={ 0 }
					max={ cap }
					low={ cap * 0.8 }
					high={ cap * 0.95 }
					optimum={ 0 }
					value={ chars }
				/>
			</div>
		);
	}
	return (
		<p className="wst-usage">
			{ sprintf(
				/* translators: %s: characters */
				__(
					'This month: %s characters (no budget set).',
					'wp-site-translator'
				),
				number( chars )
			) }
		</p>
	);
}

function TestDetails( { details } ) {
	const entries = Object.entries( details || {} ).filter(
		( [ , value ] ) => value !== null && value !== ''
	);
	if ( ! entries.length ) {
		return null;
	}
	return (
		<dl className="wst-details">
			{ entries.map( ( [ key, value ] ) => (
				<div key={ key }>
					<dt>{ key }</dt>
					<dd>{ String( value ) }</dd>
				</div>
			) ) }
		</dl>
	);
}

export default function ProviderCard( {
	row,
	settings,
	savedSettings,
	setProviderValue,
	secrets,
	setSecret,
	errors,
	refreshProviders,
} ) {
	const [ testing, setTesting ] = useState( false );
	const [ result, setResult ] = useState( null );
	const own = settings || {};
	const unsaved =
		JSON.stringify( own ) !== JSON.stringify( savedSettings || {} ) ||
		Object.keys( row.secrets ).some( ( name ) => name in secrets );

	const test = () => {
		setTesting( true );
		setResult( null );
		post( `/providers/${ row.id }/test` )
			.then( setResult )
			.catch( ( error ) =>
				setResult( {
					ok: false,
					message: errorText( error ),
					details: {},
				} )
			)
			.finally( () => {
				setTesting( false );
				refreshProviders();
			} );
	};

	let languages;
	if ( row.any_language ) {
		languages = __(
			'Translates any language pair the model knows.',
			'wp-site-translator'
		);
	} else if ( null === row.languages ) {
		languages = __(
			'Language list not loaded yet: it loads on first use or with Test connection.',
			'wp-site-translator'
		);
	} else {
		languages = sprintf(
			/* translators: %s: number of languages */
			__( '%s languages supported.', 'wp-site-translator' ),
			number( row.languages )
		);
	}

	return (
		<Card className={ `wst-provider wst-provider--${ row.id }` }>
			<CardHeader>
				<h3>
					{ row.label }
					{ row.role === 'primary' && (
						<Chip tone="neutral">
							{ __( 'Main provider', 'wp-site-translator' ) }
						</Chip>
					) }
					{ row.role === 'fallback' && (
						<Chip tone="neutral">
							{ __( 'Fallback', 'wp-site-translator' ) }
						</Chip>
					) }
				</h3>
				<VerifiedState row={ row } />
			</CardHeader>
			<CardBody>
				{ row.configured &&
					null === row.verified_at &&
					! row.unavailable && (
						<p className="wst-note">
							{ __(
								'Run Test connection once to confirm this key works. The check repeats whenever the key or connection settings change.',
								'wp-site-translator'
							) }
						</p>
					) }
				{ row.unavailable && (
					<Notice status="error" isDismissible={ false }>
						{ row.unavailable }
					</Notice>
				) }
				{ row.last_error && (
					<p className="wst-note wst-note--error">
						{ __( 'Last error:', 'wp-site-translator' ) }{ ' ' }
						{ row.last_error }
					</p>
				) }
				<fieldset className="wst-fieldset">
					<legend>
						{ __( 'Connection', 'wp-site-translator' ) }
					</legend>
					{ Object.entries( row.secrets ).map(
						( [ name, status ] ) => (
							<Secret
								key={ name }
								name={ name }
								status={ status }
								value={ secrets[ name ] }
								setSecret={ setSecret }
								error={ errors[ `secrets.${ name }` ] }
							/>
						)
					) }
					{ row.id === 'translatex' && (
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __(
								'Plan (sets the default request rate)',
								'wp-site-translator'
							) }
							options={ PLANS }
							value={ own.plan || 'free' }
							onChange={ ( value ) =>
								setProviderValue(
									'plan',
									value === 'free' ? '' : value
								)
							}
						/>
					) }
					{ row.connection.endpoint && (
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Endpoint', 'wp-site-translator' ) }
							type="url"
							placeholder={ row.connection.endpoint }
							value={ own.endpoint || '' }
							onChange={ ( value ) =>
								setProviderValue( 'endpoint', value )
							}
							help={ __(
								'Leave empty for the global endpoint. Use your resource endpoint for a custom domain.',
								'wp-site-translator'
							) }
						/>
					) }
					{ row.connection.model && (
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Model', 'wp-site-translator' ) }
							placeholder={ row.connection.model }
							value={ own.model || '' }
							onChange={ ( value ) =>
								setProviderValue( 'model', value )
							}
							help={ __(
								'Leave empty for the default model.',
								'wp-site-translator'
							) }
						/>
					) }
					<div className="wst-provider__test">
						<Button
							variant="secondary"
							onClick={ test }
							isBusy={ testing }
							disabled={ testing || unsaved || ! row.configured }
						>
							{ __( 'Test connection', 'wp-site-translator' ) }
						</Button>
						{ unsaved && (
							<span className="wst-note">
								{ __(
									'Save your changes first: the test uses the saved key and settings.',
									'wp-site-translator'
								) }
							</span>
						) }
						{ ! unsaved && ! row.configured && (
							<span className="wst-note">
								{ __(
									'Add a key and save to test the connection.',
									'wp-site-translator'
								) }
							</span>
						) }
					</div>
					{ result && (
						<Notice
							status={ result.ok ? 'success' : 'error' }
							onRemove={ () => setResult( null ) }
						>
							<p>{ result.message }</p>
							<TestDetails details={ result.details } />
						</Notice>
					) }
				</fieldset>
				<p className="wst-note">{ languages }</p>
				<Usage row={ row } />
				{ row.queued > 0 && (
					<p className="wst-note">
						{ sprintf(
							/* translators: %s: number of strings */
							_n(
								'%s string waiting for this provider.',
								'%s strings waiting for this provider.',
								row.queued,
								'wp-site-translator'
							),
							number( row.queued )
						) }
					</p>
				) }
				<fieldset className="wst-fieldset">
					<legend>{ __( 'Limits', 'wp-site-translator' ) }</legend>
					<p className="wst-note">
						{ sprintf(
							/* translators: %s: time zone */
							__(
								'Leave a field empty to use the default shown. Daily limits reset at midnight %s.',
								'wp-site-translator'
							),
							row.limits.day_timezone
						) }
					</p>
					<div className="wst-grid">
						{ LIMITS.map( ( [ key, label, help ] ) => (
							<TextControl
								key={ key }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								type="number"
								min={ 0 }
								label={ label }
								placeholder={ String( row.defaults[ key ] ) }
								help={ sprintf(
									/* translators: 1: default value, 2: extra help */
									__(
										'Default: %1$s. %2$s',
										'wp-site-translator'
									),
									number( row.defaults[ key ] ),
									help
								).trim() }
								value={
									undefined === own[ key ]
										? ''
										: String( own[ key ] )
								}
								onChange={ ( value ) =>
									setProviderValue(
										key,
										/^\d+$/.test( value )
											? parseInt( value, 10 )
											: value
									)
								}
							/>
						) ) }
					</div>
				</fieldset>
			</CardBody>
		</Card>
	);
}

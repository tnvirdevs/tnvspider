/**
 * "Translate entire site" (plan §8): list the site's pages, scan them in
 * the browser (record only), estimate the characters against the active
 * provider's remaining monthly budget, and queue the untranslated strings
 * at bulk priority after the owner confirms.
 */
import { Button, CheckboxControl, Notice } from '@wordpress/components';
import { useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorText, get, post } from '../api';
import { Section } from '../fields';
import { number } from '../format';
import { scanPage } from '../../editor/scan';

const CONCURRENT_SCANS = 3;
const paused = !! ( window.wstAdmin || {} ).paused;
const KEYS_PER_REQUEST = 200;
const LISTED_MAX = 50;

const SKIP_LABELS = {
	off: __( 'Set to "off"', 'wp-site-translator' ),
	manual: __(
		'Manual pages (machine translation is turned off for them)',
		'wp-site-translator'
	),
	personal: __(
		'Cart, checkout and account pages (may show personal data)',
		'wp-site-translator'
	),
	password: __( 'Password-protected', 'wp-site-translator' ),
	never_discover: __(
		'Matches a "never discover" path',
		'wp-site-translator'
	),
	other_url: __( 'Not an address of this site', 'wp-site-translator' ),
};

const chunks = ( list, size ) => {
	const out = [];
	for ( let i = 0; i < list.length; i += size ) {
		out.push( list.slice( i, i + size ) );
	}
	return out;
};

function Progress( { id, label, done, total } ) {
	return (
		<div className="wst-progress">
			<p id={ id }>
				{ label }{ ' ' }
				{ sprintf(
					/* translators: 1: pages done, 2: all pages */
					__( '%1$s of %2$s', 'wp-site-translator' ),
					number( done ),
					number( total )
				) }
			</p>
			<progress aria-labelledby={ id } max={ total } value={ done } />
		</div>
	);
}

function Excluded( { items } ) {
	const byReason = {};
	items.forEach( ( item ) => {
		( byReason[ item.skip ] = byReason[ item.skip ] || [] ).push( item );
	} );
	const reasons = Object.keys( byReason );
	if ( reasons.length === 0 ) {
		return null;
	}
	return (
		<details className="wst-disclosure">
			<summary>
				{ sprintf(
					/* translators: %s: number of pages */
					_n(
						'%s page is left out',
						'%s pages are left out',
						items.length,
						'wp-site-translator'
					),
					number( items.length )
				) }
			</summary>
			<ul>
				{ reasons.map( ( reason ) => (
					<li key={ reason }>
						<strong>{ SKIP_LABELS[ reason ] || reason }</strong>
						{ ': ' }
						{ byReason[ reason ]
							.slice( 0, LISTED_MAX )
							.map( ( item ) => item.path )
							.join( ', ' ) }
						{ byReason[ reason ].length > LISTED_MAX && ' …' }
					</li>
				) ) }
			</ul>
		</details>
	);
}

function Budget( { provider, chars } ) {
	if ( provider.problem ) {
		return (
			<Notice status="error" isDismissible={ false }>
				<p>{ provider.problem }</p>
			</Notice>
		);
	}
	if ( provider.remaining === null ) {
		return (
			<p>
				{ sprintf(
					/* translators: 1: provider name, 2: characters used this month */
					__(
						'%1$s has no monthly character limit set here; %2$s characters used this month.',
						'wp-site-translator'
					),
					provider.label,
					number( provider.used )
				) }
			</p>
		);
	}
	return (
		<>
			<p>
				{ sprintf(
					/* translators: 1: provider, 2: used, 3: monthly limit, 4: characters already queued, 5: remaining */
					__(
						'%1$s: %2$s of %3$s characters used this month, %4$s already queued; %5$s left.',
						'wp-site-translator'
					),
					provider.label,
					number( provider.used ),
					number( provider.cap ),
					number( provider.waiting ),
					number( provider.remaining )
				) }
			</p>
			{ chars > provider.remaining && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ sprintf(
							/* translators: 1: characters left, 2: date */
							__(
								'This is more than the %1$s characters left this month. Translation stops at the limit; the rest stays queued and continues after the limit resets on %2$s (or with the fallback provider, if one is set).',
								'wp-site-translator'
							),
							number( provider.remaining ),
							provider.resets_on
						) }
					</p>
				</Notice>
			) }
		</>
	);
}

export default function SiteTranslation( { refreshQueue } ) {
	const [ step, setStep ] = useState( 'start' );
	const [ items, setItems ] = useState( [] );
	const [ progress, setProgress ] = useState( null );
	const [ scanned, setScanned ] = useState( [] );
	const [ failures, setFailures ] = useState( [] );
	const [ estimate, setEstimate ] = useState( null );
	const [ confirmed, setConfirmed ] = useState( false );
	const [ queued, setQueued ] = useState( null );
	const [ error, setError ] = useState( '' );
	const stop = useRef( false );

	const toScan = items.filter( ( item ) => ! item.skip );
	const skipped = items.filter( ( item ) => item.skip );

	const list = async () => {
		setError( '' );
		setStep( 'listing' );
		try {
			const first = await get( '/site/pages', { page: 1 } );
			let all = first.items;
			setProgress( { done: 1, total: first.pages } );
			for ( let page = 2; page <= first.pages; page++ ) {
				const next = await get( '/site/pages', { page } );
				all = all.concat( next.items );
				setProgress( { done: page, total: first.pages } );
			}
			const seen = new Set();
			setItems(
				all.filter( ( item ) => {
					if ( seen.has( item.path ) ) {
						return false;
					}
					seen.add( item.path );
					return true;
				} )
			);
			setStep( 'listed' );
		} catch ( e ) {
			setError( errorText( e ) );
			setStep( 'start' );
		}
		setProgress( null );
	};

	const runEstimate = async ( keys ) => {
		setStep( 'estimating' );
		const strings = new Map();
		let provider = null;
		try {
			for ( const part of chunks( keys, KEYS_PER_REQUEST ) ) {
				const response = await post( '/site/estimate', {
					page_keys: part,
				} );
				response.strings.forEach( ( [ id, chars ] ) =>
					strings.set( id, chars )
				);
				provider = response.provider;
			}
		} catch ( e ) {
			setError( errorText( e ) );
			setStep( 'scanned' );
			return;
		}
		let chars = 0;
		strings.forEach( ( value ) => ( chars += value ) );
		setEstimate( { strings: strings.size, chars, provider } );
		setConfirmed( false );
		setStep( 'confirm' );
	};

	const scan = async () => {
		setError( '' );
		setFailures( [] );
		setStep( 'scanning' );
		stop.current = false;
		const keys = [];
		const failed = [];
		let next = 0;
		let done = 0;
		setProgress( { done, total: toScan.length } );
		const worker = async () => {
			while ( ! stop.current && next < toScan.length ) {
				const item = toScan[ next++ ];
				try {
					const summary = await scanPage(
						item.post_id
							? { post_id: item.post_id }
							: { path: item.path },
						false,
						true
					);
					if ( summary.needs_confirmation ) {
						failed.push( {
							path: item.path,
							message: __(
								'May show personal data; scan it in the editor if needed.',
								'wp-site-translator'
							),
						} );
					} else {
						keys.push( summary.page_key );
					}
				} catch ( e ) {
					failed.push( { path: item.path, message: errorText( e ) } );
				}
				done++;
				setProgress( { done, total: toScan.length } );
			}
		};
		await Promise.all(
			Array.from( { length: CONCURRENT_SCANS }, () => worker() )
		);
		setProgress( null );
		setScanned( keys );
		setFailures( failed );
		if ( keys.length === 0 ) {
			setStep( 'scanned' );
			return;
		}
		await runEstimate( keys );
	};

	const enqueue = async () => {
		setError( '' );
		setStep( 'queueing' );
		const ids = new Set();
		let done = 0;
		const parts = chunks( scanned, KEYS_PER_REQUEST );
		setProgress( { done, total: parts.length } );
		try {
			for ( const part of parts ) {
				const response = await post( '/site/queue', {
					page_keys: part,
				} );
				response.ids.forEach( ( id ) => ids.add( id ) );
				done++;
				setProgress( { done, total: parts.length } );
			}
		} catch ( e ) {
			setError(
				done > 0
					? sprintf(
							/* translators: 1: error message, 2: number of strings queued */
							__(
								'%1$s %2$s strings were queued before the error; queueing again adds only the rest.',
								'wp-site-translator'
							),
							errorText( e ),
							number( ids.size )
						)
					: errorText( e )
			);
			setProgress( null );
			setStep( 'confirm' );
			return;
		}
		setProgress( null );
		setQueued( ids.size );
		setStep( 'done' );
		refreshQueue();
	};

	return (
		<Section
			title={ __( 'Translate entire site', 'wp-site-translator' ) }
			description={ __(
				'Lists the home page, archives, every published post, page and product, and every category or tag page; scans them as a visitor sees them; then shows how many characters would be sent to the provider. Nothing is queued until you confirm. Queued strings are translated after visitor and editor requests (lowest priority).',
				'wp-site-translator'
			) }
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					<p>{ error }</p>
				</Notice>
			) }
			{ paused && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ __(
							'TranslatePress is active, so the translated front end is paused and pages cannot be scanned. Deactivate TranslatePress first (Translator → Migration).',
							'wp-site-translator'
						) }
					</p>
				</Notice>
			) }
			{ step === 'start' && ! paused && (
				<Button variant="primary" onClick={ list }>
					{ __( 'List the site’s pages', 'wp-site-translator' ) }
				</Button>
			) }
			{ step === 'listing' && progress && (
				<Progress
					id="wst-site-listing"
					label={ __( 'Listing pages…', 'wp-site-translator' ) }
					{ ...progress }
				/>
			) }
			{ step === 'listed' && (
				<>
					<p>
						{ sprintf(
							/* translators: %s: number of pages */
							_n(
								'%s page to scan.',
								'%s pages to scan.',
								toScan.length,
								'wp-site-translator'
							),
							number( toScan.length )
						) }
					</p>
					<Excluded items={ skipped } />
					<div className="wst-toolbar">
						<Button
							variant="primary"
							onClick={ scan }
							disabled={ toScan.length === 0 }
						>
							{ __( 'Scan pages', 'wp-site-translator' ) }
						</Button>
						<Button variant="tertiary" href="#/overview">
							{ __( 'Cancel', 'wp-site-translator' ) }
						</Button>
					</div>
				</>
			) }
			{ step === 'scanning' && progress && (
				<>
					<Progress
						id="wst-site-scanning"
						label={ __( 'Scanning pages…', 'wp-site-translator' ) }
						{ ...progress }
					/>
					<Button
						variant="secondary"
						onClick={ () => ( stop.current = true ) }
					>
						{ __(
							'Stop and estimate what was scanned',
							'wp-site-translator'
						) }
					</Button>
				</>
			) }
			{ failures.length > 0 && step !== 'scanning' && (
				<details className="wst-disclosure">
					<summary>
						{ sprintf(
							/* translators: %s: number of pages */
							_n(
								'%s page could not be scanned',
								'%s pages could not be scanned',
								failures.length,
								'wp-site-translator'
							),
							number( failures.length )
						) }
					</summary>
					<ul>
						{ failures.slice( 0, LISTED_MAX ).map( ( failure ) => (
							<li key={ failure.path }>
								<code>{ failure.path }</code>:{ ' ' }
								{ failure.message }
							</li>
						) ) }
					</ul>
				</details>
			) }
			{ step === 'scanned' && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ __(
							'No page could be scanned, so there is nothing to estimate.',
							'wp-site-translator'
						) }
					</p>
				</Notice>
			) }
			{ step === 'estimating' && (
				<p>{ __( 'Estimating…', 'wp-site-translator' ) }</p>
			) }
			{ ( step === 'confirm' || step === 'queueing' ) && estimate && (
				<>
					<dl className="wst-stats">
						<div>
							<dt>
								{ __( 'Pages scanned', 'wp-site-translator' ) }
							</dt>
							<dd>{ number( scanned.length ) }</dd>
						</div>
						<div>
							<dt>
								{ __(
									'Strings to translate',
									'wp-site-translator'
								) }
							</dt>
							<dd>{ number( estimate.strings ) }</dd>
						</div>
						<div>
							<dt>
								{ __(
									'Estimated characters',
									'wp-site-translator'
								) }
							</dt>
							<dd>{ number( estimate.chars ) }</dd>
						</div>
					</dl>
					{ estimate.strings === 0 ? (
						<Notice status="success" isDismissible={ false }>
							<p>
								{ __(
									'Every string on the scanned pages is already translated or queued.',
									'wp-site-translator'
								) }
							</p>
						</Notice>
					) : (
						<>
							<Budget
								provider={ estimate.provider }
								chars={ estimate.chars }
							/>
							{ ! estimate.provider.problem && (
								<>
									<CheckboxControl
										__nextHasNoMarginBottom
										label={ sprintf(
											/* translators: 1: characters, 2: provider */
											__(
												'Send about %1$s characters to %2$s for translation.',
												'wp-site-translator'
											),
											number( estimate.chars ),
											estimate.provider.label
										) }
										checked={ confirmed }
										onChange={ setConfirmed }
										disabled={ step === 'queueing' }
									/>
									<div className="wst-toolbar">
										<Button
											variant="primary"
											onClick={ enqueue }
											disabled={
												! confirmed ||
												step === 'queueing'
											}
										>
											{ sprintf(
												/* translators: %s: number of strings */
												_n(
													'Queue %s string',
													'Queue %s strings',
													estimate.strings,
													'wp-site-translator'
												),
												number( estimate.strings )
											) }
										</Button>
										<Button
											variant="tertiary"
											href="#/overview"
										>
											{ __(
												'Cancel',
												'wp-site-translator'
											) }
										</Button>
									</div>
								</>
							) }
						</>
					) }
					{ step === 'queueing' && progress && (
						<Progress
							id="wst-site-queueing"
							label={ __( 'Queueing…', 'wp-site-translator' ) }
							{ ...progress }
						/>
					) }
				</>
			) }
			{ step === 'done' && (
				<Notice status="success" isDismissible={ false }>
					<p>
						{ sprintf(
							/* translators: %s: number of strings */
							_n(
								'%s string queued. Translation runs in the background; follow it in the queue on the Overview.',
								'%s strings queued. Translation runs in the background; follow it in the queue on the Overview.',
								queued,
								'wp-site-translator'
							),
							number( queued )
						) }
					</p>
					<Button variant="secondary" href="#/overview">
						{ __( 'Back to the Overview', 'wp-site-translator' ) }
					</Button>
				</Notice>
			) }
		</Section>
	);
}

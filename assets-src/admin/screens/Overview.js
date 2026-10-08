/**
 * Overview: setup checklist, coverage, queue panel with live progress,
 * provider summaries and recent log entries.
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorText, get, post } from '../api';
import { Section } from '../fields';
import { Chip, dateTime, duration, number } from '../format';
import { Usage } from './ProviderCard';
import { editorUrl } from './Pages';

function Checklist( { checklist } ) {
	const steps = [
		[
			'language',
			__( 'Choose the target language', 'wp-site-translator' ),
			'#/languages',
		],
		[
			'provider',
			__(
				'Connect a translation provider and pass Test connection',
				'wp-site-translator'
			),
			'#/translation',
		],
		[
			'page',
			__(
				'Translate the first page (visit or scan a translated page)',
				'wp-site-translator'
			),
			'#/pages',
		],
	];
	return (
		<ol className="wst-checklist">
			{ steps.map( ( [ key, label, href ] ) => (
				<li key={ key } className={ checklist[ key ] ? 'is-done' : '' }>
					<Chip tone={ checklist[ key ] ? 'ok' : 'neutral' }>
						{ checklist[ key ]
							? __( 'Done', 'wp-site-translator' )
							: __( 'To do', 'wp-site-translator' ) }
					</Chip>
					{ checklist[ key ] ? (
						label
					) : (
						<a href={ href }>{ label }</a>
					) }
				</li>
			) ) }
		</ol>
	);
}

function Coverage( { coverage } ) {
	if ( ! coverage ) {
		return (
			<p className="wst-empty">
				{ __(
					'Choose a target language to see coverage.',
					'wp-site-translator'
				) }
			</p>
		);
	}
	if ( ! coverage.total ) {
		return (
			<p className="wst-empty">
				{ __(
					'No strings recorded yet. Visit a translated page to start.',
					'wp-site-translator'
				) }
			</p>
		);
	}
	const done = coverage.machine + coverage.manual;
	const percent = Math.floor( ( 100 * done ) / coverage.total );
	return (
		<div className="wst-coverage">
			<label htmlFor="wst-coverage">
				{ sprintf(
					/* translators: 1: percent, 2: translated, 3: total, 4: manual */
					__(
						'%1$d %% translated: %2$s of %3$s strings (%4$s manual).',
						'wp-site-translator'
					),
					percent,
					number( done ),
					number( coverage.total ),
					number( coverage.manual )
				) }
			</label>
			<progress id="wst-coverage" max={ coverage.total } value={ done } />
		</div>
	);
}

/**
 * Resolve after ms, or sooner when stop.current becomes true.
 *
 * @param {number} ms   Milliseconds.
 * @param {Object} stop Ref set to true to stop.
 */
function sleep( ms, stop ) {
	return new Promise( ( resolve ) => {
		const end = Date.now() + ms;
		const tick = () =>
			stop.current || Date.now() >= end
				? resolve()
				: window.setTimeout( tick, 250 );
		tick();
	} );
}

function QueuePanel( { queue, refreshQueue, onProgress } ) {
	const [ running, setRunning ] = useState( false );
	const [ session, setSession ] = useState( null );
	const [ message, setMessage ] = useState( null );
	const [ waiting, setWaiting ] = useState( 0 );
	const stop = useRef( false );

	useEffect( () => () => ( stop.current = true ), [] );

	const run = async () => {
		stop.current = false;
		setRunning( true );
		setMessage( null );
		const totals = { translated: 0, failed: 0, batches: 0 };
		setSession( totals );
		try {
			while ( ! stop.current ) {
				const report = await post( '/queue/run' );
				totals.translated += report.translated;
				totals.failed += report.failed;
				totals.batches += report.batches;
				setSession( { ...totals } );
				const blocked = Object.values( report.blocked || {} );
				if ( blocked.length ) {
					setMessage( {
						status: 'warning',
						text: blocked.join( ' ' ),
					} );
					break;
				}
				if ( report.pending + report.processing === 0 ) {
					setMessage( {
						status: 'success',
						text: __( 'The queue is empty.', 'wp-site-translator' ),
					} );
					break;
				}
				if ( report.batches === 0 && report.wait === 0 ) {
					setMessage( {
						status: 'info',
						text: __(
							'Nothing could be processed right now (rows are waiting for a retry or locked by another run).',
							'wp-site-translator'
						),
					} );
					break;
				}
				if ( report.wait > 0 ) {
					// The provider's rate limit: wait as long as the server asks.
					setWaiting( Math.ceil( report.wait ) );
					await sleep( report.wait * 1000, stop );
					setWaiting( 0 );
				}
			}
		} catch ( e ) {
			setMessage( { status: 'error', text: errorText( e ) } );
		} finally {
			setRunning( false );
			refreshQueue();
			onProgress();
		}
	};

	const retry = () =>
		post( '/queue/retry-failed' )
			.then( ( r ) =>
				setMessage( {
					status: 'success',
					text: sprintf(
						/* translators: %s: number of strings */
						__(
							'%s failed strings queued again.',
							'wp-site-translator'
						),
						number( r.retried )
					),
				} )
			)
			.catch( ( e ) =>
				setMessage( { status: 'error', text: errorText( e ) } )
			)
			.finally( refreshQueue );

	if ( ! queue ) {
		return <Spinner />;
	}
	const queued = queue.pending + queue.processing;
	return (
		<div className="wst-queue">
			<dl className="wst-stats">
				<div>
					<dt>{ __( 'Waiting', 'wp-site-translator' ) }</dt>
					<dd>{ number( queued ) }</dd>
				</div>
				<div>
					<dt>{ __( 'Failed', 'wp-site-translator' ) }</dt>
					<dd>{ number( queue.failed ) }</dd>
				</div>
				<div>
					<dt>
						{ __( 'Characters waiting', 'wp-site-translator' ) }
					</dt>
					<dd>{ number( queue.chars_pending ) }</dd>
				</div>
				<div>
					<dt>{ __( 'Estimated time', 'wp-site-translator' ) }</dt>
					<dd>{ queued ? duration( queue.eta_seconds ) : '—' }</dd>
				</div>
			</dl>
			{ queue.active && queue.active.fallbackReason && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: 1: provider id, 2: reason */
						__(
							'Using the fallback provider %1$s: %2$s',
							'wp-site-translator'
						),
						queue.active.id,
						queue.active.fallbackReason
					) }
				</Notice>
			) }
			{ ! queue.active && queue.problem && (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'No provider can translate now:',
						'wp-site-translator'
					) }{ ' ' }
					{ queue.problem }
				</Notice>
			) }
			<p className="wst-note">
				{ queue.next_run
					? sprintf(
							/* translators: %s: date and time */
							__(
								'Next background run: %s. Processing here works even when WP-Cron does not run.',
								'wp-site-translator'
							),
							dateTime( queue.next_run )
						)
					: __(
							'No background run scheduled.',
							'wp-site-translator'
						) }
			</p>
			<div className="wst-toolbar">
				{ ! running ? (
					<Button
						variant="primary"
						onClick={ run }
						disabled={ queued === 0 }
					>
						{ __( 'Process queue now', 'wp-site-translator' ) }
					</Button>
				) : (
					<Button
						variant="secondary"
						onClick={ () => ( stop.current = true ) }
					>
						{ __( 'Stop', 'wp-site-translator' ) }
					</Button>
				) }
				{ queue.failed > 0 && (
					<Button
						variant="secondary"
						onClick={ retry }
						disabled={ running }
					>
						{ __( 'Retry failed', 'wp-site-translator' ) }
					</Button>
				) }
				{ running && <Spinner /> }
				{ waiting > 0 && (
					<span className="wst-note" aria-live="polite">
						{ sprintf(
							/* translators: %d: seconds */
							__(
								'Waiting %d s for the provider rate limit…',
								'wp-site-translator'
							),
							waiting
						) }
					</span>
				) }
			</div>
			{ session && (
				<p className="wst-note" aria-live="polite">
					{ sprintf(
						/* translators: 1: translated strings, 2: failed strings, 3: batches */
						_n(
							'This run: %1$s translated, %2$s failed, %3$s batch.',
							'This run: %1$s translated, %2$s failed, %3$s batches.',
							session.batches,
							'wp-site-translator'
						),
						number( session.translated ),
						number( session.failed ),
						number( session.batches )
					) }
				</p>
			) }
			{ message && (
				<Notice
					status={ message.status }
					onRemove={ () => setMessage( null ) }
				>
					{ message.text }
				</Notice>
			) }
		</div>
	);
}

function ProviderSummary( { row } ) {
	let state;
	if ( ! row.configured ) {
		state = (
			<Chip tone="neutral">
				{ __( 'Not configured', 'wp-site-translator' ) }
			</Chip>
		);
	} else if ( row.unavailable ) {
		state = (
			<Chip tone="error">{ __( 'Paused', 'wp-site-translator' ) }</Chip>
		);
	} else if ( null === row.verified_at ) {
		state = (
			<Chip tone="warning">
				{ __( 'Not verified yet', 'wp-site-translator' ) }
			</Chip>
		);
	} else {
		state = (
			<Chip tone="ok">{ __( 'Verified', 'wp-site-translator' ) }</Chip>
		);
	}
	return (
		<li className="wst-provider-summary">
			<strong>{ row.label }</strong> { state }
			{ row.role === 'primary' && (
				<span className="wst-muted">
					{ ' ' }
					· { __( 'main', 'wp-site-translator' ) }
				</span>
			) }
			{ row.role === 'fallback' && (
				<span className="wst-muted">
					{ ' ' }
					· { __( 'fallback', 'wp-site-translator' ) }
				</span>
			) }
			<Usage row={ row } />
			{ row.unavailable && (
				<p className="wst-note wst-note--error">{ row.unavailable }</p>
			) }
			{ row.last_error && (
				<p className="wst-note wst-note--error">
					{ __( 'Last error:', 'wp-site-translator' ) }{ ' ' }
					{ row.last_error }
				</p>
			) }
		</li>
	);
}

export default function Overview( {
	providers,
	queue,
	refreshQueue,
	refreshProviders,
	data,
} ) {
	const [ overview, setOverview ] = useState( null );
	const [ error, setError ] = useState( null );

	const load = useCallback(
		() =>
			get( '/overview' )
				.then( ( response ) => {
					setOverview( response );
					setError( null );
				} )
				.catch( ( e ) => setError( errorText( e ) ) ),
		[]
	);

	useEffect( () => {
		load();
	}, [ load ] );

	const used = ( providers || [] ).filter(
		( row ) => row.role || row.configured
	);

	return (
		<>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					<p>{ error }</p>
					<Button variant="secondary" onClick={ load }>
						{ __( 'Try again', 'wp-site-translator' ) }
					</Button>
				</Notice>
			) }
			{ ( window.wstAdmin || {} ).paused && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'TranslatePress is active, so the translated front end of WP Site Translator is paused.',
						'wp-site-translator'
					) }{ ' ' }
					<a href="#/migration">
						{ __( 'Import and go live', 'wp-site-translator' ) }
					</a>
				</Notice>
			) }
			{ overview && overview.render_errors > 0 && (
				<Notice status="error" isDismissible={ false }>
					{ sprintf(
						/* translators: %d: number of failures */
						_n(
							'%d translated page was shown untranslated in the last 24 hours because rendering failed.',
							'%d translated pages were shown untranslated in the last 24 hours because rendering failed.',
							overview.render_errors,
							'wp-site-translator'
						),
						overview.render_errors
					) }{ ' ' }
					<a href="#/advanced">
						{ __( 'See the log', 'wp-site-translator' ) }
					</a>
				</Notice>
			) }
			<div className="wst-columns">
				<Section
					title={ __( 'Getting started', 'wp-site-translator' ) }
				>
					{ overview ? (
						<Checklist checklist={ overview.checklist } />
					) : (
						! error && <Spinner />
					) }
					{ data.languages.target && (
						<div className="wst-toolbar">
							<Button variant="secondary" href={ editorUrl() }>
								{ __(
									'Open the translation editor',
									'wp-site-translator'
								) }
							</Button>
							<Button variant="secondary" href="#/site">
								{ __(
									'Translate entire site',
									'wp-site-translator'
								) }
							</Button>
						</div>
					) }
				</Section>
				<Section title={ __( 'Coverage', 'wp-site-translator' ) }>
					{ overview ? (
						<Coverage coverage={ overview.coverage } />
					) : (
						! error && <Spinner />
					) }
				</Section>
			</div>
			<Section title={ __( 'Translation queue', 'wp-site-translator' ) }>
				<QueuePanel
					queue={ queue }
					refreshQueue={ refreshQueue }
					onProgress={ () => {
						load();
						refreshProviders();
					} }
				/>
			</Section>
			<Section title={ __( 'Providers', 'wp-site-translator' ) }>
				{ ! providers && <Spinner /> }
				{ providers && used.length === 0 && (
					<p className="wst-empty">
						{ __(
							'No provider is set up yet.',
							'wp-site-translator'
						) }{ ' ' }
						<a href="#/translation">
							{ __( 'Set one up', 'wp-site-translator' ) }
						</a>
					</p>
				) }
				{ used.length > 0 && (
					<ul className="wst-provider-summaries">
						{ used.map( ( row ) => (
							<ProviderSummary key={ row.id } row={ row } />
						) ) }
					</ul>
				) }
			</Section>
			<Section title={ __( 'Recent log entries', 'wp-site-translator' ) }>
				{ overview && overview.recent_entries.length === 0 && (
					<p className="wst-empty">
						{ __( 'Nothing logged.', 'wp-site-translator' ) }
					</p>
				) }
				{ overview && overview.recent_entries.length > 0 && (
					<ul className="wst-recent">
						{ overview.recent_entries.map( ( entry ) => (
							<li key={ entry.id }>
								<Chip
									tone={
										entry.level === 'error'
											? 'error'
											: 'warning'
									}
								>
									{ entry.level }
								</Chip>{ ' ' }
								<span className="wst-muted">
									{ entry.created_at } UTC · { entry.source }
								</span>{ ' ' }
								{ entry.message }
							</li>
						) ) }
					</ul>
				) }
				<p>
					<a href="#/advanced">
						{ __( 'Open the full log', 'wp-site-translator' ) }
					</a>
				</p>
			</Section>
		</>
	);
}

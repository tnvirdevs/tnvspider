/**
 * Migration from TranslatePress (plan §13A.4): detection, language and
 * URL-slug prefill, dry run and chunked import (read-only on TranslatePress
 * tables), match report, content references and the go-live checklist.
 * Works while TranslatePress is active (our front end is paused then).
 */
import {
	Button,
	Notice,
	RadioControl,
	Spinner,
	TextareaControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorText, get, post } from '../api';
import { Section, Toggle } from '../fields';
import { Chip, number } from '../format';

const LISTED_MAX = 100;

const POLICIES = [
	{
		value: 'add_only',
		label: __(
			'Keep existing translations (add only)',
			'wp-site-translator'
		),
	},
	{
		value: 'keep_manual',
		label: __(
			'Overwrite machine translations, keep manual ones',
			'wp-site-translator'
		),
	},
	{
		value: 'overwrite_all',
		label: __( 'Overwrite all', 'wp-site-translator' ),
	},
];

const OUTCOMES = [
	[ 'new', __( 'New translations', 'wp-site-translator' ) ],
	[ 'update', __( 'Updates', 'wp-site-translator' ) ],
	[ 'unchanged', __( 'Unchanged', 'wp-site-translator' ) ],
	[ 'conflict', __( 'Kept by the conflict policy', 'wp-site-translator' ) ],
	[ 'invalid', __( 'Invalid', 'wp-site-translator' ) ],
];

function Done( { done, children } ) {
	return (
		<li className={ done ? 'is-done' : '' }>
			<Chip tone={ done ? 'ok' : 'neutral' }>
				{ done
					? __( 'Done', 'wp-site-translator' )
					: __( 'To do', 'wp-site-translator' ) }
			</Chip>
			<div>{ children }</div>
		</li>
	);
}

function Languages( { status, table, reload, reloadSettings } ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const prefill = status.prefill[ table.table ];
	const ours = status.ours;
	const rows = [
		[
			__( 'Default language', 'wp-site-translator' ),
			prefill.settings.default_language,
			ours.default_language,
		],
		[
			__( 'Target language', 'wp-site-translator' ),
			prefill.settings.target_language,
			ours.target_language || '—',
		],
		[
			__( 'Target URL slug', 'wp-site-translator' ),
			prefill.settings.target_slug,
			ours.target_slug || '—',
		],
		[
			__( 'Default-language URL slug', 'wp-site-translator' ),
			prefill.settings.default_slug,
			ours.default_slug,
		],
		[
			__( 'Prefix the default language', 'wp-site-translator' ),
			prefill.settings.prefix_default
				? __( 'Yes', 'wp-site-translator' )
				: __( 'No', 'wp-site-translator' ),
			ours.prefix_default
				? __( 'Yes', 'wp-site-translator' )
				: __( 'No', 'wp-site-translator' ),
		],
	];
	const copy = () => {
		setBusy( true );
		setError( '' );
		post( '/migration/settings', { table: table.table } )
			.then( () => Promise.all( [ reload(), reloadSettings() ] ) )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<>
			<table className="wst-table wst-table--cards">
				<thead>
					<tr>
						<th scope="col">
							{ __( 'Setting', 'wp-site-translator' ) }
						</th>
						<th scope="col">TranslatePress</th>
						<th scope="col">WP Site Translator</th>
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( [ label, theirs, mine ] ) => (
						<tr key={ label }>
							<th scope="row" className="wst-table__primary">
								{ label }
							</th>
							<td data-label="TranslatePress">
								<span className="wst-table__value">
									<code>{ theirs || '—' }</code>
								</span>
							</td>
							<td data-label="WP Site Translator">
								<span className="wst-table__value">
									<code>{ mine }</code>
								</span>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			{ prefill.problem && (
				<Notice status="error" isDismissible={ false }>
					<p>{ prefill.problem }</p>
				</Notice>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					<p>{ error }</p>
				</Notice>
			) }
			<div className="wst-toolbar">
				<Button
					variant="secondary"
					onClick={ copy }
					isBusy={ busy }
					disabled={ busy || !! prefill.problem }
				>
					{ __(
						'Use the TranslatePress languages and URL slugs',
						'wp-site-translator'
					) }
				</Button>
			</div>
		</>
	);
}

function Import( { table, targetReady, reload } ) {
	const [ policy, setPolicy ] = useState( 'add_only' );
	const [ progress, setProgress ] = useState( null );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( '' );

	const run = async ( apply ) => {
		setError( '' );
		setResult( null );
		const counts = {};
		const skipped = { untranslated: 0, deprecated: 0 };
		const rows = [];
		let after = 0;
		let read = 0;
		setProgress( { apply, read } );
		try {
			for (;;) {
				const response = await post( '/migration/import', {
					table: table.table,
					after,
					policy,
					dry_run: ! apply,
				} );
				Object.entries( response.counts ).forEach(
					( [ key, value ] ) => {
						counts[ key ] = ( counts[ key ] || 0 ) + value;
					}
				);
				skipped.untranslated += response.skipped.untranslated;
				skipped.deprecated += response.skipped.deprecated;
				rows.push( ...response.rows );
				read += response.read;
				after = response.next;
				setProgress( { apply, read } );
				if ( response.done ) {
					break;
				}
			}
		} catch ( e ) {
			setError(
				apply && read > 0
					? sprintf(
							/* translators: 1: error message, 2: rows handled */
							__(
								'%1$s The first %2$s TranslatePress rows were handled; importing again is safe.',
								'wp-site-translator'
							),
							errorText( e ),
							number( read )
						)
					: errorText( e )
			);
			setProgress( null );
			return;
		}
		setProgress( null );
		setResult( { apply, counts, skipped, rows, read } );
		if ( apply ) {
			reload();
		}
	};

	return (
		<>
			{ ! targetReady && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ __(
							'Use the TranslatePress languages first: the target language must be the one of this table.',
							'wp-site-translator'
						) }
					</p>
				</Notice>
			) }
			<RadioControl
				label={ __(
					'When a string is already translated here',
					'wp-site-translator'
				) }
				options={ POLICIES }
				selected={ policy }
				onChange={ ( value ) => {
					setPolicy( value );
					setResult( null );
				} }
			/>
			<div className="wst-toolbar">
				<Button
					variant="secondary"
					onClick={ () => run( false ) }
					disabled={ ! targetReady || !! progress }
				>
					{ __( 'Dry run', 'wp-site-translator' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ () => run( true ) }
					disabled={
						! targetReady || !! progress || ! result || result.apply
					}
				>
					{ __( 'Import', 'wp-site-translator' ) }
				</Button>
			</div>
			{ progress && (
				<div className="wst-progress">
					<p id="wst-migration-progress">
						{ progress.apply
							? __( 'Importing…', 'wp-site-translator' )
							: __( 'Checking…', 'wp-site-translator' ) }{ ' ' }
						{ sprintf(
							/* translators: 1: rows read, 2: all rows */
							__( '%1$s of %2$s rows', 'wp-site-translator' ),
							number( progress.read ),
							number( table.counts.total )
						) }
					</p>
					<progress
						aria-labelledby="wst-migration-progress"
						max={ table.counts.total }
						value={ progress.read }
					/>
				</div>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					<p>{ error }</p>
				</Notice>
			) }
			{ result && (
				<div aria-live="polite">
					<h3>
						{ result.apply
							? __( 'Import finished', 'wp-site-translator' )
							: __(
									'Dry run: nothing was changed',
									'wp-site-translator'
								) }
					</h3>
					<dl className="wst-stats">
						<div>
							<dt>
								{ __(
									'TranslatePress rows',
									'wp-site-translator'
								) }
							</dt>
							<dd>{ number( result.read ) }</dd>
						</div>
						{ OUTCOMES.map( ( [ key, label ] ) => (
							<div key={ key }>
								<dt>{ label }</dt>
								<dd>{ number( result.counts[ key ] || 0 ) }</dd>
							</div>
						) ) }
						<div>
							<dt>
								{ __(
									'Skipped: not translated',
									'wp-site-translator'
								) }
							</dt>
							<dd>{ number( result.skipped.untranslated ) }</dd>
						</div>
						<div>
							<dt>
								{ __(
									'Skipped: old translation blocks',
									'wp-site-translator'
								) }
							</dt>
							<dd>{ number( result.skipped.deprecated ) }</dd>
						</div>
					</dl>
					{ result.rows.length > 0 && (
						<details className="wst-disclosure">
							<summary>
								{ sprintf(
									/* translators: %s: number of rows */
									_n(
										'%s row needs attention',
										'%s rows need attention',
										result.rows.length,
										'wp-site-translator'
									),
									number( result.rows.length )
								) }
							</summary>
							<ul>
								{ result.rows
									.slice( 0, LISTED_MAX )
									.map( ( row ) => (
										<li
											key={ `${ row.line }-${ row.outcome }` }
										>
											{ sprintf(
												/* translators: 1: TranslatePress row id, 2: reason */
												__(
													'TranslatePress row %1$d: %2$s',
													'wp-site-translator'
												),
												row.line,
												row.reason
											) }
										</li>
									) ) }
							</ul>
						</details>
					) }
				</div>
			) }
		</>
	);
}

function MatchReport( { status } ) {
	const defaults = [ '/' ]
		.concat(
			status.checklist.urls.map( ( pair ) => {
				const path = new URL( pair.ours ).pathname;
				return path.replace(
					new RegExp( `^/${ status.ours.target_slug }(?=/)` ),
					''
				);
			} )
		)
		.filter( ( path, index, list ) => list.indexOf( path ) === index );
	const [ paths, setPaths ] = useState( defaults.join( '\n' ) );
	const [ busy, setBusy ] = useState( false );
	const [ report, setReport ] = useState( null );
	const [ error, setError ] = useState( '' );
	const run = () => {
		setBusy( true );
		setError( '' );
		post( '/migration/match', {
			paths: paths
				.split( '\n' )
				.map( ( line ) => line.trim() )
				.filter( Boolean )
				.slice( 0, 20 ),
		} )
			.then( setReport )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __(
					'Pages to check (one path per line, up to 20)',
					'wp-site-translator'
				) }
				value={ paths }
				onChange={ setPaths }
				rows={ 5 }
			/>
			<div className="wst-toolbar">
				<Button
					variant="secondary"
					onClick={ run }
					isBusy={ busy }
					disabled={ busy }
				>
					{ __( 'Check pages', 'wp-site-translator' ) }
				</Button>
			</div>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					<p>{ error }</p>
				</Notice>
			) }
			{ report && (
				<div aria-live="polite">
					<p>
						<strong>
							{ report.percent === null
								? __(
										'No strings found on these pages.',
										'wp-site-translator'
									)
								: sprintf(
										/* translators: 1: percentage, 2: matched strings, 3: all strings */
										__(
											'%1$s%% of the strings already have a translation (%2$s of %3$s).',
											'wp-site-translator'
										),
										report.percent,
										number( report.matched ),
										number( report.strings )
									) }
						</strong>
					</p>
					<ul>
						{ report.pages.map( ( page ) => (
							<li key={ page.path }>
								<code>{ page.path }</code>:{ ' ' }
								{ page.error ||
									sprintf(
										/* translators: 1: matched, 2: strings */
										__(
											'%1$s of %2$s strings translated',
											'wp-site-translator'
										),
										number( page.matched ),
										number( page.strings )
									) }
							</li>
						) ) }
					</ul>
					{ report.unmatched.length > 0 && (
						<details className="wst-disclosure">
							<summary>
								{ __(
									'Most frequent strings without a translation',
									'wp-site-translator'
								) }
							</summary>
							<ul>
								{ report.unmatched.map( ( item ) => (
									<li key={ item.text }>
										<code>{ item.text }</code>{ ' ' }
										<span className="wst-muted">
											{ sprintf(
												/* translators: %d: number of pages */
												_n(
													'(%d page)',
													'(%d pages)',
													item.pages,
													'wp-site-translator'
												),
												item.pages
											) }
										</span>
									</li>
								) ) }
							</ul>
						</details>
					) }
					<p className="wst-note">
						{ __(
							'TranslatePress and WP Site Translator split pages into strings differently, so some longer blocks do not match. Scan those pages after going live and translate what is missing.',
							'wp-site-translator'
						) }
					</p>
				</div>
			) }
		</>
	);
}

function References( { references, draft, update, errors } ) {
	const empty =
		references.posts.length === 0 &&
		references.widgets.length === 0 &&
		references.menus.length === 0;
	return (
		<>
			{ empty ? (
				<p>
					{ __(
						'No TranslatePress shortcodes or menu switchers were found in posts, widgets or menus.',
						'wp-site-translator'
					) }
				</p>
			) : (
				<ul>
					{ references.posts.map( ( item ) => (
						<li key={ `post-${ item.id }` }>
							<a href={ item.edit }>{ item.title }</a>{ ' ' }
							<span className="wst-muted">({ item.type })</span>:{ ' ' }
							{ item.found
								.map( ( code ) => `[${ code }]` )
								.join( ', ' ) }
						</li>
					) ) }
					{ references.widgets.map( ( item ) => (
						<li key={ item.option }>
							<a href="widgets.php">
								{ __( 'Widgets', 'wp-site-translator' ) }
							</a>{ ' ' }
							<span className="wst-muted">({ item.option })</span>
							:{ ' ' }
							{ item.found
								.map( ( code ) => `[${ code }]` )
								.join( ', ' ) }
						</li>
					) ) }
					{ references.menus.map( ( item ) => (
						<li key={ `menu-${ item.id }` }>
							<a href={ item.edit }>{ item.name }</a>:{ ' ' }
							{ sprintf(
								/* translators: %d: number of menu items */
								_n(
									'%d TranslatePress language switcher menu item: replace it with the "Language switcher" item of WP Site Translator.',
									'%d TranslatePress language switcher menu items: replace them with the "Language switcher" item of WP Site Translator.',
									item.items,
									'wp-site-translator'
								),
								item.items
							) }
						</li>
					) ) }
				</ul>
			) }
			<Toggle
				name="trp_switcher_alias"
				label={ __(
					'Show our language switcher where content uses [language-switcher]',
					'wp-site-translator'
				) }
				help={ __(
					'Works once TranslatePress is deactivated. [trp_language], [language-include] and [language-exclude] are not replaced; edit the places listed above.',
					'wp-site-translator'
				) }
				draft={ draft }
				update={ update }
				errors={ errors }
			/>
		</>
	);
}

function Checklist( { status, reload } ) {
	const [ flushing, setFlushing ] = useState( false );
	const [ error, setError ] = useState( '' );
	const list = status.checklist;
	const flush = () => {
		setFlushing( true );
		setError( '' );
		post( '/migration/flush' )
			.then( reload )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setFlushing( false ) );
	};
	return (
		<>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					<p>{ error }</p>
				</Notice>
			) }
			<ol className="wst-checklist wst-golive">
				<Done done={ list.translatepress_inactive }>
					{ __( 'Deactivate TranslatePress.', 'wp-site-translator' ) }{ ' ' }
					<a href={ status.plugins_url }>
						{ __( 'Plugins', 'wp-site-translator' ) }
					</a>
					<p className="wst-note">
						{ __(
							'Its data stays in the database untouched; you can switch back at any time.',
							'wp-site-translator'
						) }
					</p>
				</Done>
				<Done done={ list.flushed && list.translatepress_inactive }>
					{ __(
						'Flush permalinks, so the old language rules are gone.',
						'wp-site-translator'
					) }{ ' ' }
					<Button
						variant="link"
						onClick={ flush }
						isBusy={ flushing }
						disabled={ flushing || ! list.translatepress_inactive }
					>
						{ __( 'Flush now', 'wp-site-translator' ) }
					</Button>
				</Done>
				<Done done={ list.slugs_match === true }>
					{ __(
						'Confirm the URL slugs are the same as in TranslatePress.',
						'wp-site-translator'
					) }{ ' ' }
					{ list.slugs_match === false && (
						<a href="#/languages">
							{ __( 'Languages', 'wp-site-translator' ) }
						</a>
					) }
				</Done>
				<Done
					done={
						list.translatepress_inactive && list.scanned_pages > 0
					}
				>
					{ __(
						'Scan the key pages (or the whole site).',
						'wp-site-translator'
					) }{ ' ' }
					{ list.translatepress_inactive ? (
						<a href="#/site">
							{ __(
								'Translate entire site',
								'wp-site-translator'
							) }
						</a>
					) : (
						<span className="wst-muted">
							{ __(
								'Possible after deactivating TranslatePress.',
								'wp-site-translator'
							) }
						</span>
					) }
				</Done>
				<Done
					done={
						list.urls.length > 0 &&
						list.urls.every( ( pair ) => pair.same )
					}
				>
					{ __(
						'Compare a few translated URLs: they should stay the same.',
						'wp-site-translator'
					) }
					{ list.urls.length > 0 && (
						<ul>
							{ list.urls.map( ( pair ) => (
								<li key={ pair.ours }>
									<a href={ pair.ours }>{ pair.ours }</a>{ ' ' }
									{ pair.same ? (
										<Chip tone="ok">
											{ __(
												'Same',
												'wp-site-translator'
											) }
										</Chip>
									) : (
										<>
											<Chip tone="warning">
												{ __(
													'Different',
													'wp-site-translator'
												) }
											</Chip>{ ' ' }
											{ sprintf(
												/* translators: %s: URL under TranslatePress */
												__(
													'was %s',
													'wp-site-translator'
												),
												pair.translatepress
											) }
										</>
									) }
								</li>
							) ) }
						</ul>
					) }
				</Done>
			</ol>
		</>
	);
}

export default function Migration( { draft, update, errors, reloadSettings } ) {
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ chosen, setChosen ] = useState( '' );

	const reload = useCallback(
		() =>
			get( '/migration/status' )
				.then( ( response ) => {
					setStatus( response );
					setError( '' );
				} )
				.catch( ( e ) => setError( errorText( e ) ) ),
		[]
	);

	useEffect( () => {
		reload();
	}, [ reload ] );

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				<p>{ error }</p>
				<Button variant="secondary" onClick={ reload }>
					{ __( 'Try again', 'wp-site-translator' ) }
				</Button>
			</Notice>
		);
	}
	if ( ! status ) {
		return <Spinner />;
	}
	const table =
		status.tables.find( ( item ) => item.table === chosen ) ||
		status.tables[ 0 ];
	const targetReady =
		!! table && status.ours.target_language === table.target;

	return (
		<>
			<Section title={ __( 'TranslatePress', 'wp-site-translator' ) }>
				<p>
					{ status.active && (
						<Chip tone="warning">
							{ __(
								'Active: our front end is paused',
								'wp-site-translator'
							) }
						</Chip>
					) }
					{ ! status.active && status.installed && (
						<Chip tone="neutral">
							{ __(
								'Installed, not active',
								'wp-site-translator'
							) }
						</Chip>
					) }
					{ ! status.active && ! status.installed && (
						<Chip tone="neutral">
							{ __(
								'Plugin removed, data still in the database',
								'wp-site-translator'
							) }
						</Chip>
					) }
				</p>
				<p className="wst-note">
					{ __(
						'Everything here only reads TranslatePress data; nothing of TranslatePress is changed or deleted.',
						'wp-site-translator'
					) }
				</p>
				{ status.tables.length === 0 ? (
					<p>
						{ __(
							'No TranslatePress translation tables were found for the configured languages.',
							'wp-site-translator'
						) }
					</p>
				) : (
					<>
						{ status.tables.length > 1 && (
							<RadioControl
								label={ __(
									'Language to import (WP Site Translator translates into one language)',
									'wp-site-translator'
								) }
								options={ status.tables.map( ( item ) => ( {
									value: item.table,
									label: `${ item.default } → ${ item.target }`,
								} ) ) }
								selected={ table.table }
								onChange={ setChosen }
							/>
						) }
						<dl className="wst-stats">
							<div>
								<dt>
									{ __(
										'Language pair',
										'wp-site-translator'
									) }
								</dt>
								<dd>
									{ table.default } → { table.target }
								</dd>
							</div>
							<div>
								<dt>
									{ __( 'Machine', 'wp-site-translator' ) }
								</dt>
								<dd>{ number( table.counts.machine ) }</dd>
							</div>
							<div>
								<dt>
									{ __( 'Manual', 'wp-site-translator' ) }
								</dt>
								<dd>{ number( table.counts.manual ) }</dd>
							</div>
							<div>
								<dt>
									{ __(
										'Not translated',
										'wp-site-translator'
									) }
								</dt>
								<dd>{ number( table.counts.untranslated ) }</dd>
							</div>
							<div>
								<dt>
									{ __(
										'Old translation blocks',
										'wp-site-translator'
									) }
								</dt>
								<dd>{ number( table.counts.deprecated ) }</dd>
							</div>
						</dl>
					</>
				) }
			</Section>
			{ table && (
				<>
					<Section
						title={ __(
							'1. Languages and URL slugs',
							'wp-site-translator'
						) }
						description={ __(
							'Use the same languages and URL slugs, so translated pages keep their addresses and search rankings.',
							'wp-site-translator'
						) }
					>
						<Languages
							status={ status }
							table={ table }
							reload={ reload }
							reloadSettings={ reloadSettings }
						/>
					</Section>
					<Section
						title={ __(
							'2. Import translations',
							'wp-site-translator'
						) }
						description={ __(
							'Machine translations stay machine translations; manual (reviewed) ones are protected from machine translation. Rows without a translation and old translation blocks are skipped. Importing again is safe.',
							'wp-site-translator'
						) }
					>
						<Import
							table={ table }
							targetReady={ targetReady }
							reload={ reload }
						/>
					</Section>
					<Section
						title={ __( '3. Match report', 'wp-site-translator' ) }
						description={ __(
							'Loads pages in the default language and checks how many of their strings already have a translation. Nothing is recorded or sent to a provider.',
							'wp-site-translator'
						) }
					>
						<MatchReport status={ status } />
					</Section>
				</>
			) }
			<Section
				title={ __(
					'Switchers and shortcodes in your content',
					'wp-site-translator'
				) }
			>
				<References
					references={ status.references }
					draft={ draft }
					update={ update }
					errors={ errors }
				/>
			</Section>
			<Section title={ __( 'Go live', 'wp-site-translator' ) }>
				<Checklist status={ status } reload={ reload } />
			</Section>
		</>
	);
}

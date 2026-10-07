/**
 * The translation editor: page header with scan and queue state, filters,
 * the string list with autosave, bulk machine translation, and the
 * optional preview.
 */
import {
	Button,
	CheckboxControl,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { del, errorText, get, getWithTotal, post } from '../admin/api';
import { Chip, dateTime, number } from '../admin/format';
import PagePicker from './PagePicker';
import Preview from './Preview';
import Row from './Row';
import { scanPage } from './scan';

const config = window.wstEditor || {
	postId: null,
	path: null,
	language: { tag: '', dir: 'ltr', native: '' },
	providerReady: false,
	mtOnManual: true,
	settingsUrl: '',
};

const PER_PAGE = 50;
const POLL_MS = 8000;
const MAX_IDS = 500;

const FILTERS = [
	[ 'all', __( 'All', 'wp-site-translator' ) ],
	[ 'untranslated', __( 'Untranslated', 'wp-site-translator' ) ],
	[ 'machine', __( 'Machine', 'wp-site-translator' ) ],
	[ 'manual', __( 'Manual', 'wp-site-translator' ) ],
	[ 'warning', __( 'Has warning', 'wp-site-translator' ) ],
];

const MODE_LABELS = {
	auto: __( 'Automatic translation', 'wp-site-translator' ),
	manual: __( 'Manual only', 'wp-site-translator' ),
	off: __( 'Off (not translated)', 'wp-site-translator' ),
};

/**
 * Seconds since the epoch of a UTC MySQL datetime.
 *
 * @param {string} value e.g. 2026-10-07 08:00:00.
 * @return {number} Unix time.
 */
const unix = ( value ) =>
	Math.floor( Date.parse( value.replace( ' ', 'T' ) + 'Z' ) / 1000 );

export default function Editor() {
	const pageRef = useMemo( () => {
		if ( config.postId ) {
			return { post_id: config.postId };
		}
		return config.path ? { path: config.path } : null;
	}, [] );
	const [ page, setPage ] = useState( null );
	const [ pageError, setPageError ] = useState( null );
	const [ query, setQuery ] = useState( {
		filter: 'all',
		search: '',
		includeGlobal: true,
		page: 1,
	} );
	const [ search, setSearch ] = useState( '' );
	const [ list, setList ] = useState( null );
	const [ listError, setListError ] = useState( null );
	const [ drafts, setDrafts ] = useState( {} );
	const [ rowState, setRowState ] = useState( {} );
	const [ scan, setScan ] = useState( {
		running: false,
		notice: null,
		confirm: false,
	} );
	const [ bulk, setBulk ] = useState( null );
	const [ selectedId, setSelectedId ] = useState( null );
	const [ showPreview, setShowPreview ] = useState(
		() => window.innerWidth >= 1200
	);
	const [ previewKey, setPreviewKey ] = useState( 0 );
	const fields = useRef( {} );

	const canMt =
		config.providerReady &&
		( ! page || page.mode !== 'manual' || config.mtOnManual );

	const loadPage = useCallback( () => {
		setPageError( null );
		return get( '/editor/page', pageRef )
			.then( ( data ) => {
				setPage( data );
				return data;
			} )
			.catch( ( e ) => {
				setPageError( errorText( e ) );
				return null;
			} );
	}, [ pageRef ] );

	const loadList = useCallback( () => {
		if ( ! page ) {
			return Promise.resolve();
		}
		setListError( null );
		return getWithTotal( '/strings', {
			page_key: page.page_key,
			filter: query.filter,
			search: query.search,
			include_global: query.includeGlobal,
			page: query.page,
			per_page: PER_PAGE,
		} )
			.then( ( result ) =>
				setList( {
					...result.items,
					total: result.total,
					pages: result.pages,
				} )
			)
			.catch( ( e ) => setListError( errorText( e ) ) );
	}, [ page, query ] );

	const runScan = useCallback(
		( allowPersonal = false ) => {
			setScan( { running: true, notice: null, confirm: false } );
			return scanPage( pageRef, allowPersonal )
				.then( ( summary ) => {
					if ( summary.needs_confirmation ) {
						setScan( {
							running: false,
							notice: null,
							confirm: true,
						} );
						return;
					}
					let text;
					if ( summary.mode === 'off' ) {
						text = __(
							'This page is set to “off”: it is not translated, so nothing was recorded.',
							'wp-site-translator'
						);
					} else {
						text = sprintf(
							/* translators: 1: strings found, 2: new strings, 3: queued strings */
							__(
								'Scan finished: %1$s strings on the page, %2$s new, %3$s queued for machine translation.',
								'wp-site-translator'
							),
							number( summary.strings ),
							number( summary.new || 0 ),
							number( summary.queued || 0 )
						);
					}
					setScan( {
						running: false,
						notice: { status: 'success', text },
						confirm: false,
					} );
					loadPage();
					setPreviewKey( ( key ) => key + 1 );
				} )
				.catch( ( e ) =>
					setScan( {
						running: false,
						notice: {
							status: 'error',
							text: sprintf(
								/* translators: %s: error */
								__(
									'The scan failed: %s',
									'wp-site-translator'
								),
								errorText( e )
							),
						},
						confirm: false,
					} )
				);
		},
		[ pageRef, loadPage ]
	);

	useEffect( () => {
		if ( ! pageRef ) {
			return;
		}
		loadPage().then( ( data ) => {
			if ( data && ! data.last_scan && data.mode !== 'off' ) {
				runScan();
			}
		} );
	}, [ pageRef, loadPage, runScan ] );

	useEffect( () => {
		loadList();
	}, [ loadList ] );

	// Live queue indicator: refresh while strings wait for machine translation.
	const queued = list ? list.counts.queued : 0;
	useEffect( () => {
		if ( ! queued ) {
			return undefined;
		}
		const timer = window.setInterval(
			() => ! document.hidden && loadList(),
			POLL_MS
		);
		return () => window.clearInterval( timer );
	}, [ queued, loadList ] );

	useEffect( () => {
		const timer = window.setTimeout(
			() =>
				setQuery( ( q ) =>
					q.search === search ? q : { ...q, search, page: 1 }
				),
			300
		);
		return () => window.clearTimeout( timer );
	}, [ search ] );

	const replaceItem = ( item ) =>
		setList( ( current ) =>
			current
				? {
						...current,
						items: current.items.map( ( row ) =>
							row.id === item.id ? item : row
						),
					}
				: current
		);

	const setRow = ( id, value ) =>
		setRowState( ( current ) => ( { ...current, [ id ]: value } ) );

	const clearDraft = ( id ) =>
		setDrafts( ( current ) => {
			const next = { ...current };
			delete next[ id ];
			return next;
		} );

	const save = ( item, value, force = false ) => {
		const state = rowState[ item.id ];
		if (
			( state && state.busy ) ||
			( ! force && value === ( item.translation || '' ) )
		) {
			return;
		}
		if ( value.trim() === '' ) {
			setRow( item.id, {
				error: __(
					'Empty translations are not saved. Use “Remove translation” to show the original.',
					'wp-site-translator'
				),
			} );
			return;
		}
		// Optimistic: the typed text stays; the row shows Saving… until the server answers.
		setRow( item.id, { busy: true } );
		post( `/strings/${ item.id }/translation`, { translation: value } )
			.then( ( saved ) => {
				replaceItem( saved );
				clearDraft( item.id );
				setRow( item.id, { saved: true } );
			} )
			.catch( ( e ) => setRow( item.id, { error: errorText( e ) } ) );
	};

	const action = ( item, name ) => {
		setRow( item.id, { busy: true } );
		const translateNow = () =>
			post( '/strings/translate', {
				ids: [ item.id ],
				mode: 'now',
				...( page ? { path: page.path } : {} ),
			} );
		let request;
		if ( name === 'manual' ) {
			request = post( `/strings/${ item.id }/manual` );
		} else if ( name === 'remove' ) {
			request = del( `/strings/${ item.id }/translation` );
		} else if ( name === 'revert' ) {
			request = del( `/strings/${ item.id }/translation` ).then(
				translateNow
			);
		} else {
			request = translateNow();
		}
		request
			.then( ( result ) => {
				if ( result && result.id === item.id ) {
					replaceItem( result );
				}
				clearDraft( item.id );
				setRow( item.id, {} );
				return loadList();
			} )
			.catch( ( e ) => setRow( item.id, { error: errorText( e ) } ) );
	};

	const translateAll = async () => {
		setBulk( { running: true } );
		try {
			const ids = [];
			for ( let pageNo = 1; ; pageNo++ ) {
				const chunk = await getWithTotal( '/strings', {
					page_key: page.page_key,
					filter: 'untranslated',
					include_global: query.includeGlobal,
					page: pageNo,
					per_page: 200,
				} );
				chunk.items.items.forEach( ( item ) => ids.push( item.id ) );
				if ( pageNo >= chunk.pages ) {
					break;
				}
			}
			let total = 0;
			for ( let i = 0; i < ids.length; i += MAX_IDS ) {
				const result = await post( '/strings/translate', {
					ids: ids.slice( i, i + MAX_IDS ),
					path: page.path,
				} );
				total += result.queued;
			}
			setBulk( {
				running: false,
				status: 'success',
				text: sprintf(
					/* translators: %s: number of strings */
					_n(
						'%s string queued for machine translation.',
						'%s strings queued for machine translation.',
						total,
						'wp-site-translator'
					),
					number( total )
				),
			} );
			loadList();
		} catch ( e ) {
			setBulk( {
				running: false,
				status: 'error',
				text: errorText( e ),
			} );
		}
	};

	const items = list ? list.items : [];
	const move = ( item, step ) => {
		const index = items.findIndex( ( row ) => row.id === item.id );
		const next = items[ index + step ];
		if ( next && fields.current[ next.id ] ) {
			fields.current[ next.id ].focus();
		}
	};
	const selectFromPreview = useCallback( ( id ) => {
		setSelectedId( id );
		const field = fields.current[ id ];
		if ( field ) {
			field.scrollIntoView( { block: 'center' } );
			field.focus();
		}
	}, [] );

	if ( ! pageRef ) {
		return (
			<div className="wst-editor">
				<h1>{ __( 'Translation editor', 'wp-site-translator' ) }</h1>
				<PagePicker />
			</div>
		);
	}
	if ( pageError ) {
		return (
			<div className="wst-editor">
				<h1>{ __( 'Translation editor', 'wp-site-translator' ) }</h1>
				<Notice status="error" isDismissible={ false }>
					<p>{ pageError }</p>
					<Button variant="secondary" onClick={ loadPage }>
						{ __( 'Try again', 'wp-site-translator' ) }
					</Button>
				</Notice>
				<PagePicker />
			</div>
		);
	}
	if ( ! page ) {
		return (
			<div className="wst-editor wst-editor--loading">
				<Spinner />
				<span>{ __( 'Loading the page…', 'wp-site-translator' ) }</span>
			</div>
		);
	}

	const counts = list ? list.counts : null;

	return (
		<div className={ `wst-editor${ showPreview ? ' has-preview' : '' }` }>
			<header className="wst-editor__header">
				<div>
					<h1>{ page.title }</h1>
					<p className="wst-editor__facts">
						<Chip
							tone={ page.mode === 'off' ? 'error' : 'neutral' }
						>
							{ MODE_LABELS[ page.mode ] || page.mode }
						</Chip>{ ' ' }
						<a
							href={ page.target_url }
							target="_blank"
							rel="noreferrer"
						>
							{ sprintf(
								/* translators: %s: language name */
								__( 'View in %s', 'wp-site-translator' ),
								config.language.native
							) }
						</a>
						{ ' · ' }
						{ page.last_scan
							? sprintf(
									/* translators: %s: date and time */
									__(
										'Last scanned %s',
										'wp-site-translator'
									),
									dateTime( unix( page.last_scan ) )
								)
							: __( 'Not scanned yet', 'wp-site-translator' ) }
						{ counts && counts.queued > 0 && (
							<>
								{ ' · ' }
								<span aria-live="polite">
									{ sprintf(
										/* translators: %s: number of strings */
										_n(
											'%s string waiting for machine translation',
											'%s strings waiting for machine translation',
											counts.queued,
											'wp-site-translator'
										),
										number( counts.queued )
									) }
								</span>
							</>
						) }
					</p>
				</div>
				<div className="wst-editor__actions">
					<Button
						variant="secondary"
						onClick={ () => runScan() }
						isBusy={ scan.running }
						disabled={ scan.running }
					>
						{ __( 'Scan page', 'wp-site-translator' ) }
					</Button>
					{ canMt && page.mode !== 'off' && (
						<Button
							variant="primary"
							onClick={ translateAll }
							isBusy={ bulk && bulk.running }
							disabled={
								( bulk && bulk.running ) ||
								! counts ||
								counts.untranslated === 0
							}
						>
							{ __(
								'Translate untranslated with provider',
								'wp-site-translator'
							) }
						</Button>
					) }
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __( 'Show preview', 'wp-site-translator' ) }
						checked={ showPreview }
						onChange={ setShowPreview }
					/>
				</div>
			</header>
			{ ! config.providerReady && (
				<p className="wst-note">
					{ __(
						'No translation provider is ready, so machine translation is unavailable here. Manual translation works.',
						'wp-site-translator'
					) }{ ' ' }
					{ config.settingsUrl && (
						<a href={ `${ config.settingsUrl }#/translation` }>
							{ __( 'Set up a provider', 'wp-site-translator' ) }
						</a>
					) }
				</p>
			) }
			{ config.providerReady &&
				page.mode === 'manual' &&
				! config.mtOnManual && (
					<p className="wst-note">
						{ __(
							'Machine translation is turned off for manual pages.',
							'wp-site-translator'
						) }
					</p>
				) }
			{ scan.running && (
				<p className="wst-note" aria-live="polite">
					<Spinner />{ ' ' }
					{ __(
						'Scanning the translated page…',
						'wp-site-translator'
					) }
				</p>
			) }
			{ scan.confirm && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ __(
							'This page may show personal data (search results, cart, checkout or account). Its strings will be recorded but never sent for machine translation automatically. Scan it anyway?',
							'wp-site-translator'
						) }
					</p>
					<Button variant="primary" onClick={ () => runScan( true ) }>
						{ __( 'Scan anyway', 'wp-site-translator' ) }
					</Button>{ ' ' }
					<Button
						variant="tertiary"
						onClick={ () =>
							setScan( {
								running: false,
								notice: null,
								confirm: false,
							} )
						}
					>
						{ __( 'Cancel', 'wp-site-translator' ) }
					</Button>
				</Notice>
			) }
			{ scan.notice && (
				<Notice
					status={ scan.notice.status }
					onRemove={ () => setScan( { ...scan, notice: null } ) }
				>
					{ scan.notice.text }
				</Notice>
			) }
			{ bulk && bulk.text && (
				<Notice
					status={ bulk.status }
					onRemove={ () => setBulk( null ) }
				>
					{ bulk.text }
				</Notice>
			) }
			<div className="wst-editor__body">
				<section
					className="wst-editor__list"
					aria-label={ __( 'Strings', 'wp-site-translator' ) }
				>
					<div className="wst-editor__filters">
						<div
							className="wst-filter-chips"
							role="group"
							aria-label={ __( 'Filter', 'wp-site-translator' ) }
						>
							{ FILTERS.map( ( [ id, label ] ) => (
								<Button
									key={ id }
									variant={
										query.filter === id
											? 'primary'
											: 'secondary'
									}
									size="small"
									aria-pressed={ query.filter === id }
									onClick={ () =>
										setQuery( {
											...query,
											filter: id,
											page: 1,
										} )
									}
								>
									{ label }
									{ counts &&
										` (${ number( counts[ id ] ) })` }
								</Button>
							) ) }
						</div>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="search"
							label={ __(
								'Search strings',
								'wp-site-translator'
							) }
							value={ search }
							onChange={ setSearch }
						/>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __(
								'Include site-wide strings (menus, footer)',
								'wp-site-translator'
							) }
							checked={ query.includeGlobal }
							onChange={ ( value ) =>
								setQuery( {
									...query,
									includeGlobal: value,
									page: 1,
								} )
							}
						/>
						<p className="wst-hint">
							{ __(
								'Changes save when you leave a field. Ctrl+Enter saves and moves on, Esc undoes, Alt+↑/↓ moves between strings. A translation applies everywhere the text appears.',
								'wp-site-translator'
							) }
						</p>
					</div>
					{ listError && (
						<Notice status="error" isDismissible={ false }>
							<p>{ listError }</p>
							<Button variant="secondary" onClick={ loadList }>
								{ __( 'Try again', 'wp-site-translator' ) }
							</Button>
						</Notice>
					) }
					{ ! list && ! listError && <Spinner /> }
					{ list && items.length === 0 && (
						<p className="wst-empty">
							{ page.last_scan ||
							query.filter !== 'all' ||
							query.search
								? __(
										'No strings match.',
										'wp-site-translator'
									)
								: __(
										'No strings yet: scan the page to find them.',
										'wp-site-translator'
									) }
						</p>
					) }
					{ items.length > 0 && (
						<ul className="wst-rows">
							{ items.map( ( item ) => (
								<Row
									key={ item.id }
									ref={ ( el ) =>
										( fields.current[ item.id ] = el )
									}
									item={ item }
									language={ config.language }
									draft={ drafts[ item.id ] }
									state={ rowState[ item.id ] }
									canMt={ canMt && page.mode !== 'off' }
									selected={ selectedId === item.id }
									onDraft={ ( row, value ) => {
										setDrafts( ( current ) => ( {
											...current,
											[ row.id ]: value,
										} ) );
										if ( rowState[ row.id ] ) {
											setRow( row.id, {} );
										}
									} }
									onSave={ save }
									onReset={ ( row ) => {
										clearDraft( row.id );
										setRow( row.id, {} );
									} }
									onMove={ move }
									onFocus={ ( row ) =>
										setSelectedId( row.id )
									}
									onAction={ action }
								/>
							) ) }
						</ul>
					) }
					{ list && list.pages > 1 && (
						<div className="wst-pagination">
							<Button
								variant="secondary"
								disabled={ query.page <= 1 }
								onClick={ () =>
									setQuery( {
										...query,
										page: query.page - 1,
									} )
								}
							>
								{ __( 'Previous', 'wp-site-translator' ) }
							</Button>
							<span>
								{ sprintf(
									/* translators: 1: page, 2: pages */
									__(
										'Page %1$d of %2$d',
										'wp-site-translator'
									),
									query.page,
									list.pages
								) }
							</span>
							<Button
								variant="secondary"
								disabled={ query.page >= list.pages }
								onClick={ () =>
									setQuery( {
										...query,
										page: query.page + 1,
									} )
								}
							>
								{ __( 'Next', 'wp-site-translator' ) }
							</Button>
						</div>
					) }
				</section>
				{ showPreview && (
					<Preview
						pageRef={ pageRef }
						items={ items }
						selectedId={ selectedId }
						onSelect={ selectFromPreview }
						reloadKey={ previewKey }
					/>
				) }
			</div>
		</div>
	);
}

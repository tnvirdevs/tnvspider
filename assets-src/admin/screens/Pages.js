/**
 * Pages: mode and coverage of every page, post and product; bulk mode change.
 */
import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorText, getWithTotal, post } from '../api';
import { Section } from '../fields';

const PER_PAGE = 20;

const MODE_LABELS = {
	inherit: __( 'Default', 'wp-site-translator' ),
	auto: __( 'Automatic', 'wp-site-translator' ),
	manual: __( 'Manual only', 'wp-site-translator' ),
	off: __( 'Off', 'wp-site-translator' ),
};

const SOURCE_LABELS = {
	page: __( 'set on the page', 'wp-site-translator' ),
	path: __( 'from a path rule', 'wp-site-translator' ),
	site: __( 'site mode', 'wp-site-translator' ),
};

const modeOptions = ( first ) => [
	first,
	...Object.entries( MODE_LABELS ).map( ( [ value, label ] ) => ( {
		value,
		label,
	} ) ),
];

function Coverage( { coverage, lastScan } ) {
	if ( ! coverage.total ) {
		return (
			<span className="wst-muted">
				{ __( 'Not visited or scanned yet', 'wp-site-translator' ) }
			</span>
		);
	}
	return (
		<span>
			{ sprintf(
				/* translators: 1: percent, 2: translated, 3: total */
				__( '%1$d %% (%2$d of %3$d)', 'wp-site-translator' ),
				coverage.percent,
				coverage.translated,
				coverage.total
			) }
			{ lastScan && <span className="wst-muted"> · { lastScan }</span> }
		</span>
	);
}

export default function Pages( { data } ) {
	const [ query, setQuery ] = useState( { page: 1, search: '', mode: '' } );
	const [ search, setSearch ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ selected, setSelected ] = useState( [] );
	const [ bulkMode, setBulkMode ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const hasTarget = !! data.languages.target;

	const load = useCallback( () => {
		setError( null );
		const args = {
			page: query.page,
			per_page: PER_PAGE,
			search: query.search,
		};
		if ( query.mode ) {
			args.mode = query.mode;
		}
		return getWithTotal( '/pages', args )
			.then( setResult )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [ query ] );

	useEffect( () => {
		if ( hasTarget ) {
			load();
		}
	}, [ hasTarget, load ] );

	if ( ! hasTarget ) {
		return (
			<Section title={ __( 'Pages', 'wp-site-translator' ) }>
				<p className="wst-empty">
					{ __(
						'Choose and save a target language on the Languages screen first.',
						'wp-site-translator'
					) }{ ' ' }
					<a href="#/languages">
						{ __( 'Go to Languages', 'wp-site-translator' ) }
					</a>
				</p>
			</Section>
		);
	}

	const items = result ? result.items : [];
	const allSelected =
		items.length > 0 &&
		items.every( ( item ) => selected.includes( item.id ) );

	const apply = () => {
		setBusy( true );
		setNotice( null );
		post( '/pages/mode', { ids: selected, mode: bulkMode } )
			.then( ( response ) => {
				const skipped = Object.keys( response.skipped || {} ).length;
				setNotice( {
					status: skipped ? 'warning' : 'success',
					text:
						sprintf(
							/* translators: %d: number of pages */
							_n(
								'%d page updated.',
								'%d pages updated.',
								response.updated.length,
								'wp-site-translator'
							),
							response.updated.length
						) +
						( skipped
							? ' ' +
								sprintf(
									/* translators: %d: number of pages */
									_n(
										'%d page skipped (not found or not allowed).',
										'%d pages skipped (not found or not allowed).',
										skipped,
										'wp-site-translator'
									),
									skipped
								)
							: '' ),
				} );
				setSelected( [] );
				return load();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	return (
		<Section
			title={ __( 'Pages', 'wp-site-translator' ) }
			description={ __(
				'Each page follows the site mode and path rules unless you set a mode for it here or in the editor sidebar.',
				'wp-site-translator'
			) }
		>
			<form
				className="wst-toolbar"
				role="search"
				onSubmit={ ( event ) => {
					event.preventDefault();
					setQuery( { ...query, page: 1, search } );
				} }
			>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Search pages', 'wp-site-translator' ) }
					value={ search }
					onChange={ setSearch }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Page setting', 'wp-site-translator' ) }
					value={ query.mode }
					options={ modeOptions( {
						value: '',
						label: __( 'All', 'wp-site-translator' ),
					} ) }
					onChange={ ( mode ) =>
						setQuery( { ...query, page: 1, mode } )
					}
				/>
				<Button variant="secondary" type="submit">
					{ __( 'Search', 'wp-site-translator' ) }
				</Button>
			</form>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					<p>{ error }</p>
					<Button variant="secondary" onClick={ load }>
						{ __( 'Try again', 'wp-site-translator' ) }
					</Button>
				</Notice>
			) }
			{ ! result && ! error && <Spinner /> }
			{ result && items.length === 0 && (
				<p className="wst-empty">
					{ __( 'No pages found.', 'wp-site-translator' ) }
				</p>
			) }
			{ items.length > 0 && (
				<>
					<div className="wst-toolbar">
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __(
								'Set mode of selected pages',
								'wp-site-translator'
							) }
							value={ bulkMode }
							options={ modeOptions( {
								value: '',
								label: __( '— Choose —', 'wp-site-translator' ),
							} ) }
							onChange={ setBulkMode }
						/>
						<Button
							variant="primary"
							onClick={ apply }
							isBusy={ busy }
							disabled={ busy || ! bulkMode || ! selected.length }
						>
							{ sprintf(
								/* translators: %d: number of selected pages */
								__(
									'Apply to %d selected',
									'wp-site-translator'
								),
								selected.length
							) }
						</Button>
					</div>
					<div className="wst-table-wrap">
						<table className="wp-list-table widefat striped wst-table">
							<thead>
								<tr>
									<td className="check-column">
										<CheckboxControl
											__nextHasNoMarginBottom
											label={ __(
												'Select all on this page',
												'wp-site-translator'
											) }
											hideLabelFromVision
											checked={ allSelected }
											onChange={ ( on ) =>
												setSelected(
													on
														? items.map(
																( item ) =>
																	item.id
															)
														: []
												)
											}
										/>
									</td>
									<th scope="col">
										{ __( 'Title', 'wp-site-translator' ) }
									</th>
									<th scope="col">
										{ __( 'Type', 'wp-site-translator' ) }
									</th>
									<th scope="col">
										{ __(
											'Page setting',
											'wp-site-translator'
										) }
									</th>
									<th scope="col">
										{ __(
											'Applies',
											'wp-site-translator'
										) }
									</th>
									<th scope="col">
										{ __(
											'Translated',
											'wp-site-translator'
										) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ items.map( ( item ) => (
									<tr key={ item.id }>
										<th
											scope="row"
											className="check-column"
										>
											<CheckboxControl
												__nextHasNoMarginBottom
												label={
													item.title ||
													__(
														'(no title)',
														'wp-site-translator'
													)
												}
												hideLabelFromVision
												disabled={ ! item.can_edit }
												checked={ selected.includes(
													item.id
												) }
												onChange={ ( on ) =>
													setSelected(
														on
															? [
																	...selected,
																	item.id,
																]
															: selected.filter(
																	( id ) =>
																		id !==
																		item.id
																)
													)
												}
											/>
										</th>
										<td>
											<a href={ item.link }>
												{ item.title ||
													__(
														'(no title)',
														'wp-site-translator'
													) }
											</a>
											{ item.status !== 'publish' && (
												<span className="wst-muted">
													{ ' ' }
													· { item.status }
												</span>
											) }
										</td>
										<td>{ item.type }</td>
										<td>
											{ MODE_LABELS[ item.mode ] ||
												item.mode }
										</td>
										<td>
											{ MODE_LABELS[
												item.effective.mode
											] || item.effective.mode }{ ' ' }
											<span className="wst-muted">
												(
												{ SOURCE_LABELS[
													item.effective.source
												] || item.effective.source }
												)
											</span>
										</td>
										<td>
											<Coverage
												coverage={ item.coverage }
												lastScan={ item.last_scan }
											/>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
					<div className="wst-pagination">
						<Button
							variant="secondary"
							disabled={ query.page <= 1 }
							onClick={ () =>
								setQuery( { ...query, page: query.page - 1 } )
							}
						>
							{ __( 'Previous', 'wp-site-translator' ) }
						</Button>
						<span>
							{ sprintf(
								/* translators: 1: page, 2: pages, 3: total items */
								__(
									'Page %1$d of %2$d (%3$d items)',
									'wp-site-translator'
								),
								query.page,
								Math.max( 1, result.pages ),
								result.total
							) }
						</span>
						<Button
							variant="secondary"
							disabled={ query.page >= result.pages }
							onClick={ () =>
								setQuery( { ...query, page: query.page + 1 } )
							}
						>
							{ __( 'Next', 'wp-site-translator' ) }
						</Button>
					</div>
				</>
			) }
		</Section>
	);
}

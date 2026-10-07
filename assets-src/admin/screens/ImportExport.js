/**
 * Import / Export screen (plan §12 screen 7, §13A.3): CSV export with
 * filters; CSV import read in the browser, checked in a dry run, then
 * applied in chunks with a conflict policy.
 */
import {
	Button,
	Notice,
	RadioControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorText, post } from '../api';
import { number } from '../format';
import { Section } from '../fields';
import { CsvError, importRows } from '../csv';

const config = window.wstAdmin || {};
const exportConfig = config.export || {};
const importConfig = config.import || {};
const REQUIRED = [ 'original', 'translated' ];
const LISTED_MAX = 200;

const FILTERS = [
	{ value: 'all', label: __( 'All strings', 'wp-site-translator' ) },
	{
		value: 'untranslated',
		label: __( 'Untranslated only', 'wp-site-translator' ),
	},
	{
		value: 'machine',
		label: __( 'Machine translations', 'wp-site-translator' ),
	},
	{
		value: 'manual',
		label: __( 'Manual translations', 'wp-site-translator' ),
	},
	{ value: 'page', label: __( 'One page', 'wp-site-translator' ) },
];

const POLICIES = [
	{
		value: 'keep_manual',
		label: __(
			'Overwrite machine translations, keep manual ones',
			'wp-site-translator'
		),
	},
	{
		value: 'add_only',
		label: __(
			'Add only: keep every existing translation',
			'wp-site-translator'
		),
	},
	{
		value: 'overwrite_all',
		label: __(
			'Overwrite all, including manual translations',
			'wp-site-translator'
		),
	},
];

const STATUSES = [
	{
		value: 'manual',
		label: __(
			'Manual (protected from machine translation)',
			'wp-site-translator'
		),
	},
	{ value: 'machine', label: __( 'Machine', 'wp-site-translator' ) },
	{
		value: 'file',
		label: __(
			'As in the file’s status column (empty = manual)',
			'wp-site-translator'
		),
	},
];

const OUTCOMES = [
	[ 'new', __( 'New translations', 'wp-site-translator' ) ],
	[ 'update', __( 'Updates', 'wp-site-translator' ) ],
	[ 'unchanged', __( 'Unchanged', 'wp-site-translator' ) ],
	[ 'conflict', __( 'Kept by the conflict policy', 'wp-site-translator' ) ],
	[ 'skipped', __( 'Skipped (no translation)', 'wp-site-translator' ) ],
	[ 'invalid', __( 'Invalid', 'wp-site-translator' ) ],
];

const OUTCOME_LABEL = {
	conflict: __( 'Kept', 'wp-site-translator' ),
	skipped: __( 'Skipped', 'wp-site-translator' ),
	invalid: __( 'Invalid', 'wp-site-translator' ),
};

function csvErrorText( error ) {
	if ( error.message === 'unterminated-quote' ) {
		return sprintf(
			/* translators: %d: line number */
			__(
				'The file is not valid CSV: a quoted cell that starts on line %d is never closed.',
				'wp-site-translator'
			),
			error.line
		);
	}
	return sprintf(
		/* translators: %d: line number */
		__(
			'The file is not valid CSV: unexpected text after a closing quote on line %d.',
			'wp-site-translator'
		),
		error.line
	);
}

function ExportSection() {
	const [ filter, setFilter ] = useState( 'all' );
	const [ path, setPath ] = useState( '' );
	return (
		<Section
			title={ __( 'Export', 'wp-site-translator' ) }
			description={ __(
				'Download strings and their translations as a CSV file (UTF-8). Columns: original, translated, status, kind, lang, pages. Cells that a spreadsheet would run as a formula start with an apostrophe; the import removes it.',
				'wp-site-translator'
			) }
		>
			<form method="get" action={ exportConfig.url }>
				<input
					type="hidden"
					name="action"
					value={ exportConfig.action }
				/>
				<input
					type="hidden"
					name="_wpnonce"
					value={ exportConfig.nonce }
				/>
				<div className="wst-field">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Strings', 'wp-site-translator' ) }
						name="filter"
						value={ filter }
						options={ FILTERS }
						onChange={ setFilter }
					/>
				</div>
				{ filter === 'page' && (
					<div className="wst-field">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Page path', 'wp-site-translator' ) }
							help={ __(
								'Path without the language prefix, for example /about/. The page must have been visited or scanned.',
								'wp-site-translator'
							) }
							name="path"
							value={ path }
							required
							onChange={ setPath }
						/>
					</div>
				) }
				<Button variant="primary" type="submit">
					{ __( 'Download CSV', 'wp-site-translator' ) }
				</Button>
			</form>
		</Section>
	);
}

function Progress( { label, done, total } ) {
	return (
		<div className="wst-progress">
			<p id="wst-import-progress">
				{ label }{ ' ' }
				{ sprintf(
					/* translators: 1: rows done, 2: all rows */
					__( '%1$s of %2$s rows', 'wp-site-translator' ),
					number( done ),
					number( total )
				) }
			</p>
			<progress
				aria-labelledby="wst-import-progress"
				max={ total }
				value={ done }
			/>
		</div>
	);
}

function Summary( { result, applied } ) {
	const listed = result.rows.slice( 0, LISTED_MAX );
	return (
		<div className="wst-import-summary" aria-live="polite">
			<h3>
				{ applied
					? __( 'Import finished', 'wp-site-translator' )
					: __(
							'Dry run: nothing was changed',
							'wp-site-translator'
						) }
			</h3>
			<dl className="wst-stats">
				<div>
					<dt>{ __( 'Rows', 'wp-site-translator' ) }</dt>
					<dd>{ number( result.total ) }</dd>
				</div>
				{ OUTCOMES.map( ( [ key, label ] ) => (
					<div key={ key }>
						<dt>{ label }</dt>
						<dd>{ number( result.counts[ key ] || 0 ) }</dd>
					</div>
				) ) }
			</dl>
			{ listed.length > 0 && (
				<table className="wst-table wst-table--cards">
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Line', 'wp-site-translator' ) }
							</th>
							<th scope="col">
								{ __( 'Result', 'wp-site-translator' ) }
							</th>
							<th scope="col">
								{ __( 'Reason', 'wp-site-translator' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ listed.map( ( row ) => (
							<tr key={ `${ row.line }-${ row.outcome }` }>
								<th scope="row" className="wst-table__primary">
									{ number( row.line ) }
								</th>
								<td
									data-label={ __(
										'Result',
										'wp-site-translator'
									) }
								>
									<span className="wst-table__value">
										{ OUTCOME_LABEL[ row.outcome ] ||
											row.outcome }
									</span>
								</td>
								<td
									data-label={ __(
										'Reason',
										'wp-site-translator'
									) }
								>
									<span className="wst-table__value">
										{ row.reason }
									</span>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ result.rows.length > LISTED_MAX && (
				<p>
					{ sprintf(
						/* translators: %s: number of rows not listed */
						_n(
							'… and %s more row.',
							'… and %s more rows.',
							result.rows.length - LISTED_MAX,
							'wp-site-translator'
						),
						number( result.rows.length - LISTED_MAX )
					) }
				</p>
			) }
		</div>
	);
}

function ImportSection() {
	const [ file, setFile ] = useState( null );
	const [ fileError, setFileError ] = useState( '' );
	const [ policy, setPolicy ] = useState( 'keep_manual' );
	const [ as, setAs ] = useState( 'manual' );
	const [ progress, setProgress ] = useState( null );
	const [ result, setResult ] = useState( null );
	const [ requestError, setRequestError ] = useState( '' );

	const reset = () => {
		setResult( null );
		setRequestError( '' );
	};

	const choose = async ( event ) => {
		const picked = event.target.files && event.target.files[ 0 ];
		reset();
		setFile( null );
		setFileError( '' );
		if ( ! picked ) {
			return;
		}
		if ( picked.size > importConfig.maxFileBytes ) {
			setFileError(
				sprintf(
					/* translators: %s: size in MB */
					__(
						'The file is larger than %s MB. Split it into smaller files.',
						'wp-site-translator'
					),
					number( importConfig.maxFileBytes / 1048576 )
				)
			);
			return;
		}
		let parsed;
		try {
			parsed = importRows(
				await picked.text(),
				importConfig.columns,
				REQUIRED
			);
		} catch ( error ) {
			if ( error instanceof CsvError ) {
				setFileError( csvErrorText( error ) );
				return;
			}
			throw error;
		}
		if ( parsed.missing.length > 0 ) {
			setFileError(
				sprintf(
					/* translators: %s: column names */
					__(
						'The first line must name the columns; missing: %s.',
						'wp-site-translator'
					),
					parsed.missing.join( ', ' )
				)
			);
			return;
		}
		const total = parsed.rows.length + parsed.problems.length;
		if ( total === 0 ) {
			setFileError( __( 'The file has no rows.', 'wp-site-translator' ) );
			return;
		}
		if ( total > importConfig.maxRows ) {
			setFileError(
				sprintf(
					/* translators: %s: row limit */
					__(
						'The file has more than %s rows. Split it into smaller files.',
						'wp-site-translator'
					),
					number( importConfig.maxRows )
				)
			);
			return;
		}
		setFile( { name: picked.name, ...parsed, total } );
	};

	const run = async ( apply ) => {
		reset();
		const counts = {};
		const listed = parsedProblems( file.problems );
		counts.invalid = listed.length;
		let done = 0;
		const label = apply
			? __( 'Importing…', 'wp-site-translator' )
			: __( 'Checking…', 'wp-site-translator' );
		setProgress( { label, done, total: file.rows.length } );
		try {
			for (
				let start = 0;
				start < file.rows.length;
				start += importConfig.chunk
			) {
				const rows = file.rows.slice(
					start,
					start + importConfig.chunk
				);
				const response = await post(
					apply ? '/import/apply' : '/import/check',
					{ rows, policy, as }
				);
				Object.entries( response.counts ).forEach(
					( [ key, value ] ) => {
						counts[ key ] = ( counts[ key ] || 0 ) + value;
					}
				);
				listed.push( ...response.rows );
				done += rows.length;
				setProgress( { label, done, total: file.rows.length } );
			}
		} catch ( error ) {
			setRequestError(
				apply && done > 0
					? sprintf(
							/* translators: 1: error message, 2: rows imported */
							__(
								'%1$s The first %2$s rows were imported. Importing the same file again is safe: rows already imported come out unchanged.',
								'wp-site-translator'
							),
							errorText( error ),
							number( done )
						)
					: errorText( error )
			);
			setProgress( null );
			return;
		}
		listed.sort( ( a, b ) => a.line - b.line );
		setProgress( null );
		setResult( { apply, counts, rows: listed, total: file.total } );
	};

	return (
		<Section
			title={ __( 'Import', 'wp-site-translator' ) }
			description={ __(
				'Import a CSV file with at least the columns original and translated (status, kind and lang are optional; lang must be the target language). Check the file first: the dry run changes nothing. Inline translations must keep the original’s tags and attributes.',
				'wp-site-translator'
			) }
		>
			<div className="wst-field">
				<label htmlFor="wst-import-file" className="wst-file-label">
					{ __( 'CSV file', 'wp-site-translator' ) }
				</label>
				<input
					id="wst-import-file"
					type="file"
					accept=".csv,text/csv"
					onChange={ choose }
					disabled={ !! progress }
				/>
				<p className="wst-note">
					{ sprintf(
						/* translators: 1: size in MB, 2: row limit */
						__(
							'Up to %1$s MB and %2$s rows.',
							'wp-site-translator'
						),
						number( importConfig.maxFileBytes / 1048576 ),
						number( importConfig.maxRows )
					) }
				</p>
				{ fileError && (
					<Notice status="error" isDismissible={ false }>
						<p>{ fileError }</p>
					</Notice>
				) }
				{ file && (
					<p>
						{ sprintf(
							/* translators: 1: file name, 2: number of rows */
							_n(
								'%1$s: %2$s row read.',
								'%1$s: %2$s rows read.',
								file.total,
								'wp-site-translator'
							),
							file.name,
							number( file.total )
						) }
					</p>
				) }
			</div>
			<div className="wst-field">
				<RadioControl
					label={ __( 'Import as', 'wp-site-translator' ) }
					options={ STATUSES }
					selected={ as }
					onChange={ ( value ) => {
						setAs( value );
						reset();
					} }
				/>
			</div>
			<div className="wst-field">
				<RadioControl
					label={ __(
						'When a string is already translated',
						'wp-site-translator'
					) }
					options={ POLICIES }
					selected={ policy }
					onChange={ ( value ) => {
						setPolicy( value );
						reset();
					} }
				/>
			</div>
			<div className="wst-toolbar">
				<Button
					variant="secondary"
					disabled={ ! file || !! progress }
					onClick={ () => run( false ) }
				>
					{ __( 'Check file (dry run)', 'wp-site-translator' ) }
				</Button>
				<Button
					variant="primary"
					disabled={
						! file || !! progress || ! result || result.apply
					}
					onClick={ () => run( true ) }
				>
					{ __( 'Import', 'wp-site-translator' ) }
				</Button>
			</div>
			{ progress && <Progress { ...progress } /> }
			{ requestError && (
				<Notice status="error" isDismissible={ false }>
					<p>{ requestError }</p>
				</Notice>
			) }
			{ result && <Summary result={ result } applied={ result.apply } /> }
		</Section>
	);
}

/**
 * Records with the wrong number of cells, listed as invalid.
 *
 * @param {Object[]} problems From importRows().
 */
function parsedProblems( problems ) {
	return problems.map( ( problem ) => ( {
		line: problem.line,
		outcome: 'invalid',
		reason: sprintf(
			/* translators: 1: cells found, 2: cells expected */
			__(
				'Found %1$d cells, the header has %2$d.',
				'wp-site-translator'
			),
			problem.cells,
			problem.expected
		),
	} ) );
}

export default function ImportExport() {
	return (
		<>
			<ExportSection />
			<ImportSection />
		</>
	);
}

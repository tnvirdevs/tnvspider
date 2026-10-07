/**
 * Advanced: excluded areas, never-translate terms, links and hreflang,
 * logging with the log viewer, data tools and uninstall behaviour.
 */
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { errorText, get, post } from '../api';
import { Choice, Lines, Section, Toggle } from '../fields';
import { Chip, number } from '../format';

function LogViewer() {
	const [ level, setLevel ] = useState( '' );
	const [ entries, setEntries ] = useState( null );
	const [ error, setError ] = useState( null );

	const load = useCallback( () => {
		setError( null );
		setEntries( null );
		get( '/log', level ? { level, limit: 100 } : { limit: 100 } )
			.then( setEntries )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [ level ] );

	useEffect( () => {
		load();
	}, [ load ] );

	return (
		<div className="wst-log">
			<div className="wst-toolbar">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Show', 'wp-site-translator' ) }
					value={ level }
					options={ [
						{
							value: '',
							label: __(
								'Errors and warnings',
								'wp-site-translator'
							),
						},
						{
							value: 'error',
							label: __( 'Errors only', 'wp-site-translator' ),
						},
						{
							value: 'warning',
							label: __( 'Warnings only', 'wp-site-translator' ),
						},
					] }
					onChange={ setLevel }
				/>
				<Button variant="secondary" onClick={ load }>
					{ __( 'Refresh', 'wp-site-translator' ) }
				</Button>
			</div>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! entries && ! error && <Spinner /> }
			{ entries && entries.length === 0 && (
				<p className="wst-empty">
					{ __( 'The log is empty.', 'wp-site-translator' ) }
				</p>
			) }
			{ entries && entries.length > 0 && (
				<div className="wst-table-wrap">
					<table className="widefat striped wst-table">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Time (UTC)', 'wp-site-translator' ) }
								</th>
								<th scope="col">
									{ __( 'Level', 'wp-site-translator' ) }
								</th>
								<th scope="col">
									{ __( 'Source', 'wp-site-translator' ) }
								</th>
								<th scope="col">
									{ __( 'Message', 'wp-site-translator' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ entries.map( ( entry ) => (
								<tr key={ entry.id }>
									<td>{ entry.created_at }</td>
									<td>
										<Chip
											tone={
												entry.level === 'error'
													? 'error'
													: 'warning'
											}
										>
											{ entry.level }
										</Chip>
									</td>
									<td>{ entry.source }</td>
									<td>
										{ entry.message }
										{ Object.keys( entry.context ).length >
											0 && (
											<details>
												<summary>
													{ __(
														'Details',
														'wp-site-translator'
													) }
												</summary>
												<pre>
													{ JSON.stringify(
														entry.context,
														null,
														2
													) }
												</pre>
											</details>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</div>
	);
}

/**
 * A destructive action with an explicit confirmation step.
 *
 * @param {Object}                props
 * @param {string}                props.label    Button text.
 * @param {string}                props.confirm  Question shown before running.
 * @param {() => Promise<string>} props.run      Returns a promise of a result message.
 * @param {boolean}               props.disabled
 */
function DangerAction( { label, confirm, run, disabled = false } ) {
	const [ asking, setAsking ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null );
	const go = () => {
		setBusy( true );
		run()
			.then( ( text ) => setResult( { status: 'success', text } ) )
			.catch( ( e ) =>
				setResult( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => {
				setBusy( false );
				setAsking( false );
			} );
	};
	return (
		<div className="wst-danger">
			{ ! asking ? (
				<Button
					variant="secondary"
					isDestructive
					disabled={ disabled }
					onClick={ () => setAsking( true ) }
				>
					{ label }
				</Button>
			) : (
				<div
					className="wst-danger__confirm"
					role="alertdialog"
					aria-label={ label }
				>
					<p>{ confirm }</p>
					<Button
						variant="primary"
						isDestructive
						isBusy={ busy }
						disabled={ busy }
						onClick={ go }
					>
						{ __( 'Yes, delete', 'wp-site-translator' ) }
					</Button>
					<Button
						variant="tertiary"
						disabled={ busy }
						onClick={ () => setAsking( false ) }
					>
						{ __( 'Cancel', 'wp-site-translator' ) }
					</Button>
				</div>
			) }
			{ result && (
				<Notice
					status={ result.status }
					onRemove={ () => setResult( null ) }
				>
					{ result.text }
				</Notice>
			) }
		</div>
	);
}

function Orphans() {
	const [ days, setDays ] = useState( '30' );
	const [ preview, setPreview ] = useState( null );
	const [ error, setError ] = useState( null );
	const valid = /^\d+$/.test( days ) && parseInt( days, 10 ) >= 1;

	const check = () => {
		setError( null );
		setPreview( null );
		get( '/data/orphans', { days: parseInt( days, 10 ) } )
			.then( setPreview )
			.catch( ( e ) => setError( errorText( e ) ) );
	};

	return (
		<div className="wst-field">
			<h3>{ __( 'Unused strings', 'wp-site-translator' ) }</h3>
			<p className="wst-note">
				{ __(
					'Strings no longer found on any page, not shared site-wide and without a manual translation. Check first, then delete.',
					'wp-site-translator'
				) }
			</p>
			<div className="wst-toolbar">
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="number"
					min={ 1 }
					label={ __(
						'Recorded more than this many days ago',
						'wp-site-translator'
					) }
					value={ days }
					onChange={ ( value ) => {
						setDays( value );
						setPreview( null );
					} }
				/>
				<Button
					variant="secondary"
					disabled={ ! valid }
					onClick={ check }
				>
					{ __( 'Check (dry run)', 'wp-site-translator' ) }
				</Button>
			</div>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ preview && (
				<>
					<p>
						{ sprintf(
							/* translators: 1: number of strings, 2: characters */
							__(
								'%1$s unused strings (%2$s characters) would be deleted with their machine translations.',
								'wp-site-translator'
							),
							number( preview.count ),
							number( preview.chars )
						) }
					</p>
					<DangerAction
						label={ __(
							'Delete unused strings',
							'wp-site-translator'
						) }
						confirm={ __(
							'Delete these strings and their machine translations? This cannot be undone.',
							'wp-site-translator'
						) }
						disabled={ preview.count === 0 }
						run={ () =>
							post( '/data/orphans/clear', {
								days: preview.days,
								confirm: true,
							} ).then( ( r ) => {
								setPreview( null );
								return sprintf(
									/* translators: %s: number of strings */
									__(
										'%s strings deleted.',
										'wp-site-translator'
									),
									number( r.deleted )
								);
							} )
						}
					/>
				</>
			) }
		</div>
	);
}

export default function Advanced( {
	draft,
	update,
	errors,
	data,
	refreshQueue,
} ) {
	const fields = { draft, update, errors };
	return (
		<>
			<Section title={ __( 'Excluded areas', 'wp-site-translator' ) }>
				<Lines
					{ ...fields }
					name="exclude_selectors"
					label={ __(
						'Never translate elements matching these CSS selectors (one per line)',
						'wp-site-translator'
					) }
					help={ __(
						'Supported: tag (div), .class, #id, [attr], [attr=value], several combined (div.note[data-x]), descendants separated by spaces (.footer .legal) and comma lists. Not supported: *, >, +, ~ and pseudo-classes. Elements with data-wst-no-translate or data-no-translation are always skipped.',
						'wp-site-translator'
					) }
				/>
			</Section>
			<Section
				title={ __( 'Never-translate terms', 'wp-site-translator' ) }
				description={ __(
					'Brand and product names that must stay as written. Changes apply to new translations only.',
					'wp-site-translator'
				) }
			>
				<Lines
					{ ...fields }
					name="never_translate_terms"
					rows={ 6 }
					label={ __(
						'Terms (one per line, up to 500)',
						'wp-site-translator'
					) }
				/>
				<Toggle
					{ ...fields }
					name="terms_case_insensitive"
					label={ __(
						'Match regardless of upper and lower case',
						'wp-site-translator'
					) }
				/>
				<Toggle
					{ ...fields }
					name="terms_whole_word"
					label={ __(
						'Match whole words only',
						'wp-site-translator'
					) }
				/>
			</Section>
			<Section
				title={ __( 'Links and search engines', 'wp-site-translator' ) }
			>
				<Toggle
					{ ...fields }
					name="force_language_links"
					label={ __(
						'Keep visitors in their language on internal links written into content',
						'wp-site-translator'
					) }
				/>
				<Toggle
					{ ...fields }
					name="hreflang_x_default"
					label={ __(
						'Add an x-default hreflang link pointing to the default language',
						'wp-site-translator'
					) }
				/>
				<Toggle
					{ ...fields }
					name="hreflang_drop_region"
					label={ __(
						'Use language codes without region (bn instead of bn-BD) in hreflang and the lang attribute',
						'wp-site-translator'
					) }
				/>
			</Section>
			<Section title={ __( 'Log', 'wp-site-translator' ) }>
				<Choice
					{ ...fields }
					radio
					name="log_level"
					label={ __( 'Record', 'wp-site-translator' ) }
					options={ [
						{
							value: 'warning',
							label: __(
								'Errors and warnings',
								'wp-site-translator'
							),
						},
						{
							value: 'error',
							label: __( 'Errors only', 'wp-site-translator' ),
						},
					] }
					help={ __(
						'The latest 1,000 entries are kept.',
						'wp-site-translator'
					) }
				/>
				<LogViewer />
			</Section>
			<Section title={ __( 'Data', 'wp-site-translator' ) }>
				<div className="wst-field">
					<h3>{ __( 'Translation queue', 'wp-site-translator' ) }</h3>
					<DangerAction
						label={ __( 'Clear the queue', 'wp-site-translator' ) }
						confirm={ __(
							'Remove all waiting and failed strings from the queue? Strings being translated right now finish. Visits and scans add strings again.',
							'wp-site-translator'
						) }
						run={ () =>
							post( '/queue/clear', { state: 'all' } ).then(
								( r ) => {
									refreshQueue();
									return sprintf(
										/* translators: %s: number of rows */
										__(
											'%s queued strings removed.',
											'wp-site-translator'
										),
										number( r.cleared )
									);
								}
							)
						}
					/>
				</div>
				<div className="wst-field">
					<h3>
						{ __( 'Machine translations', 'wp-site-translator' ) }
					</h3>
					<p className="wst-note">
						{ __(
							'Deletes every machine translation of the target language. Manual translations are kept. Pages show the original text until strings are translated again.',
							'wp-site-translator'
						) }
					</p>
					<DangerAction
						label={ __(
							'Delete machine translations',
							'wp-site-translator'
						) }
						disabled={ ! data.languages.target }
						confirm={ __(
							'Delete all machine translations? Manual translations are kept. This cannot be undone.',
							'wp-site-translator'
						) }
						run={ () =>
							post( '/data/machine/clear', {
								confirm: true,
							} ).then( ( r ) =>
								sprintf(
									/* translators: %s: number of translations */
									__(
										'%s machine translations deleted.',
										'wp-site-translator'
									),
									number( r.deleted )
								)
							)
						}
					/>
				</div>
				<Orphans />
				<Toggle
					{ ...fields }
					name="delete_on_uninstall"
					label={ __(
						'Delete all translator data when the plugin is deleted',
						'wp-site-translator'
					) }
					help={ __(
						'Off: deleting the plugin keeps every translation and setting, so reinstalling restores them. On: tables, settings, keys and page modes are removed for good.',
						'wp-site-translator'
					) }
				/>
			</Section>
		</>
	);
}

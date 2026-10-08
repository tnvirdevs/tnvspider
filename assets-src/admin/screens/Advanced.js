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
import { Choice, Lines, Section, Text, Toggle } from '../fields';
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
					<table className="wst-table wst-table--cards">
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
									<td
										data-label={ __(
											'Time (UTC)',
											'wp-site-translator'
										) }
									>
										<span className="wst-table__value">
											{ entry.created_at }
										</span>
									</td>
									<td
										data-label={ __(
											'Level',
											'wp-site-translator'
										) }
									>
										<span className="wst-table__value">
											<Chip
												tone={
													entry.level === 'error'
														? 'error'
														: 'warning'
												}
											>
												{ entry.level }
											</Chip>
										</span>
									</td>
									<td
										data-label={ __(
											'Source',
											'wp-site-translator'
										) }
									>
										<span className="wst-table__value">
											{ entry.source }
										</span>
									</td>
									<td
										className="wst-table__wide"
										data-label={ __(
											'Message',
											'wp-site-translator'
										) }
									>
										<span className="wst-table__value">
											{ entry.message }
											{ Object.keys( entry.context )
												.length > 0 && (
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
										</span>
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

/**
 * Digit set suggested for a target language (never enabled automatically,
 * plan §13A.6).
 *
 * @param {string} locale Target locale.
 */
function digitsSuggestion( locale ) {
	const language = ( locale || '' ).split( '_' )[ 0 ];
	if ( language === 'bn' ) {
		return __(
			'Suggested for Bengali: Bengali digits.',
			'wp-site-translator'
		);
	}
	if ( language === 'ur' || language === 'fa' ) {
		return __(
			'Suggested for Urdu and Persian: Persian digits.',
			'wp-site-translator'
		);
	}
	return '';
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
			<Section
				title={ __( 'Digits', 'wp-site-translator' ) }
				description={ __(
					'Write numbers with the digits of the target language on translated pages. Only visible text changes: links, attributes, e-mail addresses, form values, code and never-translate terms keep their digits.',
					'wp-site-translator'
				) }
			>
				<Choice
					{ ...fields }
					radio
					name="digits_mode"
					label={ __(
						'Digits on translated pages',
						'wp-site-translator'
					) }
					help={ digitsSuggestion( fields.draft.target_language ) }
					options={ [
						{
							value: 'auto',
							label: __(
								'Automatic: Arabic-Indic digits for Arabic, unchanged for other languages',
								'wp-site-translator'
							),
						},
						{
							value: 'off',
							label: __(
								'Unchanged (0123456789)',
								'wp-site-translator'
							),
						},
						{
							value: 'bengali',
							label: __(
								'Bengali (০১২৩৪৫৬৭৮৯)',
								'wp-site-translator'
							),
						},
						{
							value: 'arabic_indic',
							label: __(
								'Arabic-Indic (٠١٢٣٤٥٦٧٨٩)',
								'wp-site-translator'
							),
						},
						{
							value: 'persian',
							label: __(
								'Persian, also used for Urdu (۰۱۲۳۴۵۶۷۸۹)',
								'wp-site-translator'
							),
						},
					] }
				/>
				<Toggle
					{ ...fields }
					name="digits_skip_prices"
					label={ __(
						'Keep WooCommerce prices as they are',
						'wp-site-translator'
					) }
				/>
			</Section>
			<Section
				title={ __( 'Dynamic content', 'wp-site-translator' ) }
				description={ __(
					'Text that arrives after the page loads: cart fragments, AJAX responses and text that scripts add. Only existing translations are used; nothing here creates strings or calls a provider.',
					'wp-site-translator'
				) }
			>
				<Toggle
					{ ...fields }
					name="dynamic_fragments"
					label={ __(
						'Translate AJAX and REST responses on translated pages (WooCommerce cart fragments, add to cart, order review, checkout messages)',
						'wp-site-translator'
					) }
				/>
				<Lines
					{ ...fields }
					name="dynamic_json_keys"
					label={ __(
						'JSON keys whose plain-text values are translated',
						'wp-site-translator'
					) }
					help={ __(
						'One per line. Values that contain HTML are translated under any key; keys, numbers, IDs, URLs and nonces never change.',
						'wp-site-translator'
					) }
				/>
				<Toggle
					{ ...fields }
					name="dynamic_lookup"
					label={ __(
						'Translate dynamic content: text that scripts add to the page',
						'wp-site-translator'
					) }
					help={ __(
						'A small script asks for existing translations of new text (public, read-only, limited to 60 requests a minute per visitor). Text assembled by scripts, such as "Items: " plus a number, cannot be matched. Use "Scan dynamic content" in the editor to collect such texts.',
						'wp-site-translator'
					) }
				/>
				<Choice
					{ ...fields }
					name="trusted_proxy"
					label={ __(
						'Visitor address for the rate limit',
						'wp-site-translator'
					) }
					help={ __(
						'Behind Cloudflare or another proxy every visitor arrives from the proxy address; choose the header the proxy sets, or all visitors share one limit. Only choose a header your proxy always sets: visitors can send any header themselves.',
						'wp-site-translator'
					) }
					options={ [
						{
							value: 'none',
							label: __(
								'Direct connection (no proxy)',
								'wp-site-translator'
							),
						},
						{
							value: 'cloudflare',
							label: __(
								'Cloudflare (CF-Connecting-IP)',
								'wp-site-translator'
							),
						},
						{
							value: 'forwarded',
							label: __(
								'Proxy or load balancer (X-Forwarded-For, last address)',
								'wp-site-translator'
							),
						},
						{
							value: 'custom',
							label: __( 'Custom header', 'wp-site-translator' ),
						},
					] }
				/>
				{ fields.draft.trusted_proxy === 'custom' && (
					<Text
						{ ...fields }
						name="trusted_proxy_header"
						label={ __( 'Header name', 'wp-site-translator' ) }
						placeholder="X-Real-IP"
					/>
				) }
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

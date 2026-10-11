/**
 * Translation: site mode, providers, discovery and pages kept out of
 * automatic translation.
 */
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { Choice, FieldError, Integer, Lines, Section, Toggle } from '../fields';
import ProviderCard from './ProviderCard';

const MODES = [
	{
		value: 'auto',
		label: __( 'Translate automatically', 'wp-site-translator' ),
	},
	{ value: 'manual', label: __( 'Manual only', 'wp-site-translator' ) },
	{ value: 'off', label: __( 'Off (not translated)', 'wp-site-translator' ) },
];

function PathRules( { draft, update, errors } ) {
	const rules = draft.path_rules || [];
	const set = ( index, changes ) =>
		update(
			'path_rules',
			rules.map( ( rule, i ) =>
				i === index ? { ...rule, ...changes } : rule
			)
		);
	return (
		<div className="wst-field">
			<h3>{ __( 'Path rules', 'wp-site-translator' ) }</h3>
			<p className="wst-note">
				{ __(
					'Set the mode of whole sections. "/shop/*" matches /shop/ and everything below it, "/about/" matches that page only and {{home}} is the front page. Rules are checked in order and the first match applies; a mode set on a page itself wins over rules.',
					'wp-site-translator'
				) }
			</p>
			{ rules.length === 0 && (
				<p className="wst-empty">
					{ __(
						'No path rules yet: every page uses the site mode.',
						'wp-site-translator'
					) }
				</p>
			) }
			<ol className="wst-rules">
				{ rules.map( ( rule, index ) => (
					<li key={ index } className="wst-rules__row">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ sprintf(
								/* translators: %d: rule number */
								__( 'Path of rule %d', 'wp-site-translator' ),
								index + 1
							) }
							value={ rule.path }
							placeholder="/shop/*"
							onChange={ ( path ) => set( index, { path } ) }
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ sprintf(
								/* translators: %d: rule number */
								__( 'Mode of rule %d', 'wp-site-translator' ),
								index + 1
							) }
							options={ MODES }
							value={ rule.mode }
							onChange={ ( mode ) => set( index, { mode } ) }
						/>
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () =>
								update(
									'path_rules',
									rules.filter( ( r, i ) => i !== index )
								)
							}
						>
							{ __( 'Remove', 'wp-site-translator' ) }
						</Button>
					</li>
				) ) }
			</ol>
			<Button
				variant="secondary"
				onClick={ () =>
					update( 'path_rules', [
						...rules,
						{ path: '', mode: 'off' },
					] )
				}
			>
				{ __( 'Add rule', 'wp-site-translator' ) }
			</Button>
			<FieldError error={ errors.path_rules } />
		</div>
	);
}

export default function Translation( props ) {
	const {
		draft,
		update,
		errors,
		data,
		providers,
		providersError,
		refreshProviders,
		secrets,
		setSecret,
	} = props;
	const fields = { draft, update, errors };
	const providerOptions = ( providers || [] ).map( ( row ) => ( {
		value: row.id,
		label: row.label,
	} ) );

	const setProviderValue = ( id ) => ( key, value ) => {
		const all = { ...( draft.providers || {} ) };
		const own = { ...( all[ id ] || {} ) };
		if ( value === '' ) {
			delete own[ key ];
		} else {
			own[ key ] = value;
		}
		if ( Object.keys( own ).length ) {
			all[ id ] = own;
		} else {
			delete all[ id ];
		}
		update( 'providers', all );
	};

	return (
		<>
			<Section title={ __( 'Site mode', 'wp-site-translator' ) }>
				<Choice
					{ ...fields }
					radio
					name="site_mode"
					label={ __(
						'How new strings are translated',
						'wp-site-translator'
					) }
					options={ [
						{
							value: 'auto',
							label: __(
								'Automatically: new strings are queued for machine translation',
								'wp-site-translator'
							),
						},
						{
							value: 'manual',
							label: __(
								'Manually: only translations you enter are shown; nothing is sent to a provider unless you ask',
								'wp-site-translator'
							),
						},
					] }
				/>
				<Toggle
					{ ...fields }
					name="editor_mt_on_manual"
					label={ __(
						'Allow machine translation in the editor on manual pages',
						'wp-site-translator'
					) }
					help={ __(
						'Manual pages never send strings automatically. With this on, editors can still ask the provider for a translation.',
						'wp-site-translator'
					) }
				/>
			</Section>
			<Section
				title={ __( 'Translation providers', 'wp-site-translator' ) }
				description={ __(
					'Strings are translated in the background through a rate-limited queue; pages never wait for a provider. The fallback takes over while the main provider is paused, out of budget or cannot translate the language pair.',
					'wp-site-translator'
				) }
			>
				{ providersError && (
					<Notice status="error" isDismissible={ false }>
						<p>{ providersError }</p>
						<Button
							variant="secondary"
							onClick={ refreshProviders }
						>
							{ __( 'Try again', 'wp-site-translator' ) }
						</Button>
					</Notice>
				) }
				{ ! providers && ! providersError && <Spinner /> }
				{ providers && (
					<>
						<div className="wst-grid">
							<Choice
								{ ...fields }
								name="provider"
								label={ __(
									'Main provider',
									'wp-site-translator'
								) }
								options={ [
									{
										value: '',
										label: __(
											'None (manual translations only)',
											'wp-site-translator'
										),
									},
									...providerOptions,
								] }
							/>
							<Choice
								{ ...fields }
								name="fallback_provider"
								label={ __(
									'Fallback provider',
									'wp-site-translator'
								) }
								options={ [
									{
										value: '',
										label: __(
											'None',
											'wp-site-translator'
										),
									},
									...providerOptions.filter(
										( option ) =>
											option.value !== draft.provider
									),
								] }
							/>
						</div>
						<FieldError error={ errors.providers } />
						<div className="wst-providers">
							{ providers.map( ( row ) => (
								<ProviderCard
									key={ row.id }
									row={ row }
									settings={
										( draft.providers || {} )[ row.id ]
									}
									savedSettings={
										( data.settings.providers || {} )[
											row.id
										]
									}
									setProviderValue={ setProviderValue(
										row.id
									) }
									secrets={ secrets }
									setSecret={ setSecret }
									errors={ errors }
									refreshProviders={ refreshProviders }
								/>
							) ) }
						</div>
					</>
				) }
			</Section>
			<Section
				title={ __( 'Finding strings', 'wp-site-translator' ) }
				description={ __(
					'Strings are recorded when a translated page is visited or scanned.',
					'wp-site-translator'
				) }
			>
				<Toggle
					{ ...fields }
					name="discover_on_visit"
					label={ __(
						'Record new strings when visitors open translated pages',
						'wp-site-translator'
					) }
					help={ __(
						'Off: only scans record strings.',
						'wp-site-translator'
					) }
				/>
				<Toggle
					{ ...fields }
					name="block_crawlers"
					label={ __(
						'Ignore visits from search engines and other bots',
						'wp-site-translator'
					) }
					help={ __(
						'Bots still see existing translations but never add strings to the queue.',
						'wp-site-translator'
					) }
				/>
				<div className="wst-grid">
					<Integer
						{ ...fields }
						name="discovery_cap_page_hour"
						label={ __(
							'New strings per page per hour',
							'wp-site-translator'
						) }
					/>
					<Integer
						{ ...fields }
						name="discovery_cap_site_hour"
						label={ __(
							'New strings per site per hour',
							'wp-site-translator'
						) }
					/>
					<Integer
						{ ...fields }
						name="max_string_length"
						label={ __(
							'Longest string recorded (characters)',
							'wp-site-translator'
						) }
					/>
				</div>
				<Lines
					{ ...fields }
					name="discovery_query_args"
					rows={ 3 }
					label={ __(
						'Query arguments that make a page distinct (one per line)',
						'wp-site-translator'
					) }
					help={ __(
						'Other query strings are ignored when recording where a string appears.',
						'wp-site-translator'
					) }
				/>
				<Lines
					{ ...fields }
					name="never_discover_paths"
					rows={ 3 }
					label={ __(
						'Paths never recorded (one per line, * at the end matches a section)',
						'wp-site-translator'
					) }
				/>
			</Section>
			<Section
				title={ __(
					'Pages not translated automatically',
					'wp-site-translator'
				) }
			>
				<PathRules { ...fields } />
				<Choice
					{ ...fields }
					radio
					name="off_behavior"
					label={ __(
						'When a visitor opens the translated address of a page that is off',
						'wp-site-translator'
					) }
					options={ [
						{
							value: 'redirect',
							label: __(
								'Redirect to the original page',
								'wp-site-translator'
							),
						},
						{
							value: 'original',
							label: __(
								'Show the original text at the translated address',
								'wp-site-translator'
							),
						},
					] }
				/>
				<p className="wst-note">
					{ __(
						'Set the mode of single pages on the Pages screen or in the editor sidebar.',
						'wp-site-translator'
					) }
				</p>
			</Section>
		</>
	);
}

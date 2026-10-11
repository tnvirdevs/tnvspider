/**
 * Language switcher: style, colours, floating position, live preview and
 * how to place it (shortcode, block, menu item).
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Choice, FieldError, Integer, Section, Toggle } from '../fields';
import { languageName } from '../format';

const config = window.wstAdmin || { shortcode: '[wst_switcher]', menusUrl: '' };

const COLOR_LABELS = {
	text: __( 'Text', 'wp-site-translator' ),
	background: __( 'Background', 'wp-site-translator' ),
	accent: __( 'Accent (current language and focus)', 'wp-site-translator' ),
};

function label( language, draft ) {
	const code = language.code.toUpperCase();
	if ( draft.switcher_style === 'codes' ) {
		return code;
	}
	const name = languageName( language, draft.name_style );
	if ( draft.switcher_style === 'codes_names' ) {
		return `${ code } · ${ name }`;
	}
	return name;
}

/**
 * Same markup and classes as the PHP switcher, styled by assets/switcher.css.
 *
 * @param {Object}  props
 * @param {Object}  props.draft     Settings draft.
 * @param {Array}   props.languages Default and target language.
 * @param {boolean} props.floating  Floating variant.
 */
function Preview( { draft, languages, floating } ) {
	const classes = [
		'wst-switcher',
		`wst-switcher--${ draft.switcher_theme }`,
	];
	if ( floating ) {
		classes.push(
			'wst-switcher--floating',
			`wst-switcher--${ draft.switcher_position }`
		);
	}
	const style = {
		...( draft.switcher_theme === 'custom'
			? {
					'--wst-switcher-text': draft.switcher_colors.text,
					'--wst-switcher-bg': draft.switcher_colors.background,
					'--wst-switcher-accent': draft.switcher_colors.accent,
				}
			: {} ),
		...( floating && Number.isInteger( draft.switcher_offset )
			? { '--wst-switcher-offset': `${ draft.switcher_offset }px` }
			: {} ),
	};
	return (
		<nav
			className={ classes.join( ' ' ) }
			aria-label={ __( 'Language (preview)', 'wp-site-translator' ) }
			style={ style }
		>
			<ul className="wst-switcher__list">
				{ languages.map( ( language, index ) => (
					<li
						key={ language.locale }
						className={ `wst-switcher__item${ index === 0 ? ' is-current' : '' }` }
					>
						{ /* Preview only: links lead nowhere. */ }
						<a
							className="wst-switcher__link"
							href="#preview"
							hrefLang={ language.tag }
							lang={ language.tag }
							aria-current={ index === 0 ? 'true' : undefined }
							onClick={ ( event ) => event.preventDefault() }
						>
							{ label( language, draft ) }
						</a>
					</li>
				) ) }
			</ul>
		</nav>
	);
}

export default function Switcher( { draft, update, errors, languages } ) {
	const fields = { draft, update, errors };
	const [ copied, setCopied ] = useState( '' );
	const byLocale = Object.fromEntries(
		languages.map( ( language ) => [ language.locale, language ] )
	);
	const pair = [
		byLocale[ draft.default_language ],
		byLocale[ draft.target_language ],
	].filter( Boolean );

	const manual = __(
		'Copying is not available here: select the shortcode and copy it.',
		'wp-site-translator'
	);
	const copy = () => {
		if ( ! window.navigator.clipboard ) {
			setCopied( manual );
			return;
		}
		window.navigator.clipboard.writeText( config.shortcode ).then(
			() => setCopied( __( 'Copied.', 'wp-site-translator' ) ),
			() => setCopied( manual )
		);
	};

	return (
		<>
			<Section title={ __( 'Appearance', 'wp-site-translator' ) }>
				<div className="wst-switcher-settings">
					<div>
						<Choice
							{ ...fields }
							radio
							name="switcher_style"
							label={ __( 'Labels', 'wp-site-translator' ) }
							options={ [
								{
									value: 'names',
									label: __(
										'Language names',
										'wp-site-translator'
									),
								},
								{
									value: 'codes',
									label: __(
										'Short codes (EN, BN)',
										'wp-site-translator'
									),
								},
								{
									value: 'codes_names',
									label: __(
										'Codes and names',
										'wp-site-translator'
									),
								},
							] }
							help={ __(
								'Names follow the language name style on the Languages screen.',
								'wp-site-translator'
							) }
						/>
						<Choice
							{ ...fields }
							name="switcher_theme"
							label={ __( 'Colours', 'wp-site-translator' ) }
							options={ [
								{
									value: 'inherit',
									label: __(
										'Follow the theme',
										'wp-site-translator'
									),
								},
								{
									value: 'light',
									label: __( 'Light', 'wp-site-translator' ),
								},
								{
									value: 'dark',
									label: __( 'Dark', 'wp-site-translator' ),
								},
								{
									value: 'custom',
									label: __( 'Custom', 'wp-site-translator' ),
								},
							] }
						/>
						{ draft.switcher_theme === 'custom' && (
							<fieldset className="wst-fieldset wst-colors">
								<legend>
									{ __(
										'Custom colours',
										'wp-site-translator'
									) }
								</legend>
								{ Object.keys( COLOR_LABELS ).map( ( key ) => (
									<div className="wst-color" key={ key }>
										<label htmlFor={ `wst-color-${ key }` }>
											{ COLOR_LABELS[ key ] }
										</label>
										<input
											id={ `wst-color-${ key }` }
											type="color"
											value={
												draft.switcher_colors[ key ]
											}
											onChange={ ( event ) =>
												update( 'switcher_colors', {
													...draft.switcher_colors,
													[ key ]: event.target.value,
												} )
											}
										/>
										<code>
											{ draft.switcher_colors[ key ] }
										</code>
									</div>
								) ) }
								<FieldError error={ errors.switcher_colors } />
							</fieldset>
						) }
					</div>
					<div
						className="wst-preview"
						aria-label={ __(
							'Live preview',
							'wp-site-translator'
						) }
						role="group"
					>
						<h3>{ __( 'Live preview', 'wp-site-translator' ) }</h3>
						{ pair.length < 2 ? (
							<p className="wst-empty">
								{ __(
									'Choose a target language to see the preview.',
									'wp-site-translator'
								) }
							</p>
						) : (
							<>
								<div className="wst-preview__inline">
									<Preview
										draft={ draft }
										languages={ pair }
										floating={ false }
									/>
								</div>
								{ draft.switcher_floating && (
									<div
										className="wst-preview__page"
										aria-hidden="true"
										style={ {
											blockSize: `${ Math.max(
												160,
												( Number.isInteger(
													draft.switcher_offset
												)
													? draft.switcher_offset
													: 16 ) + 90
											) }px`,
										} }
									>
										<Preview
											draft={ draft }
											languages={ pair }
											floating
										/>
									</div>
								) }
							</>
						) }
					</div>
				</div>
			</Section>
			<Section title={ __( 'Floating switcher', 'wp-site-translator' ) }>
				<Toggle
					{ ...fields }
					name="switcher_floating"
					label={ __(
						'Show a floating switcher on every page',
						'wp-site-translator'
					) }
				/>
				{ draft.switcher_floating && (
					<Choice
						{ ...fields }
						name="switcher_position"
						label={ __( 'Position', 'wp-site-translator' ) }
						help={ __(
							'Left and right swap on right-to-left pages.',
							'wp-site-translator'
						) }
						options={ [
							{
								value: 'bottom-right',
								label: __(
									'Bottom right',
									'wp-site-translator'
								),
							},
							{
								value: 'bottom-left',
								label: __(
									'Bottom left',
									'wp-site-translator'
								),
							},
							{
								value: 'top-right',
								label: __( 'Top right', 'wp-site-translator' ),
							},
							{
								value: 'top-left',
								label: __( 'Top left', 'wp-site-translator' ),
							},
						] }
					/>
				) }
				{ draft.switcher_floating && (
					<Integer
						{ ...fields }
						name="switcher_offset"
						label={ __(
							'Distance from the top or bottom edge (pixels)',
							'wp-site-translator'
						) }
						help={ __(
							'Raise it when the theme has a fixed bar at that edge, for example a bottom navigation bar on phones. 0–400; default 16.',
							'wp-site-translator'
						) }
					/>
				) }
			</Section>
			<Section
				title={ __( 'Placing the switcher', 'wp-site-translator' ) }
			>
				<h3>{ __( 'Shortcode', 'wp-site-translator' ) }</h3>
				<p className="wst-copy">
					<code>{ config.shortcode }</code>
					<Button variant="secondary" onClick={ copy }>
						{ __( 'Copy shortcode', 'wp-site-translator' ) }
					</Button>
					<span aria-live="polite" className="wst-note">
						{ copied }
					</span>
				</p>
				<h3>{ __( 'Block', 'wp-site-translator' ) }</h3>
				<p>
					{ __(
						'In the block editor or the site editor, add the "Language switcher" block (for example to the header template).',
						'wp-site-translator'
					) }
				</p>
				<h3>{ __( 'Menu', 'wp-site-translator' ) }</h3>
				<p>
					{ sprintf(
						/* translators: %s: screen name */
						__(
							'In classic themes, open %s, find the "Language switcher" box and add it to a menu. It shows one link per language.',
							'wp-site-translator'
						),
						__( 'Appearance → Menus', 'wp-site-translator' )
					) }{ ' ' }
					{ config.menusUrl && (
						<a href={ config.menusUrl }>
							{ __( 'Open Menus', 'wp-site-translator' ) }
						</a>
					) }
				</p>
			</Section>
		</>
	);
}

/**
 * Languages: the two languages, URL prefixes and how names are shown.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Choice, Section, Text, Toggle } from '../fields';
import { languageOptions } from '../format';

function Direction( { language } ) {
	if ( ! language ) {
		return null;
	}
	return (
		<p className="wst-note">
			{ sprintf(
				/* translators: 1: language name, 2: direction */
				__(
					'%1$s is written %2$s (detected automatically).',
					'wp-site-translator'
				),
				language.english,
				language.rtl
					? __( 'right to left', 'wp-site-translator' )
					: __( 'left to right', 'wp-site-translator' )
			) }
		</p>
	);
}

export default function Languages( { draft, update, errors, languages } ) {
	const fields = { draft, update, errors };
	const byLocale = Object.fromEntries(
		languages.map( ( language ) => [ language.locale, language ] )
	);
	const defaultLanguage = byLocale[ draft.default_language ];
	const target = byLocale[ draft.target_language ];
	const options = languageOptions( languages );

	return (
		<>
			<Section
				title={ __( 'Languages', 'wp-site-translator' ) }
				description={ __(
					'The site is translated between exactly two languages. Visitors reach the target language under its URL prefix, for example /bn/about/.',
					'wp-site-translator'
				) }
			>
				<Choice
					{ ...fields }
					name="default_language"
					label={ __(
						'Default language (the language your content is written in)',
						'wp-site-translator'
					) }
					options={ options }
				/>
				<Direction language={ defaultLanguage } />
				<Choice
					{ ...fields }
					name="target_language"
					label={ __( 'Target language', 'wp-site-translator' ) }
					options={ [
						{
							value: '',
							label: __(
								'— Choose a language —',
								'wp-site-translator'
							),
						},
						...options.filter(
							( option ) =>
								option.value !== draft.default_language
						),
					] }
				/>
				<Direction language={ target } />
				<Text
					{ ...fields }
					name="target_slug"
					label={ __(
						'URL prefix of the target language',
						'wp-site-translator'
					) }
					placeholder={ target ? target.slug : '' }
					help={
						target
							? sprintf(
									/* translators: %s: default prefix */
									__(
										'Leave empty to use "%s". Lowercase letters, digits and hyphens.',
										'wp-site-translator'
									),
									target.slug
								)
							: __(
									'Choose a target language first.',
									'wp-site-translator'
								)
					}
				/>
			</Section>
			<Section
				title={ __( 'Default language URLs', 'wp-site-translator' ) }
				description={ __(
					'By default the original pages keep their URLs. With a prefix, they move under it (for example /en/about/) and the old URLs redirect permanently.',
					'wp-site-translator'
				) }
			>
				<Toggle
					{ ...fields }
					name="prefix_default"
					label={ __(
						'Add a language prefix to the default language',
						'wp-site-translator'
					) }
				/>
				{ draft.prefix_default && (
					<Text
						{ ...fields }
						name="default_slug"
						label={ __(
							'URL prefix of the default language',
							'wp-site-translator'
						) }
						placeholder={
							defaultLanguage ? defaultLanguage.slug : ''
						}
						help={ __(
							'Leave empty to use the language code. It must differ from the target prefix.',
							'wp-site-translator'
						) }
					/>
				) }
			</Section>
			<Section title={ __( 'Language names', 'wp-site-translator' ) }>
				<Choice
					{ ...fields }
					radio
					name="name_style"
					label={ __( 'Show language names', 'wp-site-translator' ) }
					options={ [
						{
							value: 'native',
							label: target
								? sprintf(
										/* translators: %s: example */
										__(
											'In their own language (%s)',
											'wp-site-translator'
										),
										target.native
									)
								: __(
										'In their own language',
										'wp-site-translator'
									),
						},
						{
							value: 'english',
							label: target
								? sprintf(
										/* translators: %s: example */
										__(
											'In English (%s)',
											'wp-site-translator'
										),
										target.english
									)
								: __( 'In English', 'wp-site-translator' ),
						},
					] }
				/>
			</Section>
		</>
	);
}

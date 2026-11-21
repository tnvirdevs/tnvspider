<?php
/**
 * Curated list of languages and their provider codes.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Languages;

/**
 * Looks up languages by WordPress locale. Unknown locales still work with
 * derived defaults, so the owner can pick any locale.
 */
final class Registry {

	/** Primary subtags written right to left. */
	private const RTL = array( 'ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'ku', 'ps', 'sd', 'ug', 'ur', 'yi' );

	/**
	 * Locale => [English name, native name, slug, provider code overrides].
	 * The slug defaults to the ISO code when null.
	 *
	 * @var array<string, array{0: string, 1: string, 2: string|null, 3: array<string, string>}>
	 */
	private const LANGUAGES = array(
		'af'    => array( 'Afrikaans', 'Afrikaans', null, array() ),
		'ar'    => array( 'Arabic', 'العربية', null, array() ),
		'bg_BG' => array( 'Bulgarian', 'Български', null, array() ),
		'bn_BD' => array( 'Bengali', 'বাংলা', null, array() ),
		'bs_BA' => array( 'Bosnian', 'Bosanski', null, array() ),
		'ca'    => array( 'Catalan', 'Català', null, array() ),
		'cs_CZ' => array( 'Czech', 'Čeština', null, array() ),
		'da_DK' => array( 'Danish', 'Dansk', null, array() ),
		'de_DE' => array( 'German', 'Deutsch', null, array() ),
		'el'    => array( 'Greek', 'Ελληνικά', null, array() ),
		'en_GB' => array( 'English (UK)', 'English (UK)', 'en-gb', array() ),
		'en_US' => array( 'English', 'English', null, array() ),
		'es_ES' => array( 'Spanish', 'Español', null, array() ),
		'et'    => array( 'Estonian', 'Eesti', null, array() ),
		'fa_IR' => array( 'Persian', 'فارسی', null, array() ),
		'fi'    => array( 'Finnish', 'Suomi', null, array() ),
		'fr_FR' => array( 'French', 'Français', null, array() ),
		'he_IL' => array( 'Hebrew', 'עברית', null, array( 'translatex' => 'iw' ) ),
		'hi_IN' => array( 'Hindi', 'हिन्दी', null, array() ),
		'hr'    => array( 'Croatian', 'Hrvatski', null, array() ),
		'hu_HU' => array( 'Hungarian', 'Magyar', null, array() ),
		'hy'    => array( 'Armenian', 'Հայերեն', null, array() ),
		'id_ID' => array( 'Indonesian', 'Bahasa Indonesia', null, array() ),
		'is_IS' => array( 'Icelandic', 'Íslenska', null, array() ),
		'it_IT' => array( 'Italian', 'Italiano', null, array() ),
		'ja'    => array( 'Japanese', '日本語', null, array() ),
		'ko_KR' => array( 'Korean', '한국어', null, array() ),
		'lb_LU' => array( 'Luxembourgish', 'Lëtzebuergesch', null, array() ),
		'lt_LT' => array( 'Lithuanian', 'Lietuvių', null, array() ),
		'lv'    => array( 'Latvian', 'Latviešu', null, array() ),
		'ms_MY' => array( 'Malay', 'Bahasa Melayu', null, array() ),
		'nb_NO' => array( 'Norwegian', 'Norsk bokmål', 'no', array( 'translatex' => 'no' ) ),
		'ne_NP' => array( 'Nepali', 'नेपाली', null, array() ),
		'nl_NL' => array( 'Dutch', 'Nederlands', null, array() ),
		'pl_PL' => array( 'Polish', 'Polski', null, array() ),
		'ps'    => array( 'Pashto', 'پښتو', null, array() ),
		'pt_BR' => array( 'Portuguese (Brazil)', 'Português do Brasil', 'pt-br', array() ),
		'pt_PT' => array( 'Portuguese', 'Português', null, array( 'microsoft' => 'pt-pt' ) ),
		'ro_RO' => array( 'Romanian', 'Română', null, array() ),
		'ru_RU' => array( 'Russian', 'Русский', null, array() ),
		'si_LK' => array( 'Sinhala', 'සිංහල', null, array() ),
		'sk_SK' => array( 'Slovak', 'Slovenčina', null, array() ),
		'sl_SI' => array( 'Slovenian', 'Slovenščina', null, array() ),
		'sq'    => array( 'Albanian', 'Shqip', null, array() ),
		'sr_RS' => array( 'Serbian', 'Српски', null, array( 'microsoft' => 'sr-Cyrl' ) ),
		'sv_SE' => array( 'Swedish', 'Svenska', null, array() ),
		'sw'    => array( 'Swahili', 'Kiswahili', null, array() ),
		'ta_IN' => array( 'Tamil', 'தமிழ்', null, array() ),
		'th'    => array( 'Thai', 'ไทย', null, array() ),
		'tl'    => array( 'Filipino', 'Filipino', null, array( 'microsoft' => 'fil' ) ),
		'tr_TR' => array( 'Turkish', 'Türkçe', null, array() ),
		'uk'    => array( 'Ukrainian', 'Українська', null, array() ),
		'ur'    => array( 'Urdu', 'اردو', null, array() ),
		'vi'    => array( 'Vietnamese', 'Tiếng Việt', null, array() ),
		'zh_CN' => array(
			'Chinese (Simplified)',
			'简体中文',
			'zh',
			array(
				'translatex' => 'zh-CN',
				'microsoft'  => 'zh-Hans',
			),
		),
		'zh_TW' => array(
			'Chinese (Traditional)',
			'繁體中文',
			'zh-tw',
			array(
				'translatex' => 'zh-TW',
				'microsoft'  => 'zh-Hant',
			),
		),
	);

	/**
	 * Language for a locale. Unknown but well-formed locales get derived
	 * defaults (name = locale, slug = ISO code).
	 *
	 * @param string $locale WordPress locale.
	 * @throws \InvalidArgumentException For a malformed locale.
	 */
	public function get( string $locale ): Language {
		if ( 1 !== preg_match( '/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})*$/', $locale ) ) {
			throw new \InvalidArgumentException( 'Invalid locale: ' . esc_html( $locale ) );
		}

		$iso = strtolower( (string) strtok( $locale, '_' ) );
		$dir = in_array( $iso, self::RTL, true ) ? 'rtl' : 'ltr';

		if ( isset( self::LANGUAGES[ $locale ] ) ) {
			[ $english, $native, $slug, $codes ] = self::LANGUAGES[ $locale ];

			return new Language( $locale, $slug ?? $iso, $english, $native, $dir, $codes );
		}

		return new Language( $locale, $iso, $locale, $locale, $dir );
	}

	/**
	 * Whether the locale is in the curated list.
	 *
	 * @param string $locale WordPress locale.
	 */
	public function isKnown( string $locale ): bool {
		return isset( self::LANGUAGES[ $locale ] );
	}

	/**
	 * Every curated locale.
	 *
	 * @return list<string>
	 */
	public function locales(): array {
		return array_keys( self::LANGUAGES );
	}
}

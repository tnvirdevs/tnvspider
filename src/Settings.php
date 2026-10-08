<?php
/**
 * Plugin settings stored in one option (plan §4).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST;

use WST\Languages\Language;
use WST\Html\Selectors;
use WST\Languages\Registry;
use WST\Log\Logger;

/**
 * Validated, typed view of the wst_settings option. Only settings that
 * have a real effect are defined here; later phases add theirs together
 * with the behaviour.
 */
final class Settings {

	public const OPTION = Config::PREFIX . 'settings';

	/** Bump when the stored shape changes. */
	public const SCHEMA = 1;

	public const MODE_AUTO   = 'auto';
	public const MODE_MANUAL = 'manual';
	public const MODE_OFF    = 'off';

	/** Modes a path rule or a page can set (plan §9). */
	public const MODES = array( self::MODE_AUTO, self::MODE_MANUAL, self::MODE_OFF );

	/** What a target-language URL of an "off" page does: redirect to the original URL, or show the original text. */
	public const OFF_REDIRECT = 'redirect';
	public const OFF_ORIGINAL = 'original';

	/** A URL slug: lowercase letters, digits and hyphens, up to 20 characters. */
	public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,19}$/';

	/** Language names in the switcher: in the language itself, or in English. */
	public const NAME_STYLES = array( 'native', 'english' );

	/** Switcher labels: full names, short codes (EN, BN), or both. Text only (flags: HANDOVER gate). */
	public const SWITCHER_STYLES = array( 'names', 'codes', 'codes_names' );

	/** Corner of the floating switcher. */
	public const SWITCHER_POSITIONS = array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' );

	/** Default distance of the floating switcher from the top or bottom edge, in pixels. */
	public const SWITCHER_OFFSET = 16;

	/** Largest vertical offset accepted, in pixels. */
	public const SWITCHER_OFFSET_MAX = 400;

	/** JSON keys whose plain-text values are translated in AJAX/REST responses (plan §13A.5). */
	public const DYNAMIC_JSON_KEYS = array( 'message', 'messages', 'notice', 'notices', 'error', 'errors', 'html', 'content', 'label', 'title' );

	/** Where the visitor IP for the lookup rate limit comes from: REMOTE_ADDR, CF-Connecting-IP, X-Forwarded-For or a custom header. */
	public const TRUSTED_PROXIES = array( 'none', 'cloudflare', 'forwarded', 'custom' );

	/**
	 * Digit conversion on target pages (plan §13A.6). "auto" = Arabic-Indic
	 * digits for an Arabic target (owner decision), off for any other.
	 */
	public const DIGITS_MODES = array( 'auto', 'off', 'bengali', 'arabic_indic', 'persian' );

	/** Browser-language suggestion (plan §13A.7): off, a bar that asks, or an automatic first-visit redirect. */
	public const SUGGESTION_MODES = array( 'off', 'bar', 'redirect' );

	/** Longest suggestion bar text. */
	public const SUGGESTION_TEXT_MAX = 200;

	/** JSON keys and header names: letters, digits, "_" and "-". */
	private const KEY_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

	/** Switcher colours: follow the theme, light, dark, or custom colours. */
	public const SWITCHER_THEMES = array( 'inherit', 'light', 'dark', 'custom' );

	/** Default custom colours. */
	public const SWITCHER_COLORS = array(
		'text'       => '#1e1e1e',
		'background' => '#ffffff',
		'accent'     => '#2271b1',
	);

	/** Most path rules kept. */
	public const MAX_PATH_RULES = 200;

	/** Providers known to V1. */
	public const PROVIDER_IDS = array( 'translatex', 'microsoft', 'gemini' );

	/** Never-translate terms kept (plan §13A.1). */
	public const MAX_TERMS = 500;

	/** Per-provider integer settings: key => [minimum, maximum]. 0 means unlimited or "provider cap". */
	public const PROVIDER_INTS = array(
		'requests_per_minute'   => array( 0, 10000 ),
		'requests_per_day'      => array( 0, 10000000 ),
		'chars_per_minute'      => array( 0, 100000000 ),
		'max_items_per_request' => array( 0, 10000 ),
		'max_chars_per_request' => array( 0, 1000000 ),
		'concurrency'           => array( 1, 5 ),
		'max_attempts'          => array( 1, 20 ),
		'monthly_char_cap'      => array( 0, 1000000000000 ),
		'timeout'               => array( 5, 120 ),
	);

	/**
	 * Sanitised values.
	 *
	 * @var array{
	 *     default_language: string,
	 *     target_language: string,
	 *     target_slug: string,
	 *     default_slug: string,
	 *     prefix_default: bool,
	 *     name_style: string,
	 *     switcher_style: string,
	 *     switcher_floating: bool,
	 *     switcher_position: string,
	 *     switcher_offset: int,
	 *     switcher_theme: string,
	 *     switcher_colors: array{text: string, background: string, accent: string},
	 *     site_mode: string,
	 *     discover_on_visit: bool,
	 *     block_crawlers: bool,
	 *     force_language_links: bool,
	 *     hreflang_x_default: bool,
	 *     hreflang_drop_region: bool,
	 *     discovery_query_args: list<string>,
	 *     never_discover_paths: list<string>,
	 *     exclude_selectors: list<string>,
	 *     log_level: string,
	 *     delete_on_uninstall: bool,
	 *     path_rules: list<array{path: string, mode: string}>,
	 *     off_behavior: string,
	 *     discovery_cap_page_hour: int,
	 *     discovery_cap_site_hour: int,
	 *     max_string_length: int,
	 *     provider: string,
	 *     fallback_provider: string,
	 *     providers: array<string, array<string, int|string>>,
	 *     never_translate_terms: list<string>,
	 *     terms_case_insensitive: bool,
	 *     terms_whole_word: bool,
	 *     editor_mt_on_manual: bool,
	 *     trp_switcher_alias: bool,
	 *     dynamic_fragments: bool,
	 *     dynamic_json_keys: list<string>,
	 *     dynamic_lookup: bool,
	 *     trusted_proxy: string,
	 *     trusted_proxy_header: string,
	 *     digits_mode: string,
	 *     digits_skip_prices: bool,
	 *     lang_suggestion: string,
	 *     suggestion_position: string,
	 *     suggestion_text_default: string,
	 *     suggestion_text_target: string
	 * }
	 */
	private array $values;

	/**
	 * Language registry.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Build settings from raw (stored or submitted) values.
	 *
	 * @param array<string, mixed> $raw        Raw values; unknown keys are dropped.
	 * @param string               $siteLocale Site locale, the default for default_language.
	 * @param Registry             $registry   Language registry.
	 */
	public function __construct( array $raw, string $siteLocale, Registry $registry ) {
		$this->registry = $registry;
		$this->values   = self::sanitize( $raw, $siteLocale );
	}

	/**
	 * Settings from the stored option.
	 */
	public static function load(): self {
		$raw = get_option( self::OPTION, array() );

		return new self( is_array( $raw ) ? $raw : array(), self::siteLocale(), new Registry() );
	}

	/**
	 * The site's configured locale, read the way core's get_locale() does but
	 * without the 'locale' filter, which the Router changes on target requests.
	 */
	public static function siteLocale(): string {
		$option = get_option( 'WPLANG' );
		if ( is_string( $option ) && '' !== $option ) {
			return $option;
		}
		if ( defined( 'WPLANG' ) && is_string( WPLANG ) && '' !== WPLANG ) {
			return WPLANG;
		}

		return 'en_US';
	}

	/**
	 * Values in storable form.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array( 'schema' => self::SCHEMA ) + $this->values;
	}

	/**
	 * The site's own language.
	 */
	public function defaultLanguage(): Language {
		$language = $this->registry->get( $this->values['default_language'] );

		return '' === $this->values['default_slug'] ? $language : $language->withSlug( $this->values['default_slug'] );
	}

	/**
	 * Slug of the default language's URL prefix when "prefix the default
	 * language" is on and the slug differs from the target's, else null.
	 */
	public function defaultPrefix(): ?string {
		$target = $this->targetLanguage();
		$slug   = $this->defaultLanguage()->slug();

		return $this->values['prefix_default'] && ( null === $target || $target->slug() !== $slug ) ? $slug : null;
	}

	/**
	 * The translation target, or null while none is configured.
	 */
	public function targetLanguage(): ?Language {
		if ( '' === $this->values['target_language'] ) {
			return null;
		}
		$language = $this->registry->get( $this->values['target_language'] );

		return '' === $this->values['target_slug'] ? $language : $language->withSlug( $this->values['target_slug'] );
	}

	/**
	 * Site-wide mode: auto or manual.
	 */
	public function siteMode(): string {
		return $this->values['site_mode'];
	}

	/**
	 * Boolean setting.
	 *
	 * @param string $key One of discover_on_visit, block_crawlers, force_language_links,
	 *                    hreflang_x_default, hreflang_drop_region, editor_mt_on_manual,
	 *                    trp_switcher_alias.
	 */
	public function flag( string $key ): bool {
		return (bool) ( $this->values[ $key ] ?? false );
	}

	/**
	 * Integer setting.
	 *
	 * @param string $key One of discovery_cap_page_hour, discovery_cap_site_hour, max_string_length, switcher_offset.
	 */
	public function number( string $key ): int {
		return (int) ( $this->values[ $key ] ?? 0 );
	}

	/**
	 * Query arguments a URL may carry and still be discovered.
	 *
	 * @return list<string>
	 */
	public function discoveryQueryArgs(): array {
		return $this->values['discovery_query_args'];
	}

	/**
	 * Path rules excluded from discovery (trailing * wildcard, {{home}} token).
	 *
	 * @return list<string>
	 */
	public function neverDiscoverPaths(): array {
		return $this->values['never_discover_paths'];
	}

	/**
	 * Primary provider id, or '' when none is chosen.
	 */
	public function provider(): string {
		return $this->values['provider'];
	}

	/**
	 * Fallback provider id, or ''.
	 */
	public function fallbackProvider(): string {
		return $this->values['fallback_provider'];
	}

	/**
	 * Stored overrides of one provider; missing keys use the provider defaults.
	 *
	 * @param string $id Provider id.
	 * @return array<string, int|string>
	 */
	public function providerSettings( string $id ): array {
		return $this->values['providers'][ $id ] ?? array();
	}

	/**
	 * JSON keys whose plain-text values are translated in AJAX/REST responses.
	 *
	 * @return list<string>
	 */
	public function dynamicJsonKeys(): array {
		return $this->values['dynamic_json_keys'];
	}

	/**
	 * Suggestion bar text written in a language: "default" or "target" ('' = use the language name).
	 *
	 * @param string $which default or target.
	 */
	public function suggestionText( string $which ): string {
		return 'target' === $which ? $this->values['suggestion_text_target'] : $this->values['suggestion_text_default'];
	}

	/**
	 * Effective digit conversion: off, bengali, arabic_indic or persian.
	 * "auto" means Arabic-Indic digits for an Arabic target language, off otherwise.
	 */
	public function digitsMode(): string {
		$mode = $this->values['digits_mode'];
		if ( 'auto' !== $mode ) {
			return $mode;
		}

		return str_starts_with( $this->values['target_language'], 'ar' ) ? 'arabic_indic' : 'off';
	}

	/**
	 * Custom header with the visitor IP (trusted_proxy = custom), or ''.
	 */
	public function trustedProxyHeader(): string {
		return $this->values['trusted_proxy_header'];
	}

	/**
	 * A choice setting (name_style, switcher_style, switcher_position, switcher_theme).
	 *
	 * @param string $key Setting key.
	 * @throws \InvalidArgumentException For a key that is not a choice.
	 */
	public function choice( string $key ): string {
		if ( ! in_array( $key, array( 'name_style', 'switcher_style', 'switcher_position', 'switcher_theme', 'off_behavior', 'log_level', 'trusted_proxy', 'digits_mode', 'lang_suggestion', 'suggestion_position' ), true ) ) {
			throw new \InvalidArgumentException( 'Not a choice setting: ' . esc_html( $key ) );
		}

		return (string) $this->values[ $key ];
	}

	/**
	 * Custom switcher colours.
	 *
	 * @return array{text: string, background: string, accent: string}
	 */
	public function switcherColors(): array {
		return $this->values['switcher_colors'];
	}

	/**
	 * User exclude selectors (supported subset only, plan §13).
	 *
	 * @return list<string>
	 */
	public function excludeSelectors(): array {
		return $this->values['exclude_selectors'];
	}

	/**
	 * Path rules in order; the first match wins (plan §9).
	 *
	 * @return list<array{path: string, mode: string}>
	 */
	public function pathRules(): array {
		return $this->values['path_rules'];
	}

	/**
	 * What a target-language URL of an "off" page does: OFF_REDIRECT or OFF_ORIGINAL.
	 */
	public function offBehavior(): string {
		return $this->values['off_behavior'];
	}

	/**
	 * Never-translate terms.
	 *
	 * @return list<string>
	 */
	public function neverTranslateTerms(): array {
		return $this->values['never_translate_terms'];
	}

	/**
	 * Validate raw values. Invalid entries fall back to defaults.
	 *
	 * @param array<string, mixed> $raw        Raw values.
	 * @param string               $siteLocale Site locale.
	 * @return array{
	 *     default_language: string,
	 *     target_language: string,
	 *     target_slug: string,
	 *     default_slug: string,
	 *     prefix_default: bool,
	 *     name_style: string,
	 *     switcher_style: string,
	 *     switcher_floating: bool,
	 *     switcher_position: string,
	 *     switcher_offset: int,
	 *     switcher_theme: string,
	 *     switcher_colors: array{text: string, background: string, accent: string},
	 *     site_mode: string,
	 *     discover_on_visit: bool,
	 *     block_crawlers: bool,
	 *     force_language_links: bool,
	 *     hreflang_x_default: bool,
	 *     hreflang_drop_region: bool,
	 *     discovery_query_args: list<string>,
	 *     never_discover_paths: list<string>,
	 *     exclude_selectors: list<string>,
	 *     log_level: string,
	 *     delete_on_uninstall: bool,
	 *     path_rules: list<array{path: string, mode: string}>,
	 *     off_behavior: string,
	 *     discovery_cap_page_hour: int,
	 *     discovery_cap_site_hour: int,
	 *     max_string_length: int,
	 *     provider: string,
	 *     fallback_provider: string,
	 *     providers: array<string, array<string, int|string>>,
	 *     never_translate_terms: list<string>,
	 *     terms_case_insensitive: bool,
	 *     terms_whole_word: bool,
	 *     editor_mt_on_manual: bool,
	 *     trp_switcher_alias: bool,
	 *     dynamic_fragments: bool,
	 *     dynamic_json_keys: list<string>,
	 *     dynamic_lookup: bool,
	 *     trusted_proxy: string,
	 *     trusted_proxy_header: string,
	 *     digits_mode: string,
	 *     digits_skip_prices: bool,
	 *     lang_suggestion: string,
	 *     suggestion_position: string,
	 *     suggestion_text_default: string,
	 *     suggestion_text_target: string
	 * }
	 */
	private static function sanitize( array $raw, string $siteLocale ): array {
		$default = self::readLocale( $raw['default_language'] ?? '' );
		$default = '' === $default ? self::readLocale( $siteLocale ) : $default;
		$target  = self::readLocale( $raw['target_language'] ?? '' );
		$slug    = isset( $raw['target_slug'] ) && is_string( $raw['target_slug'] ) ? strtolower( trim( $raw['target_slug'], '/ ' ) ) : '';
		$dslug   = isset( $raw['default_slug'] ) && is_string( $raw['default_slug'] ) ? strtolower( trim( $raw['default_slug'], '/ ' ) ) : '';
		$mode    = $raw['site_mode'] ?? self::MODE_AUTO;

		return array(
			'default_language'        => '' === $default ? 'en_US' : $default,
			'target_language'         => $target === $default ? '' : $target,
			'target_slug'             => 1 === preg_match( self::SLUG_PATTERN, $slug ) ? $slug : '',
			'default_slug'            => 1 === preg_match( self::SLUG_PATTERN, $dslug ) ? $dslug : '',
			'prefix_default'          => self::readBool( $raw, 'prefix_default', false ),
			'name_style'              => self::readChoice( $raw, 'name_style', self::NAME_STYLES ),
			'switcher_style'          => self::readChoice( $raw, 'switcher_style', self::SWITCHER_STYLES ),
			'switcher_floating'       => self::readBool( $raw, 'switcher_floating', false ),
			'switcher_position'       => self::readChoice( $raw, 'switcher_position', self::SWITCHER_POSITIONS ),
			'switcher_offset'         => self::readInt( $raw, 'switcher_offset', self::SWITCHER_OFFSET, self::SWITCHER_OFFSET_MAX ),
			'switcher_theme'          => self::readChoice( $raw, 'switcher_theme', self::SWITCHER_THEMES ),
			'switcher_colors'         => self::readColors( $raw['switcher_colors'] ?? array() ),
			'site_mode'               => self::MODE_MANUAL === $mode ? self::MODE_MANUAL : self::MODE_AUTO,
			'discover_on_visit'       => self::readBool( $raw, 'discover_on_visit', true ),
			'block_crawlers'          => self::readBool( $raw, 'block_crawlers', true ),
			'force_language_links'    => self::readBool( $raw, 'force_language_links', true ),
			'hreflang_x_default'      => self::readBool( $raw, 'hreflang_x_default', true ),
			'hreflang_drop_region'    => self::readBool( $raw, 'hreflang_drop_region', false ),
			'discovery_query_args'    => self::readList( $raw, 'discovery_query_args', array( 'paged' ) ),
			'never_discover_paths'    => self::readList( $raw, 'never_discover_paths', array() ),
			'log_level'               => self::readChoice( $raw, 'log_level', Logger::LEVELS ),
			'delete_on_uninstall'     => self::readBool( $raw, 'delete_on_uninstall', false ),
			'exclude_selectors'       => array_slice( array_values( array_filter( self::readList( $raw, 'exclude_selectors', array() ), array( Selectors::class, 'isValid' ) ) ), 0, Selectors::MAX ),
			'path_rules'              => self::readPathRules( $raw['path_rules'] ?? array() ),
			'off_behavior'            => self::OFF_ORIGINAL === ( $raw['off_behavior'] ?? '' ) ? self::OFF_ORIGINAL : self::OFF_REDIRECT,
			'discovery_cap_page_hour' => self::readInt( $raw, 'discovery_cap_page_hour', 100, 100000 ),
			'discovery_cap_site_hour' => self::readInt( $raw, 'discovery_cap_site_hour', 1000, 1000000 ),
			'max_string_length'       => self::readInt( $raw, 'max_string_length', 2000, 50000 ),
			'provider'                => self::readProviderId( $raw['provider'] ?? '' ),
			'fallback_provider'       => self::readFallback( $raw ),
			'providers'               => self::readProviders( $raw['providers'] ?? array() ),
			'never_translate_terms'   => array_slice( self::readList( $raw, 'never_translate_terms', array() ), 0, self::MAX_TERMS ),
			'terms_case_insensitive'  => self::readBool( $raw, 'terms_case_insensitive', true ),
			'terms_whole_word'        => self::readBool( $raw, 'terms_whole_word', true ),
			'editor_mt_on_manual'     => self::readBool( $raw, 'editor_mt_on_manual', true ),
			'trp_switcher_alias'      => self::readBool( $raw, 'trp_switcher_alias', true ),
			'dynamic_fragments'       => self::readBool( $raw, 'dynamic_fragments', true ),
			'dynamic_json_keys'       => array_values( array_filter( self::readList( $raw, 'dynamic_json_keys', self::DYNAMIC_JSON_KEYS ), static fn( string $key ): bool => 1 === preg_match( self::KEY_PATTERN, $key ) ) ),
			'dynamic_lookup'          => self::readBool( $raw, 'dynamic_lookup', false ),
			'trusted_proxy'           => self::readChoice( $raw, 'trusted_proxy', self::TRUSTED_PROXIES ),
			'digits_mode'             => self::readChoice( $raw, 'digits_mode', self::DIGITS_MODES ),
			'digits_skip_prices'      => self::readBool( $raw, 'digits_skip_prices', true ),
			'lang_suggestion'         => self::readChoice( $raw, 'lang_suggestion', self::SUGGESTION_MODES ),
			'suggestion_position'     => self::readChoice( $raw, 'suggestion_position', array( 'bottom', 'top' ) ),
			'suggestion_text_default' => self::readShortText( $raw, 'suggestion_text_default' ),
			'suggestion_text_target'  => self::readShortText( $raw, 'suggestion_text_target' ),
			'trusted_proxy_header'    => isset( $raw['trusted_proxy_header'] ) && is_string( $raw['trusted_proxy_header'] ) && 1 === preg_match( self::KEY_PATTERN, trim( $raw['trusted_proxy_header'] ) ) ? trim( $raw['trusted_proxy_header'] ) : '',
		);
	}

	/**
	 * Path rules in order: path pattern ("/shop/*", "{{home}}", exact path)
	 * and mode. Invalid entries are dropped.
	 *
	 * @param mixed $value Raw value.
	 * @return list<array{path: string, mode: string}>
	 */
	private static function readPathRules( $value ): array {
		$rules = array();
		if ( ! is_array( $value ) ) {
			return $rules;
		}
		foreach ( $value as $rule ) {
			$path = is_array( $rule ) && is_string( $rule['path'] ?? null ) ? trim( $rule['path'] ) : '';
			$mode = is_array( $rule ) ? ( $rule['mode'] ?? '' ) : '';
			if ( ( '{{home}}' === $path || 1 === preg_match( '#^/[^\s?\#]{0,250}$#', $path ) ) && in_array( $mode, self::MODES, true ) ) {
				$rules[] = array(
					'path' => $path,
					'mode' => (string) $mode,
				);
			}
		}

		return array_slice( $rules, 0, self::MAX_PATH_RULES );
	}

	/**
	 * One of $choices (the first is the default).
	 *
	 * @param array<string, mixed> $raw     Raw values.
	 * @param string               $key     Key.
	 * @param string[]             $choices Allowed values (first = default).
	 * @phpstan-param list<string> $choices
	 */
	private static function readChoice( array $raw, string $key, array $choices ): string {
		$value = $raw[ $key ] ?? null;

		return is_string( $value ) && in_array( $value, $choices, true ) ? $value : $choices[0];
	}

	/**
	 * Custom switcher colours: #rgb or #rrggbb, defaults for anything else.
	 *
	 * @param mixed $value Raw value.
	 * @return array{text: string, background: string, accent: string}
	 */
	private static function readColors( $value ): array {
		$colors = self::SWITCHER_COLORS;
		foreach ( array_keys( $colors ) as $key ) {
			$given = is_array( $value ) ? ( $value[ $key ] ?? null ) : null;
			if ( is_string( $given ) && 1 === preg_match( '/^#(?:[0-9a-f]{3}){1,2}$/i', $given ) ) {
				$colors[ $key ] = strtolower( $given );
			}
		}

		return $colors;
	}

	/**
	 * A known provider id, or ''.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function readProviderId( $value ): string {
		return is_string( $value ) && in_array( $value, self::PROVIDER_IDS, true ) ? $value : '';
	}

	/**
	 * Fallback provider: known, and different from the primary.
	 *
	 * @param array<string, mixed> $raw Raw values.
	 */
	private static function readFallback( array $raw ): string {
		$fallback = self::readProviderId( $raw['fallback_provider'] ?? '' );

		return self::readProviderId( $raw['provider'] ?? '' ) === $fallback ? '' : $fallback;
	}

	/**
	 * Per-provider overrides. Only valid keys that were given are kept;
	 * missing keys mean "use the provider default" (plan §8).
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, array<string, int|string>>
	 */
	private static function readProviders( $value ): array {
		$clean = array();
		if ( ! is_array( $value ) ) {
			return $clean;
		}
		foreach ( self::PROVIDER_IDS as $id ) {
			$given = $value[ $id ] ?? null;
			if ( ! is_array( $given ) ) {
				continue;
			}
			foreach ( self::PROVIDER_INTS as $key => [ $min, $max ] ) {
				if ( ! isset( $given[ $key ] ) ) {
					continue;
				}
				$number = filter_var( $given[ $key ], FILTER_VALIDATE_INT );
				if ( false !== $number && $number >= $min ) {
					$clean[ $id ][ $key ] = min( $number, $max );
				}
			}
			$plan = $given['plan'] ?? null;
			if ( 'translatex' === $id && is_string( $plan ) && in_array( $plan, array( 'free', 'startup', 'business', 'enterprise' ), true ) ) {
				$clean[ $id ]['plan'] = $plan;
			}
			$endpoint = $given['endpoint'] ?? null;
			if ( 'microsoft' === $id && is_string( $endpoint ) && 1 === preg_match( '#^https://[a-z0-9.\-]+(/[\w./\-]*)?$#i', $endpoint ) ) {
				$clean[ $id ]['endpoint'] = rtrim( $endpoint, '/' );
			}
			$model = $given['model'] ?? null;
			if ( 'gemini' === $id && is_string( $model ) && 1 === preg_match( '/^[a-z0-9][a-z0-9.\-]{1,63}$/', $model ) ) {
				$clean[ $id ]['model'] = $model;
			}
		}

		return $clean;
	}

	/**
	 * A well-formed locale, or ''.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function readLocale( $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})*$/', $value ) ? $value : '';
	}

	/**
	 * Single-line plain text, at most SUGGESTION_TEXT_MAX characters.
	 *
	 * @param array<string, mixed> $raw Raw values.
	 * @param string               $key Setting key.
	 */
	private static function readShortText( array $raw, string $key ): string {
		$value = isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? sanitize_text_field( $raw[ $key ] ) : '';

		return mb_substr( $value, 0, self::SUGGESTION_TEXT_MAX );
	}

	/**
	 * Boolean setting.
	 *
	 * @param array<string, mixed> $raw      Raw values.
	 * @param string               $key      Setting key.
	 * @param bool                 $fallback Default.
	 */
	private static function readBool( array $raw, string $key, bool $fallback ): bool {
		return isset( $raw[ $key ] ) ? (bool) filter_var( $raw[ $key ], FILTER_VALIDATE_BOOLEAN ) : $fallback;
	}

	/**
	 * Non-negative integer setting, clamped to $max.
	 *
	 * @param array<string, mixed> $raw      Raw values.
	 * @param string               $key      Setting key.
	 * @param int                  $fallback Default.
	 * @param int                  $max      Upper bound.
	 */
	private static function readInt( array $raw, string $key, int $fallback, int $max ): int {
		$value = isset( $raw[ $key ] ) ? filter_var( $raw[ $key ], FILTER_VALIDATE_INT ) : false;

		return false === $value || $value < 0 ? $fallback : min( $value, $max );
	}

	/**
	 * List of non-empty trimmed strings.
	 *
	 * @param array<string, mixed> $raw      Raw values.
	 * @param string               $key      Setting key.
	 * @param string[]             $fallback Default.
	 * @phpstan-param list<string> $fallback
	 * @return list<string>
	 */
	private static function readList( array $raw, string $key, array $fallback ): array {
		if ( ! isset( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
			return $fallback;
		}
		$items = array();
		foreach ( $raw[ $key ] as $item ) {
			$item = is_scalar( $item ) ? trim( (string) $item ) : '';
			if ( '' !== $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}
}

<?php
/**
 * Plugin settings stored in one option (plan §4).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST;

use WST\Languages\Language;
use WST\Languages\Registry;

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
	 *     site_mode: string,
	 *     discover_on_visit: bool,
	 *     block_crawlers: bool,
	 *     force_language_links: bool,
	 *     hreflang_x_default: bool,
	 *     hreflang_drop_region: bool,
	 *     discovery_query_args: list<string>,
	 *     never_discover_paths: list<string>,
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
	 *     terms_whole_word: bool
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
		return $this->registry->get( $this->values['default_language'] );
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
	 *                    hreflang_x_default, hreflang_drop_region.
	 */
	public function flag( string $key ): bool {
		return (bool) ( $this->values[ $key ] ?? false );
	}

	/**
	 * Integer setting.
	 *
	 * @param string $key One of discovery_cap_page_hour, discovery_cap_site_hour, max_string_length.
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
	 *     site_mode: string,
	 *     discover_on_visit: bool,
	 *     block_crawlers: bool,
	 *     force_language_links: bool,
	 *     hreflang_x_default: bool,
	 *     hreflang_drop_region: bool,
	 *     discovery_query_args: list<string>,
	 *     never_discover_paths: list<string>,
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
	 *     terms_whole_word: bool
	 * }
	 */
	private static function sanitize( array $raw, string $siteLocale ): array {
		$default = self::readLocale( $raw['default_language'] ?? '' );
		$default = '' === $default ? self::readLocale( $siteLocale ) : $default;
		$target  = self::readLocale( $raw['target_language'] ?? '' );
		$slug    = isset( $raw['target_slug'] ) && is_string( $raw['target_slug'] ) ? strtolower( trim( $raw['target_slug'], '/ ' ) ) : '';
		$mode    = $raw['site_mode'] ?? self::MODE_AUTO;

		return array(
			'default_language'        => '' === $default ? 'en_US' : $default,
			'target_language'         => $target === $default ? '' : $target,
			'target_slug'             => 1 === preg_match( '/^[a-z0-9][a-z0-9-]{0,19}$/', $slug ) ? $slug : '',
			'site_mode'               => self::MODE_MANUAL === $mode ? self::MODE_MANUAL : self::MODE_AUTO,
			'discover_on_visit'       => self::readBool( $raw, 'discover_on_visit', true ),
			'block_crawlers'          => self::readBool( $raw, 'block_crawlers', true ),
			'force_language_links'    => self::readBool( $raw, 'force_language_links', true ),
			'hreflang_x_default'      => self::readBool( $raw, 'hreflang_x_default', true ),
			'hreflang_drop_region'    => self::readBool( $raw, 'hreflang_drop_region', false ),
			'discovery_query_args'    => self::readList( $raw, 'discovery_query_args', array( 'paged' ) ),
			'never_discover_paths'    => self::readList( $raw, 'never_discover_paths', array() ),
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

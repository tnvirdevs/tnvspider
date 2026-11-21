<?php
/**
 * One language the site can be served in.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Languages;

/**
 * Immutable language description, keyed by WordPress locale.
 */
final class Language {

	/**
	 * Create a language.
	 *
	 * @param string                $locale      WordPress locale, e.g. bn_BD.
	 * @param string                $slug        URL slug, e.g. bn.
	 * @param string                $englishName Name in English.
	 * @param string                $nativeName  Name in the language itself.
	 * @param string                $dir         Text direction: ltr or rtl.
	 * @param array<string, string> $codes       Provider id => language code, where it differs from the ISO code.
	 */
	public function __construct(
		private string $locale,
		private string $slug,
		private string $englishName,
		private string $nativeName,
		private string $dir,
		private array $codes = array()
	) {
	}

	/**
	 * WordPress locale, e.g. bn_BD.
	 */
	public function locale(): string {
		return $this->locale;
	}

	/**
	 * URL slug used in the language prefix.
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * Name in English.
	 */
	public function englishName(): string {
		return $this->englishName;
	}

	/**
	 * Name in the language itself.
	 */
	public function nativeName(): string {
		return $this->nativeName;
	}

	/**
	 * Text direction, ltr or rtl.
	 */
	public function dir(): string {
		return $this->dir;
	}

	/**
	 * Whether the language is written right to left.
	 */
	public function isRtl(): bool {
		return 'rtl' === $this->dir;
	}

	/**
	 * ISO 639 code: the locale's primary subtag, e.g. bn.
	 */
	public function isoCode(): string {
		return strtolower( (string) strtok( $this->locale, '_' ) );
	}

	/**
	 * BCP 47 tag for lang and hreflang attributes, e.g. bn-BD or bn.
	 *
	 * @param bool $dropRegion Whether to return only the primary subtag.
	 */
	public function tag( bool $dropRegion = false ): string {
		return $dropRegion ? $this->isoCode() : str_replace( '_', '-', $this->locale );
	}

	/**
	 * Language code a provider expects.
	 *
	 * @param string $provider Provider id.
	 */
	public function providerCode( string $provider ): string {
		return $this->codes[ $provider ] ?? $this->isoCode();
	}

	/**
	 * Copy of this language with another URL slug.
	 *
	 * @param string $slug URL slug.
	 */
	public function withSlug( string $slug ): self {
		return new self( $this->locale, $slug, $this->englishName, $this->nativeName, $this->dir, $this->codes );
	}
}

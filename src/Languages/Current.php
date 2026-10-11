<?php
/**
 * The language of the current request.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Languages;

/**
 * Single global accessor for the active language (plan §5). Set by the
 * Router once per request; everything else only reads it.
 */
final class Current {

	/**
	 * Active language, null until the Router has run.
	 *
	 * @var Language|null
	 */
	private static ?Language $language = null;

	/**
	 * Whether the active language is the translation target.
	 *
	 * @var bool
	 */
	private static bool $isTarget = false;

	/**
	 * Set the active language.
	 *
	 * @param Language $language Active language.
	 * @param bool     $isTarget Whether it is the target language.
	 */
	public static function set( Language $language, bool $isTarget ): void {
		self::$language = $language;
		self::$isTarget = $isTarget;
	}

	/**
	 * Forget the active language (default-language behaviour).
	 */
	public static function reset(): void {
		self::$language = null;
		self::$isTarget = false;
	}

	/**
	 * Active language, or null when the request is not routed.
	 */
	public static function language(): ?Language {
		return self::$language;
	}

	/**
	 * Whether the request is served in the target language.
	 */
	public static function isTarget(): bool {
		return self::$isTarget;
	}
}

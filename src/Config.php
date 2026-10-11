<?php
/**
 * Every product name in one place, so a rename is a single edit.
 * The plugin header in wp-site-translator.php must be updated by hand.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST;

/**
 * Plugin-wide names and versions.
 */
final class Config {

	public const NAME           = 'WP Site Translator';
	public const VERSION        = '0.1.0';
	public const SLUG           = 'wp-site-translator';
	public const TEXT_DOMAIN    = 'wp-site-translator';
	public const PREFIX         = 'wst_';
	public const REST_NAMESPACE = 'wst/v1';
}

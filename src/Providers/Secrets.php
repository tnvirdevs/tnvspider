<?php
/**
 * API keys and other provider secrets (plan §3).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

use WST\Config;

/**
 * Resolves secrets by name, e.g. WST_TRANSLATEX_KEY: a PHP constant
 * (wp-config.php) wins, then an environment variable of the same name, then
 * the non-autoloaded wst_secrets option. Values never leave this class
 * except to the provider adapter; everything else gets masked().
 */
final class Secrets {

	public const OPTION = Config::PREFIX . 'secrets';

	/** Secret names V1 knows. */
	public const NAMES = array( 'WST_TRANSLATEX_KEY', 'WST_AZURE_KEY', 'WST_AZURE_REGION', 'WST_GEMINI_KEY' );

	/**
	 * Value of a secret, or '' when not configured.
	 *
	 * @param string $name One of self::NAMES.
	 * @throws \InvalidArgumentException For an unknown name.
	 */
	public function get( string $name ): string {
		if ( ! in_array( $name, self::NAMES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown secret.' );
		}
		if ( defined( $name ) ) {
			$value = constant( $name );

			return is_string( $value ) ? trim( $value ) : '';
		}
		$env = getenv( $name );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return trim( $env );
		}
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) && isset( $stored[ $name ] ) && is_string( $stored[ $name ] ) ? $stored[ $name ] : '';
	}

	/**
	 * Whether a secret is configured.
	 *
	 * @param string $name Secret name.
	 */
	public function has( string $name ): bool {
		return '' !== $this->get( $name );
	}

	/**
	 * Safe display form: the last four characters only.
	 *
	 * @param string $name Secret name.
	 */
	public function masked( string $name ): string {
		$value = $this->get( $name );

		return '' === $value ? '' : '••••' . substr( $value, -4 );
	}

	/**
	 * Store a secret in the non-autoloaded option ('' removes it).
	 *
	 * @param string $name  Secret name.
	 * @param string $value Value.
	 * @throws \InvalidArgumentException For an unknown name.
	 */
	public function set( string $name, string $value ): void {
		if ( ! in_array( $name, self::NAMES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown secret.' );
		}
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( '' === trim( $value ) ) {
			unset( $stored[ $name ] );
		} else {
			$stored[ $name ] = trim( $value );
		}
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Remove every configured secret value from a text, for messages and logs.
	 *
	 * @param string $text Text that may contain a secret.
	 */
	public function redact( string $text ): string {
		foreach ( self::NAMES as $name ) {
			$value = $this->get( $name );
			if ( strlen( $value ) >= 4 ) {
				$text = str_replace( $value, '[redacted]', $text );
			}
		}

		return (string) preg_replace( '/([?&](?:key|api_key|apikey)=)[^&\s]+/i', '$1[redacted]', $text );
	}
}

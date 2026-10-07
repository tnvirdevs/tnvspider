<?php
/**
 * Stored list of the language codes a provider supports.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

use WST\Config;
use WST\Providers\Errors\PermanentError;
use WST\Providers\Errors\TransientError;

/**
 * Pair checks run while rendering, so they read this stored list and never
 * call the provider. Before the first load a pair counts as supported, so
 * strings get queued; the adapter loads the list on demand before its first
 * request and refuses an unsupported pair then (see requireSupport()).
 * Connection tests also fill it; translation runs refresh it once a day.
 */
final class LanguageList {

	/** Age after which translation runs reload the list. */
	public const MAX_AGE = DAY_IN_SECONDS;

	/**
	 * Create the list for one provider.
	 *
	 * @param string $provider Provider id.
	 * @param string $label    Provider name for messages.
	 */
	public function __construct( private string $provider, private string $label ) {
	}

	/**
	 * Option name.
	 */
	public function option(): string {
		return Config::PREFIX . $this->provider . '_languages';
	}

	/**
	 * Whether both codes are supported; true while the list was never loaded.
	 *
	 * @param string $source Source code.
	 * @param string $target Target code.
	 */
	public function supports( string $source, string $target ): bool {
		$codes = $this->codes();

		return null === $codes || ( in_array( $source, $codes, true ) && in_array( $target, $codes, true ) );
	}

	/**
	 * Refuse a pair the loaded list does not contain (adapters call this
	 * after loading the list, before sending).
	 *
	 * @param string $source Source code.
	 * @param string $target Target code.
	 * @throws PermanentError When the pair is not supported.
	 */
	public function requireSupport( string $source, string $target ): void {
		if ( ! $this->supports( $source, $target ) ) {
			throw new PermanentError( esc_html( sprintf( '%s does not support %s to %s.', $this->label, $source, $target ) ) );
		}
	}

	/**
	 * Number of stored codes, or null when the list was never loaded.
	 */
	public function count(): ?int {
		$codes = $this->codes();

		return null === $codes ? null : count( $codes );
	}

	/**
	 * Whether the list is missing or older than MAX_AGE.
	 */
	public function isStale(): bool {
		$stored = get_option( $this->option() );

		return ! is_array( $stored ) || (int) ( $stored['fetched'] ?? 0 ) < time() - self::MAX_AGE;
	}

	/**
	 * Store codes received from the provider; invalid entries are dropped.
	 *
	 * @param array<mixed> $codes Codes.
	 * @return list<string> Stored codes.
	 * @throws TransientError When no valid code remains.
	 */
	public function store( array $codes ): array {
		$valid = array_values( array_filter( $codes, static fn( $code ): bool => is_string( $code ) && 1 === preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8}){0,2}$/', $code ) ) );
		if ( array() === $valid ) {
			throw new TransientError( esc_html( $this->label . ' returned an empty language list.' ) );
		}
		update_option(
			$this->option(),
			array(
				'fetched' => time(),
				'codes'   => $valid,
			),
			false
		);

		return $valid;
	}

	/**
	 * Stored codes, or null when never loaded.
	 *
	 * @return list<string>|null
	 */
	private function codes(): ?array {
		$stored = get_option( $this->option() );
		if ( ! is_array( $stored ) || ! isset( $stored['codes'] ) || ! is_array( $stored['codes'] ) ) {
			return null;
		}

		return array_values( array_filter( $stored['codes'], 'is_string' ) );
	}
}

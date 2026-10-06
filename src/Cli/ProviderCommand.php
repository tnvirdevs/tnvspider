<?php
/**
 * WP-CLI: wp wst provider test.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Cli;

use WST\Providers\Gemini;
use WST\Providers\Microsoft;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\TranslateX;
use WST\Settings;

/**
 * Check provider credentials.
 */
final class ProviderCommand {

	/** Key name per provider, for the "not configured" message. */
	private const KEYS = array(
		TranslateX::ID => TranslateX::SECRET,
		Microsoft::ID  => Microsoft::SECRET,
		Gemini::ID     => Gemini::SECRET,
	);

	/**
	 * Create the command.
	 *
	 * @param ProviderRegistry $providers Configured adapters.
	 * @param ProviderState    $state     Pauses.
	 */
	public function __construct(
		private ProviderRegistry $providers,
		private ProviderState $state
	) {
	}

	/**
	 * Test a provider's key with one small request. On success a pause after
	 * an earlier auth failure is lifted.
	 *
	 * ## OPTIONS
	 *
	 * <provider>
	 * : translatex, microsoft or gemini.
	 *
	 * @param string[]              $args  Positional arguments.
	 * @param array<string, string> $assoc Options.
	 * @phpstan-param list<string>  $args
	 */
	public function test( array $args, array $assoc ): void {
		unset( $assoc );
		$id = $args[0] ?? '';
		if ( ! in_array( $id, Settings::PROVIDER_IDS, true ) ) {
			\WP_CLI::error( 'Unknown provider. Use one of: ' . implode( ', ', Settings::PROVIDER_IDS ) . '.' );
		}
		$adapter = $this->providers->get( $id );
		if ( null === $adapter ) {
			\WP_CLI::error( sprintf( '%s is not configured: set %s (constant, environment variable or stored secret).', $id, self::KEYS[ $id ] ) );
		}
		$result = $adapter->testConnection();
		if ( ! $result->ok ) {
			\WP_CLI::error( $result->message );
		}
		$this->state->resume( $id );
		foreach ( $result->details as $name => $value ) {
			\WP_CLI::line( sprintf( '%s: %s', $name, is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) ) );
		}
		\WP_CLI::success( $result->message );
	}
}

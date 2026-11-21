<?php
/**
 * WP-CLI: wp wst provider test.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Cli;

use WST\Providers\Tester;
use WST\Settings;

/**
 * Check provider credentials.
 */
final class ProviderCommand {

	/**
	 * Create the command.
	 *
	 * @param Tester $tester Connection test.
	 */
	public function __construct( private Tester $tester ) {
	}

	/**
	 * Test a provider's key with one small request. On success a pause after
	 * an earlier auth failure is lifted and the provider counts as verified.
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
		$result = $this->tester->test( $id );
		if ( ! $result->ok ) {
			\WP_CLI::error( $result->message );
		}
		foreach ( $result->details as $name => $value ) {
			\WP_CLI::line( sprintf( '%s: %s', $name, is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) ) );
		}
		\WP_CLI::success( $result->message );
	}
}

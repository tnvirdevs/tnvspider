<?php
/**
 * Connection test shared by WP-CLI and the admin (plan §14 POST /providers/{id}/test).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

use WST\Settings;

/**
 * Runs a provider's connection test. On success it lifts a pause from an
 * earlier auth failure and records the credentials as verified; until then
 * the provider shows as "not tested yet".
 */
final class Tester {

	/**
	 * Create the tester.
	 *
	 * @param Settings         $settings  Settings.
	 * @param ProviderRegistry $providers Configured adapters.
	 * @param ProviderState    $state     Pauses, quotas, verification.
	 * @param Secrets          $secrets   Credentials.
	 */
	public function __construct(
		private Settings $settings,
		private ProviderRegistry $providers,
		private ProviderState $state,
		private Secrets $secrets
	) {
	}

	/**
	 * Test one provider.
	 *
	 * @param string $id Provider id.
	 * @throws \InvalidArgumentException For an unknown provider id.
	 */
	public function test( string $id ): TestResult {
		if ( ! in_array( $id, Settings::PROVIDER_IDS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown provider.' );
		}
		$adapter = $this->providers->get( $id );
		if ( null === $adapter ) {
			return new TestResult( false, sprintf( '%s is not configured: set %s.', $id, Secrets::BY_PROVIDER[ $id ][0] ) );
		}
		$result = $adapter->testConnection();
		if ( $result->ok ) {
			$this->state->resume( $id );
			$this->state->markVerified( $id, $this->fingerprint( $id ), time() );
		}

		return $result;
	}

	/**
	 * When the connection test last passed with the current credentials, or null.
	 *
	 * @param string $id Provider id.
	 */
	public function verifiedAt( string $id ): ?int {
		return $this->state->verifiedAt( $id, $this->fingerprint( $id ) );
	}

	/**
	 * Fingerprint of the provider's current credentials and connection settings.
	 *
	 * @param string $id Provider id.
	 */
	private function fingerprint( string $id ): string {
		return $this->secrets->fingerprint( $id, $this->settings->providerSettings( $id ) );
	}
}

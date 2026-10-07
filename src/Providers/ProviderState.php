<?php
/**
 * Paused providers and exhausted quotas.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

use WST\Config;

/**
 * A provider is unavailable while paused (auth failure, until the key works
 * again) or while its quota is used up (until the next budget period).
 */
final class ProviderState {

	public const OPTION = Config::PREFIX . 'provider_state';

	/**
	 * Pause a provider.
	 *
	 * @param string $id     Provider id.
	 * @param string $reason Why, shown to the owner.
	 */
	public function pause( string $id, string $reason ): void {
		$this->update( $id, array( 'paused' => $reason ) );
	}

	/**
	 * Clear the pause, e.g. after a successful connection test.
	 *
	 * @param string $id Provider id.
	 */
	public function resume( string $id ): void {
		$this->update( $id, array( 'paused' => '' ) );
	}

	/**
	 * Record a passed connection test for these credentials.
	 *
	 * @param string $id          Provider id.
	 * @param string $fingerprint Secrets::fingerprint() of the tested credentials.
	 * @param int    $at          Unix time.
	 */
	public function markVerified( string $id, string $fingerprint, int $at ): void {
		$this->update(
			$id,
			array(
				'verified_fp' => $fingerprint,
				'verified_at' => (string) $at,
			)
		);
	}

	/**
	 * When the connection test last passed for these credentials, or null
	 * ("not tested yet": never, or the key or connection settings changed).
	 *
	 * @param string $id          Provider id.
	 * @param string $fingerprint Current Secrets::fingerprint().
	 */
	public function verifiedAt( string $id, string $fingerprint ): ?int {
		$state = $this->all()[ $id ] ?? array();

		return isset( $state['verified_fp'], $state['verified_at'] ) && hash_equals( (string) $state['verified_fp'], $fingerprint ) ? (int) $state['verified_at'] : null;
	}

	/**
	 * Record that the quota is used up for a period.
	 *
	 * @param string $id     Provider id.
	 * @param string $period Budget period.
	 */
	public function quotaExceeded( string $id, string $period ): void {
		$this->update( $id, array( 'quota_period' => $period ) );
	}

	/**
	 * Why a provider cannot be used now, or '' when it can.
	 *
	 * @param string $id     Provider id.
	 * @param string $period Current budget period.
	 */
	public function unavailableReason( string $id, string $period ): string {
		$state = $this->all()[ $id ] ?? array();
		if ( '' !== ( $state['paused'] ?? '' ) ) {
			return (string) $state['paused'];
		}

		return ( $state['quota_period'] ?? '' ) === $period ? 'Quota used up for ' . $period . '.' : '';
	}

	/**
	 * Merge values into a provider's state.
	 *
	 * @param string                $id     Provider id.
	 * @param array<string, string> $values Values.
	 */
	private function update( string $id, array $values ): void {
		$all        = $this->all();
		$all[ $id ] = array_merge( $all[ $id ] ?? array(), $values );
		update_option( self::OPTION, $all, true );
	}

	/**
	 * Stored state.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function all(): array {
		$value = get_option( self::OPTION, array() );

		return is_array( $value ) ? $value : array();
	}
}

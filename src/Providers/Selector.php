<?php
/**
 * Which provider translates right now (plan §8, fallback rules).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

use WST\Languages\Language;
use WST\Providers\Errors\ProviderError;
use WST\Settings;

/**
 * The primary provider unless it is missing, paused, out of quota or does
 * not support the pair; then the fallback, if that one is usable. Recomputed
 * on every call, so traffic returns to the primary as soon as it recovers.
 */
final class Selector {

	/**
	 * Create the selector.
	 *
	 * @param Settings         $settings Settings.
	 * @param ProviderRegistry $registry Adapters.
	 * @param ProviderState    $state    Pauses and quotas.
	 */
	public function __construct(
		private Settings $settings,
		private ProviderRegistry $registry,
		private ProviderState $state
	) {
	}

	/**
	 * Active provider for a pair, with the reason when it is the fallback.
	 *
	 * @param Language $source Source language.
	 * @param Language $target Target language.
	 * @param string   $period Current budget period.
	 * @return array{id: string, fallbackReason: string}|null Null when no provider can be used.
	 */
	public function active( Language $source, Language $target, string $period ): ?array {
		$primary = $this->settings->provider();
		$reason  = '' === $primary ? 'No provider is selected.' : $this->problem( $primary, $source, $target, $period );
		if ( '' === $reason ) {
			return array(
				'id'             => $primary,
				'fallbackReason' => '',
			);
		}

		$fallback = $this->settings->fallbackProvider();
		if ( '' !== $fallback && '' === $this->problem( $fallback, $source, $target, $period ) ) {
			return array(
				'id'             => $fallback,
				'fallbackReason' => $reason,
			);
		}

		return null;
	}

	/**
	 * Why a provider cannot translate the pair now, or ''.
	 *
	 * @param string   $id     Provider id.
	 * @param Language $source Source language.
	 * @param Language $target Target language.
	 * @param string   $period Budget period.
	 */
	public function problem( string $id, Language $source, Language $target, string $period ): string {
		$provider = $this->registry->get( $id );
		if ( null === $provider ) {
			return 'Provider ' . $id . ' is not configured.';
		}
		$reason = $this->state->unavailableReason( $id, $period );
		if ( '' !== $reason ) {
			return $reason;
		}
		try {
			if ( ! $provider->supportsPair( $source->providerCode( $id ), $target->providerCode( $id ) ) ) {
				return $provider->label() . ' does not support ' . $source->englishName() . ' to ' . $target->englishName() . '.';
			}
		} catch ( ProviderError $e ) {
			return $e->getMessage();
		}

		return '';
	}
}

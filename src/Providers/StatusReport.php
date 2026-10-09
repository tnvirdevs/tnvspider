<?php
/**
 * Per-provider status for WP-CLI and the admin (plan §8 "UX numbers").
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

use WST\Queue\Queue;
use WST\Queue\Usage;
use WST\Settings;

/**
 * Configured or not, tested or not, languages loaded, why it cannot be used
 * now, monthly usage against the cap, effective limits, queue and last error.
 * Never contains a secret.
 */
final class StatusReport {

	/**
	 * Create the report.
	 *
	 * @param Settings         $settings  Settings.
	 * @param Secrets          $secrets   Key store (only asked whether keys exist).
	 * @param ProviderRegistry $providers Configured adapters.
	 * @param ProviderState    $state     Pauses and quotas.
	 * @param Tester           $tester    Verification state.
	 * @param Usage            $usage     Monthly usage.
	 * @param Queue            $queue     Queue counts.
	 */
	public function __construct(
		private Settings $settings,
		private Secrets $secrets,
		private ProviderRegistry $providers,
		private ProviderState $state,
		private Tester $tester,
		private Usage $usage,
		private Queue $queue
	) {
	}

	/**
	 * Status of every provider.
	 *
	 * @return list<array{id: string, label: string, configured: bool, role: string, verified_at: int|null, languages: int|null, any_language: bool, unavailable: string, usage: array{period: string, chars: int, cap: int}, limits: array<string, int|string>, defaults: array<string, int|string>, queued: int, last_error: string, secrets: array<string, bool>}>
	 */
	public function all(): array {
		$period = Usage::period();
		$stats  = $this->queue->stats();
		$rows   = array();
		foreach ( Settings::PROVIDER_IDS as $id ) {
			$adapter = $this->providers->get( $id ) ?? ProviderRegistry::adapter( $id, $this->settings, $this->secrets );
			$caps    = $adapter->capabilities();
			$limits  = Limits::resolve( $this->settings, $id, $caps );
			$list    = Gemini::ID === $id ? null : new LanguageList( $id, $adapter->label() );
			$secrets = array();
			foreach ( Secrets::BY_PROVIDER[ $id ] as $name ) {
				$secrets[ $name ] = $this->secrets->has( $name );
			}
			$rows[] = array(
				'id'           => $id,
				'label'        => $adapter->label(),
				'configured'   => null !== $this->providers->get( $id ),
				'role'         => $this->settings->provider() === $id ? 'primary' : ( $this->settings->fallbackProvider() === $id ? 'fallback' : '' ),
				'verified_at'  => $this->tester->verifiedAt( $id ),
				'languages'    => null === $list ? null : $list->count(),
				'any_language' => null === $list,
				'unavailable'  => $this->state->unavailableReason( $id, $period ),
				'usage'        => array(
					'period' => $period,
					'chars'  => $this->usage->chars( $id, $period ),
					'cap'    => $limits->monthlyCap,
				),
				'limits'       => self::limitsArray( $limits ),
				'defaults'     => self::limitsArray( Limits::resolve( new Settings( array(), 'en_US', new \WST\Languages\Registry() ), $id, $caps ) ),
				'queued'       => $stats['by_provider'][ $id ] ?? 0,
				'last_error'   => $stats['last_errors'][ $id ] ?? '',
				'secrets'      => $secrets,
			);
		}

		return $rows;
	}

	/**
	 * Limits as a plain array.
	 *
	 * @param Limits $limits Limits.
	 * @return array<string, int|string>
	 */
	private static function limitsArray( Limits $limits ): array {
		return array(
			'requests_per_minute'   => $limits->rpm,
			'requests_per_day'      => $limits->rpd,
			'chars_per_minute'      => $limits->cpm,
			'max_items_per_request' => $limits->maxItems,
			'max_chars_per_request' => $limits->maxChars,
			'concurrency'           => $limits->concurrency,
			'max_attempts'          => $limits->maxAttempts,
			'monthly_char_cap'      => $limits->monthlyCap,
			'timeout'               => $limits->timeout,
			'day_timezone'          => $limits->dayTimezone,
		);
	}
}

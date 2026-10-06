<?php
/**
 * Processes the translation queue (plan §8 worker cycle).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Queue;

use WST\Config;
use WST\Languages\Registry;
use WST\Log\Logger;
use WST\Providers\Errors\AuthError;
use WST\Providers\Errors\PermanentError;
use WST\Providers\Errors\ProviderError;
use WST\Providers\Errors\QuotaExceeded;
use WST\Providers\Errors\RateLimited;
use WST\Providers\Limits;
use WST\Providers\ProviderInterface;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\Protection\Protector;
use WST\Providers\RequestPlan;
use WST\Providers\Secrets;
use WST\Providers\Selector;
use WST\Settings;
use WST\Storage\StringStore;

/**
 * One run processes batches until its time budget is used or nothing is due.
 * Every provider call goes through the rate limiter and the monthly budget;
 * the provider's own 429 always wins. Results never overwrite manual
 * translations. Provider failures map to the typed errors of plan §7.
 */
final class Worker {

	/** Default time budget of one run, in seconds. */
	public const TIME_BUDGET = 20.0;

	/**
	 * Clock returning Unix time with microseconds.
	 *
	 * @var callable(): float
	 */
	private $clock;

	/**
	 * Create the worker.
	 *
	 * @param Settings         $settings  Settings.
	 * @param Queue            $queue     Queue.
	 * @param StringStore      $store     Translations.
	 * @param RateLimiter      $limiter   Rate limiter.
	 * @param Usage            $usage     Monthly usage.
	 * @param ProviderState    $state     Pauses and quotas.
	 * @param ProviderRegistry $providers Adapters.
	 * @param Selector         $selector  Availability checks.
	 * @param Registry         $languages Language registry.
	 * @param Secrets          $secrets   For redacting messages.
	 * @param Logger           $logger    Plugin log.
	 * @param \wpdb            $db        For worker slot locks.
	 * @param callable|null    $clock     Clock; defaults to microtime(true).
	 */
	public function __construct(
		private Settings $settings,
		private Queue $queue,
		private StringStore $store,
		private RateLimiter $limiter,
		private Usage $usage,
		private ProviderState $state,
		private ProviderRegistry $providers,
		private Selector $selector,
		private Registry $languages,
		private Secrets $secrets,
		private Logger $logger,
		private \wpdb $db,
		?callable $clock = null
	) {
		$this->clock = $clock ?? static fn(): float => microtime( true );
	}

	/**
	 * Process batches until $budget seconds are used, $maxBatches are done or
	 * nothing more can be done now.
	 *
	 * @param float $budget     Seconds.
	 * @param int   $maxBatches Batches at most (the admin runner uses 1).
	 */
	public function run( float $budget = self::TIME_BUDGET, int $maxBatches = PHP_INT_MAX ): WorkerReport {
		$report   = new WorkerReport();
		$deadline = ( $this->clock )() + $budget;
		while ( ( $this->clock )() < $deadline && $report->batches < $maxBatches ) {
			$progress = false;
			foreach ( $this->queue->duePairs( (int) ( $this->clock )() ) as $pair ) {
				if ( $this->processPair( $pair['provider'], $pair['lang'], $report ) ) {
					$progress = true;
					break;
				}
			}
			if ( ! $progress ) {
				break;
			}
		}

		return $report;
	}

	/**
	 * Handle one provider/language pair: hand rows to the fallback when the
	 * provider is unavailable, else translate one batch.
	 *
	 * @param string       $providerId Provider id.
	 * @param string       $lang       Target locale.
	 * @param WorkerReport $report     Report.
	 * @return bool Whether anything changed.
	 */
	private function processPair( string $providerId, string $lang, WorkerReport $report ): bool {
		$source  = $this->settings->defaultLanguage();
		$target  = $this->languages->get( $lang );
		$period  = Usage::period( (int) ( $this->clock )() );
		$problem = $this->selector->problem( $providerId, $source, $target, $period );
		if ( '' !== $problem ) {
			$fallback = $this->fallbackFor( $providerId, $lang, $period );
			if ( '' !== $fallback ) {
				$this->queue->repoint( $providerId, $fallback, $lang );
				$this->logger->warning( 'queue', 'Using fallback provider ' . $fallback . ': ' . $problem );
				$report->fallbacks[ $providerId ] = $fallback;

				return true;
			}
			$report->blocked[ $providerId ] = $problem;

			return false;
		}

		$provider = $this->providers->get( $providerId );
		if ( null === $provider ) {
			return false;
		}
		$limits = Limits::resolve( $this->settings, $providerId, $provider->capabilities() );
		$slot   = $this->takeSlot( $providerId, $limits->concurrency );
		if ( null === $slot ) {
			return false;
		}
		try {
			return $this->translateBatch( $provider, $limits, $lang, $period, $report );
		} finally {
			$this->db->query( (string) $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $slot ) );
		}
	}

	/**
	 * Claim and translate one batch.
	 *
	 * @param ProviderInterface $provider Adapter.
	 * @param Limits            $limits   Effective limits.
	 * @param string            $lang     Target locale.
	 * @param string            $period   Budget period.
	 * @param WorkerReport      $report   Report.
	 * @return bool Whether anything changed.
	 */
	private function translateBatch( ProviderInterface $provider, Limits $limits, string $lang, string $period, WorkerReport $report ): bool {
		$id   = $provider->id();
		$now  = (int) ( $this->clock )();
		$rows = $this->queue->claim( $id, $lang, $limits->maxItems, $limits->maxChars, $limits->timeout * 2 + 60, $now );
		if ( array() === $rows ) {
			return false;
		}
		++$report->batches;

		$strings = array();
		foreach ( $rows as $row ) {
			$strings[ $row['string_id'] ] = array(
				'text'    => $row['text'],
				'kind'    => $row['kind'],
				'segment' => $row['attempts'] > 0 && str_starts_with( $row['last_error'], 'Tags ' ),
			);
		}
		$caps = $provider->capabilities();
		$plan = new RequestPlan(
			$strings,
			$caps,
			new Protector( $this->settings->neverTranslateTerms(), $this->settings->flag( 'terms_case_insensitive' ), $this->settings->flag( 'terms_whole_word' ), $caps->tokenFormat ),
			$limits->maxItems,
			$limits->maxChars
		);

		if ( ! $this->withinBudget( $id, $limits, $plan->chars(), $period ) ) {
			$this->queue->release( array_column( $rows, 'id' ), $now );

			return true;
		}

		$source       = $this->settings->defaultLanguage()->providerCode( $id );
		$target       = $this->languages->get( $lang )->providerCode( $id );
		$outputs      = array();
		$unavailable  = false;
		$sent         = array();
		$unitErrors   = array();
		$requestError = '';
		foreach ( $plan->requests() as $request ) {
			$chars = (int) array_sum( array_map( 'mb_strlen', $request['items'] ) );
			$wait  = $this->limiter->acquire( $id, $limits, $chars, ( $this->clock )() );
			if ( $wait > 0 ) {
				$report->noteWait( $wait );
				break;
			}
			++$report->requests;
			try {
				$result = $provider->translate( $request['items'], $source, $target, $request['html'] );
			} catch ( RateLimited $e ) {
				$this->limiter->block( $id, $e->retryAfter(), ( $this->clock )() );
				$report->noteWait( (float) $e->retryAfter() );
				break;
			} catch ( AuthError $e ) {
				$this->state->pause( $id, $this->secrets->redact( $e->getMessage() ) );
				$this->logger->error( 'queue', 'Provider ' . $id . ' paused: ' . $this->secrets->redact( $e->getMessage() ) );
				$unavailable = true;
				break;
			} catch ( QuotaExceeded $e ) {
				$this->state->quotaExceeded( $id, $period );
				$this->logger->warning( 'queue', 'Provider ' . $id . ' quota used up: ' . $this->secrets->redact( $e->getMessage() ) );
				$unavailable = true;
				break;
			} catch ( PermanentError $e ) {
				foreach ( array_keys( $request['items'] ) as $unit ) {
					$sent[ $unit ]       = true;
					$unitErrors[ $unit ] = $this->secrets->redact( $e->getMessage() );
				}
				continue;
			} catch ( ProviderError $e ) {
				// Transient: the service is unhealthy; stop this batch, retry later.
				foreach ( array_keys( $request['items'] ) as $unit ) {
					$sent[ $unit ]       = true;
					$unitErrors[ $unit ] = $this->secrets->redact( $e->getMessage() );
				}
				$requestError = $this->secrets->redact( $e->getMessage() );
				break;
			}
			$this->usage->add( $id, $result->charsBilled > 0 ? $result->charsBilled : $chars, 1, $period );
			foreach ( array_keys( $request['items'] ) as $unit ) {
				$sent[ $unit ] = true;
			}
			$outputs += $result->translations;
			foreach ( $result->errors as $unit => $message ) {
				$unitErrors[ $unit ] = $this->secrets->redact( $message );
			}
		}

		$outcome    = $plan->complete( $outputs );
		$done       = array();
		$release    = array();
		$fallback   = $this->fallbackFor( $id, $lang, $period );
		$now        = (int) ( $this->clock )();
		$completed  = array();
		$failedHere = 0;
		foreach ( $rows as $row ) {
			$sid = $row['string_id'];
			if ( isset( $outcome['translated'][ $sid ] ) ) {
				$entry = $outcome['translated'][ $sid ];
				$this->store->saveMachine( $sid, $row['text'], $lang, $entry['translation'], array() === $plan->unitsOf( $sid ) ? RequestPlan::PROTECTED_TERM : $id, $entry['flags'] );
				$done[]      = $row['id'];
				$completed[] = $sid;
				continue;
			}
			$units = $plan->unitsOf( $sid );
			if ( array() === array_diff( $units, array_keys( $sent ) ) ) {
				$message = $this->firstError( $units, $unitErrors ) ?? ( $outcome['failed'][ $sid ] ?? ( '' === $requestError ? 'Translation failed.' : $requestError ) );
				$this->queue->fail( $row, $message, $limits->maxAttempts, $fallback, $now );
				++$report->failed;
				++$failedHere;
				continue;
			}
			$release[] = $row['id'];
		}
		$this->queue->complete( $done );
		// Rows not sent because of a wait are due again when the wait ends.
		$this->queue->release( $release, $now + (int) ceil( $report->wait ) );
		$report->translated += count( $done );

		if ( array() !== $completed ) {
			/**
			 * Fires after machine translations were stored.
			 *
			 * @param int[]  $stringIds String ids.
			 * @param string $lang      Target locale.
			 */
			do_action( 'wst_strings_translated', $completed, $lang );
		}

		// Stored or failed strings are progress, and so is a provider becoming
		// unavailable (the next pass hands its rows to the fallback). Rows
		// released for a wait are not.
		return array() !== $done || $failedHere > 0 || $unavailable;
	}

	/**
	 * Whether the batch fits the monthly budget; warns once at 80 % and marks
	 * the quota as used up at 100 %.
	 *
	 * @param string $id     Provider id.
	 * @param Limits $limits Limits.
	 * @param int    $chars  Characters of the batch.
	 * @param string $period Budget period.
	 */
	private function withinBudget( string $id, Limits $limits, int $chars, string $period ): bool {
		if ( $limits->monthlyCap <= 0 ) {
			return true;
		}
		$used = $this->usage->chars( $id, $period );
		if ( $used + $chars > $limits->monthlyCap ) {
			$this->state->quotaExceeded( $id, $period );
			$this->logger->warning( 'queue', sprintf( 'Monthly character budget of %s reached (%d of %d used).', $id, $used, $limits->monthlyCap ) );

			return false;
		}
		$flag = Config::PREFIX . 'budget_warned_' . $id . '_' . $period;
		if ( ( $used + $chars ) * 5 >= $limits->monthlyCap * 4 && ! get_option( $flag ) ) {
			update_option( $flag, 1, false );
			$this->logger->warning( 'queue', sprintf( '%s has used 80%% of its monthly character budget.', $id ) );
		}

		return true;
	}

	/**
	 * Fallback that may take over rows of $providerId, or ''. Only rows of
	 * the primary are handed over, and only to a usable fallback.
	 *
	 * @param string $providerId Provider id.
	 * @param string $lang       Target locale.
	 * @param string $period     Budget period.
	 */
	private function fallbackFor( string $providerId, string $lang, string $period ): string {
		$fallback = $this->settings->fallbackProvider();
		if ( '' === $fallback || $providerId !== $this->settings->provider() ) {
			return '';
		}

		return '' === $this->selector->problem( $fallback, $this->settings->defaultLanguage(), $this->languages->get( $lang ), $period ) ? $fallback : '';
	}

	/**
	 * Take one of the provider's concurrency slots without waiting.
	 *
	 * @param string $providerId  Provider id.
	 * @param int    $concurrency Slots.
	 * @return string|null Lock name, or null when every slot is busy.
	 */
	private function takeSlot( string $providerId, int $concurrency ): ?string {
		$slots = max( 1, $concurrency );
		for ( $slot = 1; $slot <= $slots; $slot++ ) {
			$name = Config::PREFIX . 'worker_' . $providerId . '_' . $slot;
			if ( '1' === (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * First provider error among a string's units.
	 *
	 * @param int[]              $units  Unit ids.
	 * @param array<int, string> $errors Unit errors.
	 */
	private function firstError( array $units, array $errors ): ?string {
		foreach ( $units as $unit ) {
			if ( isset( $errors[ $unit ] ) ) {
				return $errors[ $unit ];
			}
		}

		return null;
	}
}

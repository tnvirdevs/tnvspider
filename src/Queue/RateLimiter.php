<?php
/**
 * Rate limiter shared by every worker process (plan §8, D10).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Queue;

use WST\Config;
use WST\Providers\Limits;

/**
 * Per provider: requests per minute and characters per minute are enforced
 * by strict spacing (each grant moves the next allowed time forward by
 * 60/rpm, or chars x 60/cpm), so no 60-second window ever exceeds the limit;
 * requests per day by a counter in the provider's day time zone. A provider
 * 429 blocks the provider until its Retry-After, whatever the settings.
 * 0 disables a dimension. State lives in one options row, read and written
 * under a MySQL named lock, bypassing the object cache.
 */
final class RateLimiter {

	/** Seconds to wait for the lock before answering "try again shortly". */
	private const LOCK_TIMEOUT = 10;

	/**
	 * Waits shorter than this are rounding noise and count as "now". Grants
	 * never move the schedule earlier (it advances from max(schedule, now)),
	 * so this cannot raise the rate.
	 */
	private const EPSILON = 0.001;

	/**
	 * Create the limiter.
	 *
	 * @param \wpdb $db Database connection.
	 */
	public function __construct( private \wpdb $db ) {
	}

	/**
	 * Ask to send one request of $chars characters now.
	 *
	 * @param string     $provider Provider id.
	 * @param Limits     $limits   Effective limits.
	 * @param int        $chars    Characters in the request.
	 * @param float|null $now      Unix time; defaults to the current time.
	 * @return float 0 when granted (and counted), else seconds to wait.
	 */
	public function acquire( string $provider, Limits $limits, int $chars, ?float $now = null ): float {
		$now = $now ?? microtime( true );

		return $this->locked(
			$provider,
			function ( array $state ) use ( $limits, $chars, $now ): array {
				$day  = $this->day( $now, $limits->dayTimezone );
				$wait = max( 0.0, $state['blocked_until'] - $now );
				if ( $limits->rpm > 0 ) {
					$wait = max( $wait, $state['rpm_tat'] - $now );
				}
				if ( $limits->cpm > 0 ) {
					$wait = max( $wait, $state['cpm_tat'] - $now );
				}
				$dayCount = $day === $state['day'] ? $state['day_count'] : 0;
				if ( $limits->rpd > 0 && $dayCount >= $limits->rpd ) {
					$wait = max( $wait, $this->nextDayStart( $now, $limits->dayTimezone ) - $now );
				}
				if ( $wait > self::EPSILON ) {
					return array( $wait, null );
				}

				if ( $limits->rpm > 0 ) {
					$state['rpm_tat'] = max( $state['rpm_tat'], $now ) + 60 / $limits->rpm;
				}
				if ( $limits->cpm > 0 ) {
					$state['cpm_tat'] = max( $state['cpm_tat'], $now ) + $chars * 60 / $limits->cpm;
				}
				$state['day']       = $day;
				$state['day_count'] = $dayCount + 1;

				return array( 0.0, $state );
			}
		);
	}

	/**
	 * Block a provider for $seconds (HTTP 429 / Retry-After). Honoured even
	 * when every limit is 0.
	 *
	 * @param string     $provider Provider id.
	 * @param int        $seconds  Seconds.
	 * @param float|null $now      Unix time.
	 */
	public function block( string $provider, int $seconds, ?float $now = null ): void {
		$until = ( $now ?? microtime( true ) ) + max( 1, $seconds );
		$this->locked(
			$provider,
			static function ( array $state ) use ( $until ): array {
				$state['blocked_until'] = max( $state['blocked_until'], $until );

				return array( 0.0, $state );
			}
		);
	}

	/**
	 * Run $change on the provider's state under the named lock and store the
	 * state it returns (null = unchanged).
	 *
	 * @param string                                                                                                                                            $provider Provider id.
	 * @param callable(array{rpm_tat: float, cpm_tat: float, day: string, day_count: int, blocked_until: float}): array{0: float, 1: array<string, mixed>|null} $change Change.
	 * @return float The wait $change reported, or a short wait when the lock is busy.
	 * @throws \RuntimeException When the state cannot be written.
	 */
	private function locked( string $provider, callable $change ): float {
		$lock = Config::PREFIX . 'rl_' . $provider;
		if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT ) ) ) {
			return 1.0;
		}
		try {
			$option           = Config::PREFIX . 'rl_state_' . $provider;
			$raw              = $this->db->get_var( $this->db->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $this->db->options, $option ) );
			[ $wait, $state ] = $change( self::state( is_string( $raw ) ? $raw : '' ) );
			if ( null !== $state ) {
				$written = $this->db->query(
					(string) $this->db->prepare(
						"INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
						$this->db->options,
						$option,
						(string) wp_json_encode( $state )
					)
				);
				if ( false === $written ) {
					throw new \RuntimeException( 'Could not store the rate limiter state: ' . esc_html( $this->db->last_error ) );
				}
			}

			return (float) $wait;
		} finally {
			$this->db->query( (string) $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Stored state with defaults.
	 *
	 * @param string $raw JSON.
	 * @return array{rpm_tat: float, cpm_tat: float, day: string, day_count: int, blocked_until: float}
	 */
	private static function state( string $raw ): array {
		$data = json_decode( $raw, true );
		$data = is_array( $data ) ? $data : array();

		return array(
			'rpm_tat'       => (float) ( $data['rpm_tat'] ?? 0 ),
			'cpm_tat'       => (float) ( $data['cpm_tat'] ?? 0 ),
			'day'           => (string) ( $data['day'] ?? '' ),
			'day_count'     => (int) ( $data['day_count'] ?? 0 ),
			'blocked_until' => (float) ( $data['blocked_until'] ?? 0 ),
		);
	}

	/**
	 * Calendar day of $now in $timezone.
	 *
	 * @param float  $now      Unix time.
	 * @param string $timezone Time zone.
	 */
	private function day( float $now, string $timezone ): string {
		return ( new \DateTimeImmutable( '@' . (int) $now ) )->setTimezone( new \DateTimeZone( $timezone ) )->format( 'Y-m-d' );
	}

	/**
	 * Unix time of the next midnight in $timezone.
	 *
	 * @param float  $now      Unix time.
	 * @param string $timezone Time zone.
	 */
	private function nextDayStart( float $now, string $timezone ): float {
		$local = ( new \DateTimeImmutable( '@' . (int) $now ) )->setTimezone( new \DateTimeZone( $timezone ) );

		return (float) $local->modify( 'tomorrow' )->getTimestamp();
	}
}

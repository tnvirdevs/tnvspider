<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Queue;

use WP_UnitTestCase;
use WST\Providers\Limits;
use WST\Queue\RateLimiter;

final class RateLimiterTest extends WP_UnitTestCase {

	private RateLimiter $limiter;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->limiter = new RateLimiter( $wpdb );
	}

	private function limits( int $rpm = 0, int $rpd = 0, int $cpm = 0, string $tz = 'UTC' ): Limits {
		return new Limits( $rpm, $rpd, $cpm, 100, 10000, 1, 5, 0, 30, $tz );
	}

	public function test_requests_per_minute_are_spaced(): void {
		$limits = $this->limits( 60 );
		$t      = 1_800_000_000.0;

		$this->assertSame( 0.0, $this->limiter->acquire( 'p', $limits, 10, $t ) );
		$this->assertEqualsWithDelta( 1.0, $this->limiter->acquire( 'p', $limits, 10, $t ), 0.0001 );
		$this->assertEqualsWithDelta( 0.5, $this->limiter->acquire( 'p', $limits, 10, $t + 0.5 ), 0.0001 );
		$this->assertSame( 0.0, $this->limiter->acquire( 'p', $limits, 10, $t + 1.0 ) );
	}

	public function test_no_sixty_second_window_exceeds_the_rpm(): void {
		$limits = $this->limits( 50 );
		$t      = 1_800_000_000.0;
		$grants = array();
		// 0.1 s steps divide the 1.2 s spacing exactly, so throughput is measurable.
		for ( $i = 0; $i < 3000; $i++ ) {
			$clock = $t + $i / 10;
			if ( 0.0 === $this->limiter->acquire( 'p', $limits, 1, $clock ) ) {
				$grants[] = $clock;
			}
		}
		foreach ( $grants as $start ) {
			$inWindow = count( array_filter( $grants, static fn( float $g ): bool => $g >= $start && $g < $start + 60 ) );
			$this->assertLessThanOrEqual( 50, $inWindow );
		}
		$this->assertGreaterThanOrEqual( 249, count( $grants ), 'Throughput stays at the limit (250 in 300 s).' );
	}

	public function test_zero_means_unlimited(): void {
		$limits = $this->limits();
		for ( $i = 0; $i < 1000; $i++ ) {
			$this->assertSame( 0.0, $this->limiter->acquire( 'p', $limits, 50000, 1_800_000_000.0 ) );
		}
	}

	public function test_a_429_block_is_honoured_even_when_unlimited(): void {
		$limits = $this->limits();
		$t      = 1_800_000_000.0;
		$this->limiter->block( 'p', 30, $t );

		$this->assertEqualsWithDelta( 30.0, $this->limiter->acquire( 'p', $limits, 1, $t ), 0.0001 );
		$this->assertEqualsWithDelta( 10.0, $this->limiter->acquire( 'p', $limits, 1, $t + 20 ), 0.0001 );
		$this->assertSame( 0.0, $this->limiter->acquire( 'p', $limits, 1, $t + 30 ) );
	}

	public function test_characters_per_minute(): void {
		$limits = $this->limits( 0, 0, 33000 );
		$t      = 1_800_000_000.0;

		$this->assertSame( 0.0, $this->limiter->acquire( 'p', $limits, 11000, $t ) );
		$this->assertEqualsWithDelta( 20.0, $this->limiter->acquire( 'p', $limits, 11000, $t ), 0.0001 );
		$this->assertSame( 0.0, $this->limiter->acquire( 'p', $limits, 11000, $t + 20 ) );
	}

	public function test_requests_per_day_reset_at_midnight_in_the_provider_time_zone(): void {
		$limits = $this->limits( 0, 2, 0, 'America/Los_Angeles' );
		// 2026-10-06 23:00 in Los Angeles is 2026-10-07 06:00 UTC.
		$t = (float) ( new \DateTimeImmutable( '2026-10-06 23:00:00', new \DateTimeZone( 'America/Los_Angeles' ) ) )->getTimestamp();

		$this->assertSame( 0.0, $this->limiter->acquire( 'p', $limits, 1, $t ) );
		$this->assertSame( 0.0, $this->limiter->acquire( 'p', $limits, 1, $t ) );
		$this->assertEqualsWithDelta( 3600.0, $this->limiter->acquire( 'p', $limits, 1, $t ), 0.0001 );
		$this->assertSame( 0.0, $this->limiter->acquire( 'p', $limits, 1, $t + 3600 ) );
	}

	public function test_providers_are_independent(): void {
		$limits = $this->limits( 1 );
		$t      = 1_800_000_000.0;

		$this->assertSame( 0.0, $this->limiter->acquire( 'a', $limits, 1, $t ) );
		$this->assertSame( 0.0, $this->limiter->acquire( 'b', $limits, 1, $t ) );
		$this->assertGreaterThan( 0.0, $this->limiter->acquire( 'a', $limits, 1, $t ) );
	}

	/**
	 * Four worker processes, each with its own database connection, compete
	 * for one provider at 120 requests per minute for 3 seconds. Every pair
	 * of grants must be at least 0.5 s apart.
	 */
	public function test_concurrent_workers_never_exceed_the_rate(): void {
		if ( ! function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'pcntl is required for the concurrency test.' );
		}
		global $wpdb;
		$provider = 'concurrency' . getmypid();
		$file     = tempnam( sys_get_temp_dir(), 'wst-rl' );
		$children = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$pid = pcntl_fork();
			if ( 0 === $pid ) {
				$this->worker( $provider, (string) $file );
			}
			$children[] = $pid;
		}
		foreach ( $children as $pid ) {
			pcntl_waitpid( $pid, $status );
		}

		$grants = array_map( 'floatval', array_filter( explode( "\n", (string) file_get_contents( (string) $file ) ) ) );
		unlink( (string) $file );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, 'wst_rl_state_' . $provider ) );
		sort( $grants );

		$this->assertGreaterThanOrEqual( 5, count( $grants ) );
		for ( $i = 1, $n = count( $grants ); $i < $n; $i++ ) {
			$this->assertGreaterThanOrEqual( 0.5 - 0.005, $grants[ $i ] - $grants[ $i - 1 ], 'Two grants closer than 60/rpm.' );
		}
	}

	/**
	 * Child process body: own connection, acquire in a loop, record grants,
	 * exit without running destructors (they would close the parent's socket).
	 *
	 * @param string $provider Provider id.
	 * @param string $file     Result file.
	 */
	private function worker( string $provider, string $file ): void {
		$db         = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$db->prefix = $GLOBALS['wpdb']->prefix;
		$db->set_prefix( $GLOBALS['wpdb']->prefix );
		$limiter = new RateLimiter( $db );
		$limits  = $this->limits( 120 );
		$end     = microtime( true ) + 3;
		while ( microtime( true ) < $end ) {
			$wait = $limiter->acquire( $provider, $limits, 1 );
			if ( 0.0 === $wait ) {
				file_put_contents( $file, sprintf( "%.6F\n", microtime( true ) ), FILE_APPEND | LOCK_EX );
				continue;
			}
			usleep( (int) min( 200000, $wait * 1e6 ) );
		}
		posix_kill( getmypid(), SIGKILL );
	}
}

<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Queue;

use WP_UnitTestCase;
use WST\Database\Schema;
use WST\Languages\Registry;
use WST\Log\Logger;
use WST\Providers\BatchResult;
use WST\Providers\Capabilities;
use WST\Providers\Errors\AuthError;
use WST\Providers\Errors\PermanentError;
use WST\Providers\Errors\QuotaExceeded;
use WST\Providers\Errors\RateLimited;
use WST\Providers\Errors\TransientError;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\RequestPlan;
use WST\Providers\Secrets;
use WST\Providers\Selector;
use WST\Queue\Queue;
use WST\Queue\RateLimiter;
use WST\Queue\Usage;
use WST\Queue\Worker;
use WST\Settings;
use WST\Storage\StringStore;
use WST\Tests\Support\FakeProvider;

final class WorkerTest extends WP_UnitTestCase {

	private const LANG = 'bn_BD';

	private float $now = 1_800_000_000.0;

	private Schema $schema;

	private StringStore $store;

	private Queue $queue;

	private Usage $usage;

	private ProviderState $state;

	private FakeProvider $primary;

	private FakeProvider $fallback;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->schema   = new Schema( $wpdb );
		$this->store    = new StringStore( $wpdb, $this->schema );
		$this->queue    = new Queue( $wpdb, $this->schema );
		$this->usage    = new Usage( $wpdb, $this->schema );
		$this->state    = new ProviderState();
		$this->primary  = new FakeProvider( 'translatex', new Capabilities( false, 2, 5000 ) );
		$this->fallback = new FakeProvider( 'microsoft', new Capabilities( true, 50, 5000 ) );
		delete_option( ProviderState::OPTION );
		wp_cache_flush();
	}

	/**
	 * @param array<string, mixed> $values Settings.
	 */
	private function worker( array $values = array() ): Worker {
		global $wpdb;
		$settings = new Settings(
			$values + array(
				'target_language' => self::LANG,
				'provider'        => 'translatex',
			),
			'en_US',
			new Registry()
		);
		$registry = new ProviderRegistry(
			array(
				'translatex' => $this->primary,
				'microsoft'  => $this->fallback,
			)
		);

		return new Worker(
			$settings,
			$this->queue,
			$this->store,
			new RateLimiter( $wpdb ),
			$this->usage,
			$this->state,
			$registry,
			new Selector( $settings, $registry, $this->state ),
			new Registry(),
			new Secrets(),
			new Logger( $wpdb, $this->schema ),
			$wpdb,
			fn(): float => $this->now
		);
	}

	/**
	 * Record strings and queue them for the primary provider.
	 *
	 * @param array<string, string> $strings Text => kind.
	 * @return array<string, int> Text => string id.
	 */
	private function enqueue( array $strings, string $provider = 'translatex' ): array {
		$ids = $this->store->recordOnPage( $strings, '/page/', null );
		$this->queue->enqueue( array_values( $ids ), self::LANG, $provider, Queue::PRIORITY_VISITOR, (int) $this->now );

		return $ids;
	}

	private function translation( string $text ): ?array {
		wp_cache_flush();

		return $this->store->find( $text, self::LANG );
	}

	public function test_translates_queued_strings_and_counts_usage(): void {
		$this->enqueue(
			array(
				'Shop now'      => 'text',
				'Add to cart'   => 'text',
				'Free shipping' => 'text',
			)
		);

		$report = $this->worker()->run();

		$this->assertSame( 3, $report->translated );
		$this->assertSame( 2, $report->requests, 'Two requests: the provider cap is 2 items.' );
		$this->assertSame( '[bn] Shop now', $this->translation( 'Shop now' )['translated'] ?? null );
		$this->assertSame( StringStore::STATUS_MACHINE, $this->translation( 'Shop now' )['status'] ?? null );
		$this->assertFalse( $this->queue->hasWork() );
		$this->assertSame( strlen( 'Shop now' ) + strlen( 'Add to cart' ) + strlen( 'Free shipping' ), $this->usage->chars( 'translatex', Usage::period( (int) $this->now ) ) );
	}

	public function test_manual_translations_are_never_overwritten(): void {
		$ids = $this->enqueue( array( 'Cart' => 'text' ) );
		global $wpdb;
		$wpdb->insert(
			$this->schema->table( 'translations' ),
			array(
				'string_id'  => $ids['Cart'],
				'lang'       => self::LANG,
				'translated' => 'ঝুড়ি',
				'status'     => StringStore::STATUS_MANUAL,
				'updated_at' => '2026-01-01 00:00:00',
			)
		);

		$this->worker()->run();

		$this->assertSame( 'ঝুড়ি', $this->translation( 'Cart' )['translated'] ?? null );
		$this->assertSame( StringStore::STATUS_MANUAL, $this->translation( 'Cart' )['status'] ?? null );
	}

	public function test_zero_rpm_sends_batches_back_to_back(): void {
		$strings = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$strings[ 'Item number ' . $i ] = 'text';
		}
		$this->enqueue( $strings );

		$report = $this->worker( array( 'providers' => array( 'translatex' => array( 'requests_per_minute' => 0 ) ) ) )->run();

		$this->assertSame( 5, $report->requests );
		$this->assertSame( 10, $report->translated );
		$this->assertSame( 0.0, $report->wait );
	}

	public function test_rpm_limit_spaces_batches_and_releases_rows_without_counting_attempts(): void {
		$this->enqueue(
			array(
				'One'   => 'text',
				'Two'   => 'text',
				'Three' => 'text',
			)
		);

		$report = $this->worker( array( 'providers' => array( 'translatex' => array( 'requests_per_minute' => 1 ) ) ) )->run();

		$this->assertSame( 1, $report->requests );
		$this->assertSame( 2, $report->translated );
		$this->assertEqualsWithDelta( 60.0, $report->wait, 0.01 );
		$this->assertSame( 0, $this->queue->stats()['failed'] );
		$this->assertSame( 1, $this->queue->stats()['pending'] );
	}

	public function test_a_429_is_honoured_even_with_unlimited_rpm(): void {
		$this->enqueue( array( 'One' => 'text' ) );
		$this->primary->throw = array( new RateLimited( 'Too many requests', 45 ) );

		$report = $this->worker()->run();

		$this->assertSame( 45.0, $report->wait );
		$this->assertSame( 1, $this->queue->stats()['pending'] );
		$this->assertSame( array(), $report->fallbacks, 'A plain 429 never switches to the fallback.' );

		// Within the block nothing is sent, even with rpm = 0.
		$this->now += 10;
		$this->assertSame( 0, $this->worker( array( 'fallback_provider' => 'microsoft' ) )->run()->requests );
		$this->now += 40;
		$this->assertSame( 1, $this->worker()->run()->translated );
	}

	public function test_transient_errors_back_off_and_count_an_attempt(): void {
		$this->enqueue( array( 'One' => 'text' ) );
		$this->primary->throw = array( new TransientError( 'HTTP 503' ) );

		$report = $this->worker()->run();

		$this->assertSame( 1, $report->failed );
		$this->assertSame( 0, $this->worker()->run()->requests, 'Backoff: not due again yet.' );
		$this->now += Queue::BACKOFF_BASE;
		$this->assertSame( 1, $this->worker()->run()->translated );
	}

	public function test_result_count_mismatch_is_retried_one_item_per_request(): void {
		$this->enqueue(
			array(
				'One' => 'text',
				'Two' => 'text',
			)
		);
		$this->primary->throw = array( new PermanentError( BatchResult::COUNT_MISMATCH . ': sent 2, got 1.' ) );

		$this->assertSame( 2, $this->worker()->run()->failed );
		$this->now += Queue::BACKOFF_BASE;
		$report     = $this->worker()->run();

		$this->assertSame( 2, $report->translated );
		$this->assertSame( array( 2, 1, 1 ), array_map( static fn( array $call ): int => count( $call[0] ), $this->primary->calls ), 'Retried as single-item requests.' );
	}

	public function test_auth_error_pauses_the_primary_and_the_fallback_takes_over(): void {
		$this->enqueue(
			array(
				'One' => 'text',
				'Two' => 'text',
			)
		);
		$this->primary->throw = array( new AuthError( 'invalid api key' ) );

		$report = $this->worker( array( 'fallback_provider' => 'microsoft' ) )->run();

		$this->assertNotSame( '', $this->state->unavailableReason( 'translatex', Usage::period( (int) $this->now ) ) );
		$this->assertSame( array( 'translatex' => 'microsoft' ), $report->fallbacks );
		$this->assertSame( 2, $report->translated );
		$this->assertCount( 1, $this->fallback->calls );
		global $wpdb;
		$this->assertSame( array( 'microsoft' ), $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT provider FROM %i', $this->schema->table( 'translations' ) ) ) );

		// Once the key works again (a successful connection test resumes it),
		// new work goes to the primary.
		$this->state->resume( 'translatex' );
		$settings = new Settings(
			array(
				'target_language'   => self::LANG,
				'provider'          => 'translatex',
				'fallback_provider' => 'microsoft',
			),
			'en_US',
			new Registry()
		);
		$selector = new Selector(
			$settings,
			new ProviderRegistry(
				array(
					'translatex' => $this->primary,
					'microsoft'  => $this->fallback,
				)
			),
			$this->state
		);
		$this->assertSame( 'translatex', $selector->active( $settings->defaultLanguage(), ( new Registry() )->get( self::LANG ), Usage::period( (int) $this->now ) )['id'] ?? null );
	}

	public function test_auth_error_without_fallback_keeps_rows_waiting(): void {
		$this->enqueue( array( 'One' => 'text' ) );
		$this->primary->throw = array( new AuthError( 'invalid api key' ) );

		$report = $this->worker()->run();

		$this->assertSame( 0, $report->failed );
		$this->assertArrayHasKey( 'translatex', $report->blocked );
		$this->assertSame( 1, $this->queue->stats()['pending'] );
	}

	public function test_quota_exceeded_switches_and_returns_next_period(): void {
		$this->enqueue( array( 'One' => 'text' ) );
		$this->primary->throw = array( new QuotaExceeded( '403001 free quota exceeded' ) );
		$settings             = array( 'fallback_provider' => 'microsoft' );

		$this->worker( $settings )->run();
		$this->assertSame( 'microsoft', $this->translationProvider( 'One' ) );

		$selector = new Selector(
			new Settings(
				$settings + array(
					'target_language' => self::LANG,
					'provider'        => 'translatex',
				),
				'en_US',
				new Registry()
			),
			new ProviderRegistry(
				array(
					'translatex' => $this->primary,
					'microsoft'  => $this->fallback,
				)
			),
			$this->state
		);
		$registry = new Registry();
		$this->assertSame( 'microsoft', $selector->active( $registry->get( 'en_US' ), $registry->get( self::LANG ), Usage::period( (int) $this->now ) )['id'] ?? null );
		$nextMonth = Usage::period( (int) $this->now + 32 * DAY_IN_SECONDS );
		$this->assertSame( 'translatex', $selector->active( $registry->get( 'en_US' ), $registry->get( self::LANG ), $nextMonth )['id'] ?? null );
	}

	public function test_monthly_budget_stops_the_provider(): void {
		$this->enqueue( array( 'A long enough sentence' => 'text' ) );

		$report = $this->worker( array( 'providers' => array( 'translatex' => array( 'monthly_char_cap' => 10 ) ) ) )->run();

		$this->assertSame( 0, $report->requests );
		$this->assertStringContainsString( 'Quota', $this->state->unavailableReason( 'translatex', Usage::period( (int) $this->now ) ) );
		$this->assertSame( 1, $this->queue->stats()['pending'] );
	}

	public function test_exhausted_attempts_move_to_the_fallback_or_fail(): void {
		$this->enqueue( array( 'One' => 'text' ) );
		$this->primary->translator = static fn(): string => '';

		$worker = $this->worker( array( 'providers' => array( 'translatex' => array( 'max_attempts' => 2 ) ) ) );
		$worker->run();
		$this->now += 3600;
		$worker->run();
		$this->assertSame( 1, $this->queue->stats()['failed'], 'No fallback: failed after 2 attempts.' );

		$this->queue->clear();
		$this->enqueue( array( 'Two' => 'text' ) );
		$worker = $this->worker(
			array(
				'fallback_provider' => 'microsoft',
				'providers'         => array( 'translatex' => array( 'max_attempts' => 2 ) ),
			)
		);
		$worker->run();
		$this->now += 3600;
		$worker->run();
		$this->assertSame( 'microsoft', $this->translationProvider( 'Two' ) );
	}

	public function test_never_translate_terms_cost_no_request(): void {
		$this->enqueue( array( 'Purfello' => 'text' ) );

		$report = $this->worker( array( 'never_translate_terms' => array( 'purfello' ) ) )->run();

		$this->assertSame( 0, $report->requests );
		$this->assertSame( 'Purfello', $this->translation( 'Purfello' )['translated'] ?? null );
		$this->assertSame( 'term', $this->translationProvider( 'Purfello' ) );
	}

	public function test_inline_tag_mismatch_is_retried_segmented(): void {
		$this->enqueue( array( 'Go <b>now</b>' => 'inline' ), 'microsoft' );
		$this->fallback->translator = static fn( string $text, string $target, bool $html ): string => $html ? 'এখন <i id="1">যান</i>' : '[' . $target . '] ' . $text;

		$worker = $this->worker( array( 'provider' => 'microsoft' ) );
		$worker->run();
		$this->assertSame( 1, $this->queue->stats()['pending'] );
		$this->now += 3600;
		$worker->run();

		$this->assertSame( '[bn] Go <b>[bn] now</b>', $this->translation( 'Go <b>now</b>' )['translated'] ?? null );
		$this->assertFalse( end( $this->fallback->calls )[3], 'The retry was sent as plain text segments.' );
	}

	public function test_no_request_starts_after_the_budget_even_inside_a_batch(): void {
		$strings = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$strings[ 'Slow item ' . $i ] = 'text';
		}
		$this->enqueue( $strings );
		// Every request takes 15 s; the provider caps a request at 2 items.
		$this->primary->translator = function ( string $text, string $target ): string {
			$this->now += 7.5;

			return strtoupper( $target ) . ': ' . $text;
		};

		$report = $this->worker(
			array(
				'providers' => array(
					'translatex' => array(
						'requests_per_minute'   => 0,
						'max_items_per_request' => 2,
					),
				),
			)
		)->run( 20.0 );

		$this->assertSame( 2, $report->requests, 'Started at 0 s and 15 s; at 30 s the 20 s budget is used.' );
		$this->assertSame( 4, $report->translated );
		$stats = $this->queue->stats();
		$this->assertSame( 2, $stats['pending'], 'The rest waits for the next run.' );
		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(attempts) FROM %i WHERE lang = %s', $this->schema->table( 'queue' ), self::LANG ) ), 'No attempt counted.' );
	}

	public function test_request_timeout_must_fit_max_execution_time(): void {
		$never = static function (): bool {
			throw new \LogicException( 'Must not raise the limit.' );
		};
		$this->assertTrue( Worker::fitsTimeLimit( 30, 0, 500.0, $never ), 'No limit (CLI).' );
		$this->assertTrue( Worker::fitsTimeLimit( 30, 60, 20.0, $never ), '35 s needed, 40 s left.' );

		$asked = array();
		$raise = static function ( int $seconds ) use ( &$asked ): bool {
			$asked[] = $seconds;

			return false;
		};
		$this->assertFalse( Worker::fitsTimeLimit( 30, 30, 1.0, $raise ), 'Default timeout on a 30 s host that forbids raising.' );
		$this->assertSame( array( 35 ), $asked, 'Asks for the timeout plus the margin.' );
		$this->assertTrue( Worker::fitsTimeLimit( 30, 30, 1.0, static fn( int $seconds ): bool => 35 === $seconds ), 'set_time_limit allowed: fits.' );
	}

	public function test_plain_text_provider_sends_inline_as_one_sentence(): void {
		$this->enqueue( array( 'Go <b>now</b>' => 'inline' ) );
		// No "[bn]" prefix here: a bracket outside a token is rejected as token debris.
		$this->primary->translator = static fn( string $text, string $target ): string => strtoupper( $target ) . ': ' . $text;

		$this->worker()->run();

		$this->assertSame( 'BN: Go <b>now</b>', $this->translation( 'Go <b>now</b>' )['translated'] ?? null );
		$this->assertSame( 0, $this->translationFlags( 'Go <b>now</b>' ) );
		$this->assertSame( array( 1 => 'Go [[1]]now[[2]]' ), $this->primary->calls[0][0], 'One text with tag tokens.' );
	}

	public function test_plain_text_tag_mismatch_falls_back_to_segments(): void {
		$this->enqueue( array( 'Go <b>now</b>' => 'inline' ) );
		$this->primary->translator = static fn( string $text, string $target ): string => str_contains( $text, '[[' ) ? 'জাও এখন' : strtoupper( $target ) . ': ' . $text;

		$worker = $this->worker();
		$worker->run();
		$this->assertNull( $this->translation( 'Go <b>now</b>' )['translated'] ?? null, 'Sentence lost its tags: nothing stored.' );
		$this->now += Queue::BACKOFF_BASE;
		$worker->run();

		$this->assertSame( 'BN: Go <b>BN: now</b>', $this->translation( 'Go <b>now</b>' )['translated'] ?? null );
		$this->assertSame( RequestPlan::FLAG_SEGMENTED, $this->translationFlags( 'Go <b>now</b>' ) );
	}

	private function translationFlags( string $text ): ?int {
		global $wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT t.flags FROM %i t JOIN %i s ON s.id = t.string_id WHERE s.hash = %s',
				$this->schema->table( 'translations' ),
				$this->schema->table( 'strings' ),
				StringStore::hash( $text )
			)
		);

		return null === $value ? null : (int) $value;
	}

	private function translationProvider( string $text ): ?string {
		global $wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT t.provider FROM %i t JOIN %i s ON s.id = t.string_id WHERE s.hash = %s',
				$this->schema->table( 'translations' ),
				$this->schema->table( 'strings' ),
				StringStore::hash( $text )
			)
		);

		return null === $value ? null : (string) $value;
	}
}

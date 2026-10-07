<?php
/**
 * Fallback rules of plan §8 with the real Microsoft and Gemini adapters
 * behind fake HTTP (recorded shapes); live keys are checked in Phase 7.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Queue;

use WP_UnitTestCase;
use WST\Database\Schema;
use WST\Languages\Registry;
use WST\Log\Logger;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\Secrets;
use WST\Providers\Selector;
use WST\Queue\Queue;
use WST\Queue\RateLimiter;
use WST\Queue\Usage;
use WST\Queue\Worker;
use WST\Settings;
use WST\Storage\StringStore;
use WST\Tests\Support\HttpStub;

final class RealAdapterFallbackTest extends WP_UnitTestCase {

	use HttpStub;

	private Schema $schema;

	private StringStore $store;

	private Queue $queue;

	private Secrets $secrets;

	private float $now;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		foreach ( Secrets::NAMES as $name ) {
			if ( false !== getenv( $name ) || defined( $name ) ) {
				$this->markTestSkipped( $name . ' is set in the environment; it overrides the stored option.' );
			}
		}
		$this->schema  = new Schema( $wpdb );
		$this->store   = new StringStore( $wpdb, $this->schema );
		$this->queue   = new Queue( $wpdb, $this->schema );
		$this->secrets = new Secrets();
		$this->secrets->set( 'WST_AZURE_KEY', 'ms-test-key-0123456789' );
		$this->secrets->set( 'WST_GEMINI_KEY', 'gm-test-key-0123456789' );
		$this->now = (float) strtotime( '2026-10-07 10:00:00 UTC' );
		update_option(
			'wst_microsoft_languages',
			array(
				'fetched' => time(),
				'codes'   => array( 'en', 'bn' ),
			),
			false
		);
		foreach ( array( ProviderState::OPTION, 'wst_rl_state_microsoft', 'wst_rl_state_gemini' ) as $option ) {
			delete_option( $option );
		}
		$this->stubHttp();
	}

	public function tear_down(): void {
		delete_option( Secrets::OPTION );
		parent::tear_down();
	}

	private function settings(): Settings {
		return new Settings(
			array(
				'target_language'   => 'bn_BD',
				'provider'          => 'microsoft',
				'fallback_provider' => 'gemini',
			),
			'en_US',
			new Registry()
		);
	}

	private function worker(): Worker {
		global $wpdb;
		$settings = $this->settings();
		$registry = ProviderRegistry::configured( $settings, $this->secrets );
		$state    = new ProviderState();

		return new Worker( $settings, $this->queue, $this->store, new RateLimiter( $wpdb ), new Usage( $wpdb, $this->schema ), $state, $registry, new Selector( $settings, $registry, $state ), new Registry(), $this->secrets, new Logger( $wpdb, $this->schema ), $wpdb, fn(): float => $this->now );
	}

	/**
	 * A generateContent answer that translates every requested item.
	 *
	 * @return callable(string, array<string, mixed>): array<string, mixed>
	 */
	private static function geminiEcho(): callable {
		return static function ( string $url, array $args ): array {
			$body  = json_decode( (string) $args['body'], true );
			$items = json_decode( $body['contents'][0]['parts'][0]['text'], true );
			$out   = array_map(
				static fn( array $item ): array => array(
					'id'   => $item['id'],
					'text' => 'GEMINI: ' . $item['text'],
				),
				$items
			);

			return self::response(
				200,
				array(
					'candidates' => array(
						array(
							'content'      => array( 'parts' => array( array( 'text' => (string) wp_json_encode( $out ) ) ) ),
							'finishReason' => 'STOP',
						),
					),
				)
			);
		};
	}

	/**
	 * @param string ...$texts Texts.
	 */
	private function enqueue( string ...$texts ): void {
		$ids = $this->store->recordOnPage( array_fill_keys( $texts, 'text' ), '/page/', null );
		$this->queue->enqueue( array_values( $ids ), 'bn_BD', 'microsoft', Queue::PRIORITY_VISITOR, (int) $this->now );
	}

	private function storedProvider( string $text ): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT t.provider FROM %i t JOIN %i s ON s.id = t.string_id WHERE s.original = %s', $this->schema->table( 'translations' ), $this->schema->table( 'strings' ), $text ) );

		return null === $value ? null : (string) $value;
	}

	public function test_microsoft_quota_403001_hands_over_to_gemini_and_returns_next_month(): void {
		$this->enqueue( 'Add to cart', 'Free shipping' );
		$this->responses[] = self::response(
			403,
			array(
				'error' => array(
					'code'    => 403001,
					'message' => 'The operation is not allowed because the subscription has exceeded its free quota.',
				),
			)
		);
		$this->responses[] = self::geminiEcho();

		$report = $this->worker()->run();

		$this->assertSame( array( 'microsoft' => 'gemini' ), $report->fallbacks );
		$this->assertSame( 2, $report->translated );
		$this->assertSame( 'gemini', $this->storedProvider( 'Add to cart' ) );
		$this->assertStringContainsString( 'api.cognitive.microsofttranslator.com', $this->requests[0][0] );
		$this->assertStringContainsString( 'generativelanguage.googleapis.com', $this->requests[1][0] );

		$settings = $this->settings();
		$registry = ProviderRegistry::configured( $settings, $this->secrets );
		$selector = new Selector( $settings, $registry, new ProviderState() );
		$this->assertSame( 'gemini', $selector->active( $settings->defaultLanguage(), $settings->targetLanguage(), Usage::period( (int) $this->now ) )['id'] ?? null );
		$this->assertSame( 'microsoft', $selector->active( $settings->defaultLanguage(), $settings->targetLanguage(), Usage::period( (int) $this->now + 32 * DAY_IN_SECONDS ) )['id'] ?? null, 'Back to the primary in the next budget period.' );
	}

	public function test_microsoft_auth_failure_pauses_it_and_gemini_takes_over(): void {
		$this->enqueue( 'Add to cart' );
		$this->responses[] = self::fixture( 'microsoft', 'invalid-key' );
		$this->responses[] = self::geminiEcho();

		$report = $this->worker()->run();

		$this->assertSame( array( 'microsoft' => 'gemini' ), $report->fallbacks );
		$this->assertSame( 'gemini', $this->storedProvider( 'Add to cart' ) );
		$this->assertStringContainsString( '401001', ( new ProviderState() )->unavailableReason( 'microsoft', Usage::period( (int) $this->now ) ) );
	}

	public function test_a_plain_429_waits_and_never_switches(): void {
		$this->enqueue( 'Add to cart' );
		$this->responses[] = self::response(
			429,
			array(
				'error' => array(
					'code'    => 429001,
					'message' => 'The server rejected the request because the client has exceeded request limits.',
				),
			),
			array( 'retry-after' => '20' )
		);

		$report = $this->worker()->run();

		$this->assertSame( array(), $report->fallbacks );
		$this->assertSame( 0, $report->translated );
		$this->assertSame( 1, $this->queue->stats()['pending'], 'Still Microsoft\'s, waiting for the Retry-After.' );
		$this->assertCount( 1, $this->requests, 'Gemini was never called.' );
	}

	public function test_gemini_as_fallback_has_its_own_limits(): void {
		$this->enqueue( 'One', 'Two' );
		$this->responses[] = self::response(
			403,
			array(
				'error' => array(
					'code'    => 403001,
					'message' => 'Quota.',
				),
			)
		);
		$this->responses[] = self::geminiEcho();

		$this->worker()->run();
		$this->enqueue( 'Three' );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET provider = 'gemini'", $this->schema->table( 'queue' ) ) );
		$report = $this->worker()->run();

		$this->assertSame( 0, $report->requests, 'Gemini default 5 RPM: the next request waits 12 s.' );
		$this->assertGreaterThan( 11.0, $report->wait );
	}
}

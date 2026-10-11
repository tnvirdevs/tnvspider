<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Providers;

use WP_UnitTestCase;
use WST\Languages\Registry;
use WST\Providers\BatchResult;
use WST\Providers\Errors\AuthError;
use WST\Providers\Errors\PermanentError;
use WST\Providers\Errors\RateLimited;
use WST\Providers\Errors\TransientError;
use WST\Providers\Http;
use WST\Providers\Secrets;
use WST\Providers\TranslateX;
use WST\Settings;
use WST\Tests\Support\HttpStub;

/**
 * HTTP is answered by pre_http_request: recorded fixtures where they exist
 * (tests/fixtures/providers/translatex), otherwise the documented contract.
 * No request leaves the test.
 */
final class TranslateXTest extends WP_UnitTestCase {

	private const KEY = 'test-key-0123456789';

	use HttpStub;

	private Secrets $secrets;

	public function set_up(): void {
		parent::set_up();
		$this->secrets = new Secrets();
		$this->secrets->set( TranslateX::SECRET, self::KEY );
		delete_option( 'wst_translatex_languages' );
		$this->stubHttp();
	}

	public function tear_down(): void {
		delete_option( Secrets::OPTION );
		parent::tear_down();
	}

	/**
	 * The key the adapter actually uses (an environment key wins over the option).
	 */
	private function key(): string {
		return $this->secrets->get( TranslateX::SECRET );
	}

	/**
	 * @param array<string, mixed> $providers Provider settings.
	 */
	private function adapter( array $providers = array() ): TranslateX {
		$settings = new Settings(
			array(
				'target_language' => 'bn_BD',
				'providers'       => array( 'translatex' => $providers ),
			),
			'en_US',
			new Registry()
		);

		return new TranslateX( $settings, $this->secrets, new Http( $this->secrets ) );
	}

	private function languagesLoaded(): void {
		update_option(
			'wst_translatex_languages',
			array(
				'fetched' => time(),
				'codes'   => array( 'en', 'bn', 'ar', 'it' ),
			),
			false
		);
	}

	public function test_recorded_invalid_key_response_is_an_auth_error(): void {
		$this->languagesLoaded();
		$this->responses[] = self::fixture( 'translatex', 'invalid-key' );

		try {
			$this->adapter()->translate( array( 7 => 'Hello World!' ), 'en', 'bn', false );
			$this->fail( 'Expected AuthError.' );
		} catch ( AuthError $e ) {
			$this->assertStringContainsString( 'invalid api key', $e->getMessage() );
			$this->assertStringNotContainsString( $this->key(), $e->getMessage() );
		}
	}

	public function test_recorded_missing_key_response_is_an_auth_error(): void {
		$this->languagesLoaded();
		$this->responses[] = self::fixture( 'translatex', 'missing-key' );

		$this->expectException( AuthError::class );
		$this->adapter()->translate( array( 7 => 'Hello World!' ), 'en', 'bn', false );
	}

	public function test_recorded_success_batch_and_language_list(): void {
		$this->responses[] = self::fixture( 'translatex', 'supported-languages' );
		$this->responses[] = self::fixture( 'translatex', 'success-batch' );
		$adapter           = $this->adapter();

		$codes = $adapter->loadLanguages();
		$this->assertCount( 35, $codes, 'Free plan: 35 languages.' );
		$this->assertTrue( $adapter->supportsPair( 'en', 'bn' ) );
		$this->assertTrue( $adapter->supportsPair( 'en', 'ar' ) );

		$result = $adapter->translate(
			array(
				3 => 'Shop now',
				9 => 'Add to cart',
				4 => 'Free shipping on orders over 1,500.',
			),
			'en',
			'bn',
			false
		);
		$this->assertSame( array( 3, 9, 4 ), array_keys( $result->translations ) );
		$this->assertSame( 'এখনই কেনাকাটা করুন', $result->translations[3] );
		$this->assertSame( 45, $result->rateLimitRemaining );
	}

	public function test_recorded_empty_entry_is_not_a_translation(): void {
		$this->languagesLoaded();
		$this->responses[] = self::fixture( 'translatex', 'mixed-empty-text' );

		$result = $this->adapter()->translate(
			array(
				1 => 'Shop now',
				2 => 'Sale',
				3 => 'Add to cart',
			),
			'en',
			'bn',
			false
		);

		$this->assertSame( array( 1, 3 ), array_keys( $result->translations ) );
		$this->assertSame( array( 2 ), array_keys( $result->errors ) );
	}

	public function test_recorded_failures_map_to_their_classes(): void {
		$this->languagesLoaded();
		$cases = array(
			'unsupported-pair' => PermanentError::class,
			'no-text-param'    => PermanentError::class,
			'html-param'       => PermanentError::class,
			'chars-5000'       => PermanentError::class,
			'rate-limited'     => RateLimited::class,
		);
		foreach ( $cases as $fixture => $class ) {
			$this->responses[] = self::fixture( 'translatex', $fixture );
			try {
				$this->adapter()->translate( array( 1 => 'Hello' ), 'en', 'bn', false );
				$this->fail( 'Expected ' . $class . ' for ' . $fixture );
			} catch ( \Throwable $e ) {
				$this->assertInstanceOf( $class, $e, $fixture );
				if ( 'chars-5000' === $fixture ) {
					$this->assertStringStartsWith( BatchResult::TOO_LARGE, $e->getMessage() );
				}
				if ( $e instanceof RateLimited ) {
					$this->assertSame( 60, $e->retryAfter(), 'No Retry-After header: wait a minute.' );
				}
			}
		}
	}

	public function test_texts_over_the_byte_limit_are_split_at_sentences_and_joined(): void {
		$this->languagesLoaded();
		// About 2,850 bytes of Bengali (about 1,000 characters): over the 2,000-byte limit.
		$sentence = 'আমাদের দোকানে স্বাগতম, আজই কেনাকাটা করুন। ';
		$long     = trim( str_repeat( $sentence, 25 ) ) . "\n\nশেষ অনুচ্ছেদ।";
		$this->assertGreaterThan( TranslateX::MAX_TEXT_BYTES, strlen( $long ) );
		$this->assertLessThan( TranslateX::MAX_TEXT_BYTES, mb_strlen( $long ), 'The limit is bytes, not characters.' );
		$this->responses[] = self::response( 200, array( 'translation' => array( 'One', 'PART A', 'PART B' ) ) );

		$result = $this->adapter()->translate(
			array(
				1 => 'এক',
				2 => $long,
			),
			'bn',
			'en',
			false
		);

		parse_str( str_replace( 'text=', 'text[]=', $this->requests[0][1]['body'] ), $sent );
		$this->assertCount( 3, $sent['text'], 'One request: the short text plus two pieces.' );
		$this->assertSame( 'এক', $sent['text'][0] );
		foreach ( array_slice( $sent['text'], 1 ) as $piece ) {
			$this->assertLessThanOrEqual( TranslateX::MAX_TEXT_BYTES, strlen( $piece ) );
			$this->assertMatchesRegularExpression( '/।$/u', $piece, 'Pieces end at a sentence boundary.' );
		}
		$this->assertSame( $long, $sent['text'][1] . ' ' . $sent['text'][2] );
		$this->assertSame(
			array(
				1 => 'One',
				2 => 'PART A PART B',
			),
			$result->translations
		);
	}

	public function test_an_empty_piece_fails_the_whole_long_text(): void {
		$this->languagesLoaded();
		$long              = trim( str_repeat( 'Welcome to our shop. ', 120 ) );
		$this->responses[] = self::response( 200, array( 'translation' => array( 'স্বাগতম', '' ) ) );

		$result = $this->adapter()->translate( array( 7 => $long ), 'en', 'bn', false );

		$this->assertSame( array(), $result->translations );
		$this->assertSame( array( 7 ), array_keys( $result->errors ) );
	}

	public function test_no_key_configured_fails_without_a_request(): void {
		if ( false !== getenv( TranslateX::SECRET ) || defined( TranslateX::SECRET ) ) {
			$this->markTestSkipped( TranslateX::SECRET . ' is set in the environment; it overrides the stored option.' );
		}
		$this->secrets->set( TranslateX::SECRET, '' );
		$this->languagesLoaded();

		$this->expectException( AuthError::class );
		try {
			$this->adapter()->translate( array( 1 => 'Hi' ), 'en', 'bn', false );
		} finally {
			$this->assertSame( array(), $this->requests );
		}
	}

	public function test_success_maps_positional_results_and_sends_the_key_only_in_a_header(): void {
		$this->languagesLoaded();
		$this->responses[] = self::response(
			200,
			array( 'translation' => array( 'হ্যালো', 'বিদায় & শুভেচ্ছা' ) ),
			array(
				'x-tx-ratelimit'           => '50/min',
				'x-tx-ratelimit-remaining' => '48',
			)
		);

		$result = $this->adapter()->translate(
			array(
				12 => 'Hello',
				5  => 'Bye & regards',
			),
			'en',
			'bn',
			false
		);

		$this->assertSame(
			array(
				12 => 'হ্যালো',
				5  => 'বিদায় & শুভেচ্ছা',
			),
			$result->translations
		);
		$this->assertSame( array(), $result->errors );
		$this->assertSame( 48, $result->rateLimitRemaining );

		[ $url, $args ] = $this->requests[0];
		$this->assertSame( 'https://api.translatex.com/translate?sl=en&tl=bn', $url );
		$this->assertStringNotContainsString( $this->key(), $url );
		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame( $this->key(), $args['headers']['X-API-Key'] );
		$this->assertSame( 'application/x-www-form-urlencoded; charset=UTF-8', $args['headers']['Content-Type'] );
		$this->assertSame( 'text=Hello&text=Bye%20%26%20regards', $args['body'] );
	}

	public function test_empty_entry_is_a_per_item_failure_never_a_translation(): void {
		$this->languagesLoaded();
		$this->responses[] = self::response( 200, array( 'translation' => array( 'এক', '  ', null ) ) );

		$result = $this->adapter()->translate(
			array(
				1 => 'One',
				2 => 'Two',
				3 => 'Three',
			),
			'en',
			'bn',
			false
		);

		$this->assertSame( array( 1 => 'এক' ), $result->translations );
		$this->assertSame( array( 2, 3 ), array_keys( $result->errors ) );
	}

	public function test_result_count_mismatch_fails_the_batch(): void {
		$this->languagesLoaded();
		$this->responses[] = self::response( 200, array( 'translation' => array( 'এক' ) ) );

		try {
			$this->adapter()->translate(
				array(
					1 => 'One',
					2 => 'Two',
				),
				'en',
				'bn',
				false
			);
			$this->fail( 'Expected PermanentError.' );
		} catch ( PermanentError $e ) {
			$this->assertStringStartsWith( BatchResult::COUNT_MISMATCH, $e->getMessage() );
		}
	}

	public function test_malformed_success_body_is_transient(): void {
		$this->languagesLoaded();
		$this->responses[] = self::response( 200, array( 'translation' => 'not a list' ) );

		$this->expectException( TransientError::class );
		$this->adapter()->translate( array( 1 => 'One' ), 'en', 'bn', false );
	}

	public function test_429_honours_retry_after_or_waits_a_minute(): void {
		$this->languagesLoaded();
		$this->responses[] = self::response( 429, array( 'err' => 'too many requests' ), array( 'retry-after' => '12' ) );
		$this->responses[] = self::response( 429, array( 'err' => 'too many requests' ) );

		foreach ( array( 12, 60 ) as $expected ) {
			try {
				$this->adapter()->translate( array( 1 => 'One' ), 'en', 'bn', false );
				$this->fail( 'Expected RateLimited.' );
			} catch ( RateLimited $e ) {
				$this->assertSame( $expected, $e->retryAfter() );
			}
		}
	}

	public function test_server_and_network_errors_are_transient_and_redacted(): void {
		$this->languagesLoaded();
		$this->responses[] = self::response( 503, array( 'err' => 'maintenance' ) );
		$this->responses[] = new \WP_Error( 'http_request_failed', 'cURL error 28 for https://api.translatex.com/?key=' . $this->key() );

		foreach ( array( 'maintenance', 'Network error' ) as $expected ) {
			try {
				$this->adapter()->translate( array( 1 => 'One' ), 'en', 'bn', false );
				$this->fail( 'Expected TransientError.' );
			} catch ( TransientError $e ) {
				$this->assertStringContainsString( $expected, $e->getMessage() );
				$this->assertStringNotContainsString( $this->key(), $e->getMessage() );
			}
		}
	}

	public function test_other_client_errors_are_permanent(): void {
		$this->languagesLoaded();
		$this->responses[] = self::response( 400, array( 'err' => 'unsupported target language' ) );

		$this->expectException( PermanentError::class );
		$this->expectExceptionMessage( 'unsupported target language' );
		$this->adapter()->translate( array( 1 => 'One' ), 'en', 'it', false );
	}

	public function test_pair_support_comes_from_the_stored_list_without_requests(): void {
		$adapter = $this->adapter();
		$this->assertTrue( $adapter->supportsPair( 'en', 'xx' ), 'Not loaded yet: queue anyway; translate() loads the list.' );
		$this->assertSame( array(), $this->requests );

		$this->responses[] = self::response(
			200,
			array(
				'languages' => array(
					array(
						'language' => 'en',
						'name'     => 'English',
					),
					array(
						'language' => 'bn',
						'name'     => 'Bengali',
					),
					array(
						'language' => '<script>',
						'name'     => 'bad',
					),
				),
			)
		);
		$this->assertSame( array( 'en', 'bn' ), $adapter->loadLanguages() );
		$this->assertSame( 'https://api.translatex.com/supported-languages', $this->requests[0][0] );
		$this->assertSame( 'GET', $this->requests[0][1]['method'] );

		$this->assertTrue( $adapter->supportsPair( 'en', 'bn' ) );
		$this->assertFalse( $adapter->supportsPair( 'en', 'ar' ) );
		$this->assertCount( 1, $this->requests );
	}

	public function test_missing_language_list_is_loaded_on_demand_and_an_unsupported_pair_is_refused(): void {
		$this->responses[] = self::fixture( 'translatex', 'supported-languages' );

		try {
			$this->adapter()->translate( array( 1 => 'Hello' ), 'en', 'xx', false );
			$this->fail( 'Expected PermanentError.' );
		} catch ( PermanentError $e ) {
			$this->assertStringContainsString( 'does not support en to xx', $e->getMessage() );
		}
		$this->assertCount( 1, $this->requests, 'Only the language list was fetched; nothing was sent for translation.' );
		$this->assertFalse( $this->adapter()->supportsPair( 'en', 'xx' ), 'Now known: the provider is unavailable for this pair.' );
		$this->assertTrue( $this->adapter()->supportsPair( 'en', 'bn' ) );
	}

	public function test_stale_language_list_is_refreshed_before_translating(): void {
		update_option(
			'wst_translatex_languages',
			array(
				'fetched' => time() - DAY_IN_SECONDS - 5,
				'codes'   => array( 'en', 'bn' ),
			),
			false
		);
		$this->responses[] = self::response( 200, array( 'languages' => array( array( 'language' => 'en' ), array( 'language' => 'bn' ) ) ) );
		$this->responses[] = self::response( 200, array( 'translation' => array( 'এক' ) ) );

		$this->adapter()->translate( array( 1 => 'One' ), 'en', 'bn', false );

		$this->assertStringEndsWith( '/supported-languages', $this->requests[0][0] );
		$this->assertStringEndsWith( '/translate?sl=en&tl=bn', $this->requests[1][0] );
	}

	public function test_capabilities_follow_the_plan_and_never_use_html(): void {
		$this->assertSame( 50, $this->adapter()->capabilities()->defaultRpm );
		$this->assertSame( 75, $this->adapter( array( 'plan' => 'business' ) )->capabilities()->defaultRpm );
		$enterprise = $this->adapter( array( 'plan' => 'enterprise' ) )->capabilities();
		$this->assertSame( 100, $enterprise->defaultRpm );
		$this->assertFalse( $enterprise->supportsHtml );
		$this->assertSame( '{%d}', $enterprise->tokenFormat, 'TranslateX keeps "{1}" intact (tokens-format fixtures).' );
	}

	public function test_connection_test_reports_failure_without_the_key(): void {
		$this->responses[] = self::fixture( 'translatex', 'invalid-key' );

		$result = $this->adapter()->testConnection();

		$this->assertFalse( $result->ok );
		$this->assertStringContainsString( 'invalid api key', $result->message );
		$this->assertStringNotContainsString( $this->key(), $result->message );
	}

	public function test_connection_test_loads_languages_and_translates_a_sample(): void {
		$this->responses[] = self::response( 200, array( 'languages' => array( array( 'language' => 'en' ), array( 'language' => 'bn' ), array( 'language' => 'it' ) ) ) );
		$this->responses[] = self::response( 200, array( 'translation' => array( 'Ciao mondo!' ) ), array( 'x-tx-ratelimit-remaining' => '49' ) );

		$result = $this->adapter()->testConnection();

		$this->assertTrue( $result->ok, $result->message );
		$this->assertSame( 3, $result->details['languages'] );
		$this->assertSame( 'supported', $result->details['pair'] );
		$this->assertSame( 'Ciao mondo!', $result->details['sample'] );
	}
}

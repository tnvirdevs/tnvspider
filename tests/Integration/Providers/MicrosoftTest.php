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
use WST\Providers\Errors\QuotaExceeded;
use WST\Providers\Errors\RateLimited;
use WST\Providers\Errors\TransientError;
use WST\Providers\Http;
use WST\Providers\Microsoft;
use WST\Providers\Secrets;
use WST\Settings;
use WST\Tests\Support\HttpStub;

/**
 * Recorded fixtures (tests/fixtures/providers/microsoft) where they exist,
 * otherwise the documented v3 contract and error codes (plan §7.1).
 */
final class MicrosoftTest extends WP_UnitTestCase {

	use HttpStub;

	private Secrets $secrets;

	public function set_up(): void {
		parent::set_up();
		$this->secrets = new Secrets();
		$this->secrets->set( Microsoft::SECRET, 'ms-test-key-0123456789' );
		$this->secrets->set( Microsoft::REGION, 'westeurope' );
		update_option(
			'wst_microsoft_languages',
			array(
				'fetched' => time(),
				'codes'   => array( 'en', 'bn', 'ar', 'it' ),
			),
			false
		);
		$this->stubHttp();
	}

	public function tear_down(): void {
		delete_option( Secrets::OPTION );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $providers Provider settings.
	 */
	private function adapter( array $providers = array() ): Microsoft {
		$settings = new Settings(
			array(
				'target_language' => 'bn_BD',
				'providers'       => array( 'microsoft' => $providers ),
			),
			'en_US',
			new Registry()
		);

		return new Microsoft( $settings, $this->secrets, new Http( $this->secrets ) );
	}

	/**
	 * @param int    $code    Microsoft error code.
	 * @param int    $status  HTTP status.
	 * @param string $message Message.
	 * @param array<string, string> $headers Headers.
	 * @return array<string, mixed>
	 */
	private static function error( int $code, int $status, string $message = 'Failure.', array $headers = array() ): array {
		return self::response(
			$status,
			array(
				'error' => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			$headers
		);
	}

	/**
	 * The exception the adapter throws for one response.
	 *
	 * @param array<string, mixed> $response Response.
	 */
	private function failure( array $response ): \Throwable {
		$this->responses[] = $response;
		try {
			$this->adapter()->translate( array( 1 => 'One' ), 'en', 'bn', false );
		} catch ( \Throwable $e ) {
			return $e;
		}
		$this->fail( 'Expected an exception.' );
	}

	public function test_recorded_missing_and_invalid_key_responses_are_auth_errors(): void {
		foreach ( array( 'missing-key', 'invalid-key' ) as $case ) {
			$e = $this->failure( self::fixture( 'microsoft', $case ) );
			$this->assertInstanceOf( AuthError::class, $e, $case );
			$this->assertStringContainsString( '401001', $e->getMessage() );
			$this->assertStringNotContainsString( $this->secrets->get( Microsoft::SECRET ), $e->getMessage() );
		}
	}

	public function test_recorded_language_list_has_bengali_and_arabic(): void {
		delete_option( 'wst_microsoft_languages' );
		$this->responses[] = self::fixture( 'microsoft', 'languages' );

		$codes = $this->adapter()->loadLanguages();

		$this->assertGreaterThan( 100, count( $codes ) );
		$this->assertTrue( $this->adapter()->supportsPair( 'en', 'bn' ) );
		$this->assertTrue( $this->adapter()->supportsPair( 'en', 'ar' ) );
		$this->assertFalse( $this->adapter()->supportsPair( 'en', 'xx' ) );
		$this->assertSame( 'https://api.cognitive.microsofttranslator.com/languages?api-version=3.0&scope=translation', $this->requests[0][0] );
		$this->assertArrayNotHasKey( 'Ocp-Apim-Subscription-Key', $this->requests[0][1]['headers'], 'The language list needs no key.' );
	}

	public function test_success_sends_json_with_key_and_region_headers(): void {
		$this->responses[] = self::response(
			200,
			array(
				array(
					'translations' => array(
						array(
							'text' => 'কার্টে যোগ করুন',
							'to'   => 'bn',
						),
					),
				),
				array(
					'translations' => array(
						array(
							'text' => '<a id="1">আমাদের গল্প</a> পড়ুন',
							'to'   => 'bn',
						),
					),
				),
			)
		);

		$result = $this->adapter()->translate(
			array(
				8 => 'Add to cart',
				3 => 'Read <a id="1">our story</a>',
			),
			'en',
			'bn',
			true
		);

		$this->assertSame(
			array(
				8 => 'কার্টে যোগ করুন',
				3 => '<a id="1">আমাদের গল্প</a> পড়ুন',
			),
			$result->translations
		);
		[ $url, $args ] = $this->requests[0];
		$this->assertSame( 'https://api.cognitive.microsofttranslator.com/translate?api-version=3.0&from=en&to=bn&textType=html', $url );
		$this->assertSame( $this->secrets->get( Microsoft::SECRET ), $args['headers']['Ocp-Apim-Subscription-Key'] );
		$this->assertSame( $this->secrets->get( Microsoft::REGION ), $args['headers']['Ocp-Apim-Subscription-Region'] );
		$this->assertSame( array( array( 'Text' => 'Add to cart' ), array( 'Text' => 'Read <a id="1">our story</a>' ) ), json_decode( $args['body'], true ) );
	}

	public function test_plain_text_and_endpoint_override(): void {
		$this->responses[] = self::response( 200, array( array( 'translations' => array( array( 'text' => 'এক' ) ) ) ) );

		$this->adapter( array( 'endpoint' => 'https://api-eur.cognitive.microsofttranslator.com' ) )->translate( array( 1 => 'One' ), 'en', 'bn', false );

		$this->assertSame( 'https://api-eur.cognitive.microsofttranslator.com/translate?api-version=3.0&from=en&to=bn', $this->requests[0][0] );
	}

	public function test_empty_entries_fail_per_item_and_count_mismatch_fails_the_batch(): void {
		$this->responses[] = self::response(
			200,
			array(
				array( 'translations' => array( array( 'text' => 'এক' ) ) ),
				array( 'translations' => array( array( 'text' => '' ) ) ),
				array( 'translations' => array() ),
			)
		);
		$result            = $this->adapter()->translate(
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

		$this->responses[] = self::response( 200, array() );
		$this->expectException( PermanentError::class );
		$this->expectExceptionMessage( BatchResult::COUNT_MISMATCH );
		$this->adapter()->translate( array( 1 => 'One' ), 'en', 'bn', false );
	}

	public function test_error_codes_win_over_http_status(): void {
		$this->assertInstanceOf( QuotaExceeded::class, $this->failure( self::error( 403001, 403, 'Free quota used.' ) ), '403001 is the free quota (D11).' );
		$this->assertInstanceOf( AuthError::class, $this->failure( self::error( 403000, 403 ) ) );
		$this->assertInstanceOf( AuthError::class, $this->failure( self::error( 401000, 401 ) ) );

		$limited = $this->failure( self::error( 429001, 429, 'Too many.', array( 'retry-after' => '7' ) ) );
		$this->assertInstanceOf( RateLimited::class, $limited );
		$this->assertSame( 7, $limited instanceof RateLimited ? $limited->retryAfter() : 0 );

		foreach ( array( array( 408001, 408 ), array( 500000, 500 ), array( 503000, 503 ) ) as [ $code, $status ] ) {
			$this->assertInstanceOf( TransientError::class, $this->failure( self::error( $code, $status ) ), (string) $code );
		}
		foreach ( array( 400050, 400072, 400077 ) as $code ) {
			$e = $this->failure( self::error( $code, 400 ) );
			$this->assertInstanceOf( PermanentError::class, $e );
			$this->assertStringStartsWith( BatchResult::TOO_LARGE, $e->getMessage(), (string) $code );
		}
		$other = $this->failure( self::error( 400036, 400, 'The target language is not valid.' ) );
		$this->assertInstanceOf( PermanentError::class, $other );
		$this->assertStringNotContainsString( BatchResult::TOO_LARGE, $other->getMessage() );
	}

	public function test_status_is_used_when_no_code_is_given(): void {
		$this->assertInstanceOf( TransientError::class, $this->failure( self::response( 502, 'Bad gateway' ) ) );
		$this->assertInstanceOf( RateLimited::class, $this->failure( self::response( 429, null ) ) );
		$this->assertInstanceOf( PermanentError::class, $this->failure( self::response( 404, null ) ) );
	}

	public function test_capabilities_are_the_documented_caps(): void {
		$caps = $this->adapter()->capabilities();

		$this->assertTrue( $caps->supportsHtml );
		$this->assertSame( 1000, $caps->maxItems );
		$this->assertSame( 50000, $caps->maxChars );
		$this->assertSame( 0, $caps->defaultRpm );
		$this->assertSame( 33000, $caps->defaultCpm );
	}

	public function test_connection_test_reports_a_recorded_auth_failure(): void {
		$this->responses[] = self::fixture( 'microsoft', 'languages' );
		$this->responses[] = self::fixture( 'microsoft', 'invalid-key' );

		$result = $this->adapter()->testConnection();

		$this->assertFalse( $result->ok );
		$this->assertStringContainsString( 'rejected the key', $result->message );
	}
}

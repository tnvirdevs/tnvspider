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
use WST\Providers\Gemini;
use WST\Providers\Http;
use WST\Providers\Secrets;
use WST\Settings;
use WST\Tests\Support\HttpStub;

/**
 * Recorded fixtures (tests/fixtures/providers/gemini) for the key failures,
 * otherwise the documented generateContent contract.
 */
final class GeminiTest extends WP_UnitTestCase {

	use HttpStub;

	private Secrets $secrets;

	public function set_up(): void {
		parent::set_up();
		$this->secrets = new Secrets();
		$this->secrets->set( Gemini::SECRET, 'gm-test-key-0123456789' );
		$this->stubHttp();
	}

	public function tear_down(): void {
		delete_option( Secrets::OPTION );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $providers Provider settings.
	 */
	private function adapter( array $providers = array() ): Gemini {
		$settings = new Settings(
			array(
				'target_language' => 'bn_BD',
				'providers'       => array( 'gemini' => $providers ),
			),
			'en_US',
			new Registry()
		);

		return new Gemini( $settings, $this->secrets, new Http( $this->secrets ) );
	}

	/**
	 * A generateContent answer whose text is $output.
	 *
	 * @param mixed         $output       Model output (encoded as JSON text unless a string).
	 * @param string        $finishReason Finish reason.
	 * @param array<mixed>  $extraParts   Parts placed before the answer.
	 * @return array<string, mixed>
	 */
	private static function answer( $output, string $finishReason = 'STOP', array $extraParts = array() ): array {
		$parts   = $extraParts;
		$parts[] = array( 'text' => is_string( $output ) ? $output : (string) wp_json_encode( $output, JSON_UNESCAPED_UNICODE ) );

		return self::response(
			200,
			array(
				'candidates' => array(
					array(
						'content'      => array(
							'role'  => 'model',
							'parts' => $parts,
						),
						'finishReason' => $finishReason,
					),
				),
			)
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
			$this->adapter()->translate(
				array(
					1 => 'One',
					2 => 'Two',
				),
				'en',
				'bn',
				false
			);
		} catch ( \Throwable $e ) {
			return $e;
		}
		$this->fail( 'Expected an exception.' );
	}

	public function test_recorded_key_failures_are_auth_errors(): void {
		foreach ( array( 'invalid-key', 'missing-key' ) as $case ) {
			$e = $this->failure( self::fixture( 'gemini', $case ) );
			$this->assertInstanceOf( AuthError::class, $e, $case );
			$this->assertStringNotContainsString( $this->secrets->get( Gemini::SECRET ), $e->getMessage() );
		}
	}

	public function test_success_maps_ids_and_sends_structured_output_request(): void {
		$this->responses[] = self::answer(
			array(
				array(
					'id'   => 9,
					'text' => '<a id="1">আমাদের গল্প</a> পড়ুন',
				),
				array(
					'id'   => 4,
					'text' => 'কার্টে যোগ করুন',
				),
			),
			'STOP',
			array(
				array(
					'text'    => 'thinking…',
					'thought' => true,
				),
			)
		);

		$result = $this->adapter()->translate(
			array(
				4 => 'Add to cart',
				9 => 'Read <a id="1">our story</a>',
			),
			'en',
			'bn',
			true
		);

		$this->assertSame(
			array(
				9 => '<a id="1">আমাদের গল্প</a> পড়ুন',
				4 => 'কার্টে যোগ করুন',
			),
			$result->translations
		);
		[ $url, $args ] = $this->requests[0];
		$this->assertSame( 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent', $url );
		$this->assertSame( $this->secrets->get( Gemini::SECRET ), $args['headers']['x-goog-api-key'] );
		$body = json_decode( $args['body'], true );
		$this->assertSame( 0, $body['generationConfig']['temperature'] );
		$this->assertSame( 'APPLICATION_JSON', $body['generationConfig']['responseFormat']['text']['mimeType'] );
		$this->assertSame( array( 'id', 'text' ), $body['generationConfig']['responseFormat']['text']['schema']['items']['required'] );
		$this->assertSame(
			array(
				array(
					'id'   => 4,
					'text' => 'Add to cart',
				),
				array(
					'id'   => 9,
					'text' => 'Read <a id="1">our story</a>',
				),
			),
			json_decode( $body['contents'][0]['parts'][0]['text'], true )
		);
		$this->assertStringContainsString( '"en"', $body['systemInstruction']['parts'][0]['text'] );
		$this->assertStringContainsString( '<a id="1">', $body['systemInstruction']['parts'][0]['text'] );
	}

	public function test_model_setting_changes_the_url(): void {
		$this->responses[] = self::answer(
			array(
				array(
					'id'   => 1,
					'text' => 'এক',
				),
			)
		);

		$this->adapter( array( 'model' => 'gemini-3.8-flash' ) )->translate( array( 1 => 'One' ), 'en', 'bn', false );

		$this->assertStringContainsString( '/models/gemini-3.8-flash:generateContent', $this->requests[0][0] );
	}

	public function test_empty_text_fails_that_item_only(): void {
		$this->responses[] = self::answer(
			array(
				array(
					'id'   => 1,
					'text' => 'এক',
				),
				array(
					'id'   => 2,
					'text' => ' ',
				),
			)
		);

		$result = $this->adapter()->translate(
			array(
				1 => 'One',
				2 => 'Two',
			),
			'en',
			'bn',
			false
		);

		$this->assertSame( array( 1 => 'এক' ), $result->translations );
		$this->assertSame( array( 2 ), array_keys( $result->errors ) );
	}

	public function test_invalid_output_is_a_count_mismatch(): void {
		$outputs = array(
			'missing id'  => array(
				array(
					'id'   => 1,
					'text' => 'এক',
				),
			),
			'unknown id'  => array(
				array(
					'id'   => 1,
					'text' => 'এক',
				),
				array(
					'id'   => 7,
					'text' => 'সাত',
				),
			),
			'repeated id' => array(
				array(
					'id'   => 1,
					'text' => 'এক',
				),
				array(
					'id'   => 1,
					'text' => 'এক',
				),
			),
			'commentary'  => 'Here are your translations: [...]',
			'object'      => array( 'translations' => array() ),
		);
		foreach ( $outputs as $label => $output ) {
			$e = $this->failure( self::answer( $output ) );
			$this->assertInstanceOf( PermanentError::class, $e, $label );
			$this->assertStringStartsWith( BatchResult::COUNT_MISMATCH, $e->getMessage(), $label );
		}
	}

	public function test_cut_off_blocked_and_unfinished_answers(): void {
		$cut = $this->failure( self::answer( '[{"id": 1, "te', 'MAX_TOKENS' ) );
		$this->assertInstanceOf( PermanentError::class, $cut );
		$this->assertStringStartsWith( BatchResult::TOO_LARGE, $cut->getMessage() );

		$this->assertInstanceOf( PermanentError::class, $this->failure( self::answer( '[]', 'SAFETY' ) ) );
		$this->assertInstanceOf( PermanentError::class, $this->failure( self::response( 200, array( 'promptFeedback' => array( 'blockReason' => 'OTHER' ) ) ) ) );
		$this->assertInstanceOf( TransientError::class, $this->failure( self::response( 200, array( 'candidates' => array() ) ) ) );
	}

	public function test_http_errors(): void {
		$limited = $this->failure(
			self::response(
				429,
				array(
					'error' => array(
						'code'    => 429,
						'status'  => 'RESOURCE_EXHAUSTED',
						'message' => 'Quota exceeded.',
						'details' => array(
							array(
								'@type'      => 'type.googleapis.com/google.rpc.RetryInfo',
								'retryDelay' => '17s',
							),
						),
					),
				)
			)
		);
		$this->assertInstanceOf( RateLimited::class, $limited );
		$this->assertSame( 18, $limited instanceof RateLimited ? $limited->retryAfter() : 0 );

		$plain = $this->failure( self::response( 429, array( 'error' => array( 'code' => 429 ) ) ) );
		$this->assertSame( 60, $plain instanceof RateLimited ? $plain->retryAfter() : 0 );

		$this->assertInstanceOf( QuotaExceeded::class, $this->failure( self::response( 402, array( 'error' => array( 'code' => 402 ) ) ) ) );
		$this->assertInstanceOf( TransientError::class, $this->failure( self::response( 503, array( 'error' => array( 'code' => 503 ) ) ) ) );
		$this->assertInstanceOf( TransientError::class, $this->failure( self::response( 408, null ) ) );
		$unknownModel = $this->failure( self::response( 404, array( 'error' => array( 'message' => 'models/x is not found' ) ) ) );
		$this->assertInstanceOf( AuthError::class, $unknownModel, 'An unknown model pauses the provider until the setting is fixed.' );
		$this->assertStringContainsString( 'gemini-3.5-flash-lite', $unknownModel->getMessage() );
		$this->assertInstanceOf( PermanentError::class, $this->failure( self::response( 400, array( 'error' => array( 'status' => 'INVALID_ARGUMENT' ) ) ) ) );
	}

	public function test_capabilities_and_pairs_need_no_request(): void {
		$caps = $this->adapter()->capabilities();

		$this->assertTrue( $caps->supportsHtml );
		$this->assertSame( 'America/Los_Angeles', $caps->dayTimezone );
		$this->assertSame( 5, $caps->defaultRpm );
		$this->assertSame( 100, $caps->defaultRpd );
		$this->assertTrue( $this->adapter()->supportsPair( 'en', 'bn' ) );
		$this->assertTrue( $this->adapter()->supportsPair( 'bn', 'ar' ) );
		$this->assertFalse( $this->adapter()->supportsPair( 'en', 'en' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_connection_test(): void {
		$this->responses[] = self::answer(
			array(
				array(
					'id'   => 1,
					'text' => 'Ciao mondo!',
				),
			)
		);
		$ok                = $this->adapter()->testConnection();
		$this->assertTrue( $ok->ok, $ok->message );
		$this->assertSame( 'gemini-3.5-flash-lite', $ok->details['model'] );

		$this->responses[] = self::fixture( 'gemini', 'invalid-key' );
		$bad               = $this->adapter()->testConnection();
		$this->assertFalse( $bad->ok );
		$this->assertStringContainsString( 'API key not valid', $bad->message );
	}
}

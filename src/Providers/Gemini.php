<?php
/**
 * Gemini adapter (plan §7.3).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

use WST\Providers\Errors\AuthError;
use WST\Providers\Errors\PermanentError;
use WST\Providers\Errors\ProviderError;
use WST\Providers\Errors\QuotaExceeded;
use WST\Providers\Errors\RateLimited;
use WST\Providers\Errors\TransientError;
use WST\Settings;

/**
 * Calls generateContent with structured JSON output: the input is a JSON array of
 * {id, text}, the output must be the same ids with translations, nothing
 * else. Limits are per Google Cloud project and reset at midnight Pacific.
 */
final class Gemini implements ProviderInterface {

	public const ID            = 'gemini';
	public const SECRET        = 'WST_GEMINI_KEY';
	public const API           = 'https://generativelanguage.googleapis.com/v1beta';
	public const DEFAULT_MODEL = 'gemini-3.5-flash-lite';

	/**
	 * Conservative defaults: Google does not publish per-model free-tier
	 * numbers (HANDOVER, provider facts). All are user-editable.
	 */
	private const MAX_ITEMS   = 50;
	private const MAX_CHARS   = 5000;
	private const DEFAULT_RPM = 5;
	private const DEFAULT_RPD = 100;

	/** Seconds to wait after a 429 that names no delay. */
	private const RATE_LIMIT_WAIT = 60;

	/**
	 * Create the adapter.
	 *
	 * @param Settings $settings Settings.
	 * @param Secrets  $secrets  Key store.
	 * @param Http     $http     HTTP client.
	 */
	public function __construct(
		private Settings $settings,
		private Secrets $secrets,
		private Http $http
	) {
	}

	/**
	 * Provider id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Display name.
	 */
	public function label(): string {
		return 'Gemini';
	}

	/**
	 * Limits and features.
	 */
	public function capabilities(): Capabilities {
		return new Capabilities( true, self::MAX_ITEMS, self::MAX_CHARS, self::DEFAULT_RPM, self::DEFAULT_RPD, 0, 'America/Los_Angeles' );
	}

	/**
	 * A general model translates any pair of valid language codes.
	 *
	 * @param string $source Source code.
	 * @param string $target Target code.
	 */
	public function supportsPair( string $source, string $target ): bool {
		$valid = static fn( string $code ): bool => 1 === preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8}){0,2}$/', $code );

		return $valid( $source ) && $valid( $target ) && $source !== $target;
	}

	/**
	 * Translate items.
	 *
	 * @param array<int, string> $items  Unit id => text.
	 * @param string             $source Source code.
	 * @param string             $target Target code.
	 * @param bool               $html   Items may contain tag placeholders.
	 * @throws PermanentError When the output does not match the input ids (and every failure of call()).
	 */
	public function translate( array $items, string $source, string $target, bool $html ): BatchResult {
		$result = new BatchResult();
		if ( array() === $items ) {
			return $result;
		}
		$input = array();
		foreach ( $items as $id => $text ) {
			$input[] = array(
				'id'   => $id,
				'text' => $text,
			);
		}
		$response = $this->call( (string) wp_json_encode( $this->body( $input, $source, $target, $html ) ) );
		$output   = $this->output( $response['json'] );

		$seen = array();
		foreach ( $output as $entry ) {
			$id = is_array( $entry ) && isset( $entry['id'] ) && is_int( $entry['id'] ) ? $entry['id'] : null;
			if ( null === $id || ! isset( $items[ $id ] ) || isset( $seen[ $id ] ) ) {
				throw new PermanentError( esc_html( BatchResult::COUNT_MISMATCH . ': Gemini returned unknown or repeated ids.' ) );
			}
			$seen[ $id ] = true;
			$text        = $entry['text'] ?? null;
			if ( ! is_string( $text ) || '' === trim( $text ) ) {
				$result->errors[ $id ] = 'Gemini returned an empty translation.';
				continue;
			}
			$result->translations[ $id ] = $text;
		}
		if ( count( $seen ) !== count( $items ) ) {
			throw new PermanentError( esc_html( sprintf( '%s: sent %d, Gemini returned %d.', BatchResult::COUNT_MISMATCH, count( $items ), count( $seen ) ) ) );
		}

		return $result;
	}

	/**
	 * Translate one sample string with the configured model.
	 */
	public function testConnection(): TestResult {
		try {
			$sample = $this->translate( array( 1 => 'Hello World!' ), 'en', 'it', false );
			if ( ! isset( $sample->translations[1] ) ) {
				return new TestResult( false, 'Gemini answered without a translation.' );
			}

			return new TestResult(
				true,
				'Gemini connection works.',
				array(
					'model'  => $this->model(),
					'sample' => $sample->translations[1],
				)
			);
		} catch ( ProviderError $e ) {
			return new TestResult( false, $this->secrets->redact( $e->getMessage() ) );
		}
	}

	/**
	 * Request body (plan §7.3 prompting rules).
	 *
	 * @param list<array{id: int, text: string}> $input  Items.
	 * @param string                             $source Source code.
	 * @param string                             $target Target code.
	 * @param bool                               $html   Items may contain tag placeholders.
	 * @return array<string, mixed>
	 */
	private function body( array $input, string $source, string $target, bool $html ): array {
		$rules = array(
			sprintf( 'You are a professional website and user-interface translator. Translate each item from the language with BCP-47 code "%s" to the language with BCP-47 code "%s".', $source, $target ),
			'The input is a JSON array of objects with "id" and "text". Return one object per input object with the same "id" and the translated "text". Do not add, drop, merge or split items, and add no commentary.',
			'Copy placeholder tokens such as [[1]] exactly as they are.',
			'Keep short interface labels short. Translate meaning, not word by word.',
		);
		if ( $html ) {
			$rules[] = 'Some texts contain tags such as <a id="1">…</a> or <b id="2">…</b>. Keep every tag with its id exactly once, translate the text inside and around them, and move a tag only when the target word order requires it.';
		}

		return array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => implode( "\n", $rules ) ) ) ),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => (string) wp_json_encode( $input, JSON_UNESCAPED_UNICODE ) ) ),
				),
			),
			'generationConfig'  => array(
				'temperature'    => 0,
				'responseFormat' => array(
					'text' => array(
						'mimeType' => 'APPLICATION_JSON',
						'schema'   => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'   => array( 'type' => 'integer' ),
									'text' => array( 'type' => 'string' ),
								),
								'required'   => array( 'id', 'text' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * The JSON array the model produced.
	 *
	 * @param mixed $json Response body.
	 * @return array<mixed>
	 * @throws PermanentError When the answer was blocked, cut off or not the requested JSON.
	 * @throws TransientError When the response has an unexpected shape.
	 */
	private function output( $json ): array {
		if ( ! is_array( $json ) ) {
			throw new TransientError( esc_html( 'Gemini returned an unexpected response.' ) );
		}
		$blocked = $json['promptFeedback']['blockReason'] ?? null;
		if ( is_string( $blocked ) ) {
			throw new PermanentError( esc_html( 'Gemini blocked the request: ' . $blocked ) );
		}
		$candidate = $json['candidates'][0] ?? null;
		if ( ! is_array( $candidate ) ) {
			throw new TransientError( esc_html( 'Gemini returned no candidate.' ) );
		}
		$finish = (string) ( $candidate['finishReason'] ?? '' );
		if ( 'MAX_TOKENS' === $finish ) {
			throw new PermanentError( esc_html( BatchResult::TOO_LARGE . ': Gemini reached its output limit.' ) );
		}
		if ( 'STOP' !== $finish ) {
			throw new PermanentError( esc_html( 'Gemini stopped early: ' . ( '' === $finish ? 'no reason given' : $finish ) ) );
		}
		$text = '';
		foreach ( (array) ( $candidate['content']['parts'] ?? array() ) as $part ) {
			if ( is_array( $part ) && is_string( $part['text'] ?? null ) && true !== ( $part['thought'] ?? false ) ) {
				$text .= $part['text'];
			}
		}
		$output = json_decode( $text, true );
		if ( ! is_array( $output ) || array_values( $output ) !== $output ) {
			throw new PermanentError( esc_html( BatchResult::COUNT_MISMATCH . ': Gemini did not return the requested JSON array.' ) );
		}

		return $output;
	}

	/**
	 * Call generateContent and classify failures (fixtures in
	 * tests/fixtures/providers/gemini).
	 *
	 * @param string $body JSON body.
	 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
	 * @throws AuthError Key missing or rejected.
	 * @throws QuotaExceeded Prepaid credit used up.
	 * @throws RateLimited HTTP 429.
	 * @throws TransientError HTTP 408 or 5xx.
	 * @throws PermanentError Other failures.
	 */
	private function call( string $body ): array {
		$key = $this->secrets->get( self::SECRET );
		if ( '' === $key ) {
			throw new AuthError( esc_html( 'The Gemini API key is not set (' . self::SECRET . ').' ) );
		}
		$timeout  = (int) ( $this->settings->providerSettings( self::ID )['timeout'] ?? 30 );
		$response = $this->http->request(
			'POST',
			self::API . '/models/' . rawurlencode( $this->model() ) . ':generateContent',
			array(
				'x-goog-api-key' => $key,
				'Content-Type'   => 'application/json; charset=UTF-8',
				'Accept'         => 'application/json',
			),
			$body,
			$timeout
		);
		$status   = $response['status'];
		if ( 200 === $status ) {
			return $response;
		}

		$error   = is_array( $response['json'] ) && is_array( $response['json']['error'] ?? null ) ? $response['json']['error'] : array();
		$message = $this->secrets->redact( mb_substr( is_string( $error['message'] ?? null ) ? $error['message'] : 'HTTP ' . $status, 0, 300 ) );
		$reasons = array();
		$delay   = null;
		foreach ( (array) ( $error['details'] ?? array() ) as $detail ) {
			if ( is_array( $detail ) && is_string( $detail['reason'] ?? null ) ) {
				$reasons[] = $detail['reason'];
			}
			if ( is_array( $detail ) && is_string( $detail['retryDelay'] ?? null ) && 1 === preg_match( '/^(\d+)(?:\.\d+)?s$/', $detail['retryDelay'], $m ) ) {
				$delay = max( 1, (int) $m[1] + 1 );
			}
		}

		// invalid-key.json: HTTP 400 with reason API_KEY_INVALID; missing-key.json: 403.
		if ( in_array( 'API_KEY_INVALID', $reasons, true ) || 401 === $status || 403 === $status ) {
			throw new AuthError( esc_html( 'Gemini rejected the API key: ' . $message ) );
		}
		if ( 402 === $status ) {
			throw new QuotaExceeded( esc_html( 'Gemini prepaid credit is used up: ' . $message ) );
		}
		if ( 429 === $status ) {
			throw new RateLimited( esc_html( 'Gemini rate limit reached: ' . $message ), (int) ( $delay ?? Http::retryAfter( $response['headers'], self::RATE_LIMIT_WAIT ) ) );
		}
		if ( 408 === $status || $status >= 500 ) {
			throw new TransientError( esc_html( 'Gemini is unavailable: ' . $message ) );
		}

		throw new PermanentError( esc_html( 'Gemini refused the request: ' . $message ) );
	}

	/**
	 * Configured model name.
	 */
	private function model(): string {
		$model = $this->settings->providerSettings( self::ID )['model'] ?? '';

		return is_string( $model ) && '' !== $model ? $model : self::DEFAULT_MODEL;
	}
}

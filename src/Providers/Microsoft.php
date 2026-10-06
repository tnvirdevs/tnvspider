<?php
/**
 * Microsoft Translator adapter (plan §7.1).
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
 * Translator Text API v3. Inline strings go out with textType=html and tag
 * placeholders. Error classes come from Microsoft's six-digit codes first
 * (D11), then from the HTTP status.
 */
final class Microsoft implements ProviderInterface {

	public const ID       = 'microsoft';
	public const SECRET   = 'WST_AZURE_KEY';
	public const REGION   = 'WST_AZURE_REGION';
	public const ENDPOINT = 'https://api.cognitive.microsofttranslator.com';

	/** Hard caps per request (D12). */
	private const MAX_ITEMS = 1000;
	private const MAX_CHARS = 50000;

	/** F0 throttles at about 33,300 characters per minute. */
	private const DEFAULT_CPM = 33000;

	/** Seconds to wait after a 429 without Retry-After. */
	private const RATE_LIMIT_WAIT = 60;

	/** Codes for a request that is too large: split and retry. */
	private const TOO_LARGE_CODES = array( 400050, 400072, 400077 );

	/**
	 * Supported languages.
	 *
	 * @var LanguageList
	 */
	private LanguageList $languages;

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
		$this->languages = new LanguageList( self::ID, 'Microsoft Translator' );
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
		return 'Microsoft Translator';
	}

	/**
	 * Limits and features.
	 */
	public function capabilities(): Capabilities {
		return new Capabilities( true, self::MAX_ITEMS, self::MAX_CHARS, 0, 0, self::DEFAULT_CPM );
	}

	/**
	 * Answers from the stored language list; never calls the API.
	 *
	 * @param string $source Source code.
	 * @param string $target Target code.
	 * @throws TransientError When the list was never loaded.
	 */
	public function supportsPair( string $source, string $target ): bool {
		return $this->languages->supports( $source, $target );
	}

	/**
	 * Translate items; $html sends them as HTML fragments.
	 *
	 * @param array<int, string> $items  Unit id => text.
	 * @param string             $source Source code.
	 * @param string             $target Target code.
	 * @param bool               $html   HTML fragments.
	 * @throws TransientError On an unexpected response (and every failure of call()).
	 * @throws PermanentError When the result count differs from the input.
	 */
	public function translate( array $items, string $source, string $target, bool $html ): BatchResult {
		$result = new BatchResult();
		if ( array() === $items ) {
			return $result;
		}
		if ( $this->languages->isStale() ) {
			$this->loadLanguages();
		}

		$query = array(
			'api-version' => '3.0',
			'from'        => $source,
			'to'          => $target,
		);
		if ( $html ) {
			$query['textType'] = 'html';
		}
		$body     = array_map( static fn( string $text ): array => array( 'Text' => $text ), array_values( $items ) );
		$response = $this->call( 'POST', '/translate?' . http_build_query( $query ), (string) wp_json_encode( $body ) );

		$entries = $response['json'];
		if ( ! is_array( $entries ) || array_values( $entries ) !== $entries ) {
			throw new TransientError( esc_html( 'Microsoft Translator returned an unexpected response (HTTP ' . $response['status'] . ').' ) );
		}
		if ( count( $entries ) !== count( $items ) ) {
			throw new PermanentError( esc_html( sprintf( '%s: sent %d, Microsoft Translator returned %d.', BatchResult::COUNT_MISMATCH, count( $items ), count( $entries ) ) ) );
		}

		$position = 0;
		foreach ( array_keys( $items ) as $unit ) {
			$entry = $entries[ $position++ ];
			$text  = is_array( $entry ) ? ( $entry['translations'][0]['text'] ?? null ) : null;
			if ( ! is_string( $text ) || '' === trim( $text ) ) {
				$result->errors[ $unit ] = 'Microsoft Translator returned an empty translation.';
				continue;
			}
			$result->translations[ $unit ] = $text;
		}

		return $result;
	}

	/**
	 * Load the language list and translate one sample string.
	 */
	public function testConnection(): TestResult {
		try {
			$codes  = $this->loadLanguages();
			$sample = $this->translate( array( 0 => 'Hello World!' ), 'en', 'it', false );
			if ( ! isset( $sample->translations[0] ) ) {
				return new TestResult( false, 'Microsoft Translator answered without a translation.' );
			}
			$default = $this->settings->defaultLanguage()->providerCode( self::ID );
			$target  = $this->settings->targetLanguage()?->providerCode( self::ID );

			return new TestResult(
				true,
				'Microsoft Translator connection works.',
				array(
					'languages' => count( $codes ),
					'pair'      => null === $target ? 'no target language set' : ( $this->supportsPair( $default, $target ) ? 'supported' : 'not supported' ),
					'sample'    => $sample->translations[0],
				)
			);
		} catch ( ProviderError $e ) {
			return new TestResult( false, $this->secrets->redact( $e->getMessage() ) );
		}
	}

	/**
	 * Fetch and store the supported language codes (the languages endpoint
	 * needs no key; fixture languages.json).
	 *
	 * @return list<string>
	 * @throws TransientError When the list has an unexpected shape or the request fails.
	 */
	public function loadLanguages(): array {
		$response = $this->http->request( 'GET', $this->endpoint() . '/languages?api-version=3.0&scope=translation', array( 'Accept' => 'application/json' ), null, $this->timeout() );
		$list     = is_array( $response['json'] ) ? ( $response['json']['translation'] ?? null ) : null;
		if ( 200 !== $response['status'] || ! is_array( $list ) ) {
			throw new TransientError( esc_html( 'Microsoft Translator returned an unexpected language list (HTTP ' . $response['status'] . ').' ) );
		}

		return $this->languages->store( array_map( 'strval', array_keys( $list ) ) );
	}

	/**
	 * Call the API and classify failures.
	 *
	 * @param string $method GET or POST.
	 * @param string $path   Path with query.
	 * @param string $body   JSON body.
	 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
	 * @throws AuthError Key missing or rejected.
	 * @throws QuotaExceeded Code 403001.
	 * @throws RateLimited Codes 429xxx.
	 * @throws TransientError Codes 408xxx and 5xxxxx.
	 * @throws PermanentError Other failures.
	 */
	private function call( string $method, string $path, string $body ): array {
		$key = $this->secrets->get( self::SECRET );
		if ( '' === $key ) {
			throw new AuthError( esc_html( 'The Microsoft Translator key is not set (' . self::SECRET . ').' ) );
		}
		$headers = array(
			'Ocp-Apim-Subscription-Key' => $key,
			'Content-Type'              => 'application/json; charset=UTF-8',
			'Accept'                    => 'application/json',
		);
		$region  = $this->secrets->get( self::REGION );
		if ( '' !== $region ) {
			$headers['Ocp-Apim-Subscription-Region'] = $region;
		}
		$response = $this->http->request( $method, $this->endpoint() . $path, $headers, $body, $this->timeout() );
		$status   = $response['status'];
		if ( 200 === $status ) {
			return $response;
		}

		$error   = is_array( $response['json'] ) && is_array( $response['json']['error'] ?? null ) ? $response['json']['error'] : array();
		$code    = isset( $error['code'] ) && is_numeric( $error['code'] ) ? (int) $error['code'] : 0;
		$message = $this->secrets->redact( mb_substr( isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : 'HTTP ' . $status, 0, 300 ) );
		$label   = 0 === $code ? 'Microsoft Translator' : 'Microsoft Translator (' . $code . ')';
		$class   = 0 === $code ? $status : intdiv( $code, 1000 );

		if ( 403001 === $code ) {
			throw new QuotaExceeded( esc_html( $label . ' quota exceeded: ' . $message ) );
		}
		if ( in_array( $code, self::TOO_LARGE_CODES, true ) ) {
			throw new PermanentError( esc_html( BatchResult::TOO_LARGE . ': ' . $label . ': ' . $message ) );
		}
		if ( 401 === $class || 403 === $class ) {
			throw new AuthError( esc_html( $label . ' rejected the key: ' . $message ) );
		}
		if ( 429 === $class ) {
			throw new RateLimited( esc_html( $label . ' rate limit reached: ' . $message ), (int) Http::retryAfter( $response['headers'], self::RATE_LIMIT_WAIT ) );
		}
		if ( 408 === $class || $class >= 500 || 0 === $status ) {
			throw new TransientError( esc_html( $label . ' is unavailable: ' . $message ) );
		}

		throw new PermanentError( esc_html( $label . ' refused the request: ' . $message ) );
	}

	/**
	 * API base URL: the configured endpoint or the global one.
	 */
	private function endpoint(): string {
		$endpoint = $this->settings->providerSettings( self::ID )['endpoint'] ?? '';

		return is_string( $endpoint ) && '' !== $endpoint ? $endpoint : self::ENDPOINT;
	}

	/**
	 * Request timeout in seconds.
	 */
	private function timeout(): int {
		return (int) ( $this->settings->providerSettings( self::ID )['timeout'] ?? 30 );
	}
}

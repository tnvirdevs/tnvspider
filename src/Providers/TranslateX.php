<?php
/**
 * TranslateX adapter (plan §7.2).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

use WST\Config;
use WST\Providers\Errors\AuthError;
use WST\Providers\Errors\PermanentError;
use WST\Providers\Errors\ProviderError;
use WST\Providers\Errors\RateLimited;
use WST\Providers\Errors\TransientError;
use WST\Settings;

/**
 * Plain-text translation through api.translatex.com. The key travels only in
 * the X-API-Key header. Results are positional; an empty entry or a result
 * count that differs from the input is never stored as a translation.
 */
final class TranslateX implements ProviderInterface {

	public const ID     = 'translatex';
	public const SECRET = 'WST_TRANSLATEX_KEY';
	public const API    = 'https://api.translatex.com';

	/** Requests per minute by plan (vendor pricing page). */
	public const PLAN_RPM = array(
		'free'       => 50,
		'startup'    => 50,
		'business'   => 75,
		'enterprise' => 100,
	);

	/**
	 * Batch limits. Measured (fixtures batch-500, chars-60000-total): 500
	 * texts and 60,000 characters per request are accepted, but latency grows
	 * to about one second per 1,000 characters, so batches stay small enough
	 * for the request timeout.
	 */
	private const MAX_ITEMS = 100;
	private const MAX_CHARS = 10000;

	/**
	 * Longest accepted text, in UTF-8 bytes (fixtures chars-2000/2001,
	 * bytes-bn-2000/2001). Longer texts are split at sentence boundaries.
	 */
	public const MAX_TEXT_BYTES = 2000;

	/**
	 * Placeholder token format. TranslateX keeps "{1}" unchanged in bn and ar
	 * but turns "[[1]]" into "[ [ 1]]" and sometimes adds brackets
	 * (fixtures tokens-format-*.json, probe recorded in HANDOVER).
	 */
	private const TOKEN_FORMAT = '{%d}';

	/** Seconds to wait after a 429 without Retry-After; limits are per minute. */
	private const RATE_LIMIT_WAIT = 60;

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
		$this->languages = new LanguageList( self::ID, 'TranslateX' );
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
		return 'TranslateX';
	}

	/**
	 * HTML mode (`html=`, Enterprise plan) is not used until it is verified on
	 * an Enterprise key; inline strings are translated by segments.
	 */
	public function capabilities(): Capabilities {
		$plan = (string) ( $this->settings->providerSettings( self::ID )['plan'] ?? 'free' );

		return new Capabilities( false, self::MAX_ITEMS, self::MAX_CHARS, self::PLAN_RPM[ $plan ] ?? self::PLAN_RPM['free'], 0, 0, 'UTC', self::TOKEN_FORMAT );
	}

	/**
	 * Answers from the stored language list; never calls the API. Before the
	 * list is loaded every pair counts as supported; translate() loads it.
	 *
	 * @param string $source Source code.
	 * @param string $target Target code.
	 */
	public function supportsPair( string $source, string $target ): bool {
		return $this->languages->supports( $source, $target );
	}

	/**
	 * Translate plain-text items.
	 *
	 * @param array<int, string> $items  Unit id => text.
	 * @param string             $source Source code.
	 * @param string             $target Target code.
	 * @param bool               $html   Must be false: HTML mode is not used.
	 * @throws TransientError On an unexpected response (and every failure of call()).
	 * @throws PermanentError When the result count differs from the input.
	 * @throws \LogicException When asked for HTML.
	 */
	public function translate( array $items, string $source, string $target, bool $html ): BatchResult {
		if ( $html ) {
			throw new \LogicException( 'TranslateX is used in plain-text mode only.' );
		}
		$result = new BatchResult();
		if ( array() === $items ) {
			return $result;
		}
		if ( $this->languages->isStale() ) {
			$this->loadLanguages();
		}
		$this->languages->requireSupport( $source, $target );

		// Texts over the per-text limit go out as sentence-sized pieces and
		// are joined back with their original separators.
		$pieces = array();
		$body   = array();
		foreach ( $items as $unit => $text ) {
			$pieces[ $unit ] = TextSplitter::split( $text, self::MAX_TEXT_BYTES );
			foreach ( $pieces[ $unit ] as $piece ) {
				$body[] = 'text=' . rawurlencode( $piece['text'] );
			}
		}
		$response = $this->call(
			'POST',
			'/translate?' . http_build_query(
				array(
					'sl' => $source,
					'tl' => $target,
				)
			),
			implode( '&', $body )
		);

		$translations = is_array( $response['json'] ) ? ( $response['json']['translation'] ?? null ) : null;
		if ( ! is_array( $translations ) || array_values( $translations ) !== $translations ) {
			throw new TransientError( esc_html( 'TranslateX returned an unexpected response (HTTP ' . $response['status'] . ').' ) );
		}
		if ( count( $translations ) !== count( $body ) ) {
			throw new PermanentError( esc_html( sprintf( '%s: sent %d, TranslateX returned %d.', BatchResult::COUNT_MISMATCH, count( $body ), count( $translations ) ) ) );
		}

		$position = 0;
		foreach ( $pieces as $unit => $unitPieces ) {
			$texts     = array_slice( $translations, $position, count( $unitPieces ) );
			$position += count( $unitPieces );
			foreach ( $texts as $text ) {
				if ( ! is_string( $text ) || '' === trim( $text ) ) {
					$result->errors[ $unit ] = 'TranslateX returned an empty translation.';
					continue 2;
				}
			}
			$result->translations[ $unit ] = TextSplitter::join( $unitPieces, array_map( 'strval', $texts ) );
		}
		$remaining = $response['headers']['x-tx-ratelimit-remaining'] ?? '';
		if ( ctype_digit( $remaining ) ) {
			$result->rateLimitRemaining = (int) $remaining;
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
				return new TestResult( false, 'TranslateX answered without a translation.' );
			}
			$default = $this->settings->defaultLanguage()->providerCode( self::ID );
			$target  = $this->settings->targetLanguage()?->providerCode( self::ID );

			return new TestResult(
				true,
				'TranslateX connection works.',
				array(
					'languages' => count( $codes ),
					'pair'      => null === $target ? 'no target language set' : ( $this->supportsPair( $default, $target ) ? 'supported' : 'not supported' ),
					'sample'    => $sample->translations[0],
					'remaining' => $sample->rateLimitRemaining,
				)
			);
		} catch ( ProviderError $e ) {
			return new TestResult( false, $this->secrets->redact( $e->getMessage() ) );
		}
	}

	/**
	 * Fetch and store the supported language codes.
	 *
	 * @return list<string>
	 * @throws TransientError When the list has an unexpected shape (and every failure of call()).
	 */
	public function loadLanguages(): array {
		$response  = $this->call( 'GET', '/supported-languages', null );
		$languages = is_array( $response['json'] ) ? ( $response['json']['languages'] ?? null ) : null;
		if ( ! is_array( $languages ) ) {
			throw new TransientError( esc_html( 'TranslateX returned an unexpected language list (HTTP ' . $response['status'] . ').' ) );
		}
		return $this->languages->store( array_map( static fn( $language ) => is_array( $language ) ? ( $language['language'] ?? null ) : null, $languages ) );
	}

	/**
	 * Call the API and classify failures (D11: provider-specific first).
	 *
	 * @param string      $method GET or POST.
	 * @param string      $path   Path with query, without the key.
	 * @param string|null $body   Form body.
	 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
	 * @throws AuthError Key missing or rejected.
	 * @throws RateLimited HTTP 429.
	 * @throws TransientError HTTP 5xx.
	 * @throws PermanentError Other failures.
	 */
	private function call( string $method, string $path, ?string $body ): array {
		$key = $this->secrets->get( self::SECRET );
		if ( '' === $key ) {
			throw new AuthError( esc_html( 'The TranslateX API key is not set (' . self::SECRET . ').' ) );
		}
		$headers = array(
			'X-API-Key'    => $key,
			'Accept'       => 'application/json',
			'x-api-client' => 'WP-Site-Translator/' . Config::VERSION,
		);
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
		}
		$timeout  = (int) ( $this->settings->providerSettings( self::ID )['timeout'] ?? 30 );
		$response = $this->http->request( $method, self::API . $path, $headers, $body, $timeout );

		$status  = $response['status'];
		$err     = is_array( $response['json'] ) && isset( $response['json']['err'] ) && is_string( $response['json']['err'] ) ? $response['json']['err'] : '';
		$message = $this->secrets->redact( mb_substr( '' === $err ? 'HTTP ' . $status : $err, 0, 300 ) );
		if ( 200 === $status && '' === $err ) {
			return $response;
		}
		$errText = strtolower( trim( $err ) );
		// Fixture long text: HTTP 400 "long text in request"; the retry sends the rows one by one.
		if ( 'long text in request' === $errText ) {
			throw new PermanentError( esc_html( BatchResult::TOO_LARGE . ': TranslateX: ' . $message ) );
		}
		// Fixture html-param: a plan restriction answered with 403, not an auth failure.
		if ( 403 === $status && str_contains( $errText, 'not available for your api key' ) ) {
			throw new PermanentError( esc_html( 'TranslateX refused the request: ' . $message ) );
		}
		// Fixtures missing-key.json and invalid-key.json: auth failure is HTTP 400.
		if ( 'invalid api key' === $errText || 401 === $status || 403 === $status ) {
			throw new AuthError( esc_html( 'TranslateX rejected the API key: ' . $message ) );
		}
		if ( 429 === $status ) {
			throw new RateLimited( esc_html( 'TranslateX rate limit reached: ' . $message ), (int) Http::retryAfter( $response['headers'], self::RATE_LIMIT_WAIT ) );
		}
		if ( $status >= 500 || 0 === $status ) {
			throw new TransientError( esc_html( 'TranslateX is unavailable: ' . $message ) );
		}

		throw new PermanentError( esc_html( 'TranslateX refused the request: ' . $message ) );
	}
}

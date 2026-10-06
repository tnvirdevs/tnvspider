<?php
/**
 * Records real Microsoft Translator and Gemini responses as test fixtures.
 *
 * Usage:
 *   php bin/capture-provider-fixtures.php microsoft keyless   # no key needed
 *   php bin/capture-provider-fixtures.php microsoft all       # needs WST_AZURE_KEY (+ WST_AZURE_REGION for regional resources)
 *   php bin/capture-provider-fixtures.php gemini keyless
 *   php bin/capture-provider-fixtures.php gemini all          # needs WST_GEMINI_KEY; optional WST_GEMINI_MODEL
 *
 * Keys are read from the environment, sent only in headers, never printed,
 * and a fixture that would contain one is refused. Only harmless sample
 * text is sent.
 *
 * @package WST
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DevelopmentFunctions -- Standalone dev CLI script, no WordPress.

const WST_MS_BASE        = 'https://api.cognitive.microsofttranslator.com';
const WST_GEMINI_BASE    = 'https://generativelanguage.googleapis.com/v1beta';
const WST_GEMINI_DEFAULT = 'gemini-3.5-flash-lite';

$provider = $argv[1] ?? '';
$mode     = $argv[2] ?? '';
if ( ! in_array( $provider, array( 'microsoft', 'gemini' ), true ) || ! in_array( $mode, array( 'keyless', 'all' ), true ) ) {
	fwrite( STDERR, "Usage: php bin/capture-provider-fixtures.php microsoft|gemini keyless|all\n" );
	exit( 2 );
}
$keyName = 'microsoft' === $provider ? 'WST_AZURE_KEY' : 'WST_GEMINI_KEY';
$key     = (string) getenv( $keyName );
$region  = (string) getenv( 'WST_AZURE_REGION' );
$model   = (string) ( getenv( 'WST_GEMINI_MODEL' ) ?: WST_GEMINI_DEFAULT );
if ( 'all' === $mode && '' === $key ) {
	fwrite( STDERR, "{$keyName} is not set.\n" );
	exit( 1 );
}
$dir = dirname( __DIR__ ) . '/tests/fixtures/providers/' . $provider;
if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) ) {
	fwrite( STDERR, "Cannot create {$dir}\n" );
	exit( 1 );
}

/**
 * One JSON API call.
 *
 * @param string                $method  GET or POST.
 * @param string                $url     URL (never carries a key).
 * @param array<string, string> $headers Headers.
 * @param mixed                 $body    JSON body or null.
 * @return array{status: int, headers: array<string, string>, body: string, ms: int}
 */
function wst_fx_call( string $method, string $url, array $headers, $body ): array {
	$lines = array( 'Accept: application/json' );
	foreach ( $headers as $name => $value ) {
		$lines[] = $name . ': ' . $value;
	}
	$got  = array();
	$curl = curl_init( $url );
	curl_setopt_array(
		$curl,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_HEADERFUNCTION => static function ( $handle, string $line ) use ( &$got ): int {
				$parts = explode( ':', $line, 2 );
				if ( 2 === count( $parts ) ) {
					$got[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
				}

				return strlen( $line );
			},
		)
	);
	if ( 'POST' === $method ) {
		$lines[] = 'Content-Type: application/json; charset=UTF-8';
		curl_setopt( $curl, CURLOPT_POST, true );
		curl_setopt( $curl, CURLOPT_POSTFIELDS, (string) json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}
	curl_setopt( $curl, CURLOPT_HTTPHEADER, $lines );
	$start    = hrtime( true );
	$response = curl_exec( $curl );
	$ms       = (int) ( ( hrtime( true ) - $start ) / 1e6 );
	if ( false === $response ) {
		throw new RuntimeException( 'Network error: ' . curl_error( $curl ) );
	}
	$keep = array();
	foreach ( array( 'content-type', 'retry-after', 'x-requestid', 'x-metered-usage' ) as $name ) {
		if ( isset( $got[ $name ] ) ) {
			$keep[ $name ] = $got[ $name ];
		}
	}

	return array( 'status' => (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ), 'headers' => $keep, 'body' => (string) $response, 'ms' => $ms );
}

/**
 * Save one case; large response bodies are cut at 20 KB.
 *
 * @param string               $dir      Fixture directory.
 * @param string               $name     Case name.
 * @param array<string, mixed> $request  Request description (no secrets).
 * @param array{status: int, headers: array<string, string>, body: string, ms: int} $response Response.
 * @param list<string>         $secrets  Values that must not appear.
 */
function wst_fx_save( string $dir, string $name, array $request, array $response, array $secrets ): void {
	$decoded = json_decode( $response['body'], true );
	$fixture = array(
		'case'        => $name,
		'captured_at' => gmdate( 'c' ),
		'request'     => $request,
		'response'    => array(
			'status'    => $response['status'],
			'headers'   => $response['headers'],
			'body_json' => is_array( $decoded ) && strlen( $response['body'] ) <= 20000 ? $decoded : null,
			'body_raw'  => is_array( $decoded ) && strlen( $response['body'] ) <= 20000 ? null : substr( $response['body'], 0, 20000 ),
			'ms'        => $response['ms'],
		),
	);
	$json = json_encode( $fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
	foreach ( $secrets as $secret ) {
		if ( strlen( $secret ) >= 4 && str_contains( $json, $secret ) ) {
			throw new RuntimeException( "Refusing to write {$name}: it would contain a secret." );
		}
	}
	file_put_contents( $dir . '/' . $name . '.json', $json );
	printf( "%-24s HTTP %d  %5d ms  %s\n", $name, $response['status'], $response['ms'], substr( preg_replace( '/\s+/', ' ', $response['body'] ) ?? '', 0, 100 ) );
}

/**
 * Gemini request body: the adapter's prompt shape (plan §7.3).
 *
 * @param list<array{id: int, text: string}> $items Items.
 * @return array<string, mixed>
 */
function wst_fx_gemini_body( array $items, string $from, string $to ): array {
	return array(
		'systemInstruction' => array( 'parts' => array( array( 'text' => "Translate website text from {$from} to {$to}. Return one entry per input id. Keep tokens like [[1]] and tags like <a id=\"1\"> unchanged." ) ) ),
		'contents'          => array(
			array(
				'role'  => 'user',
				'parts' => array( array( 'text' => (string) json_encode( $items, JSON_UNESCAPED_UNICODE ) ) ),
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

$secrets = array_values( array_filter( array( $key, $region ) ) );
$cases   = array();
if ( 'microsoft' === $provider ) {
	$url  = static fn( string $to, bool $html = false ): string => WST_MS_BASE . '/translate?' . http_build_query( array( 'api-version' => '3.0', 'from' => 'en', 'to' => $to ) + ( $html ? array( 'textType' => 'html' ) : array() ) );
	$auth = static fn( string $k ): array => array( 'Ocp-Apim-Subscription-Key' => $k ) + ( '' !== $region ? array( 'Ocp-Apim-Subscription-Region' => $region ) : array() );
	$one  = array( array( 'Text' => 'Hello World!' ) );

	$cases['keyless'] = array(
		'languages'   => array( 'GET', WST_MS_BASE . '/languages?api-version=3.0&scope=translation', array(), null ),
		'missing-key' => array( 'POST', $url( 'bn' ), array(), $one ),
		'invalid-key' => array( 'POST', $url( 'bn' ), array( 'Ocp-Apim-Subscription-Key' => 'invalid' ), $one ),
	);
	$cases['all']     = array(
		'success-en-bn'   => array( 'POST', $url( 'bn' ), $auth( $key ), $one ),
		'success-en-ar'   => array( 'POST', $url( 'ar' ), $auth( $key ), $one ),
		'success-batch'   => array( 'POST', $url( 'bn' ), $auth( $key ), array( array( 'Text' => 'Add to cart' ), array( 'Text' => 'Free shipping' ), array( 'Text' => 'Buy [[1]] today' ) ) ),
		'html-inline'     => array( 'POST', $url( 'bn', true ), $auth( $key ), array( array( 'Text' => 'Read <a id="1">our story</a> and <b id="2">save</b>' ) ) ),
		'unsupported-pair' => array( 'POST', $url( 'xx' ), $auth( $key ), $one ),
		'empty-text'      => array( 'POST', $url( 'bn' ), $auth( $key ), array( array( 'Text' => '' ) ) ),
	);
} else {
	$url  = WST_GEMINI_BASE . '/models/' . rawurlencode( $model ) . ':generateContent';
	$one  = wst_fx_gemini_body( array( array( 'id' => 1, 'text' => 'Hello World!' ) ), 'English', 'Bengali' );
	$auth = array( 'x-goog-api-key' => $key );

	$cases['keyless'] = array(
		'missing-key' => array( 'POST', $url, array(), $one ),
		'invalid-key' => array( 'POST', $url, array( 'x-goog-api-key' => 'invalid' ), $one ),
	);
	$cases['all']     = array(
		'success-en-bn' => array( 'POST', $url, $auth, $one ),
		'success-en-ar' => array( 'POST', $url, $auth, wst_fx_gemini_body( array( array( 'id' => 1, 'text' => 'Hello World!' ) ), 'English', 'Arabic' ) ),
		'success-batch' => array(
			'POST',
			$url,
			$auth,
			wst_fx_gemini_body(
				array(
					array( 'id' => 4, 'text' => 'Add to cart' ),
					array( 'id' => 9, 'text' => 'Read <a id="1">our story</a> and <b id="2">save</b>' ),
					array( 'id' => 2, 'text' => 'Buy [[1]] today' ),
				),
				'English',
				'Bengali'
			),
		),
		'unknown-model' => array( 'POST', WST_GEMINI_BASE . '/models/no-such-model:generateContent', $auth, $one ),
	);
}

$run = 'all' === $mode ? $cases['keyless'] + $cases['all'] : $cases['keyless'];
foreach ( $run as $name => [ $method, $target, $headers, $body ] ) {
	$response = wst_fx_call( $method, $target, $headers, $body );
	$request  = array(
		'method'  => $method,
		'url'     => $target,
		'headers' => array_map( static fn( string $v ): string => in_array( $v, $secrets, true ) ? '[secret]' : $v, $headers ),
		'body'    => $body,
	);
	wst_fx_save( $dir, $name, $request, $response, $secrets );
	usleep( 1500000 );
}

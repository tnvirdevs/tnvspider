<?php
/**
 * Records real TranslateX API responses as test fixtures (plan §7.2).
 *
 * Usage:
 *   php bin/capture-translatex-fixtures.php keyless      # cases that need no key
 *   php bin/capture-translatex-fixtures.php all          # needs WST_TRANSLATEX_KEY
 *   php bin/capture-translatex-fixtures.php rate-limit   # needs the key; bursts tiny requests until HTTP 429
 *
 * The key is read from WST_TRANSLATEX_KEY, sent only in the X-API-Key header,
 * never printed, and a fixture that would contain it is refused. Only
 * harmless sample text is sent.
 *
 * @package WST
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DevelopmentFunctions -- Standalone dev CLI script, no WordPress.

const WST_TX_BASE = 'https://api.translatex.com';

$mode = $argv[1] ?? '';
if ( ! in_array( $mode, array( 'keyless', 'all', 'rate-limit' ), true ) ) {
	fwrite( STDERR, "Usage: php bin/capture-translatex-fixtures.php keyless|all|rate-limit\n" );
	exit( 2 );
}

$key = (string) getenv( 'WST_TRANSLATEX_KEY' );
if ( 'keyless' !== $mode && '' === $key ) {
	fwrite( STDERR, "WST_TRANSLATEX_KEY is not set.\n" );
	exit( 1 );
}

$dir = dirname( __DIR__ ) . '/tests/fixtures/providers/translatex';
if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) ) {
	fwrite( STDERR, "Cannot create {$dir}\n" );
	exit( 1 );
}

/**
 * One API call. $body is a list of [name, value] pairs, form-encoded with
 * rawurlencode like the API documentation shows.
 *
 * @param string                      $method GET or POST.
 * @param string                      $path   API path.
 * @param array<string, string>       $query  Query parameters (never the key).
 * @param list<array{0: string, 1: string}> $body Form fields.
 * @param string|null                 $apiKey Key for X-API-Key, null for none.
 * @return array{status: int, headers: array<string, string>, body: string, ms: int}
 */
function wst_tx_call( string $method, string $path, array $query, array $body, ?string $apiKey ): array {
	$url     = WST_TX_BASE . $path . ( array() === $query ? '' : '?' . http_build_query( $query ) );
	$headers = array( 'Accept: */*', 'X-Api-Client: WP-Site-Translator/0.1.0' );
	if ( null !== $apiKey ) {
		$headers[] = 'X-API-Key: ' . $apiKey;
	}
	$curl = curl_init( $url );
	$got  = array();
	curl_setopt_array(
		$curl,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_HTTPHEADER     => $headers,
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
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		curl_setopt( $curl, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $curl, CURLOPT_POST, true );
		curl_setopt( $curl, CURLOPT_POSTFIELDS, implode( '&', array_map( static fn( array $f ): string => $f[0] . '=' . rawurlencode( $f[1] ), $body ) ) );
	}
	$start    = hrtime( true );
	$response = curl_exec( $curl );
	$ms       = (int) ( ( hrtime( true ) - $start ) / 1e6 );
	if ( false === $response ) {
		throw new RuntimeException( 'Network error: ' . curl_error( $curl ) );
	}
	$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );

	$keep = array();
	foreach ( array( 'content-type', 'x-tx-ratelimit', 'x-tx-ratelimit-remaining', 'retry-after' ) as $name ) {
		if ( isset( $got[ $name ] ) ) {
			$keep[ $name ] = $got[ $name ];
		}
	}

	return array( 'status' => $status, 'headers' => $keep, 'body' => (string) $response, 'ms' => $ms );
}

/**
 * Save one case. Large request bodies are summarised; the response body is
 * kept in full up to 20 KB.
 *
 * @param string               $dir    Fixture directory.
 * @param string               $name   Case name.
 * @param array<string, mixed> $request Request description.
 * @param array{status: int, headers: array<string, string>, body: string, ms: int} $response Response.
 * @param string               $key    API key, only to refuse leaking it.
 */
function wst_tx_save( string $dir, string $name, array $request, array $response, string $key ): void {
	$decoded = json_decode( $response['body'], true );
	$fixture = array(
		'case'        => $name,
		'captured_at' => gmdate( 'c' ),
		'request'     => $request,
		'response'    => array(
			'status'    => $response['status'],
			'headers'   => $response['headers'],
			'body_json' => is_array( $decoded ) ? $decoded : null,
			'body_raw'  => is_array( $decoded ) ? null : substr( $response['body'], 0, 20000 ),
			'ms'        => $response['ms'],
		),
	);
	$json = json_encode( $fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
	if ( '' !== $key && str_contains( $json, $key ) ) {
		throw new RuntimeException( "Refusing to write {$name}: it would contain the API key." );
	}
	file_put_contents( $dir . '/' . $name . '.json', $json );
	printf( "%-28s HTTP %d  %5d ms  %s\n", $name, $response['status'], $response['ms'], substr( preg_replace( '/\s+/', ' ', $response['body'] ) ?? '', 0, 90 ) );
}

/**
 * A translate request: texts as repeated text= fields.
 *
 * @param list<string> $texts Texts.
 * @return list<array{0: string, 1: string}>
 */
function wst_tx_texts( array $texts ): array {
	return array_map( static fn( string $t ): array => array( 'text', $t ), $texts );
}

$sample = 'Hello World!';
$lorem  = 'The quick brown fox jumps over the lazy dog. ';

$cases = array(
	'keyless' => array(
		'missing-key' => array( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'bn' ), wst_tx_texts( array( $sample ) ), 'none' ),
		'invalid-key' => array( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'bn' ), wst_tx_texts( array( $sample ) ), 'invalid' ),
	),
	'all'     => array(
		'supported-languages' => array( 'GET', '/supported-languages', array(), array(), 'valid' ),
		'success-en-bn'       => array( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'bn' ), wst_tx_texts( array( $sample ) ), 'valid' ),
		'success-en-ar'       => array( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'ar' ), wst_tx_texts( array( $sample ) ), 'valid' ),
		'success-bn-en'       => array( 'POST', '/translate', array( 'sl' => 'bn', 'tl' => 'en' ), wst_tx_texts( array( 'আপনাকে স্বাগতম' ) ), 'valid' ),
		'success-batch'       => array( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'bn' ), wst_tx_texts( array( 'Shop now', 'Add to cart', 'Free shipping on orders over 1,500.' ) ), 'valid' ),
		'tokens-preserved'    => array( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'bn' ), wst_tx_texts( array( 'Buy [[WST1]] today', 'Order %s ships in %1$d days', 'Visit {{shop}} now' ) ), 'valid' ),
		'unsupported-pair'    => array( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'xx' ), wst_tx_texts( array( $sample ) ), 'valid' ),
		'empty-text'          => array( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'bn' ), wst_tx_texts( array( '' ) ), 'valid' ),
		'mixed-empty-text'    => array( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'bn' ), wst_tx_texts( array( 'Shop now', '', 'Add to cart' ) ), 'valid' ),
		'no-text-param'       => array( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'bn' ), array(), 'valid' ),
		'missing-target'      => array( 'POST', '/translate', array( 'sl' => 'en' ), wst_tx_texts( array( $sample ) ), 'valid' ),
		'html-param'          => array( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'bn' ), array( array( 'html', '<p>Hello <b>World</b>!</p>' ) ), 'valid' ),
		'batch-100'           => array( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'bn' ), wst_tx_texts( array_map( static fn( int $i ): string => 'Item ' . $i, range( 1, 100 ) ) ), 'valid' ),
		'batch-101'           => array( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'bn' ), wst_tx_texts( array_map( static fn( int $i ): string => 'Item ' . $i, range( 1, 101 ) ) ), 'valid' ),
		'batch-500'           => array( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'bn' ), wst_tx_texts( array_map( static fn( int $i ): string => 'Item ' . $i, range( 1, 500 ) ) ), 'valid' ),
		'chars-5000'          => array( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'bn' ), wst_tx_texts( array( substr( str_repeat( $lorem, 120 ), 0, 5000 ) ) ), 'valid' ),
		'chars-20000'         => array( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'bn' ), wst_tx_texts( array( substr( str_repeat( $lorem, 500 ), 0, 20000 ) ) ), 'valid' ),
		'chars-60000-total'   => array( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'bn' ), wst_tx_texts( array_fill( 0, 30, substr( str_repeat( $lorem, 50 ), 0, 2000 ) ) ), 'valid' ),
	),
);

$run = 'all' === $mode ? array_merge( $cases['keyless'], $cases['all'] ) : ( 'keyless' === $mode ? $cases['keyless'] : array() );

foreach ( $run as $name => [ $method, $path, $query, $body, $keyMode ] ) {
	$apiKey   = 'valid' === $keyMode ? $key : ( 'invalid' === $keyMode ? 'invalid-key-for-fixture' : null );
	$response = wst_tx_call( $method, $path, $query, $body, $apiKey );
	$texts    = array_column( $body, 1 );
	$request  = array(
		'method' => $method,
		'path'   => $path,
		'query'  => $query,
		'key'    => $keyMode,
		'fields' => array_values( array_unique( array_column( $body, 0 ) ) ),
		'items'  => count( $body ),
		'chars'  => array_sum( array_map( 'mb_strlen', $texts ) ),
		'body'   => count( $body ) <= 5 && array_sum( array_map( 'strlen', $texts ) ) <= 500 ? $body : 'omitted (large probe)',
	);
	wst_tx_save( $dir, $name, $request, $response, $key );
	usleep( 1500000 ); // Stay well under the free plan's 50 calls per minute.
}

if ( 'rate-limit' === $mode ) {
	// Burst tiny requests until the API answers 429, to record its format.
	for ( $i = 1; $i <= 70; $i++ ) {
		$response = wst_tx_call( 'POST', '/translate', array( 'sl' => 'en', 'tl' => 'bn' ), wst_tx_texts( array( 'Hi' ) ), $key );
		if ( 200 !== $response['status'] ) {
			wst_tx_save( $dir, 'rate-limited', array( 'method' => 'POST', 'path' => '/translate', 'query' => array( 'sl' => 'en', 'tl' => 'bn' ), 'key' => 'valid', 'burst_index' => $i ), $response, $key );
			exit( 0 );
		}
	}
	echo "No 429 after 70 requests.\n";
}

<?php
/**
 * HTTP calls shared by the provider adapters.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

use WST\Config;
use WST\Providers\Errors\TransientError;

/**
 * Thin wrapper over the WordPress HTTP API. Network failures become
 * TransientError with secrets removed; the response body is returned as
 * untrusted data for the adapter to classify.
 */
final class Http {

	/**
	 * Create the client.
	 *
	 * @param Secrets $secrets For redacting messages.
	 */
	public function __construct( private Secrets $secrets ) {
	}

	/**
	 * Send a request.
	 *
	 * @param string                $method  GET or POST.
	 * @param string                $url     URL (never carries a secret).
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $body    Request body.
	 * @param int                   $timeout Seconds.
	 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
	 * @throws TransientError On network failure or timeout.
	 */
	public function request( string $method, string $url, array $headers, ?string $body, int $timeout ): array {
		$args = array(
			'method'      => $method,
			'headers'     => $headers + array( 'User-Agent' => 'WP-Site-Translator/' . Config::VERSION ),
			'timeout'     => $timeout,
			'redirection' => 0,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new TransientError( 'Network error: ' . esc_html( $this->secrets->redact( $response->get_error_message() ) ) );
		}

		$headers = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		$text = wp_remote_retrieve_body( $response );

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $headers,
			'body'    => $text,
			'json'    => json_decode( $text, true ),
		);
	}

	/**
	 * Seconds from a Retry-After header (delta seconds or HTTP date), or $fallback.
	 *
	 * @param array<string, string> $headers Lower-cased headers.
	 * @param int                   $fallback Seconds when absent or unreadable.
	 */
	public static function retryAfter( array $headers, int $fallback ): int {
		$value = trim( $headers['retry-after'] ?? '' );
		if ( '' === $value ) {
			return $fallback;
		}
		if ( ctype_digit( $value ) ) {
			return max( 1, (int) $value );
		}
		$at = strtotime( $value );

		return false === $at ? $fallback : max( 1, $at - time() );
	}
}

<?php
/**
 * Answers WordPress HTTP requests in tests.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Support;

/**
 * Hooks pre_http_request: records each request and returns the next queued
 * response; an unexpected request fails the test, so nothing leaves it.
 */
trait HttpStub {

	/**
	 * Requests seen: [url, args].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * Responses to return, in order; a callable gets (url, args) and returns one.
	 *
	 * @var list<array<string, mixed>|\WP_Error|callable>
	 */
	private array $responses = array();

	/**
	 * Start answering requests. Call from set_up().
	 */
	private function stubHttp(): void {
		$this->requests  = array();
		$this->responses = array();
		add_filter( 'pre_http_request', array( $this, 'answerHttp' ), 10, 3 );
	}

	/**
	 * @param false|array<string, mixed>|\WP_Error $pre  Short-circuit value.
	 * @param array<string, mixed>                 $args Request args.
	 * @param string                               $url  URL.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function answerHttp( $pre, $args, $url ) {
		unset( $pre );
		$this->requests[] = array( $url, $args );
		if ( array() === $this->responses ) {
			$this->fail( 'Unexpected HTTP request to ' . $url );
		}

		$next = array_shift( $this->responses );

		return is_callable( $next ) ? $next( $url, $args ) : $next;
	}

	/**
	 * A response in the WordPress HTTP API shape.
	 *
	 * @param int                   $status  HTTP status.
	 * @param mixed                 $json    Body.
	 * @param array<string, string> $headers Headers.
	 * @return array<string, mixed>
	 */
	private static function response( int $status, $json, array $headers = array() ): array {
		return array(
			'headers'  => $headers + array( 'content-type' => 'application/json' ),
			'body'     => (string) wp_json_encode( $json ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Replay a recorded fixture from tests/fixtures/providers.
	 *
	 * @param string $provider Provider directory.
	 * @param string $name     Fixture name.
	 * @return array<string, mixed>
	 */
	private static function fixture( string $provider, string $name ): array {
		$data = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/providers/' . $provider . '/' . $name . '.json' ), true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Missing fixture ' . esc_html( $provider . '/' . $name ) );
		}

		return self::response( $data['response']['status'], $data['response']['body_json'], $data['response']['headers'] );
	}
}

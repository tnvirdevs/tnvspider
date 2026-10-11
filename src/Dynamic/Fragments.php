<?php
/**
 * Translation of AJAX and REST responses (plan §13A.5a).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Dynamic;

use WST\Languages\Current;
use WST\Log\Logger;
use WST\Render\Pipeline;
use WST\Settings;

/**
 * For target-language AJAX requests (admin-ajax.php, WooCommerce wc-ajax:
 * cart fragments, add to cart, order review, checkout) and REST requests
 * (the Router takes their language from wst_lang or the referer), translates
 * the response with existing translations: JSON string values that contain
 * HTML tags, and plain-text values under the allowlisted keys, at most five
 * levels deep. Keys, numbers, booleans, ids, URLs, nonces and tokens are
 * never touched; HTML bodies are translated as fragments. On any error the
 * original body is returned and the error logged (rethrown under WP_DEBUG).
 */
final class Fragments {

	/** Deepest JSON level whose values are translated. */
	public const MAX_DEPTH = 5;

	/** Keys whose values are never changed, whatever they contain. */
	private const PROTECTED_KEYS = '/(nonce|token|hash|key|url|href|src|redirect|^ids?$|_ids?$|^id_)/i';

	/**
	 * Create the translator.
	 *
	 * @param Settings $settings Settings (toggle, JSON keys).
	 * @param Pipeline $pipeline Fragment and text translation.
	 * @param Logger   $logger   Failure log.
	 */
	public function __construct( private Settings $settings, private Pipeline $pipeline, private Logger $logger ) {
	}

	/**
	 * Hook into target-language AJAX and REST requests. Call after the Router
	 * detected the language.
	 */
	public function boot(): void {
		if ( ! $this->settings->flag( 'dynamic_fragments' ) || ! Current::isTarget() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing hint.
		if ( wp_doing_ajax() || isset( $_GET['wc-ajax'] ) ) {
			ob_start( array( $this, 'finish' ) );
		}
		add_filter( 'rest_post_dispatch', array( $this, 'restResponse' ), PHP_INT_MAX );
	}

	/**
	 * Output buffer callback for AJAX responses.
	 *
	 * @param mixed $body Buffered output.
	 * @return mixed
	 * @throws \Throwable Re-thrown under WP_DEBUG after logging.
	 */
	public function finish( $body ) {
		if ( ! is_string( $body ) || '' === $body ) {
			return $body;
		}
		try {
			return $this->translateBody( $body );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'dynamic', 'An AJAX response was sent untranslated because translating it failed.', array( 'error' => $e->getMessage() ) );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				throw $e;
			}

			return $body;
		}
	}

	/**
	 * REST responses: translate the data before it is encoded.
	 *
	 * @param mixed $response Response.
	 * @return mixed
	 * @throws \Throwable Re-thrown under WP_DEBUG after logging.
	 */
	public function restResponse( $response ) {
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}
		try {
			$changed = false;
			$data    = $this->walk( $response->get_data(), null, 0, $changed );
			if ( $changed ) {
				$response->set_data( $data );
			}
		} catch ( \Throwable $e ) {
			$this->logger->error( 'dynamic', 'A REST response was sent untranslated because translating it failed.', array( 'error' => $e->getMessage() ) );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				throw $e;
			}
		}

		return $response;
	}

	/**
	 * Translate a JSON or HTML body; anything else comes back unchanged.
	 *
	 * @param string $body Response body.
	 */
	public function translateBody( string $body ): string {
		$trimmed = ltrim( $body );
		if ( '' === $trimmed ) {
			return $body;
		}
		if ( '{' === $trimmed[0] || '[' === $trimmed[0] ) {
			$data = json_decode( $body );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return $body;
			}
			$changed = false;
			$data    = $this->walk( $data, null, 0, $changed );

			return $changed ? (string) wp_json_encode( $data ) : $body;
		}

		return self::hasTags( $body ) ? $this->pipeline->translateFragment( $body ) : $body;
	}

	/**
	 * Translate the values of a decoded JSON value.
	 *
	 * @param mixed       $value Value.
	 * @param string|null $key   Its key, if it has one.
	 * @param int         $depth   Nesting level.
	 * @param bool        $changed Set to true when a value changed.
	 * @return mixed
	 */
	private function walk( $value, ?string $key, int $depth, bool &$changed ) {
		if ( null !== $key && 1 === preg_match( self::PROTECTED_KEYS, $key ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			$translated = $this->translateValue( $value, $key );
			$changed    = $changed || $translated !== $value;

			return $translated;
		}
		if ( $depth >= self::MAX_DEPTH ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $childKey => $child ) {
				// List items inherit their parent's key ("messages": ["…"]).
				$value[ $childKey ] = $this->walk( $child, is_int( $childKey ) ? $key : (string) $childKey, $depth + 1, $changed );
			}
		} elseif ( $value instanceof \stdClass ) {
			foreach ( get_object_vars( $value ) as $childKey => $child ) {
				$value->{$childKey} = $this->walk( $child, (string) $childKey, $depth + 1, $changed );
			}
		}

		return $value;
	}

	/**
	 * A string value: HTML is translated as a fragment, plain text only
	 * under an allowlisted key.
	 *
	 * @param string      $value Value.
	 * @param string|null $key   Its key.
	 */
	private function translateValue( string $value, ?string $key ): string {
		if ( self::hasTags( $value ) ) {
			$translated = $this->pipeline->translateFragment( $value );
		} elseif ( null !== $key && in_array( strtolower( $key ), array_map( 'strtolower', $this->settings->dynamicJsonKeys() ), true ) ) {
			$translated = $this->pipeline->translateTexts( array( $value ) )[ $value ] ?? $value;
		} else {
			return $value;
		}

		return $translated;
	}

	/**
	 * Whether a string contains an HTML tag.
	 *
	 * @param string $value Value.
	 */
	private static function hasTags( string $value ): bool {
		return 1 === preg_match( '#<[a-z][a-z0-9-]*(\s[^>]*)?/?>#i', $value );
	}
}

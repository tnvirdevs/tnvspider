<?php
/**
 * The provider asked us to slow down.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers\Errors;

/**
 * HTTP 429 or equivalent. Always honoured, even with an unlimited rate setting.
 */
final class RateLimited extends ProviderError {

	/**
	 * Create the error.
	 *
	 * @param string $message    Message.
	 * @param int    $retryAfter Seconds to wait before the next request.
	 */
	public function __construct( string $message, private int $retryAfter ) {
		parent::__construct( $message );
	}

	/**
	 * Seconds to wait before the next request.
	 */
	public function retryAfter(): int {
		return $this->retryAfter;
	}
}

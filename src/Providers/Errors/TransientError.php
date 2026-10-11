<?php
/**
 * A temporary failure (network, timeout, 5xx); retry with backoff.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers\Errors;

/**
 * A temporary failure (network, timeout, 5xx); retry with backoff.
 */
final class TransientError extends ProviderError {
}

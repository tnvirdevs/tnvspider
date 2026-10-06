<?php
/**
 * The request itself is invalid; retrying the same batch will not help.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers\Errors;

/**
 * The request itself is invalid; retrying the same batch will not help.
 */
final class PermanentError extends ProviderError {
}

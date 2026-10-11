<?php
/**
 * Base class of failures the queue understands (plan §7).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers\Errors;

/**
 * A provider failure for a whole request. Messages must never contain
 * secrets or full request URLs.
 */
abstract class ProviderError extends \RuntimeException {
}

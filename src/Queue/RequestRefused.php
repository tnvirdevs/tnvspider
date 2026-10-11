<?php
/**
 * An explicit translation request that cannot be carried out.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Queue;

/**
 * The message is shown to the user as is.
 */
final class RequestRefused extends \RuntimeException {
}

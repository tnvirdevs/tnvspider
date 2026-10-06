<?php
/**
 * Signatures of third-party cache plugin functions used behind
 * function_exists() checks, for static analysis only.
 *
 * @package WST
 */

// phpcs:disable -- Third-party signatures, copied as declared.

/**
 * WP Rocket 3.23.5.1, inc/functions/files.php.
 *
 * @param string|array<string> $urls        URLs of cache files to delete.
 * @param object|null          $filesystem  Filesystem handler.
 * @param bool                 $run_actions Run actions.
 * @return array<string, bool>
 */
function rocket_clean_files( $urls, $filesystem = null, $run_actions = true ) {
	return array();
}

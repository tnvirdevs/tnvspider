<?php
/**
 * Signatures of third-party SEO plugin functions used behind
 * is_callable() checks, for static analysis only.
 *
 * @package WST
 */

// phpcs:disable -- Third-party signatures, copied as declared.

/**
 * Yoast SEO 28.6, src/functions.php: the Surfaces API entry point.
 *
 * @return object
 */
function YoastSEO() {
	return new stdClass();
}

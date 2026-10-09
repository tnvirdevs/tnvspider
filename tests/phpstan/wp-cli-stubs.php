<?php
/**
 * Minimal WP-CLI declarations for PHPStan. Only what the plugin calls;
 * php-stubs/wp-cli-stubs does not support WordPress 7 stubs yet.
 *
 * @package WST
 */

// phpcs:disable

namespace {
class WP_CLI {
	/**
	 * @param string          $name
	 * @param callable|object|string $callable
	 * @param array<string, mixed>   $args
	 */
	public static function add_command( $name, $callable, $args = array() ): bool {}

	/** @param string $message */
	public static function success( $message ): void {}

	/** @param string $message */
	public static function line( $message = '' ): void {}

	/**
	 * @param string|\WP_Error|\Throwable $message
	 * @param bool|int                    $exit
	 * @return never
	 */
	public static function error( $message, $exit = true ) {}
}
}

namespace WP_CLI\Utils {

/**
 * @param string                           $format
 * @param array<int, array<string, mixed>> $items
 * @param array<int, string>|string        $fields
 */
function format_items( $format, $items, $fields ): void {}
}

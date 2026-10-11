<?php
/**
 * Data removal on uninstall (plan §4, §13 Data).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST;

use WST\Database\Schema;
use WST\Modes\Resolver;
use WST\Queue\Scheduler;

/**
 * Removes the plugin's tables, options, transients, post meta, capability
 * and cron event, but only when "Delete all data on uninstall" is on;
 * otherwise uninstalling keeps every translation.
 */
final class Uninstaller {

	/**
	 * Run from uninstall.php.
	 *
	 * @param \wpdb $db Database connection.
	 * @return bool Whether data was deleted.
	 */
	public static function run( \wpdb $db ): bool {
		$settings = get_option( Settings::OPTION, array() );
		if ( ! is_array( $settings ) || true !== ( $settings['delete_on_uninstall'] ?? false ) ) {
			return false;
		}

		wp_clear_scheduled_hook( Scheduler::HOOK );
		( new Schema( $db ) )->drop();
		foreach ( Access::ROLES as $name ) {
			$role = get_role( $name );
			if ( null !== $role ) {
				$role->remove_cap( Access::CAPABILITY );
			}
		}
		delete_post_meta_by_key( Resolver::META_KEY );
		$like = $db->esc_like( Config::PREFIX ) . '%';
		foreach ( array( $like, $db->esc_like( '_transient_' . Config::PREFIX ) . '%', $db->esc_like( '_transient_timeout_' . Config::PREFIX ) . '%' ) as $pattern ) {
			$names = $db->get_col( (string) $db->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $db->options, $pattern ) );
			foreach ( $names as $name ) {
				delete_option( (string) $name );
			}
		}
		wp_cache_flush();

		return true;
	}
}

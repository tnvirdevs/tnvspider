<?php
/**
 * The translator capability (plan §11).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST;

/**
 * `wst_translate` lets a user translate and queue strings and change page
 * modes; settings stay with `manage_options`. Granted to administrators and
 * editors on activation, and once after updates that change the grant.
 */
final class Access {

	public const CAPABILITY = Config::PREFIX . 'translate';

	/** Roles that get the capability. */
	public const ROLES = array( 'administrator', 'editor' );

	/** Stored grant version; raise VERSION to grant again after an update. */
	private const OPTION  = Config::PREFIX . 'caps_version';
	private const VERSION = 1;

	/**
	 * Grant the capability to the roles.
	 */
	public static function install(): void {
		foreach ( self::ROLES as $name ) {
			$role = get_role( $name );
			if ( null !== $role ) {
				$role->add_cap( self::CAPABILITY );
			}
		}
		update_option( self::OPTION, self::VERSION, true );
	}

	/**
	 * Grant once per VERSION (updates without re-activation).
	 */
	public static function maybeInstall(): void {
		if ( (int) get_option( self::OPTION, 0 ) < self::VERSION ) {
			self::install();
		}
	}
}

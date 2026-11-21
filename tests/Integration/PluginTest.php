<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration;

use WP_UnitTestCase;
use WST\Database\Schema;

final class PluginTest extends WP_UnitTestCase {

	public function test_activation_installs_the_schema(): void {
		$hook = 'activate_' . plugin_basename( dirname( __DIR__, 2 ) . '/wp-site-translator.php' );
		$this->assertNotFalse( has_action( $hook ), 'Activation hook is not registered.' );

		delete_option( Schema::VERSION_OPTION );
		do_action( $hook ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core activation hook.

		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
	}

	public function test_schema_upgrade_runs_on_plugins_loaded(): void {
		$this->assertNotFalse( has_action( 'plugins_loaded' ) );

		update_option( Schema::VERSION_OPTION, Schema::VERSION - 1 );
		do_action( 'plugins_loaded' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
	}
}

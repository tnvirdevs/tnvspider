<?php
/**
 * Plan §16 Phase 5 acceptance, server side: with a hostile plugin active,
 * the editor screen keeps only core and our code, and the entry points
 * lead to it.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Editor;

use WP_UnitTestCase;
use WST\Access;
use WST\Admin\AdminPage;
use WST\Admin\EditorPage;
use WST\Admin\Isolation;
use WST\Languages\Current;
use WST\Languages\Registry;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\Selector;
use WST\Settings;

final class EditorScreenTest extends WP_UnitTestCase {

	private const PLUGIN_FILE = __DIR__ . '/../../../wp-site-translator.php';

	private static string $hostileDir = '';

	public static function wpSetUpBeforeClass(): void {
		// The fixture must live in the plugins directory, like a real plugin.
		self::$hostileDir = WP_PLUGIN_DIR . '/wst-hostile';
		if ( ! is_dir( self::$hostileDir ) ) {
			mkdir( self::$hostileDir, 0755, true );
		}
		foreach ( array( 'wst-hostile.php', 'hostile.js', 'hostile.css' ) as $file ) {
			copy( dirname( __DIR__, 2 ) . '/fixtures/hostile-plugin/' . $file, self::$hostileDir . '/' . $file );
		}
	}

	public static function wpTearDownAfterClass(): void {
		foreach ( (array) glob( self::$hostileDir . '/*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( self::$hostileDir );
	}

	public function set_up(): void {
		parent::set_up();
		Access::install();
		// Hooks are restored after each test, so the fixture is loaded per test.
		include self::$hostileDir . '/wst-hostile.php';
	}

	public function tear_down(): void {
		Current::reset();
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	private function settings( array $values = array() ): Settings {
		return new Settings( $values + array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() );
	}

	/**
	 * Register the Translator menu and the editor screen as WordPress does.
	 */
	private function editorScreen( string $role = 'editor' ): EditorPage {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		$settings = $this->settings();
		$target   = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$registry = new ProviderRegistry( array() );
		$page     = new EditorPage( $settings, $target, new Selector( $settings, $registry, new ProviderState() ), new Isolation( self::PLUGIN_FILE ), self::PLUGIN_FILE );
		$page->boot();
		( new AdminPage( self::PLUGIN_FILE, new Isolation( self::PLUGIN_FILE ) ) )->addMenu();
		$page->addMenu();

		return $page;
	}

	public function test_hostile_callbacks_are_removed_on_the_editor_screen_only(): void {
		$page        = $this->editorScreen();
		$hostileHead = $this->foreignCallbacks( 'admin_head' );
		$this->assertNotEmpty( $hostileHead );

		$page->isolate( \WP_Screen::get( 'translator_page_' . EditorPage::SLUG ) );

		foreach ( Isolation::HOOKS as $hook ) {
			$this->assertSame( array(), $this->foreignCallbacks( $hook ), $hook );
		}
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $page, 'enqueue' ) ), 'Our own callbacks stay.' );
		$this->assertNotFalse( has_action( 'admin_print_footer_scripts', '_wp_footer_scripts' ), 'Core callbacks stay.' );
		$this->assertNotFalse( has_action( 'admin_notices', 'update_nag' ), 'Core notices stay.' );
	}

	public function test_other_screens_are_untouched(): void {
		$page = $this->editorScreen();

		$page->isolate( \WP_Screen::get( 'edit-post' ) );

		$this->assertNotEmpty( $this->foreignCallbacks( 'admin_head' ) );
	}

	public function test_hostile_assets_are_dequeued_and_core_and_ours_kept(): void {
		$page = $this->editorScreen();
		set_current_screen( 'translator_page_' . EditorPage::SLUG ); // Fires current_screen: isolation applies.
		do_action( 'admin_enqueue_scripts', 'translator_page_' . EditorPage::SLUG );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$this->assertFalse( wp_script_is( 'wst-hostile-js', 'enqueued' ), 'Its admin_enqueue_scripts callback was removed.' );
		$this->assertTrue( wp_script_is( EditorPage::SCRIPT_HANDLE, 'enqueued' ), 'Built editor bundle is enqueued.' );
		// Assets enqueued earlier (e.g. on admin_init) are dequeued before printing.
		wp_enqueue_script( 'wst-hostile-js', plugins_url( 'hostile.js', self::$hostileDir . '/wst-hostile.php' ), array(), '1', false );
		wp_enqueue_style( 'wst-hostile-css', plugins_url( 'hostile.css', self::$hostileDir . '/wst-hostile.php' ), array(), '1' );
		wp_enqueue_script( 'jquery' );

		( new Isolation( self::PLUGIN_FILE ) )->dequeueForeign();

		$this->assertFalse( wp_script_is( 'wst-hostile-js', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wst-hostile-css', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'jquery', 'enqueued' ) );
		$this->assertTrue( wp_script_is( EditorPage::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( EditorPage::SCRIPT_HANDLE, 'enqueued' ) );
		unset( $page );
	}

	public function test_editors_reach_the_editor_but_not_the_settings(): void {
		global $submenu;
		$this->editorScreen( 'editor' );

		$slugs = array_column( $submenu[ AdminPage::MENU_SLUG ] ?? array(), 2 );
		$this->assertContains( EditorPage::SLUG, $slugs );
		$this->assertTrue( current_user_can( Access::CAPABILITY ) );
		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->assertSame( 'http://example.org/wp-admin/admin.php?page=wst-editor&post=7', EditorPage::url( 7 ) );
		$this->assertSame( 'http://example.org/wp-admin/admin.php?page=wst-editor&path=%2Fshop%2F', EditorPage::url( null, '/shop/' ) );
	}

	public function test_settings_screen_hides_other_plugins_notices_but_keeps_their_scripts(): void {
		$this->editorScreen( 'administrator' );
		$admin = new AdminPage( self::PLUGIN_FILE, new Isolation( self::PLUGIN_FILE ) );
		$admin->addMenu();
		$this->assertNotEmpty( $this->foreignCallbacks( 'admin_notices' ) );

		$admin->hideForeignNotices( \WP_Screen::get( 'toplevel_page_' . AdminPage::MENU_SLUG ) );

		foreach ( Isolation::NOTICE_HOOKS as $hook ) {
			$this->assertSame( array(), $this->foreignCallbacks( $hook ), $hook );
		}
		$this->assertNotEmpty( $this->foreignCallbacks( 'admin_enqueue_scripts' ), 'Only notices are hidden on the settings screen.' );
		$this->assertNotFalse( has_action( 'admin_notices', 'update_nag' ), 'Core notices stay.' );
	}

	public function test_other_screens_keep_other_plugins_notices(): void {
		$this->editorScreen( 'administrator' );
		$admin = new AdminPage( self::PLUGIN_FILE, new Isolation( self::PLUGIN_FILE ) );
		$admin->addMenu();

		$admin->hideForeignNotices( \WP_Screen::get( 'dashboard' ) );

		$this->assertNotEmpty( $this->foreignCallbacks( 'admin_notices' ) );
	}

	/**
	 * Callbacks of a hook defined in the hostile fixture.
	 *
	 * @return list<string>
	 */
	private function foreignCallbacks( string $hook ): array {
		global $wp_filter;
		$isolation = new Isolation( self::PLUGIN_FILE );
		$found     = array();
		foreach ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array() as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $callback ) {
				if ( $isolation->isForeign( $callback['function'] ) ) {
					$found[] = $priority . ':' . $id;
				}
			}
		}

		return $found;
	}
}

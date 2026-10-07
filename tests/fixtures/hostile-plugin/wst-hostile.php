<?php
/**
 * Plugin Name: WST hostile test plugin
 * Description: Test fixture for plan §16 Phase 5. Breaks JavaScript on the front end and in the admin: removes jQuery and the wp global, throws errors, prints scripts in notices, head and footer. Never install on a real site.
 *
 * @package WST
 */

// phpcs:disable -- Deliberately hostile test fixture.

if ( ! defined( 'WST_HOSTILE_BREAK' ) ) {
	define( 'WST_HOSTILE_BREAK', 'window.jQuery = window.$ = undefined; window.wp = undefined; throw new Error("WST hostile plugin");' );
}

$wst_hostile_enqueue = static function () {
	wp_enqueue_script( 'wst-hostile-js', plugins_url( 'hostile.js', __FILE__ ), array(), '1', false );
	wp_enqueue_style( 'wst-hostile-css', plugins_url( 'hostile.css', __FILE__ ), array(), '1' );
};
add_action( 'wp_enqueue_scripts', $wst_hostile_enqueue );
add_action( 'admin_enqueue_scripts', $wst_hostile_enqueue );

add_action(
	'admin_head',
	static function () {
		echo '<script>' . WST_HOSTILE_BREAK . '</script>';
	}
);
add_action(
	'wp_head',
	static function () {
		echo '<script>' . WST_HOSTILE_BREAK . '</script>';
	}
);
add_action(
	'admin_notices',
	static function () {
		echo '<div class="notice notice-error wst-hostile-notice"><p>HOSTILE NOTICE</p><script>' . WST_HOSTILE_BREAK . '</script></div>';
	}
);
add_action(
	'admin_footer',
	static function () {
		echo '<script>' . WST_HOSTILE_BREAK . '</script>';
	}
);
add_action(
	'wp_footer',
	static function () {
		echo '<script>' . WST_HOSTILE_BREAK . '</script>';
	}
);

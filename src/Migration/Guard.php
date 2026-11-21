<?php
/**
 * Coexistence guard with TranslatePress (plan §13A.4).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Migration;

use WST\Access;
use WST\Admin\AdminPage;

/**
 * While TranslatePress is active, the plugin bootstrap leaves our router,
 * render pipeline, switcher, head tags, off-page handling and toolbar item
 * off, so the two plugins never translate the same request. Admin screens,
 * the importer, the editor's string list and the queue keep working. This
 * class prints the notice that says so.
 */
final class Guard {

	/**
	 * Whether our front end must stay off.
	 */
	public static function frontEndOff(): bool {
		return TranslatePress::isActive();
	}

	/**
	 * Register the notice.
	 */
	public function boot(): void {
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Explain the pause on every admin screen.
	 */
	public function notice(): void {
		if ( ! current_user_can( Access::CAPABILITY ) ) {
			return;
		}
		$link = current_user_can( 'manage_options' )
			? sprintf( ' <a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . AdminPage::MENU_SLUG . '#/migration' ) ), esc_html__( 'Open the migration steps', 'wp-site-translator' ) )
			: '';
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s%s</p></div>',
			esc_html__( 'WP Site Translator is paused on the front end while TranslatePress is active.', 'wp-site-translator' ),
			esc_html__( 'This keeps the two plugins from translating the same pages. Importing TranslatePress translations, the string list and the queue still work; deactivate TranslatePress to go live.', 'wp-site-translator' ),
			$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		);
	}
}

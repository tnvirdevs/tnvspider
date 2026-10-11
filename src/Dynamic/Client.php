<?php
/**
 * Loads the dynamic-content script (plan §13A.5b, c).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Dynamic;

use WST\Access;
use WST\Config;
use WST\Editor\Tokens;
use WST\Languages\Current;
use WST\Modes\Resolver;
use WST\Rest\DynamicController;
use WST\Settings;

/**
 * On target-language pages that are translated (not "off"), loads
 * assets/dynamic.js when "Translate dynamic content" is on (lookup of
 * existing translations for text added by scripts), and on a page opened
 * by the editor's "Scan dynamic content" for the user who started it (also
 * reports new texts). The page's data is the same for every visitor, so
 * cached pages stay valid; scan data is only printed for that user. The
 * scan token is removed from the request once read, so links built from
 * the URL do not carry it.
 */
final class Client {

	public const HANDLE = 'wst-dynamic';

	/**
	 * Scan token of this request, when it is valid for the current user.
	 *
	 * @var string|null
	 */
	private ?string $token = null;

	/**
	 * Create the loader.
	 *
	 * @param Settings $settings   Settings.
	 * @param Resolver $modes      Page modes.
	 * @param string   $pluginFile Main plugin file.
	 */
	public function __construct( private Settings $settings, private Resolver $modes, private string $pluginFile ) {
	}

	/**
	 * Register the hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'readToken' ), 0 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Take the scan token from the request (signed, for the current user).
	 */
	public function readToken(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The token is the authorisation; it is checked below.
		if ( ! isset( $_GET[ DynamicController::TOKEN_PARAM ] ) || ! is_string( $_GET[ DynamicController::TOKEN_PARAM ] ) ) {
			return;
		}
		$token = sanitize_key( wp_unslash( $_GET[ DynamicController::TOKEN_PARAM ] ) );
		// phpcs:enable
		$record = Tokens::read( $token, Tokens::DYNAMIC );
		if ( null !== $record && is_user_logged_in() && get_current_user_id() === $record['user'] && current_user_can( Access::CAPABILITY ) ) {
			$this->token = $token;
		}
		unset( $_GET[ DynamicController::TOKEN_PARAM ], $_REQUEST[ DynamicController::TOKEN_PARAM ] );
		if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
			$_SERVER['REQUEST_URI'] = remove_query_arg( DynamicController::TOKEN_PARAM, wp_unslash( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only a parameter is removed.
		}
	}

	/**
	 * Enqueue the script with its data.
	 */
	public function enqueue(): void {
		$lookup = $this->settings->flag( 'dynamic_lookup' );
		if ( ( ! $lookup && null === $this->token ) || ! Current::isTarget() || Settings::MODE_OFF === $this->modes->current()['mode'] ) {
			return;
		}
		$data = array(
			'lookup'    => $lookup ? rest_url( Config::REST_NAMESPACE . '/lookup' ) : '',
			'scan'      => '',
			'max'       => DynamicController::MAX_TEXTS,
			'maxLength' => DynamicController::MAX_LENGTH,
			'exclude'   => $this->settings->excludeSelectors(),
		);
		if ( null !== $this->token ) {
			$data['scan']   = rest_url( Config::REST_NAMESPACE . '/scan/dynamic' );
			$data['nonce']  = wp_create_nonce( 'wp_rest' );
			$data['token']  = $this->token;
			$data['labels'] = array(
				/* translators: 1: strings found, 2: new strings */
				'status'   => __( 'Dynamic scan: %1$d texts found, %2$d new. Open menus, the mini-cart, tabs and popups.', 'wp-site-translator' ),
				'finish'   => __( 'Finish', 'wp-site-translator' ),
				/* translators: 1: strings found, 2: new strings */
				'finished' => __( 'Dynamic scan finished: %1$d texts found, %2$d new. You can close this tab.', 'wp-site-translator' ),
			);
		}
		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/dynamic.js', $this->pluginFile ),
			array(),
			Config::VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script( self::HANDLE, 'window.wstDynamic = ' . (string) wp_json_encode( $data ) . ';', 'before' );
	}
}

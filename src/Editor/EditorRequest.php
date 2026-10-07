<?php
/**
 * Scan and preview requests to the front end (plan §11, §6A).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Editor;

use WST\Languages\Language;
use WST\Routing\Urls;

/**
 * A request carrying ?wst_scan= or ?wst_preview= is checked on init: the
 * token must be valid and bound to the requested page. The page is then
 * rendered as a logged-out visitor would see it (no admin bar, no
 * personalised text), and the response is no-store, noindex and carries
 * X-WST-Scan-Id. An invalid token ends the request with 403 (JSON for scans).
 */
final class EditorRequest {

	public const SCAN_PARAM    = 'wst_scan';
	public const PREVIEW_PARAM = 'wst_preview';

	/**
	 * The verified request, or null.
	 *
	 * @var array{type: string, token: string, path: string, data: array<string, mixed>}|null
	 */
	private static ?array $current = null;

	/**
	 * Create the checker.
	 *
	 * @param Urls     $urls   URL helper.
	 * @param Language $target Target language.
	 */
	public function __construct( private Urls $urls, private Language $target ) {
	}

	/**
	 * Hook in when the request carries a token.
	 */
	public function boot(): void {
		self::$current = null;
		if ( null === self::param() ) {
			return;
		}
		add_filter( 'show_admin_bar', '__return_false' );
		add_action( 'init', array( $this, 'verify' ), 0 );
	}

	/**
	 * Check the token and switch to a visitor's view.
	 */
	public function verify(): void {
		$param = self::param();
		if ( null === $param ) {
			return;
		}
		[ $type, $token ] = $param;
		$uri              = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only parsed.
		$path             = $this->urls->unprefixedPath( $uri, $this->target->slug() );
		$record           = Tokens::SCAN === $type ? Tokens::consume( $token, $type ) : Tokens::read( $token, $type );
		nocache_headers();
		self::header( 'X-Robots-Tag: noindex, nofollow' );
		if ( null === $record || null === $path || $record['path'] !== $path || ! $this->urls->hasPrefix( (string) $this->urls->relativePath( $uri ), $this->target->slug() ) ) {
			// The editor's scan fetch sends Accept: application/json, so wp_die() answers in JSON.
			wp_die( esc_html__( 'This scan or preview link is invalid or has expired. Start it again from the translation editor.', 'wp-site-translator' ), '', array( 'response' => 403 ) );
		}
		self::header( 'X-WST-Scan-Id: ' . $token );
		self::$current = array(
			'type'  => $type,
			'token' => $token,
			'path'  => $path,
			'data'  => $record['data'],
		);
		wp_set_current_user( 0 );
	}

	/**
	 * The verified editor request of this page load, or null.
	 *
	 * @return array{type: string, token: string, path: string, data: array<string, mixed>}|null
	 */
	public static function current(): ?array {
		return self::$current;
	}

	/**
	 * Forget the verified request (tests run many requests in one process).
	 */
	public static function reset(): void {
		self::$current = null;
	}

	/**
	 * Whether this page load is a verified request of a type.
	 *
	 * @param string $type Tokens::SCAN or Tokens::PREVIEW.
	 */
	public static function is( string $type ): bool {
		return null !== self::$current && $type === self::$current['type'];
	}

	/**
	 * Send a header while that is still possible. When something printed
	 * output before init, the scan still works: its JSON body carries the
	 * scan id that the editor checks.
	 *
	 * @param string $line Header line.
	 */
	public static function header( string $line ): void {
		if ( ! headers_sent() ) {
			header( $line );
		}
	}

	/**
	 * Type and token from the query string, or null.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private static function param(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The token is the authorisation; it is checked in verify().
		foreach ( array(
			self::SCAN_PARAM    => Tokens::SCAN,
			self::PREVIEW_PARAM => Tokens::PREVIEW,
		) as $name => $type ) {
			if ( isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ) {
				return array( $type, sanitize_key( wp_unslash( $_GET[ $name ] ) ) );
			}
		}
		// phpcs:enable

		return null;
	}
}

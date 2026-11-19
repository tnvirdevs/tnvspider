<?php
/**
 * Server-side scan of one page (plan §11).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Editor;

/**
 * Scans a translated page through a request from the server to itself: the
 * page renders as a visitor with a one-time scan token, records every string
 * and (unless record_only) queues the untranslated ones as the page's mode
 * allows. Used by the editor when the browser cannot scan and by the
 * background site scan.
 */
final class LoopbackScan {

	/** Seconds one scan request may take. */
	public const TIMEOUT = 30;

	/**
	 * Scan a page.
	 *
	 * @param array<string, mixed> $page Page facts from PageTarget (path, target_url).
	 * @param array<string, mixed> $data Token options: allow_personal, record_only.
	 * @return array<string, mixed>|\WP_Error Scan summary, or why it failed.
	 */
	public function run( array $page, array $data ) {
		$token    = Tokens::issue( Tokens::SCAN, (string) $page['path'], $data );
		$response = wp_remote_get(
			add_query_arg( EditorRequest::SCAN_PARAM, $token, (string) $page['target_url'] ),
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'wst_loopback_failed', sprintf( __( 'The server could not load the page: %s', 'wp-site-translator' ), $response->get_error_message() ), array( 'status' => 502 ) );
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$summary = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $summary ) && $code >= 300 && $code < 400 ) {
			/* translators: %d: HTTP status */
			return new \WP_Error( 'wst_scan_redirected', sprintf( __( 'This page sends visitors to another address (HTTP %d), so it cannot be scanned. Cart, checkout and account pages do this without a cart or a login. Their texts are translated wherever the same texts appear on other pages.', 'wp-site-translator' ), $code ), array( 'status' => 422 ) );
		}
		if ( ! is_array( $summary ) ) {
			/* translators: %d: HTTP status */
			return new \WP_Error( 'wst_loopback_failed', sprintf( __( 'The page answered with HTTP %d and no scan result (a redirect, a login wall or a cached copy?).', 'wp-site-translator' ), $code ), array( 'status' => 502 ) );
		}
		if ( isset( $summary['error'] ) ) {
			return new \WP_Error( 'wst_scan_failed', (string) $summary['error'], array( 'status' => 502 ) );
		}
		if ( ( $summary['scan_id'] ?? '' ) !== $token ) {
			return new \WP_Error( 'wst_scan_cached', __( 'A cached copy of the page was returned instead of a scan. Exclude ?wst_scan= requests from the page cache.', 'wp-site-translator' ), array( 'status' => 502 ) );
		}

		return $summary + array( 'via' => 'server' );
	}
}

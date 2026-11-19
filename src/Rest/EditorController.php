<?php
/**
 * REST: editor page facts, scans and previews (plan §11, §14).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Rest;

use WST\Access;
use WST\Config;
use WST\Editor\EditorRequest;
use WST\Editor\LoopbackScan;
use WST\Editor\PageTarget;
use WST\Editor\Tokens;

/**
 * GET /editor/page, POST /scan/register, POST /scan/loopback and
 * POST /preview/register; all need wst_translate (and the REST nonce for
 * cookie requests). A page is named by post_id or path; URLs are always
 * built from the home URL.
 */
final class EditorController {

	/**
	 * Create the controller.
	 *
	 * @param PageTarget $pages Page resolver.
	 */
	public function __construct( private PageTarget $pages ) {
	}

	/**
	 * Register on rest_api_init.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Register the routes.
	 */
	public function register(): void {
		$can  = static fn(): bool => current_user_can( Access::CAPABILITY );
		$page = array(
			'post_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'path'    => array(
				'type'      => 'string',
				'maxLength' => 255,
			),
		);
		// record_only: record the strings but queue nothing ("Translate entire site" queues after confirmation).
		$scanArgs = array(
			'allow_personal' => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'record_only'    => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);
		$routes   = array(
			'/editor/page'      => array( 'GET', 'page', $page ),
			'/scan/register'    => array(
				'POST',
				'registerScan',
				$page + $scanArgs,
			),
			'/scan/loopback'    => array(
				'POST',
				'loopback',
				$page + $scanArgs,
			),
			'/preview/register' => array(
				'POST',
				'registerPreview',
				$page + array(
					'scripts' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			),
		);
		foreach ( $routes as $route => [ $method, $callback, $args ] ) {
			register_rest_route(
				Config::REST_NAMESPACE,
				$route,
				array(
					'methods'             => $method,
					'callback'            => array( $this, $callback ),
					'permission_callback' => $can,
					'args'                => $args,
				)
			);
		}
	}

	/**
	 * Facts about the page: path, title, mode, URLs, last scan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>|\WP_Error
	 */
	public function page( \WP_REST_Request $request ) {
		return $this->resolve( $request );
	}

	/**
	 * Token and URL for a scan done by the admin's browser.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>|\WP_Error
	 */
	public function registerScan( \WP_REST_Request $request ) {
		$page = $this->resolve( $request );
		if ( $page instanceof \WP_Error ) {
			return $page;
		}
		$token = Tokens::issue(
			Tokens::SCAN,
			$page['path'],
			array(
				'allow_personal' => true === $request->get_param( 'allow_personal' ),
				'record_only'    => true === $request->get_param( 'record_only' ),
			)
		);

		return array(
			'token' => $token,
			'url'   => add_query_arg( EditorRequest::SCAN_PARAM, $token, $page['target_url'] ),
			'page'  => $page,
		);
	}

	/**
	 * Scan through a server-side request, for when the browser cannot.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>|\WP_Error
	 */
	public function loopback( \WP_REST_Request $request ) {
		$page = $this->resolve( $request );
		if ( $page instanceof \WP_Error ) {
			return $page;
		}

		return ( new LoopbackScan() )->run(
			$page,
			array(
				'allow_personal' => true === $request->get_param( 'allow_personal' ),
				'record_only'    => true === $request->get_param( 'record_only' ),
			)
		);
	}

	/**
	 * URL of the safe preview.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>|\WP_Error
	 */
	public function registerPreview( \WP_REST_Request $request ) {
		$page = $this->resolve( $request );
		if ( $page instanceof \WP_Error ) {
			return $page;
		}
		$token = Tokens::issue( Tokens::PREVIEW, $page['path'], array( 'scripts' => true === $request->get_param( 'scripts' ) ) );

		return array(
			'token' => $token,
			'url'   => add_query_arg( EditorRequest::PREVIEW_PARAM, $token, $page['target_url'] ),
		);
	}

	/**
	 * The page named by post_id or path.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array{path: string, post_id: int|null, title: string, page_key: string, mode: string, source: string, target_url: string, default_url: string, last_seen: string|null, last_scan: string|null}|\WP_Error
	 */
	private function resolve( \WP_REST_Request $request ) {
		$postId = $request->get_param( 'post_id' );
		$path   = $request->get_param( 'path' );
		if ( ( null === $postId ) === ( null === $path ) ) {
			return new \WP_Error( 'wst_bad_request', __( 'Send either post_id or path.', 'wp-site-translator' ), array( 'status' => 400 ) );
		}
		try {
			return null !== $postId ? $this->pages->forPost( (int) $postId ) : $this->pages->forPath( (string) $path );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'wst_bad_page', $e->getMessage(), array( 'status' => 400 ) );
		}
	}
}

<?php
/**
 * REST: explicit machine translation (plan §14 POST /strings/translate).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Rest;

use WST\Access;
use WST\Config;
use WST\Languages\Language;
use WST\Queue\RequestRefused;
use WST\Queue\Requests;
use WST\Queue\Scheduler;
use WST\Queue\Worker;

/**
 * Queues strings by id (editor, priority 1) or all untranslated strings of a
 * post ("translate this page now", priority 2). mode=now also runs one
 * rate-limit-compliant batch right away. Needs the wst_translate capability
 * (and the REST nonce for cookie requests).
 */
final class StringsController {

	/** Most ids per request. */
	public const MAX_IDS = 500;

	/**
	 * Builds the worker on demand.
	 *
	 * @var callable(): Worker
	 */
	private $workerFactory;

	/**
	 * Create the controller.
	 *
	 * @param Requests  $requests      Explicit requests.
	 * @param Language  $target        Target language.
	 * @param Scheduler $scheduler     Cron runner.
	 * @param callable  $workerFactory Returns the worker.
	 * @phpstan-param callable(): Worker $workerFactory
	 */
	public function __construct( private Requests $requests, private Language $target, private Scheduler $scheduler, callable $workerFactory ) {
		$this->workerFactory = $workerFactory;
	}

	/**
	 * Register on rest_api_init.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Register the route.
	 */
	public function register(): void {
		register_rest_route(
			Config::REST_NAMESPACE,
			'/strings/translate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'translate' ),
				'permission_callback' => static fn(): bool => current_user_can( Access::CAPABILITY ),
				'args'                => array(
					'ids'     => array(
						'type'     => 'array',
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'maxItems' => self::MAX_IDS,
					),
					'post_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'mode'    => array(
						'type'    => 'string',
						'enum'    => array( 'queue', 'now' ),
						'default' => 'queue',
					),
				),
			)
		);
	}

	/**
	 * Queue (and for mode=now process one batch).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>|\WP_Error
	 */
	public function translate( \WP_REST_Request $request ) {
		$ids    = $request->get_param( 'ids' );
		$postId = $request->get_param( 'post_id' );
		if ( is_array( $ids ) === ( null !== $postId ) ) {
			return new \WP_Error( 'wst_bad_request', __( 'Send either ids or post_id.', 'wp-site-translator' ), array( 'status' => 400 ) );
		}
		try {
			$result = is_array( $ids )
				? $this->requests->strings( array_values( array_map( 'intval', $ids ) ), $this->target )
				: $this->requests->post( (int) $postId, $this->target );
		} catch ( RequestRefused $e ) {
			return new \WP_Error( 'wst_refused', $e->getMessage(), array( 'status' => 409 ) );
		}
		if ( 'now' === $request->get_param( 'mode' ) && $result['queued'] > 0 ) {
			$report = ( $this->workerFactory )()->run( Worker::TIME_BUDGET, 1 );
			$this->scheduler->stopWhenIdle();
			$result['translated'] = $report->translated;
			$result['failed']     = $report->failed;
			$result['wait']       = round( $report->wait, 1 );
		}

		return $result;
	}
}

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
use WST\Editor\PageTarget;
use WST\Providers\RequestPlan;
use WST\Languages\Language;
use WST\Queue\RequestRefused;
use WST\Queue\Requests;
use WST\Queue\Scheduler;
use WST\Queue\Worker;
use WST\Storage\StringStore;

/**
 * Queues strings by id (editor, priority 1) or all untranslated strings of a
 * post ("translate this page now", priority 2); mode=now also runs one
 * rate-limit-compliant batch right away. Lists a page's strings for the
 * editor and saves, marks or removes translations (plan §11). Needs the
 * wst_translate capability (and the REST nonce for cookie requests).
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
	 * @param Requests    $requests      Explicit requests.
	 * @param Language    $target        Target language.
	 * @param Scheduler   $scheduler     Cron runner.
	 * @param callable    $workerFactory Returns the worker.
	 * @param StringStore $store         Strings.
	 * @param PageTarget  $pages         Page resolver (mode of the editor's page).
	 * @phpstan-param callable(): Worker $workerFactory
	 */
	public function __construct( private Requests $requests, private Language $target, private Scheduler $scheduler, callable $workerFactory, private StringStore $store, private PageTarget $pages ) {
		$this->workerFactory = $workerFactory;
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
		$can = static fn(): bool => current_user_can( Access::CAPABILITY );
		register_rest_route(
			Config::REST_NAMESPACE,
			'/strings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list' ),
				'permission_callback' => $can,
				'args'                => array(
					'page_key'       => array(
						'type'    => 'string',
						'pattern' => '^[a-f0-9]{32}$',
					),
					'include_global' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'filter'         => array(
						'type'    => 'string',
						'enum'    => array( 'all', 'untranslated', 'machine', 'manual', 'warning' ),
						'default' => 'all',
					),
					'search'         => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 200,
					),
					'page'           => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page'       => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 200,
						'default' => 50,
					),
				),
			)
		);
		register_rest_route(
			Config::REST_NAMESPACE,
			'/strings/(?P<id>\d+)/translation',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save' ),
					'permission_callback' => $can,
					'args'                => array(
						'translation' => array(
							'type'      => 'string',
							'required'  => true,
							'maxLength' => 100000,
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => $can,
				),
			)
		);
		register_rest_route(
			Config::REST_NAMESPACE,
			'/strings/(?P<id>\d+)/manual',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'markManual' ),
				'permission_callback' => $can,
			)
		);
		register_rest_route(
			Config::REST_NAMESPACE,
			'/strings/translate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'translate' ),
				'permission_callback' => $can,
				'args'                => array(
					'path'    => array(
						'type'      => 'string',
						'maxLength' => 255,
					),
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
			$path   = $request->get_param( 'path' );
			$result = is_array( $ids )
				? $this->requests->strings( array_values( array_map( 'intval', $ids ) ), $this->target, is_string( $path ) ? $this->pages->forPath( $path )['mode'] : '' )
				: $this->requests->post( (int) $postId, $this->target );
		} catch ( RequestRefused $e ) {
			return new \WP_Error( 'wst_refused', $e->getMessage(), array( 'status' => 409 ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'wst_bad_page', $e->getMessage(), array( 'status' => 400 ) );
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

	/**
	 * Strings of a page (or all), with translations and warnings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function list( \WP_REST_Request $request ): \WP_REST_Response {
		$perPage  = (int) $request->get_param( 'per_page' );
		$pageKey  = $request->get_param( 'page_key' );
		$result   = $this->store->editorList(
			$this->target->locale(),
			is_string( $pageKey ) ? $pageKey : null,
			true === $request->get_param( 'include_global' ),
			(string) $request->get_param( 'filter' ),
			(string) $request->get_param( 'search' ),
			$perPage,
			( (int) $request->get_param( 'page' ) - 1 ) * $perPage
		);
		$response = new \WP_REST_Response(
			array(
				'items'  => array_map( array( self::class, 'item' ), $result['items'] ),
				'counts' => $result['counts'],
			)
		);
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / $perPage ) );

		return $response;
	}

	/**
	 * Save a manual translation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>|\WP_Error
	 */
	public function save( \WP_REST_Request $request ) {
		$string = $this->store->byId( (int) $request->get_param( 'id' ), $this->target->locale() );
		if ( null === $string ) {
			return self::notFound();
		}
		$translation = (string) $request->get_param( 'translation' );
		if ( '' === trim( $translation ) ) {
			return new \WP_Error( 'wst_empty', __( 'Type a translation, or use "Remove translation" to show the original.', 'wp-site-translator' ), array( 'status' => 400 ) );
		}
		try {
			$this->store->saveManual( $string['original'], $string['kind'], $this->target->locale(), $translation, get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'wst_invalid_translation', $e->getMessage(), array( 'status' => 400 ) );
		}

		return $this->changed( $string['id'] );
	}

	/**
	 * Remove a translation (the page shows the original again).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remove( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( null === $this->store->byId( $id, $this->target->locale() ) ) {
			return self::notFound();
		}
		if ( ! $this->store->deleteTranslation( $id, $this->target->locale() ) ) {
			return new \WP_Error( 'wst_no_translation', __( 'This string has no translation to remove.', 'wp-site-translator' ), array( 'status' => 409 ) );
		}

		return $this->changed( $id );
	}

	/**
	 * Keep a machine translation as it is, as a manual one.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>|\WP_Error
	 */
	public function markManual( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( null === $this->store->byId( $id, $this->target->locale() ) ) {
			return self::notFound();
		}
		if ( ! $this->store->markManual( $id, $this->target->locale(), get_current_user_id() ) ) {
			return new \WP_Error( 'wst_no_translation', __( 'Only a translated string can be marked manual.', 'wp-site-translator' ), array( 'status' => 409 ) );
		}

		return $this->changed( $id );
	}

	/**
	 * After a change: purge cached pages that show the string, return its row.
	 *
	 * @param int $id String id.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the string vanished meanwhile.
	 */
	private function changed( int $id ): array {
		/** This action is documented in src/Queue/Worker.php */
		do_action( 'wst_strings_translated', array( $id ), $this->target->locale() );
		$row = $this->store->editorList( $this->target->locale(), null, true, 'all', '', 1, 0, $id )['items'][0] ?? null;
		if ( null === $row ) {
			throw new \RuntimeException( 'String ' . (int) $id . ' disappeared while saving.' );
		}

		return self::item( $row );
	}

	/**
	 * Editor row: status as a word and warnings as codes.
	 *
	 * @param array{id: int, kind: string, original: string, translated: string|null, status: int|null, provider: string|null, flags: int, global: bool, queue_state: string|null, queue_error: string|null} $row Row.
	 * @return array<string, mixed>
	 */
	private static function item( array $row ): array {
		$warnings = array();
		if ( 0 !== ( $row['flags'] & RequestPlan::FLAG_SEGMENTED ) ) {
			$warnings[] = 'segmented';
		}
		if ( 0 !== ( $row['flags'] & RequestPlan::FLAG_TAGS_REPAIRED ) ) {
			$warnings[] = 'tags_repaired';
		}
		if ( 'failed' === $row['queue_state'] ) {
			$warnings[] = 'failed';
		}

		return array(
			'id'          => $row['id'],
			'kind'        => $row['kind'],
			'original'    => $row['original'],
			'translation' => $row['translated'],
			'status'      => null === $row['status'] ? 'none' : ( StringStore::STATUS_MANUAL === $row['status'] ? 'manual' : 'machine' ),
			'provider'    => $row['provider'],
			'global'      => $row['global'],
			'queue'       => $row['queue_state'],
			'error'       => $row['queue_error'],
			'warnings'    => $warnings,
		);
	}

	/**
	 * 404 for an unknown string id.
	 */
	private static function notFound(): \WP_Error {
		return new \WP_Error( 'wst_not_found', __( 'Unknown string.', 'wp-site-translator' ), array( 'status' => 404 ) );
	}
}

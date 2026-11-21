<?php
/**
 * REST: "Translate entire site" (plan §8).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Rest;

use WST\Config;
use WST\Languages\Language;
use WST\Providers\StatusReport;
use WST\Queue\Queue;
use WST\Queue\RequestRefused;
use WST\Queue\Requests;
use WST\Queue\Usage;
use WST\Site\SitePages;
use WST\Storage\StringStore;

/**
 * GET /site/pages lists the pages to scan (a slice at a time, with the
 * reason a page is left out); the browser scans them record-only; POST
 * /site/estimate returns the untranslated strings of scanned pages with
 * their characters and the active provider's remaining monthly budget;
 * POST /site/queue queues them at bulk priority after the owner confirmed.
 * Estimate and queue take page keys and check every page again (modes,
 * §6A exclusions), so the browser cannot widen what gets sent. Needs
 * manage_options: it decides how much text goes to a provider.
 */
final class SiteController {

	/** Most page keys per estimate or queue request. */
	public const MAX_PAGES = 200;

	/**
	 * Create the controller.
	 *
	 * @param SitePages    $pages    Site page list and exclusions.
	 * @param StringStore  $store    Strings.
	 * @param Requests     $requests Explicit queueing.
	 * @param Queue        $queue    Queue (characters waiting).
	 * @param StatusReport $status   Provider usage and caps.
	 * @param Language     $target   Target language.
	 */
	public function __construct(
		private SitePages $pages,
		private StringStore $store,
		private Requests $requests,
		private Queue $queue,
		private StatusReport $status,
		private Language $target
	) {
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
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		$keys  = array(
			'page_keys' => array(
				'type'     => 'array',
				'required' => true,
				'maxItems' => self::MAX_PAGES,
				'items'    => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{32}$',
				),
			),
		);
		register_rest_route(
			Config::REST_NAMESPACE,
			'/site/pages',
			array(
				'methods'             => 'GET',
				'callback'            => fn( \WP_REST_Request $request ): array => $this->pages->slice( (int) $request->get_param( 'page' ) ),
				'permission_callback' => $admin,
				'args'                => array(
					'page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
				),
			)
		);
		register_rest_route(
			Config::REST_NAMESPACE,
			'/site/estimate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'estimate' ),
				'permission_callback' => $admin,
				'args'                => $keys,
			)
		);
		register_rest_route(
			Config::REST_NAMESPACE,
			'/site/queue',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'enqueue' ),
				'permission_callback' => $admin,
				'args'                => $keys,
			)
		);
	}

	/**
	 * Untranslated strings of these pages and the budget they would use.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array{strings: list<array{0: int, 1: int}>, skipped_pages: int, provider: array<string, mixed>}
	 */
	public function estimate( \WP_REST_Request $request ): array {
		[ $keys, $skipped ] = $this->allowedKeys( $request );
		$strings            = array();
		foreach ( $this->store->untranslatedOnPages( $keys, $this->target->locale() ) as $id => $chars ) {
			$strings[] = array( $id, $chars );
		}

		return array(
			'strings'       => $strings,
			'skipped_pages' => $skipped,
			'provider'      => $this->provider(),
		);
	}

	/**
	 * Queue the untranslated strings of these pages at bulk priority.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array{ids: list<int>, provider: string}|\WP_Error
	 */
	public function enqueue( \WP_REST_Request $request ) {
		[ $keys ] = $this->allowedKeys( $request );
		$ids      = array_keys( $this->store->untranslatedOnPages( $keys, $this->target->locale() ) );
		try {
			$result = $this->requests->site( $ids, $this->target );
		} catch ( RequestRefused $e ) {
			return new \WP_Error( 'wst_refused', $e->getMessage(), array( 'status' => 409 ) );
		}

		return array(
			'ids'      => $ids,
			'provider' => $result['provider'],
		);
	}

	/**
	 * Keys of recorded pages that may be translated now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array{0: list<string>, 1: int} Allowed keys and the number left out.
	 */
	private function allowedKeys( \WP_REST_Request $request ): array {
		$asked   = array_values( array_unique( array_map( 'strval', (array) $request->get_param( 'page_keys' ) ) ) );
		$allowed = array();
		foreach ( $this->store->pagesByKeys( $asked ) as $page ) {
			if ( '' === $this->pages->skipReason( $page['post_id'], $page['path'] ) ) {
				$allowed[] = $page['page_key'];
			}
		}

		return array( $allowed, count( $asked ) - count( $allowed ) );
	}

	/**
	 * The provider that would translate now, with its monthly budget.
	 *
	 * @return array<string, mixed>
	 */
	private function provider(): array {
		$active = $this->requests->activeProvider( $this->target );
		if ( null === $active['id'] ) {
			return array(
				'id'      => '',
				'problem' => $active['problem'],
			);
		}
		$row = null;
		foreach ( $this->status->all() as $candidate ) {
			if ( $candidate['id'] === $active['id'] ) {
				$row = $candidate;
			}
		}
		$cap     = null === $row ? 0 : (int) $row['usage']['cap'];
		$used    = null === $row ? 0 : (int) $row['usage']['chars'];
		$waiting = $this->queue->charsWaiting( $active['id'] );

		return array(
			'id'        => $active['id'],
			'label'     => null === $row ? $active['id'] : (string) $row['label'],
			'problem'   => '',
			'period'    => Usage::period(),
			'cap'       => $cap,
			'used'      => $used,
			'waiting'   => $waiting,
			'remaining' => $cap > 0 ? max( 0, $cap - $used - $waiting ) : null,
			'resets_on' => ( new \DateTimeImmutable( 'first day of next month midnight', wp_timezone() ) )->format( 'Y-m-d' ),
		);
	}
}

<?php
/**
 * REST: admin queue runner (plan §8 triggers, §14).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Rest;

use WST\Config;
use WST\Providers\Limits;
use WST\Providers\ProviderRegistry;
use WST\Providers\Selector;
use WST\Queue\Queue;
use WST\Queue\Scheduler;
use WST\Queue\Usage;
use WST\Queue\Worker;
use WST\Settings;

/**
 * POST /wst/v1/queue/run processes one rate-limit-compliant batch, so an
 * open admin page keeps translations moving when WP-Cron does not run.
 * GET /queue reports counts, the active provider and an estimate;
 * retry-failed and clear manage failed and waiting rows. All require
 * manage_options; cookie requests also need the REST nonce (enforced by
 * WordPress core before the user is authenticated).
 */
final class QueueController {

	/**
	 * Builds the worker on demand.
	 *
	 * @var callable(): Worker
	 */
	private $workerFactory;

	/**
	 * Create the controller.
	 *
	 * @param Queue            $queue         Queue.
	 * @param Scheduler        $scheduler     Cron runner.
	 * @param callable         $workerFactory Returns the worker.
	 * @param Settings         $settings      Settings.
	 * @param Selector         $selector      Active provider.
	 * @param ProviderRegistry $providers     Configured adapters.
	 * @phpstan-param callable(): Worker $workerFactory
	 */
	public function __construct(
		private Queue $queue,
		private Scheduler $scheduler,
		callable $workerFactory,
		private Settings $settings,
		private Selector $selector,
		private ProviderRegistry $providers
	) {
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
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		register_rest_route(
			Config::REST_NAMESPACE,
			'/queue',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			Config::REST_NAMESPACE,
			'/queue/retry-failed',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'retryFailed' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			Config::REST_NAMESPACE,
			'/queue/clear',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'clear' ),
				'permission_callback' => $admin,
				'args'                => array(
					'state' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'pending', 'failed', 'all' ),
					),
				),
			)
		);
		register_rest_route(
			Config::REST_NAMESPACE,
			'/queue/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run' ),
				'permission_callback' => $admin,
			)
		);
	}

	/**
	 * Process one batch and report.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$report = ( $this->workerFactory )()->run( Worker::TIME_BUDGET, 1 );
		$this->scheduler->stopWhenIdle();
		$stats = $this->queue->stats();

		return array(
			'batches'       => $report->batches,
			'requests'      => $report->requests,
			'translated'    => $report->translated,
			'failed'        => $report->failed,
			'wait'          => round( $report->wait, 1 ),
			'blocked'       => $report->blocked,
			'fallbacks'     => $report->fallbacks,
			'pending'       => $stats['pending'],
			'processing'    => $stats['processing'],
			'failed_total'  => $stats['failed'],
			'chars_pending' => $stats['chars_pending'],
		);
	}

	/**
	 * Counts, the provider that would translate now and a time estimate.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$stats  = $this->queue->stats();
		$target = $this->settings->targetLanguage();
		$active = null === $target ? null : $this->selector->active( $this->settings->defaultLanguage(), $target, Usage::period() );
		$next   = wp_next_scheduled( Scheduler::HOOK );

		return array(
			'pending'       => $stats['pending'],
			'processing'    => $stats['processing'],
			'failed'        => $stats['failed'],
			'chars_pending' => $stats['chars_pending'],
			'by_provider'   => (object) $stats['by_provider'],
			'last_errors'   => (object) $stats['last_errors'],
			'active'        => $active,
			'problem'       => null !== $active || '' === $this->settings->provider() || null === $target ? '' : $this->selector->problem( $this->settings->provider(), $this->settings->defaultLanguage(), $target, Usage::period() ),
			'next_run'      => false === $next ? null : (int) $next,
			'eta_seconds'   => null === $active ? null : $this->eta( $active['id'], $stats['pending'] + $stats['processing'], $stats['chars_pending'] ),
		);
	}

	/**
	 * Give failed rows a fresh start.
	 *
	 * @return array{retried: int}
	 */
	public function retryFailed(): array {
		$retried = $this->queue->retryFailed( time() );
		if ( $retried > 0 ) {
			$this->scheduler->ensure();
		}

		return array( 'retried' => $retried );
	}

	/**
	 * Delete waiting or failed rows (rows being processed are left to finish).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array{cleared: int}
	 */
	public function clear( \WP_REST_Request $request ): array {
		$state   = (string) $request->get_param( 'state' );
		$cleared = 'all' === $state
			? $this->queue->clear( 'pending' ) + $this->queue->clear( 'failed' )
			: $this->queue->clear( $state );
		$this->scheduler->stopWhenIdle();

		return array( 'cleared' => $cleared );
	}

	/**
	 * Seconds until the waiting rows are done at the provider's request
	 * rate, or null when the rate is unlimited (no meaningful estimate).
	 *
	 * @param string $id    Provider id.
	 * @param int    $rows  Waiting rows.
	 * @param int    $chars Waiting characters.
	 */
	private function eta( string $id, int $rows, int $chars ): ?int {
		$provider = $this->providers->get( $id );
		if ( null === $provider || 0 === $rows ) {
			return null === $provider ? null : 0;
		}
		$limits   = Limits::resolve( $this->settings, $id, $provider->capabilities() );
		$requests = max( (int) ceil( $rows / max( 1, $limits->maxItems ) ), (int) ceil( $chars / max( 1, $limits->maxChars ) ) );
		$seconds  = array();
		if ( $limits->rpm > 0 ) {
			$seconds[] = (int) ceil( $requests * 60 / $limits->rpm );
		}
		if ( $limits->cpm > 0 ) {
			$seconds[] = (int) ceil( $chars * 60 / $limits->cpm );
		}

		return array() === $seconds ? null : max( $seconds );
	}
}

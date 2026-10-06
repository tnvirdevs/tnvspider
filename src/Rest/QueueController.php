<?php
/**
 * REST: admin queue runner (plan §8 triggers, §14).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Rest;

use WST\Config;
use WST\Queue\Queue;
use WST\Queue\Scheduler;
use WST\Queue\Worker;

/**
 * POST /wst/v1/queue/run processes one rate-limit-compliant batch, so an
 * open admin page keeps translations moving when WP-Cron does not run.
 * Requires manage_options; cookie requests also need the REST nonce
 * (enforced by WordPress core before the user is authenticated).
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
	 * @param Queue     $queue         Queue.
	 * @param Scheduler $scheduler     Cron runner.
	 * @param callable  $workerFactory Returns the worker.
	 * @phpstan-param callable(): Worker $workerFactory
	 */
	public function __construct( private Queue $queue, private Scheduler $scheduler, callable $workerFactory ) {
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
		register_rest_route(
			Config::REST_NAMESPACE,
			'/queue/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run' ),
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
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
}

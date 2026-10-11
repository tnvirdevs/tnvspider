<?php
/**
 * REST: overview, health, log and data tools (plan §10 Overview/Advanced, §14).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Rest;

use WST\Admin\Health;
use WST\Cache\Purger;
use WST\Config;
use WST\Log\Logger;
use WST\Providers\StatusReport;
use WST\Settings;
use WST\Storage\StringStore;

/**
 * Admin-only (manage_options). Data deletions require confirm=true, and the orphan
 * clean-up has a dry-run read (GET) before the delete (POST).
 */
final class HealthController {

	/** Default minimum age of orphan strings, in days. */
	public const ORPHAN_DAYS = 30;

	/**
	 * Create the controller.
	 *
	 * @param Settings     $settings Settings.
	 * @param Health       $health   Checks.
	 * @param Logger       $logger   Plugin log.
	 * @param StringStore  $store    Strings and translations.
	 * @param StatusReport $status   Provider status.
	 */
	public function __construct(
		private Settings $settings,
		private Health $health,
		private Logger $logger,
		private StringStore $store,
		private StatusReport $status
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
		$admin   = static fn(): bool => current_user_can( 'manage_options' );
		$confirm = array(
			'type'     => 'boolean',
			'required' => true,
			'enum'     => array( true ),
		);
		$days    = array(
			'type'    => 'integer',
			'minimum' => 1,
			'maximum' => 3650,
			'default' => self::ORPHAN_DAYS,
		);
		$routes  = array(
			'/overview'           => array( 'GET', 'overview', array() ),
			'/health'             => array(
				'GET',
				'health',
				array(
					'loopback' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			),
			'/log'                => array(
				'GET',
				'log',
				array(
					'level'  => array(
						'type' => 'string',
						'enum' => Logger::LEVELS,
					),
					'source' => array(
						'type'    => 'string',
						'pattern' => '^[a-z_]{1,32}$',
					),
					'limit'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 200,
						'default' => 50,
					),
				),
			),
			'/data/machine/clear' => array( 'POST', 'clearMachine', array( 'confirm' => $confirm ) ),
			'/data/orphans'       => array( 'GET', 'orphans', array( 'days' => $days ) ),
			'/data/orphans/clear' => array(
				'POST',
				'clearOrphans',
				array(
					'days'    => $days,
					'confirm' => $confirm,
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
					'permission_callback' => $admin,
					'args'                => $args,
				)
			);
		}
	}

	/**
	 * Setup checklist, coverage and recent errors for the Overview screen.
	 *
	 * @return array<string, mixed>
	 */
	public function overview(): array {
		$target   = $this->settings->targetLanguage();
		$coverage = null === $target ? null : $this->store->siteCoverage( $target->locale() );
		$verified = array_filter( $this->status->all(), static fn( array $row ): bool => '' !== $row['role'] && null !== $row['verified_at'] );

		return array(
			'checklist'      => array(
				'language' => null !== $target,
				'provider' => array() !== $verified,
				'page'     => null !== $coverage && $coverage['machine'] + $coverage['manual'] > 0,
			),
			'coverage'       => $coverage,
			'render_errors'  => $this->logger->countSince( 'error', 'render', DAY_IN_SECONDS ),
			'recent_entries' => $this->logger->recent( 5 ),
		);
	}

	/**
	 * Health checks, plus the loopback request when asked for.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array{checks: list<array{id: string, label: string, status: string, message: string}>}
	 */
	public function health( \WP_REST_Request $request ): array {
		$checks = $this->health->checks();
		if ( true === $request->get_param( 'loopback' ) ) {
			$checks[] = $this->health->loopback();
		}

		return array( 'checks' => $checks );
	}

	/**
	 * Latest log entries.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return list<array{id: int, created_at: string, level: string, source: string, message: string, context: array<string, mixed>}>
	 */
	public function log( \WP_REST_Request $request ): array {
		$level  = $request->get_param( 'level' );
		$source = $request->get_param( 'source' );

		return $this->logger->recent(
			(int) $request->get_param( 'limit' ),
			is_string( $level ) ? $level : null,
			is_string( $source ) ? $source : null
		);
	}

	/**
	 * Delete machine translations of the target language; manual ones stay.
	 *
	 * @return array{deleted: int}|\WP_Error
	 */
	public function clearMachine() {
		$target = $this->settings->targetLanguage();
		if ( null === $target ) {
			return new \WP_Error( 'wst_no_target', __( 'No target language is set.', 'wp-site-translator' ), array( 'status' => 400 ) );
		}
		$deleted = $this->store->deleteMachineTranslations( $target->locale() );
		Purger::purgeAll();

		return array( 'deleted' => $deleted );
	}

	/**
	 * Dry run: orphan strings that the clean-up would delete.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array{count: int, chars: int, days: int}
	 */
	public function orphans( \WP_REST_Request $request ): array {
		$days = (int) $request->get_param( 'days' );

		return $this->store->orphans( $days ) + array( 'days' => $days );
	}

	/**
	 * Delete orphan strings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array{deleted: int}
	 */
	public function clearOrphans( \WP_REST_Request $request ): array {
		return array( 'deleted' => $this->store->deleteOrphans( (int) $request->get_param( 'days' ) ) );
	}
}

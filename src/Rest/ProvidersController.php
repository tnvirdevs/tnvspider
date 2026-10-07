<?php
/**
 * REST: provider status and connection tests (plan §10 provider cards, §14).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Rest;

use WST\Config;
use WST\Providers\Secrets;
use WST\Providers\StatusReport;
use WST\Providers\Tester;
use WST\Settings;

/**
 * GET /providers and POST /providers/{id}/test, manage_options only.
 * verified_at stays null until a test passes with the current credentials,
 * which the provider cards show as "not verified yet".
 */
final class ProvidersController {

	/**
	 * Create the controller.
	 *
	 * @param StatusReport $status  Provider status.
	 * @param Tester       $tester  Connection tests.
	 * @param Secrets      $secrets Key store (for where each key comes from).
	 */
	public function __construct(
		private StatusReport $status,
		private Tester $tester,
		private Secrets $secrets
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
		register_rest_route(
			Config::REST_NAMESPACE,
			'/providers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			Config::REST_NAMESPACE,
			'/providers/(?P<id>' . implode( '|', Settings::PROVIDER_IDS ) . ')/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test' ),
				'permission_callback' => $admin,
			)
		);
	}

	/**
	 * Status of every provider, with where each credential comes from.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function list(): array {
		$rows = array();
		foreach ( $this->status->all() as $row ) {
			$secrets = array();
			foreach ( array_keys( $row['secrets'] ) as $name ) {
				$source           = $this->secrets->source( $name );
				$secrets[ $name ] = array(
					'set'    => '' !== $source,
					'source' => $source,
				);
			}
			$row['secrets'] = $secrets;
			$rows[]         = $row;
		}

		return $rows;
	}

	/**
	 * Run the connection test.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array{ok: bool, message: string, details: array<string, mixed>, verified_at: int|null}
	 */
	public function test( \WP_REST_Request $request ): array {
		$id     = (string) $request->get_param( 'id' );
		$result = $this->tester->test( $id );

		return array(
			'ok'          => $result->ok,
			'message'     => $result->message,
			'details'     => $result->details,
			'verified_at' => $this->tester->verifiedAt( $id ),
		);
	}
}

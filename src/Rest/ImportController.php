<?php
/**
 * REST: CSV import, dry run and apply (plan §13A.3).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Rest;

use WST\Config;
use WST\Transfer\Importer;

/**
 * POST /import/check (dry run, writes nothing) and POST /import/apply take
 * one chunk of parsed rows with the conflict policy and the import status.
 * Needs manage_options.
 */
final class ImportController {

	/**
	 * Create the controller.
	 *
	 * @param Importer $importer Importer.
	 */
	public function __construct( private Importer $importer ) {
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
		$args = array(
			'rows'   => array(
				'type'     => 'array',
				'required' => true,
				'maxItems' => Importer::MAX_ROWS,
				'items'    => array( 'type' => 'object' ),
			),
			'policy' => array(
				'type'    => 'string',
				'enum'    => Importer::POLICIES,
				'default' => 'keep_manual',
			),
			'as'     => array(
				'type'    => 'string',
				'enum'    => Importer::STATUSES,
				'default' => 'manual',
			),
		);
		foreach ( array(
			'check' => false,
			'apply' => true,
		) as $route => $apply ) {
			register_rest_route(
				Config::REST_NAMESPACE,
				'/import/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => fn( \WP_REST_Request $request ): array => $this->run( $request, $apply ),
					'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
					'args'                => $args,
				)
			);
		}
	}

	/**
	 * Check or apply one chunk.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $apply   Whether to write.
	 * @return array{counts: array<string, int>, rows: list<array{line: int, outcome: string, reason: string}>}
	 */
	private function run( \WP_REST_Request $request, bool $apply ): array {
		$rows = $request->get_param( 'rows' );

		return $this->importer->run(
			is_array( $rows ) ? $rows : array(),
			(string) $request->get_param( 'policy' ),
			(string) $request->get_param( 'as' ),
			$apply,
			get_current_user_id()
		);
	}
}

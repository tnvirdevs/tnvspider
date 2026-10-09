<?php
/**
 * REST: pages and their modes (plan §14 GET /pages, POST /pages/mode).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Rest;

use WST\Access;
use WST\Cache\Purger;
use WST\Config;
use WST\Languages\Language;
use WST\Modes\Resolver;
use WST\Storage\StringStore;

/**
 * Lists posts of every public type with their own mode, the resolved mode,
 * translation coverage and when they were last seen or scanned; sets the
 * mode of many posts at once. Needs wst_translate; changing a post's mode
 * also needs permission to edit that post.
 */
final class PagesController {

	/** Most posts per bulk change. */
	public const MAX_IDS = 200;

	/** Post statuses listed. */
	private const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Create the controller.
	 *
	 * @param StringStore $store  Coverage.
	 * @param Resolver    $modes  Page modes.
	 * @param Language    $target Target language.
	 * @param Purger      $purger Page-cache purge.
	 */
	public function __construct(
		private StringStore $store,
		private Resolver $modes,
		private Language $target,
		private Purger $purger
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
		$canTranslate = static fn(): bool => current_user_can( Access::CAPABILITY );
		register_rest_route(
			Config::REST_NAMESPACE,
			'/pages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list' ),
				'permission_callback' => $canTranslate,
				'args'                => array(
					'page'      => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
					'search'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'post_type' => array(
						'type' => 'string',
						'enum' => self::postTypes(),
					),
					'mode'      => array(
						'type' => 'string',
						'enum' => Resolver::PAGE_VALUES,
					),
					'include'   => array(
						'type'     => 'array',
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'maxItems' => 100,
					),
				),
			)
		);
		register_rest_route(
			Config::REST_NAMESPACE,
			'/pages/mode',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'setMode' ),
				'permission_callback' => $canTranslate,
				'args'                => array(
					'ids'  => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'minItems' => 1,
						'maxItems' => self::MAX_IDS,
					),
					'mode' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => Resolver::PAGE_VALUES,
					),
				),
			)
		);
	}

	/**
	 * One page of posts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function list( \WP_REST_Request $request ): \WP_REST_Response {
		$args    = array(
			'post_type'              => $request->get_param( 'post_type' ) ?? self::postTypes(),
			'post_status'            => self::STATUSES,
			'posts_per_page'         => (int) $request->get_param( 'per_page' ),
			'paged'                  => (int) $request->get_param( 'page' ),
			's'                      => (string) $request->get_param( 'search' ),
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
			'perm'                   => 'readable',
		);
		$include = $request->get_param( 'include' );
		if ( is_array( $include ) && array() !== $include ) {
			$args['post__in'] = array_map( 'intval', $include );
		}
		$mode = $request->get_param( 'mode' );
		if ( is_string( $mode ) ) {
			$args['meta_query'] = Resolver::INHERIT === $mode // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query -- Filter by mode on an admin list.
				? array(
					'relation' => 'OR',
					array(
						'key'     => Resolver::META_KEY,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => Resolver::META_KEY,
						'value' => Resolver::INHERIT,
					),
				)
				: array(
					array(
						'key'   => Resolver::META_KEY,
						'value' => $mode,
					),
				);
		}
		$query = new \WP_Query( $args );

		$items = array();
		foreach ( $query->posts ?? array() as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$coverage = $this->store->postCoverage( $post->ID, $this->target->locale() );
			$items[]  = array(
				'id'        => $post->ID,
				'title'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'type'      => $post->post_type,
				'status'    => $post->post_status,
				'link'      => (string) get_permalink( $post ),
				'mode'      => Resolver::pageMode( $post->ID ),
				'effective' => $this->modes->resolvePost( $post->ID ),
				'coverage'  => array(
					'total'      => $coverage['total'],
					'translated' => $coverage['translated'],
					'percent'    => 0 === $coverage['total'] ? null : (int) floor( 100 * $coverage['translated'] / $coverage['total'] ),
				),
				'last_seen' => $coverage['last_seen'],
				'last_scan' => $coverage['last_scan'],
				'can_edit'  => current_user_can( 'edit_post', $post->ID ),
			);
		}

		$response = new \WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) $query->max_num_pages );

		return $response;
	}

	/**
	 * Set the mode of many posts; posts the user may not edit are skipped.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array{updated: list<int>, skipped: array<int, string>}
	 */
	public function setMode( \WP_REST_Request $request ): array {
		$mode    = (string) $request->get_param( 'mode' );
		$updated = array();
		$skipped = array();
		foreach ( array_unique( array_map( 'intval', (array) $request->get_param( 'ids' ) ) ) as $postId ) {
			$post = get_post( $postId );
			if ( null === $post || ! in_array( $post->post_type, self::postTypes(), true ) ) {
				$skipped[ $postId ] = 'not found';
				continue;
			}
			if ( ! current_user_can( 'edit_post', $postId ) ) {
				$skipped[ $postId ] = 'not allowed';
				continue;
			}
			if ( Resolver::pageMode( $postId ) !== $mode ) {
				if ( Resolver::INHERIT === $mode ) {
					delete_post_meta( $postId, Resolver::META_KEY );
				} else {
					update_post_meta( $postId, Resolver::META_KEY, $mode );
				}
				$updated[] = $postId;
			}
		}
		$this->purger->purgePosts( $updated );

		return array(
			'updated' => $updated,
			'skipped' => $skipped,
		);
	}

	/**
	 * Public post types that have pages (attachments excluded).
	 *
	 * @return list<string>
	 */
	public static function postTypes(): array {
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );

		return array_map( 'strval', $types );
	}
}

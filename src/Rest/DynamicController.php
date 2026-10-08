<?php
/**
 * REST: dynamic content (plan §13A.5b, c).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Rest;

use WST\Access;
use WST\Config;
use WST\Editor\PageTarget;
use WST\Editor\Tokens;
use WST\Html\Segment;
use WST\Html\Text;
use WST\Languages\Language;
use WST\Modes\Resolver;
use WST\Queue\AutoQueue;
use WST\Render\Pipeline;
use WST\Settings;
use WST\Site\SitePages;
use WST\Storage\StringStore;

/**
 * POST /lookup: public and read-only. Returns the existing translations
 * of up to 100 texts (2,000 characters each) that scripts added to a
 * target-language page; never creates strings; rate-limited per visitor IP
 * (taken from the trusted-proxy setting). Registered only while "Translate
 * dynamic content" is on.
 *
 * POST /scan/dynamic/register and POST /scan/dynamic: the editor's
 * "Scan dynamic content" (wst_translate + REST nonce). The page is opened
 * with a signed token; texts the page's scripts add are recorded as kind
 * "dynamic" with the page occurrence and queued according to the page's
 * mode (never on personal pages).
 */
final class DynamicController {

	/** Most texts per request. */
	public const MAX_TEXTS = 100;

	/** Longest text, in characters. */
	public const MAX_LENGTH = 2000;

	/** Lookup requests per visitor IP and minute. */
	public const RATE_PER_MINUTE = 60;

	public const TOKEN_PARAM = 'wst_dyn_scan';

	/**
	 * Create the controller.
	 *
	 * @param Settings    $settings Settings.
	 * @param Pipeline    $pipeline Text translation.
	 * @param StringStore $store    Strings.
	 * @param PageTarget  $pages    Editor page resolution.
	 * @param Resolver    $modes    Page modes.
	 * @param AutoQueue   $auto     Automatic queueing.
	 * @param Language    $target   Target language.
	 */
	public function __construct(
		private Settings $settings,
		private Pipeline $pipeline,
		private StringStore $store,
		private PageTarget $pages,
		private Resolver $modes,
		private AutoQueue $auto,
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
		$texts = array(
			'type'     => 'array',
			'required' => true,
			'maxItems' => self::MAX_TEXTS,
			'items'    => array(
				'type'      => 'string',
				'maxLength' => self::MAX_LENGTH,
			),
		);
		if ( $this->settings->flag( 'dynamic_lookup' ) ) {
			register_rest_route(
				Config::REST_NAMESPACE,
				'/lookup',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'lookup' ),
					// Public by design: read-only, rate-limited, returns only what visitors already see.
					'permission_callback' => '__return_true',
					'args'                => array( 'strings' => $texts ),
				)
			);
		}
		$can = static fn(): bool => current_user_can( Access::CAPABILITY );
		register_rest_route(
			Config::REST_NAMESPACE,
			'/scan/dynamic/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'registerScan' ),
				'permission_callback' => $can,
				'args'                => array(
					'post_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'path'    => array(
						'type'      => 'string',
						'maxLength' => 255,
					),
				),
			)
		);
		register_rest_route(
			Config::REST_NAMESPACE,
			'/scan/dynamic',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'scan' ),
				'permission_callback' => $can,
				'args'                => array(
					'token'   => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-f0-9]{32}$',
					),
					'strings' => $texts,
				),
			)
		);
	}

	/**
	 * Existing translations of texts added by scripts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array{translations: object}|\WP_Error Translations keyed by text (an object, also when empty).
	 */
	public function lookup( \WP_REST_Request $request ) {
		if ( ! $this->allowRequest( $this->clientIp() ) ) {
			return new \WP_Error( 'wst_rate_limited', __( 'Too many requests; try again in a minute.', 'wp-site-translator' ), array( 'status' => 429 ) );
		}
		$texts = array_values( array_map( 'strval', (array) $request->get_param( 'strings' ) ) );

		return array( 'translations' => (object) $this->pipeline->translateTexts( $texts ) );
	}

	/**
	 * Token and URL for a dynamic scan of a page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>|\WP_Error
	 */
	public function registerScan( \WP_REST_Request $request ) {
		$postId = $request->get_param( 'post_id' );
		$path   = $request->get_param( 'path' );
		try {
			$page = is_int( $postId ) ? $this->pages->forPost( $postId ) : $this->pages->forPath( is_string( $path ) ? $path : '' );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'wst_bad_page', $e->getMessage(), array( 'status' => 400 ) );
		}
		$token = Tokens::issue( Tokens::DYNAMIC, $page['path'], array( 'post_id' => $page['post_id'] ) );

		return array(
			'token' => $token,
			'url'   => add_query_arg( self::TOKEN_PARAM, $token, $page['target_url'] ),
		);
	}

	/**
	 * Record texts found by a dynamic scan and queue them as the page's mode allows.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array{found: int, new: int, queued: int}|\WP_Error
	 */
	public function scan( \WP_REST_Request $request ) {
		$record = Tokens::read( (string) $request->get_param( 'token' ), Tokens::DYNAMIC );
		if ( null === $record || get_current_user_id() !== $record['user'] ) {
			return new \WP_Error( 'wst_bad_token', __( 'This dynamic scan has expired. Start it again from the translation editor.', 'wp-site-translator' ), array( 'status' => 403 ) );
		}
		$postId    = isset( $record['data']['post_id'] ) && is_int( $record['data']['post_id'] ) ? $record['data']['post_id'] : null;
		$maxLength = $this->settings->number( 'max_string_length' );
		$kinds     = array();
		foreach ( (array) $request->get_param( 'strings' ) as $text ) {
			$normalized = Text::normalize( (string) $text );
			if ( Text::isTranslatable( $normalized ) && mb_strlen( $normalized ) <= $maxLength ) {
				$kinds[ $normalized ] = Segment::DYNAMIC;
			}
		}
		if ( array() === $kinds ) {
			return array(
				'found'  => 0,
				'new'    => 0,
				'queued' => 0,
			);
		}
		$lang  = $this->target->locale();
		$known = $this->store->lookup( array_keys( $kinds ), $lang );
		$ids   = $this->store->recordOnPage( $kinds, $record['path'], $postId );

		$untranslated = array();
		foreach ( $ids as $text => $id ) {
			if ( null === ( $known[ $text ]['translated'] ?? null ) ) {
				$untranslated[] = $id;
			}
		}
		$mode     = $this->modes->resolve( $postId, $record['path'] )['mode'];
		$personal = null !== $postId && in_array( $postId, SitePages::personalPostIds(), true );
		$queued   = Settings::MODE_AUTO === $mode && ! $personal && array() !== $untranslated && $this->auto->queue( $untranslated, $this->target );

		return array(
			'found'  => count( $kinds ),
			'new'    => count( array_diff_key( $kinds, $known ) ),
			'queued' => $queued ? count( $untranslated ) : 0,
		);
	}

	/**
	 * Visitor IP for the rate limit, from the trusted-proxy setting.
	 */
	public function clientIp(): string {
		$remote = self::server( 'REMOTE_ADDR' );
		switch ( $this->settings->choice( 'trusted_proxy' ) ) {
			case 'cloudflare':
				$candidate = self::server( 'HTTP_CF_CONNECTING_IP' );
				break;
			case 'forwarded':
				// The right-most address is the one the trusted proxy added.
				$list      = array_map( 'trim', explode( ',', self::server( 'HTTP_X_FORWARDED_FOR' ) ) );
				$candidate = (string) end( $list );
				break;
			case 'custom':
				$header    = $this->settings->trustedProxyHeader();
				$candidate = '' === $header ? '' : trim( explode( ',', self::server( 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) ) ) )[0] );
				break;
			default:
				$candidate = '';
		}

		return false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ? $candidate : $remote;
	}

	/**
	 * Count a lookup for an IP; false when it is over the limit this minute.
	 *
	 * @param string $ip Visitor IP.
	 */
	private function allowRequest( string $ip ): bool {
		$key   = Config::PREFIX . 'lk_' . substr( hash( 'sha256', $ip . '|' . gmdate( 'YmdHi' ) ), 0, 32 );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_PER_MINUTE ) {
			return false;
		}
		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * A $_SERVER value as a string.
	 *
	 * @param string $key Key.
	 */
	private static function server( string $key ): string {
		return isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
	}
}

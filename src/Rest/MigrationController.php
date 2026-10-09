<?php
/**
 * REST: TranslatePress migration (plan §13A.4).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Rest;

use WST\Config;
use WST\Languages\Registry;
use WST\Migration\DictionaryImport;
use WST\Migration\MatchReport;
use WST\Migration\TranslatePress;
use WST\Routing\LanguageUrls;
use WST\Settings;
use WST\Storage\StringStore;
use WST\Transfer\Importer;

/**
 * GET /migration/status (detection, row counts, settings to prefill,
 * content references, go-live checklist), POST /migration/settings (copy
 * TranslatePress's languages and URL slugs into our settings), POST
 * /migration/import (dry run or import of one chunk, read-only on
 * TranslatePress tables), POST /migration/match (match report) and POST
 * /migration/flush (flush rewrite rules). All need manage_options and work
 * while TranslatePress is active.
 */
final class MigrationController {

	/** Option: when rewrite rules were last flushed from the checklist. */
	public const FLUSHED_OPTION = 'wst_migration_flushed';

	/**
	 * Create the controller.
	 *
	 * @param TranslatePress     $translatePress TranslatePress data.
	 * @param Settings           $settings       Our settings.
	 * @param SettingsController $settingsSaver  Validated settings save.
	 * @param StringStore        $store          Strings (scanned pages).
	 * @param \wpdb              $db             Database connection.
	 */
	public function __construct(
		private TranslatePress $translatePress,
		private Settings $settings,
		private SettingsController $settingsSaver,
		private StringStore $store,
		private \wpdb $db
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
		$admin  = static fn(): bool => current_user_can( 'manage_options' );
		$table  = array(
			'type'     => 'string',
			'required' => true,
		);
		$routes = array(
			'status'   => array( 'GET', 'status', array() ),
			'settings' => array( 'POST', 'prefill', array( 'table' => $table ) ),
			'import'   => array(
				'POST',
				'import',
				array(
					'table'   => $table,
					'after'   => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'policy'  => array(
						'type'    => 'string',
						'enum'    => Importer::POLICIES,
						'default' => 'add_only',
					),
					'dry_run' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			),
			'match'    => array(
				'POST',
				'match',
				array(
					'paths' => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => MatchReport::MAX_PAGES,
						'items'    => array(
							'type'      => 'string',
							'maxLength' => 255,
						),
					),
				),
			),
			'flush'    => array( 'POST', 'flush', array() ),
		);
		foreach ( $routes as $route => [ $method, $callback, $args ] ) {
			register_rest_route(
				Config::REST_NAMESPACE,
				'/migration/' . $route,
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
	 * Everything the Migration screen shows.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$tp      = $this->translatePress->settings();
		$tables  = $this->translatePress->tables();
		$target  = $this->settings->targetLanguage();
		$default = $this->settings->defaultLanguage();
		$ours    = array(
			'default_language' => $default->locale(),
			'target_language'  => null === $target ? '' : $target->locale(),
			'target_slug'      => null === $target ? '' : $target->slug(),
			'default_slug'     => $default->slug(),
			'prefix_default'   => $this->settings->flag( 'prefix_default' ),
		);
		$prefill = array();
		foreach ( $tables as $table ) {
			$prefill[ $table['table'] ] = $this->prefillFor( $table['target'] );
		}
		$tpTarget = null === $target || null === $tp ? null : ( $tp['slugs'][ $target->locale() ] ?? null );

		return array(
			'active'         => TranslatePress::isActive(),
			'installed'      => TranslatePress::isInstalled(),
			'plugin_file'    => TranslatePress::PLUGIN_FILE,
			'translatepress' => $tp,
			'tables'         => $tables,
			'ours'           => $ours,
			'prefill'        => $prefill,
			'references'     => $this->translatePress->references(),
			'alias'          => $this->settings->flag( 'trp_switcher_alias' ),
			'checklist'      => array(
				'translatepress_inactive' => ! TranslatePress::isActive(),
				'flushed'                 => false !== get_option( self::FLUSHED_OPTION, false ),
				'slugs_match'             => null === $tp || null === $target ? null : $this->slugsMatch( $tp, $ours ),
				'scanned_pages'           => $this->scannedPages(),
				'urls'                    => null === $tpTarget || null === $target ? array() : $this->urlPairs( (string) $tpTarget, $target->slug() ),
			),
			'plugins_url'    => admin_url( 'plugins.php' ),
		);
	}

	/**
	 * Copy TranslatePress's languages and URL slugs for one table into our settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>|\WP_Error
	 */
	public function prefill( \WP_REST_Request $request ) {
		$table = $this->translatePress->table( (string) $request->get_param( 'table' ) );
		if ( null === $table ) {
			return new \WP_Error( 'wst_unknown_table', __( 'This is not a TranslatePress dictionary table of the configured languages.', 'wp-site-translator' ), array( 'status' => 400 ) );
		}
		$prefill = $this->prefillFor( $table['target'] );
		if ( '' !== $prefill['problem'] ) {
			return new \WP_Error( 'wst_prefill', $prefill['problem'], array( 'status' => 409 ) );
		}

		return $this->settingsSaver->apply( $prefill['settings'] );
	}

	/**
	 * Dry run or import of one chunk.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>|\WP_Error
	 */
	public function import( \WP_REST_Request $request ) {
		$name  = (string) $request->get_param( 'table' );
		$table = $this->translatePress->table( $name );
		if ( null === $table ) {
			return new \WP_Error( 'wst_unknown_table', __( 'This is not a TranslatePress dictionary table of the configured languages.', 'wp-site-translator' ), array( 'status' => 400 ) );
		}
		$target = Settings::load()->targetLanguage();
		if ( null === $target || $target->locale() !== $table['target'] ) {
			return new \WP_Error( 'wst_target_mismatch', __( 'Copy the TranslatePress languages into the settings first (step 2): the target language must be the one of this table.', 'wp-site-translator' ), array( 'status' => 409 ) );
		}
		$import = new DictionaryImport( $this->translatePress, new Importer( $this->store, $target ) );

		return $import->run( $name, (int) $request->get_param( 'after' ), (string) $request->get_param( 'policy' ), ! $request->get_param( 'dry_run' ), get_current_user_id() );
	}

	/**
	 * Match report for chosen pages.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>|\WP_Error
	 */
	public function match( \WP_REST_Request $request ) {
		$settings = Settings::load();
		$target   = $settings->targetLanguage();
		if ( null === $target ) {
			return new \WP_Error( 'wst_no_target', __( 'Choose the target language first.', 'wp-site-translator' ), array( 'status' => 409 ) );
		}
		$urls  = \WST\Routing\Urls::fromHome( (string) get_option( 'home' ), rest_get_url_prefix(), $settings->defaultPrefix() );
		$paths = array_values( array_map( 'strval', (array) $request->get_param( 'paths' ) ) );

		return ( new MatchReport( $settings, $this->store, $urls, $target ) )->run( $paths );
	}

	/**
	 * Flush rewrite rules (go-live checklist, after deactivating TranslatePress).
	 *
	 * @return array{flushed: bool}
	 */
	public function flush(): array {
		flush_rewrite_rules( false );
		update_option( self::FLUSHED_OPTION, time(), false );

		return array( 'flushed' => true );
	}

	/**
	 * Our settings that keep TranslatePress's languages and URLs.
	 *
	 * @param string $target TranslatePress target locale.
	 * @return array{settings: array<string, mixed>, problem: string}
	 */
	private function prefillFor( string $target ): array {
		$tp       = $this->translatePress->settings();
		$registry = new Registry();
		if ( null === $tp ) {
			return array(
				'settings' => array(),
				'problem'  => __( 'TranslatePress settings were not found.', 'wp-site-translator' ),
			);
		}
		foreach ( array( $tp['default'], $target ) as $locale ) {
			if ( ! $registry->isKnown( $locale ) ) {
				return array(
					'settings' => array(),
					/* translators: %s: locale code */
					'problem'  => sprintf( __( 'The language %s is not available in WP Site Translator.', 'wp-site-translator' ), $locale ),
				);
			}
		}

		return array(
			'settings' => array(
				'default_language' => $tp['default'],
				'target_language'  => $target,
				'target_slug'      => $tp['slugs'][ $target ] ?? '',
				'default_slug'     => $tp['slugs'][ $tp['default'] ] ?? '',
				'prefix_default'   => $tp['prefix_default'],
			),
			'problem'  => '',
		);
	}

	/**
	 * Whether our URLs use TranslatePress's slugs and default-language prefix.
	 *
	 * @param array{default: string, languages: list<string>, slugs: array<string, string>, prefix_default: bool} $tp   TranslatePress settings.
	 * @param array<string, mixed>                                                                                $ours Our language settings.
	 */
	private function slugsMatch( array $tp, array $ours ): bool {
		$target = (string) $ours['target_language'];
		if ( ( $tp['slugs'][ $target ] ?? null ) !== $ours['target_slug'] || $tp['prefix_default'] !== $ours['prefix_default'] ) {
			return false;
		}

		return ! $tp['prefix_default'] || ( $tp['slugs'][ $tp['default'] ] ?? null ) === $ours['default_slug'];
	}

	/**
	 * Pages scanned by our editor or "Translate entire site".
	 */
	private function scannedPages(): int {
		return (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE last_scan IS NOT NULL', $this->db->prefix . Config::PREFIX . 'pages' ) );
	}

	/**
	 * Translated URLs of a few pages under TranslatePress and with us.
	 *
	 * @param string $tpSlug  TranslatePress slug of the target language.
	 * @param string $ourSlug Our slug of the target language.
	 * @return list<array{translatepress: string, ours: string, same: bool}>
	 */
	private function urlPairs( string $tpSlug, string $ourSlug ): array {
		$home  = (string) get_option( 'home' );
		$urls  = \WST\Routing\Urls::fromHome( $home, rest_get_url_prefix(), $this->settings->defaultPrefix() );
		$links = array( home_url( '/' ) );
		foreach ( get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 4,
				'orderby'        => 'modified',
			)
		) as $post ) {
			$link = get_permalink( $post );
			if ( false !== $link ) {
				$links[] = $link;
			}
		}
		$pairs = array();
		foreach ( $links as $link ) {
			$plain   = LanguageUrls::origin( $home ) . (string) wp_parse_url( $link, PHP_URL_PATH );
			$tp      = $urls->addPrefix( $plain, $tpSlug );
			$ours    = $urls->addPrefix( $plain, $ourSlug );
			$pairs[] = array(
				'translatepress' => $tp,
				'ours'           => $ours,
				'same'           => $tp === $ours,
			);
		}

		return $pairs;
	}
}

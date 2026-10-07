<?php
/**
 * REST: settings and languages for the admin app (plan §10, §14).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Rest;

use WST\Cache\Purger;
use WST\Config;
use WST\Html\Selectors;
use WST\Languages\Language;
use WST\Languages\Registry;
use WST\Providers\Secrets;
use WST\Queue\Queue;
use WST\Queue\Scheduler;
use WST\Settings;

/**
 * GET/POST /settings and GET /languages, manage_options only.
 *
 * Secrets are write-only: responses say whether each one is set and where
 * it comes from (constant, environment or the database), never any part of
 * the value. A submitted value that Settings would drop or change is
 * rejected with a 400 naming the field, so nothing is silently ignored.
 */
final class SettingsController {

	/**
	 * Create the controller.
	 *
	 * @param Secrets   $secrets   Key store.
	 * @param Queue     $queue     Queue (to reschedule work after a save).
	 * @param Scheduler $scheduler Cron runner.
	 */
	public function __construct(
		private Secrets $secrets,
		private Queue $queue,
		private Scheduler $scheduler
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
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save' ),
					'permission_callback' => $admin,
					'args'                => array(
						'settings' => array(
							'type'    => 'object',
							'default' => array(),
						),
						'secrets'  => array(
							'type'                 => 'object',
							'default'              => array(),
							'additionalProperties' => array( 'type' => 'string' ),
						),
					),
				),
			)
		);
		register_rest_route(
			Config::REST_NAMESPACE,
			'/languages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'languages' ),
				'permission_callback' => $admin,
			)
		);
	}

	/**
	 * Current settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		return $this->describe( Settings::load(), false );
	}

	/**
	 * Save submitted settings (top-level keys replace stored ones) and secrets.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>|\WP_Error
	 */
	public function save( \WP_REST_Request $request ) {
		$submitted = $request->get_param( 'settings' );
		$submitted = is_array( $submitted ) ? $submitted : array();
		$secrets   = $request->get_param( 'secrets' );
		$secrets   = is_array( $secrets ) ? $secrets : array();

		$stored = get_option( Settings::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		unset( $stored['schema'] );
		$before = new Settings( $stored, Settings::siteLocale(), new Registry() );
		$after  = new Settings( array_merge( $before->toArray(), $submitted ), Settings::siteLocale(), new Registry() );

		$invalid = self::invalid( $submitted, $after ) + $this->invalidSecrets( $secrets );
		if ( array() !== $invalid ) {
			return new \WP_Error(
				'wst_invalid_settings',
				__( 'Some values were not saved because they are not valid.', 'wp-site-translator' ),
				array(
					'status'  => 400,
					'invalid' => $invalid,
				)
			);
		}

		foreach ( $secrets as $name => $value ) {
			$this->secrets->set( (string) $name, (string) $value );
		}
		$changed = $after->toArray() !== $before->toArray();
		if ( $changed ) {
			update_option( Settings::OPTION, $after->toArray(), true );
			Purger::purgeAll();
		}
		if ( $this->queue->hasWork() ) {
			$this->scheduler->ensure();
		}

		return $this->describe( $after, $changed || array() !== $secrets );
	}

	/**
	 * Languages the admin can choose from.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function languages(): array {
		$registry = new Registry();

		return array_map( fn( string $locale ): array => self::language( $registry->get( $locale ) ), $registry->locales() );
	}

	/**
	 * Settings, secret status and the resolved languages.
	 *
	 * @param Settings $settings Settings.
	 * @param bool     $saved    Whether this request changed anything.
	 * @return array<string, mixed>
	 */
	private function describe( Settings $settings, bool $saved ): array {
		$values = $settings->toArray();
		unset( $values['schema'] );
		$secrets = array();
		foreach ( Secrets::NAMES as $name ) {
			$source           = $this->secrets->source( $name );
			$secrets[ $name ] = array(
				'set'    => '' !== $source,
				'source' => $source,
			);
		}
		$target = $settings->targetLanguage();

		return array(
			'settings'      => $values,
			'secrets'       => $secrets,
			'languages'     => array(
				'default' => self::language( $settings->defaultLanguage() ),
				'target'  => null === $target ? null : self::language( $target ),
			),
			'site_locale'   => Settings::siteLocale(),
			'default_slugs' => array(
				'default' => ( new Registry() )->get( $settings->toArray()['default_language'] )->slug(),
				'target'  => null === $target ? null : ( new Registry() )->get( $target->locale() )->slug(),
			),
			'saved'         => $saved,
		);
	}

	/**
	 * A language for the admin app.
	 *
	 * @param Language $language Language.
	 * @return array{locale: string, slug: string, english: string, native: string, rtl: bool, tag: string}
	 */
	private static function language( Language $language ): array {
		return array(
			'locale'  => $language->locale(),
			'slug'    => $language->slug(),
			'english' => $language->englishName(),
			'native'  => $language->nativeName(),
			'rtl'     => $language->isRtl(),
			'tag'     => $language->tag(),
		);
	}

	/**
	 * Submitted settings that were not kept as given, with a reason each.
	 *
	 * @param array<string, mixed> $submitted Submitted values.
	 * @param Settings             $after     Sanitised result.
	 * @return array<string, string>
	 */
	private static function invalid( array $submitted, Settings $after ): array {
		$clean   = $after->toArray();
		$invalid = array();
		foreach ( $submitted as $key => $value ) {
			$key = (string) $key;
			if ( 'schema' === $key || ! array_key_exists( $key, $clean ) ) {
				$invalid[ $key ] = __( 'Unknown setting.', 'wp-site-translator' );
				continue;
			}
			if ( wp_json_encode( $value ) === wp_json_encode( $clean[ $key ] ) ) {
				continue;
			}
			$invalid[ $key ] = self::reason( $key, $value );
		}
		$target = $after->targetLanguage();
		if ( ! isset( $invalid['default_slug'] ) && null !== $target && $after->flag( 'prefix_default' ) && $after->defaultLanguage()->slug() === $target->slug() ) {
			$invalid['default_slug'] = __( 'The default language needs a URL prefix different from the target language.', 'wp-site-translator' );
		}

		return $invalid;
	}

	/**
	 * Why a submitted value was not accepted.
	 *
	 * @param string $key   Setting.
	 * @param mixed  $value Submitted value.
	 */
	private static function reason( string $key, $value ): string {
		switch ( $key ) {
			case 'target_language':
				return __( 'Choose a language different from the default language.', 'wp-site-translator' );
			case 'target_slug':
			case 'default_slug':
				return __( 'Use 1–20 lowercase letters, digits or hyphens, starting with a letter or digit.', 'wp-site-translator' );
			case 'fallback_provider':
				return __( 'The fallback must be a different provider from the main one.', 'wp-site-translator' );
			case 'exclude_selectors':
				$bad = array_filter( is_array( $value ) ? array_map( 'strval', array_filter( $value, 'is_scalar' ) ) : array(), static fn( string $s ): bool => '' !== trim( $s ) && ! Selectors::isValid( $s ) );

				if ( array() !== $bad ) {
					/* translators: %s: selectors */
					return sprintf( __( 'Unsupported selectors: %s', 'wp-site-translator' ), implode( ', ', $bad ) );
				}

				/* translators: %d: maximum number of selectors */
				return is_array( $value ) && count( $value ) > Selectors::MAX ? sprintf( __( 'At most %d selectors.', 'wp-site-translator' ), Selectors::MAX ) : __( 'Send each selector trimmed, without empty lines.', 'wp-site-translator' );
			case 'switcher_colors':
				return __( 'Colours must be hex values such as #1e1e1e.', 'wp-site-translator' );
			case 'path_rules':
				return __( 'Each rule needs a path starting with / (or {{home}}) and a mode.', 'wp-site-translator' );
			case 'providers':
				return __( 'A provider limit is not a whole number in its allowed range, or a provider field is not valid.', 'wp-site-translator' );
		}

		return __( 'This value is not valid.', 'wp-site-translator' );
	}

	/**
	 * Submitted secrets that cannot be stored.
	 *
	 * @param array<mixed> $secrets Name => value.
	 * @return array<string, string>
	 */
	private function invalidSecrets( array $secrets ): array {
		$invalid = array();
		foreach ( $secrets as $name => $value ) {
			$name = (string) $name;
			if ( ! in_array( $name, Secrets::NAMES, true ) ) {
				$invalid[ 'secrets.' . $name ] = __( 'Unknown credential.', 'wp-site-translator' );
			} elseif ( ! is_string( $value ) ) {
				$invalid[ 'secrets.' . $name ] = __( 'Credentials must be text.', 'wp-site-translator' );
			} elseif ( in_array( $this->secrets->source( $name ), array( 'constant', 'env' ), true ) ) {
				$invalid[ 'secrets.' . $name ] = __( 'This credential is set in wp-config.php or the server environment; change it there.', 'wp-site-translator' );
			}
		}

		return $invalid;
	}
}

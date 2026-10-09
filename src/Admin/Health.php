<?php
/**
 * Health checks (plan §10 Advanced → Health).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Admin;

use WST\Database\Schema;
use WST\Log\Logger;
use WST\Providers\Selector;
use WST\Providers\StatusReport;
use WST\Queue\Queue;
use WST\Queue\Scheduler;
use WST\Queue\Usage;
use WST\Settings;

/**
 * Each check is ok, warning or error with a message an owner can act on.
 * The loopback test is separate because it makes an HTTP request.
 */
final class Health {

	/** Minimum versions (plan §3). */
	private const MIN_PHP = '8.0';
	private const MIN_WP  = '6.7';

	/**
	 * Create the checks.
	 *
	 * @param Settings     $settings Settings.
	 * @param \wpdb        $db       Database connection.
	 * @param Schema       $schema   Tables.
	 * @param Queue        $queue    Queue.
	 * @param StatusReport $status   Provider status.
	 * @param Selector     $selector Active provider.
	 * @param Logger       $logger   Plugin log.
	 */
	public function __construct(
		private Settings $settings,
		private \wpdb $db,
		private Schema $schema,
		private Queue $queue,
		private StatusReport $status,
		private Selector $selector,
		private Logger $logger
	) {
	}

	/**
	 * All checks except the loopback test.
	 *
	 * @return list<array{id: string, label: string, status: string, message: string}>
	 */
	public function checks(): array {
		return array(
			$this->php(),
			$this->wordpress(),
			$this->tables(),
			$this->locks(),
			$this->languages(),
			$this->provider(),
			$this->cron(),
			$this->renderFailures(),
		);
	}

	/**
	 * Request the home page as a visitor would (plan §10 "loopback test").
	 *
	 * @return array{id: string, label: string, status: string, message: string}
	 */
	public function loopback(): array {
		$label    = __( 'Loopback request', 'wp-site-translator' );
		$url      = home_url( '/' );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::check( 'loopback', $label, 'warning', sprintf( /* translators: 1: URL, 2: error */ __( 'The site could not load %1$s: %2$s. WP-Cron and scans may not work; use the server cron command and the admin queue runner.', 'wp-site-translator' ), $url, $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );

		return $code >= 200 && $code < 400
			? self::check( 'loopback', $label, 'ok', sprintf( /* translators: %d: HTTP status */ __( 'The site answered its own request (HTTP %d).', 'wp-site-translator' ), $code ) )
			: self::check( 'loopback', $label, 'warning', sprintf( /* translators: %d: HTTP status */ __( 'The site answered its own request with HTTP %d (basic auth, firewall or maintenance mode?).', 'wp-site-translator' ), $code ) );
	}

	/**
	 * Command for the server cron when WP-Cron is disabled.
	 */
	public static function serverCronCommand(): string {
		return '* * * * * wp --path=' . rtrim( ABSPATH, '/' ) . ' cron event run --due-now';
	}

	/**
	 * PHP version.
	 *
	 * @return array{id: string, label: string, status: string, message: string}
	 */
	private function php(): array {
		$ok = version_compare( PHP_VERSION, self::MIN_PHP, '>=' );

		/* translators: 1: current version, 2: minimum */
		return self::check( 'php', 'PHP', $ok ? 'ok' : 'error', sprintf( __( 'PHP %1$s (needs %2$s or newer).', 'wp-site-translator' ), PHP_VERSION, self::MIN_PHP ) );
	}

	/**
	 * WordPress version and the HTML API.
	 *
	 * @return array{id: string, label: string, status: string, message: string}
	 */
	private function wordpress(): array {
		$version = (string) get_bloginfo( 'version' );
		$ok      = version_compare( $version, self::MIN_WP, '>=' ) && class_exists( \WP_HTML_Tag_Processor::class );

		/* translators: 1: current version, 2: minimum */
		return self::check( 'wp', 'WordPress', $ok ? 'ok' : 'error', sprintf( __( 'WordPress %1$s (needs %2$s or newer with the HTML API).', 'wp-site-translator' ), $version, self::MIN_WP ) );
	}

	/**
	 * Plugin tables.
	 *
	 * @return array{id: string, label: string, status: string, message: string}
	 */
	private function tables(): array {
		$missing = array();
		foreach ( Schema::TABLES as $table ) {
			$name = $this->schema->table( $table );
			if ( $name !== $this->db->get_var( (string) $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $name ) ) ) ) {
				$missing[] = $name;
			}
		}

		return array() === $missing
			? self::check( 'tables', __( 'Database tables', 'wp-site-translator' ), 'ok', __( 'All plugin tables exist.', 'wp-site-translator' ) )
			: self::check( 'tables', __( 'Database tables', 'wp-site-translator' ), 'error', __( 'Missing tables (deactivate and activate the plugin): ', 'wp-site-translator' ) . implode( ', ', $missing ) );
	}

	/**
	 * MySQL named locks, which the rate limiter and the queue rely on.
	 *
	 * @return array{id: string, label: string, status: string, message: string}
	 */
	private function locks(): array {
		$got = $this->db->get_var( "SELECT GET_LOCK('wst_health', 0)" );
		$this->db->query( "SELECT RELEASE_LOCK('wst_health')" );
		$label = __( 'Database locks', 'wp-site-translator' );

		return '1' === (string) $got
			? self::check( 'locks', $label, 'ok', __( 'Named locks work (rate limiter and queue are safe across processes).', 'wp-site-translator' ) )
			: self::check( 'locks', $label, 'error', __( 'GET_LOCK() is not available on this database; the rate limiter cannot run safely.', 'wp-site-translator' ) );
	}

	/**
	 * Target language.
	 *
	 * @return array{id: string, label: string, status: string, message: string}
	 */
	private function languages(): array {
		$target = $this->settings->targetLanguage();
		$label  = __( 'Languages', 'wp-site-translator' );

		return null === $target
			? self::check( 'languages', $label, 'error', __( 'No target language is set: nothing is translated.', 'wp-site-translator' ) )
			/* translators: 1: default language, 2: target language, 3: URL prefix */
			: self::check( 'languages', $label, 'ok', sprintf( __( '%1$s → %2$s at /%3$s/.', 'wp-site-translator' ), $this->settings->defaultLanguage()->englishName(), $target->englishName(), $target->slug() ) );
	}

	/**
	 * Active provider, key and connection test.
	 *
	 * @return array{id: string, label: string, status: string, message: string}
	 */
	private function provider(): array {
		$label  = __( 'Translation provider', 'wp-site-translator' );
		$target = $this->settings->targetLanguage();
		if ( '' === $this->settings->provider() ) {
			return self::check( 'provider', $label, 'warning', __( 'No provider is selected: only manual translations are used.', 'wp-site-translator' ) );
		}
		$active = null === $target ? null : $this->selector->active( $this->settings->defaultLanguage(), $target, Usage::period() );
		if ( null === $active ) {
			return self::check( 'provider', $label, 'error', __( 'No provider can translate now: ', 'wp-site-translator' ) . $this->selector->problem( $this->settings->provider(), $this->settings->defaultLanguage(), $target ?? $this->settings->defaultLanguage(), Usage::period() ) );
		}
		$row = array_column( $this->status->all(), null, 'id' )[ $active['id'] ] ?? null;
		if ( null !== $row && null === $row['verified_at'] ) {
			/* translators: %s: provider name */
			return self::check( 'provider', $label, 'warning', sprintf( __( '%s has not passed a connection test with its current key yet.', 'wp-site-translator' ), $row['label'] ) );
		}
		if ( '' !== $active['fallbackReason'] ) {
			/* translators: 1: provider id, 2: reason */
			return self::check( 'provider', $label, 'warning', sprintf( __( 'Using the fallback provider %1$s: %2$s', 'wp-site-translator' ), $active['id'], $active['fallbackReason'] ) );
		}

		/* translators: %s: provider id */
		return self::check( 'provider', $label, 'ok', sprintf( __( '%s is ready.', 'wp-site-translator' ), $active['id'] ) );
	}

	/**
	 * WP-Cron for the queue.
	 *
	 * @return array{id: string, label: string, status: string, message: string}
	 */
	private function cron(): array {
		$label = __( 'Background processing', 'wp-site-translator' );
		$next  = wp_next_scheduled( Scheduler::HOOK );
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return self::check( 'cron', $label, 'warning', __( 'WP-Cron is disabled (DISABLE_WP_CRON). Run this every minute from the server cron: ', 'wp-site-translator' ) . self::serverCronCommand() );
		}
		if ( $this->queue->hasWork() && false === $next ) {
			return self::check( 'cron', $label, 'error', __( 'Strings are queued but no queue run is scheduled. Open the Overview to process the queue, or save any setting to reschedule.', 'wp-site-translator' ) );
		}

		return false === $next
			? self::check( 'cron', $label, 'ok', __( 'Nothing queued; no run scheduled.', 'wp-site-translator' ) )
			/* translators: %s: date and time */
			: self::check( 'cron', $label, 'ok', sprintf( __( 'Next queue run: %s.', 'wp-site-translator' ), (string) wp_date( 'Y-m-d H:i:s', $next ) ) );
	}

	/**
	 * Pages that fell back to the original HTML (plan §6 failure policy).
	 *
	 * @return array{id: string, label: string, status: string, message: string}
	 */
	private function renderFailures(): array {
		$count = $this->logger->countSince( 'error', 'render', DAY_IN_SECONDS );
		$label = __( 'Page rendering', 'wp-site-translator' );

		return 0 === $count
			? self::check( 'render', $label, 'ok', __( 'No rendering errors in the last 24 hours.', 'wp-site-translator' ) )
			/* translators: %d: number of failures */
			: self::check( 'render', $label, 'error', sprintf( _n( '%d translated page was shown untranslated in the last 24 hours because rendering failed. See the log.', '%d translated pages were shown untranslated in the last 24 hours because rendering failed. See the log.', $count, 'wp-site-translator' ), $count ) );
	}

	/**
	 * One check result.
	 *
	 * @param string $id      Check id.
	 * @param string $label   Label.
	 * @param string $status  ok, warning or error.
	 * @param string $message Message.
	 * @return array{id: string, label: string, status: string, message: string}
	 */
	private static function check( string $id, string $label, string $status, string $message ): array {
		return array(
			'id'      => $id,
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
		);
	}
}

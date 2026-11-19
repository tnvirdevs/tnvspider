<?php
/**
 * Automatic translation of every page, without waiting for visitors.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Site;

use WST\Config;
use WST\Editor\LoopbackScan;
use WST\Editor\PageTarget;
use WST\Languages\Language;
use WST\Modes\Resolver;
use WST\Queue\AutoQueue;
use WST\Queue\Scheduler;
use WST\Queue\Worker;
use WST\Settings;
use WST\Storage\StringStore;

/**
 * Walks the "Translate entire site" page list in WP-Cron and scans each page
 * through a server-side request (LoopbackScan), which records its strings and
 * queues the untranslated ones exactly as a visitor's first view would. The
 * same exclusions apply (off, personal, password-protected and never-discover
 * pages); manual pages are scanned (their strings are recorded for the
 * editor) but never queued. Pages scanned earlier without queueing ("Translate
 * entire site" left unconfirmed) get their known untranslated strings queued.
 * A page is scanned again when its post is published or updated;
 * pages without a post (home, archives) are checked again daily. Runs only
 * while the setting is on, the site mode is automatic and a provider can
 * translate; the monthly budget still limits what the queue sends.
 */
final class BackgroundScan {

	public const HOOK       = Config::PREFIX . 'site_scan';
	public const DAILY_HOOK = Config::PREFIX . 'site_scan_daily';
	public const OPTION     = Config::PREFIX . 'site_scan';

	/** Pages scanned per run at most. */
	public const PAGES_PER_RUN = 10;

	/** Seconds after which a run starts no further scan. */
	public const SECONDS_PER_RUN = 20;

	/** Posts remembered for a rescan at most. */
	private const CHANGED_MAX = 500;

	/** Named database lock: one run at a time. */
	private const LOCK = Config::PREFIX . 'site_scan';

	/**
	 * Create the scanner.
	 *
	 * @param Settings     $settings Settings.
	 * @param SitePages    $sitePages Page list.
	 * @param PageTarget   $pages    Page resolver.
	 * @param StringStore  $store    String store (page scan times).
	 * @param AutoQueue    $auto     Provider check.
	 * @param Language     $target   Target language.
	 * @param LoopbackScan $loopback Server-side scan.
	 * @param Resolver     $modes    Page modes.
	 * @param \wpdb        $db       Database (named lock).
	 */
	public function __construct(
		private Settings $settings,
		private SitePages $sitePages,
		private PageTarget $pages,
		private StringStore $store,
		private AutoQueue $auto,
		private Language $target,
		private LoopbackScan $loopback,
		private Resolver $modes,
		private \wpdb $db
	) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( self::DAILY_HOOK, array( $this, 'restart' ) );
		add_action( 'transition_post_status', array( $this, 'postChanged' ), 10, 3 );
		add_action( 'init', array( $this, 'schedule' ), 20 );
	}

	/**
	 * Whether pages are scanned now: setting on, automatic site mode and a
	 * provider that can translate.
	 */
	public function enabled(): bool {
		return $this->settings->flag( 'auto_site_scan' )
			&& Settings::MODE_AUTO === $this->settings->siteMode()
			&& '' !== $this->auto->provider( $this->target );
	}

	/**
	 * Keep the WP-Cron events in line with the state: the every-minute run
	 * while pages are left, the daily restart while the feature is on.
	 */
	public function schedule(): void {
		if ( ! $this->settings->flag( 'auto_site_scan' ) ) {
			wp_clear_scheduled_hook( self::HOOK );
			wp_clear_scheduled_hook( self::DAILY_HOOK );

			return;
		}
		if ( false === wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
		$state = $this->state();
		if ( ( 0 === $state['finished'] || array() !== $state['changed'] ) && false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), Scheduler::INTERVAL, self::HOOK );
		}
	}

	/**
	 * Remember a published or updated public post for a rescan.
	 *
	 * @param string $newStatus New status.
	 * @param string $oldStatus Old status.
	 * @param mixed  $post      Post.
	 */
	public function postChanged( $newStatus, $oldStatus, $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Hook signature.
		if ( 'publish' !== $newStatus || ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || ! is_post_publicly_viewable( $post ) ) {
			return;
		}
		$state            = $this->state();
		$state['changed'] = array_slice( array_values( array_unique( array_merge( $state['changed'], array( $post->ID ) ) ) ), -self::CHANGED_MAX );
		$this->save( $state );
		$this->schedule();
	}

	/**
	 * Start a new pass over every page (daily).
	 */
	public function restart(): void {
		$state             = $this->state();
		$state['cursor']   = 0;
		$state['checked']  = 0;
		$state['scanned']  = 0;
		$state['failed']   = 0;
		$state['started']  = time();
		$state['finished'] = 0;
		$this->save( $state );
		$this->schedule();
	}

	/**
	 * One WP-Cron run: changed posts first, then the next pages of the pass.
	 */
	public function run(): void {
		if ( ! $this->enabled() ) {
			return;
		}
		if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', self::LOCK ) ) ) {
			return;
		}
		try {
			$state    = $this->state();
			$deadline = time() + self::SECONDS_PER_RUN;
			$scans    = 0;
			while ( array() !== $state['changed'] && $scans < self::PAGES_PER_RUN && time() < $deadline && $this->timeLeft() ) {
				$postId = (int) array_shift( $state['changed'] );
				try {
					$this->scan( $this->pages->forPost( $postId ), $state );
				} catch ( \InvalidArgumentException $e ) {
					// Unpublished or deleted since it was remembered: nothing to scan.
					continue;
				}
				++$scans;
				$this->save( $state );
			}
			while ( 0 === $state['finished'] && $scans < self::PAGES_PER_RUN && time() < $deadline && $this->timeLeft() ) {
				$slice          = $this->sitePages->slice( intdiv( $state['cursor'], SitePages::PER_PAGE ) + 1 );
				$state['total'] = $slice['total'];
				$item           = $slice['items'][ $state['cursor'] % SitePages::PER_PAGE ] ?? null;
				if ( null === $item ) {
					$state['finished'] = time();
					break;
				}
				++$state['cursor'];
				++$state['checked'];
				if ( '' !== $item['skip'] ) {
					$this->save( $state );
					continue;
				}
				if ( $this->needsScan( $item['path'], $item['post_id'] ) ) {
					try {
						$page = null === $item['post_id'] ? $this->pages->forPath( $item['path'] ) : $this->pages->forPost( $item['post_id'] );
						$this->scan( $page, $state );
					} catch ( \InvalidArgumentException $e ) {
						++$state['failed'];
						$state['last_error'] = $item['path'] . ': ' . $e->getMessage();
					}
					++$scans;
				} elseif ( Settings::MODE_AUTO === $this->modes->resolve( $item['post_id'], $item['path'] )['mode'] ) {
					$known = array_keys( $this->store->untranslatedOnPages( array( StringStore::pageKey( $item['path'] ) ), $this->target->locale() ) );
					if ( array() !== $known ) {
						$this->auto->queue( $known, $this->target );
					}
				}
				$this->save( $state );
			}
			$this->save( $state );
			if ( 0 !== $state['finished'] && array() === $state['changed'] ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
		} finally {
			$this->db->query( (string) $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) );
		}
	}

	/**
	 * Progress for the Overview.
	 *
	 * @return array{on: bool, running: bool, total: int, checked: int, scanned: int, failed: int, started: int, finished: int, changed: int, last_error: string, blocked: string}
	 */
	public function status(): array {
		$state   = $this->state();
		$blocked = '';
		if ( $this->settings->flag( 'auto_site_scan' ) && ! $this->enabled() ) {
			$blocked = Settings::MODE_AUTO !== $this->settings->siteMode() ? 'manual_site' : 'no_provider';
		}

		return array(
			'on'         => $this->settings->flag( 'auto_site_scan' ),
			'running'    => false !== wp_next_scheduled( self::HOOK ),
			'total'      => $state['total'],
			'checked'    => $state['checked'],
			'scanned'    => $state['scanned'],
			'failed'     => $state['failed'],
			'started'    => $state['started'],
			'finished'   => $state['finished'],
			'changed'    => count( $state['changed'] ),
			'last_error' => $state['last_error'],
			'blocked'    => $blocked,
		);
	}

	/**
	 * Scan one page and count the result.
	 *
	 * @param array<string, mixed> $page  Page facts.
	 * @param array<string, mixed> $state State (counts updated).
	 * @phpstan-param array{cursor: int, total: int, checked: int, scanned: int, failed: int, started: int, finished: int, changed: list<int>, last_error: string} $state
	 * @phpstan-param-out array{cursor: int, total: int, checked: int, scanned: int, failed: int, started: int, finished: int, changed: list<int>, last_error: string} $state
	 */
	private function scan( array $page, array &$state ): void {
		$result = $this->loopback->run( $page, array( 'allow_personal' => false ) );
		if ( $result instanceof \WP_Error ) {
			++$state['failed'];
			$state['last_error'] = $page['path'] . ': ' . $result->get_error_message();

			return;
		}
		++$state['scanned'];
	}

	/**
	 * Whether a page needs a scan: never scanned, its post changed since,
	 * or (pages without a post) last scanned more than a day ago.
	 *
	 * @param string   $path   Site path.
	 * @param int|null $postId Post shown there, if any.
	 */
	private function needsScan( string $path, ?int $postId ): bool {
		$lastScan = $this->store->pageTimes( $path )['last_scan'];
		if ( null === $lastScan ) {
			return true;
		}
		$scanned = (int) strtotime( $lastScan . ' UTC' );
		if ( null === $postId ) {
			return $scanned < time() - DAY_IN_SECONDS;
		}
		$post = get_post( $postId );

		return null !== $post && (int) strtotime( $post->post_modified_gmt . ' UTC' ) > $scanned;
	}

	/**
	 * Whether one more scan request fits PHP's time limit (raised when allowed).
	 */
	private function timeLeft(): bool {
		$started = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );

		return Worker::fitsTimeLimit(
			LoopbackScan::TIMEOUT,
			(int) ini_get( 'max_execution_time' ),
			microtime( true ) - $started,
			static fn( int $seconds ): bool => function_exists( 'set_time_limit' ) && set_time_limit( $seconds )
		);
	}

	/**
	 * Stored state with defaults.
	 *
	 * @return array{cursor: int, total: int, checked: int, scanned: int, failed: int, started: int, finished: int, changed: list<int>, last_error: string}
	 */
	private function state(): array {
		$raw   = get_option( self::OPTION, array() );
		$raw   = is_array( $raw ) ? $raw : array();
		$int   = static fn( string $key ): int => isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ? (int) $raw[ $key ] : 0;
		$posts = isset( $raw['changed'] ) && is_array( $raw['changed'] ) ? array_values( array_map( 'intval', $raw['changed'] ) ) : array();

		return array(
			'cursor'     => $int( 'cursor' ),
			'total'      => $int( 'total' ),
			'checked'    => $int( 'checked' ),
			'scanned'    => $int( 'scanned' ),
			'failed'     => $int( 'failed' ),
			'started'    => $int( 'started' ),
			'finished'   => $int( 'finished' ),
			'changed'    => $posts,
			'last_error' => isset( $raw['last_error'] ) && is_string( $raw['last_error'] ) ? $raw['last_error'] : '',
		);
	}

	/**
	 * Store the state (not autoloaded).
	 *
	 * @param array<string, mixed> $state State.
	 */
	private function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}
}

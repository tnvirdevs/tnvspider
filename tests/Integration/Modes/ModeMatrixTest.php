<?php
/**
 * Plan §16 Phase 3: every mode × source × discover combination of §9,
 * through a real target-language request (Router + Pipeline buffer).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Modes;

use WP_UnitTestCase;
use WST\Database\Schema;
use WST\Languages\Current;
use WST\Languages\Registry;
use WST\Log\Logger;
use WST\Modes\Resolver;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\Selector;
use WST\Queue\AutoQueue;
use WST\Queue\Queue;
use WST\Queue\Scheduler;
use WST\Render\DiscoveryGate;
use WST\Render\Pipeline;
use WST\Routing\Router;
use WST\Routing\Urls;
use WST\Settings;
use WST\Storage\StringStore;
use WST\Tests\Support\FakeProvider;

final class ModeMatrixTest extends WP_UnitTestCase {

	private int $postId;

	private StringStore $store;

	private Queue $queue;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->set_permalink_structure( '/%postname%/' );
		$this->postId = self::factory()->post->create( array( 'post_name' => 'matrix-page' ) );
		$schema       = new Schema( $wpdb );
		$this->store  = new StringStore( $wpdb, $schema );
		$this->queue  = new Queue( $wpdb, $schema );
		$this->store->saveManual( 'Known string', 'text', 'bn_BD', 'জানা বাক্য', 0 );
		delete_option( ProviderState::OPTION );
		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0';
		wp_set_current_user( 0 );
		wp_cache_flush();
	}

	public function tear_down(): void {
		Current::reset();
		unset( $_SERVER['HTTP_USER_AGENT'] );
		wp_clear_scheduled_hook( Scheduler::HOOK );
		parent::tear_down();
	}

	/**
	 * Visit the target page: the Router and the pipeline's start() resolve the
	 * mode and the discovery gate for the real request.
	 *
	 * @param array<string, mixed> $values Settings.
	 * @param string               $provider Provider id, '' for none.
	 */
	private function visit( array $values, string $provider = 'translatex' ): string {
		global $wpdb;
		$settings = new Settings(
			$values + array(
				'target_language' => 'bn_BD',
				'provider'        => $provider,
			),
			'en_US',
			new Registry()
		);
		$target   = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$urls     = Urls::fromHome( (string) get_option( 'home' ), 'wp-json' );
		$logger   = new Logger( $wpdb, new Schema( $wpdb ) );
		$registry = new ProviderRegistry( '' === $provider ? array() : array( $provider => new FakeProvider( $provider ) ) );
		$schedule = new Scheduler(
			$this->queue,
			static function (): \WST\Queue\Worker {
				throw new \LogicException( 'Rendering must not run the worker.' );
			}
		);
		$schedule->boot();
		$pipeline = new Pipeline(
			$settings,
			$target,
			$this->store,
			new DiscoveryGate( $settings, $logger ),
			$logger,
			$urls,
			new AutoQueue( $settings, new Selector( $settings, $registry, new ProviderState() ), $this->queue, $schedule ),
			new Resolver( $settings, $urls, $target )
		);
		( new Router( $settings, $target, $urls ) )->boot();
		$this->go_to( '/bn/matrix-page/' );

		$level = ob_get_level();
		$pipeline->start();
		$this->assertSame( $level + 1, ob_get_level(), 'Target page is buffered.' );
		ob_end_clean();
		$context = $pipeline->context() ?? throw new \LogicException( 'No context.' );

		// process() directly: the CLI has no HTTP status, which finish() requires to be 200.
		return $pipeline->process( '<html lang="en-US"><body><p>Known string</p><p>Matrix new string</p></body></html>', $context );
	}

	/**
	 * Settings that make $mode come from $source, with the other sources
	 * set to a different mode so the precedence is visible.
	 *
	 * @param string $mode   auto, manual or off.
	 * @param string $source site, path or page.
	 * @return array<string, mixed>
	 */
	private function configure( string $mode, string $source ): array {
		$other = Settings::MODE_AUTO === $mode ? Settings::MODE_MANUAL : Settings::MODE_AUTO;
		if ( Resolver::SOURCE_PAGE === $source ) {
			update_post_meta( $this->postId, Resolver::META_KEY, $mode );
		}

		$rules = array(
			array(
				'path' => '/other/*',
				'mode' => $mode,
			),
		);
		if ( Resolver::SOURCE_SITE !== $source ) {
			// A matching rule: the result for "path", overruled for "page".
			$rules[] = array(
				'path' => '/matrix-page/',
				'mode' => Resolver::SOURCE_PATH === $source ? $mode : $other,
			);
		}

		return array(
			'site_mode'    => Resolver::SOURCE_SITE === $source ? $mode : $other,
			'off_behavior' => Settings::OFF_ORIGINAL,
			'path_rules'   => $rules,
		);
	}

	/**
	 * @dataProvider matrixProvider
	 */
	public function test_mode_source_and_discover_setting( string $mode, string $source, bool $discover ): void {
		$out = $this->visit( array( 'discover_on_visit' => $discover ) + $this->configure( $mode, $source ) );

		$expectDiscover = Settings::MODE_AUTO === $mode && $discover;
		$this->assertSame( $expectDiscover, null !== $this->store->find( 'Matrix new string', 'bn_BD' ), 'Discovery.' );
		$this->assertSame( $expectDiscover ? 1 : 0, $this->queue->stats()['pending'], 'Automatic queueing.' );

		if ( Settings::MODE_OFF === $mode ) {
			$this->assertStringContainsString( '<p>Known string</p>', $out, 'Off: original text, even where a translation exists.' );
			$this->assertStringContainsString( 'lang="en-US"', $out );
		} else {
			$this->assertStringContainsString( '<p>জানা বাক্য</p>', $out, 'Stored translations apply in every other mode.' );
			$this->assertStringContainsString( 'lang="bn-BD"', $out );
		}
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public function matrixProvider(): array {
		$cases = array();
		foreach ( Settings::MODES as $mode ) {
			foreach ( array( Resolver::SOURCE_SITE, Resolver::SOURCE_PATH, Resolver::SOURCE_PAGE ) as $source ) {
				if ( Settings::MODE_OFF === $mode && Resolver::SOURCE_SITE === $source ) {
					continue; // The site mode is auto or manual only (plan §9).
				}
				foreach ( array( true, false ) as $discover ) {
					$cases[ sprintf( '%s from %s, discover %s', $mode, $source, $discover ? 'on' : 'off' ) ] = array( $mode, $source, $discover );
				}
			}
		}

		return $cases;
	}

	public function test_page_setting_inherit_falls_back_to_path_rules_then_site(): void {
		update_post_meta( $this->postId, Resolver::META_KEY, Resolver::INHERIT );
		$this->visit(
			array(
				'site_mode'  => Settings::MODE_AUTO,
				'path_rules' => array(
					array(
						'path' => '/matrix-*',
						'mode' => Settings::MODE_MANUAL,
					),
				),
			)
		);

		$this->assertNull( $this->store->find( 'Matrix new string', 'bn_BD' ), 'The path rule (manual) applied.' );
	}

	public function test_auto_page_without_a_usable_provider_discovers_but_queues_nothing(): void {
		$this->visit( array( 'site_mode' => Settings::MODE_AUTO ), '' );

		$this->assertNotNull( $this->store->find( 'Matrix new string', 'bn_BD' ) );
		$this->assertSame( 0, $this->queue->stats()['pending'] );
	}

	public function test_manual_page_never_reaches_the_queue_even_with_known_untranslated_strings(): void {
		$this->store->recordOnPage( array( 'Matrix new string' => 'text' ), '/elsewhere/', null );
		update_post_meta( $this->postId, Resolver::META_KEY, Settings::MODE_MANUAL );

		$this->visit( array( 'site_mode' => Settings::MODE_AUTO ) );

		$this->assertFalse( $this->queue->hasWork() );
	}
}

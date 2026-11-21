<?php
/**
 * End-to-end checks for the plan §16 Phase 1 acceptance criteria: a real
 * request through the Router, the Pipeline's output buffer and core's
 * menus.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration;

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

final class Phase1AcceptanceTest extends WP_UnitTestCase {

	private int $postId;

	private Pipeline $pipeline;

	private StringStore $store;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->set_permalink_structure( '/%postname%/' );
		$this->postId = self::factory()->post->create(
			array(
				'post_name'  => 'hello',
				'post_title' => 'Hello visitor',
			)
		);

		$settings = new Settings( array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() );
		$target   = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$urls     = Urls::fromHome( (string) get_option( 'home' ), 'wp-json' );
		$schema   = new Schema( $wpdb );
		$logger   = new Logger( $wpdb, $schema );

		$this->store = new StringStore( $wpdb, $schema );
		// Phase 1 behaviour: no provider, so nothing is queued.
		$queue          = new Queue( $wpdb, $schema );
		$auto           = new AutoQueue(
			$settings,
			new Selector( $settings, new ProviderRegistry( array() ), new ProviderState() ),
			$queue,
			new Scheduler(
				$queue,
				static function (): \WST\Queue\Worker {
					throw new \LogicException( 'Rendering must not run the worker.' );
				}
			)
		);
		$this->pipeline = new Pipeline( $settings, $target, $this->store, new DiscoveryGate( $settings, $logger ), $logger, $urls, $auto, new Resolver( $settings, $urls, $target ) );
		( new Router( $settings, $target, $urls ) )->boot();
		wp_cache_flush();
	}

	public function tear_down(): void {
		Current::reset();
		parent::tear_down();
	}

	/**
	 * Run $html through the Pipeline's own output buffer, as on a live request.
	 *
	 * @param string $html Page HTML.
	 * @return array{0: string, 1: bool} Output and whether buffering started.
	 */
	private function render( string $html ): array {
		ob_start();
		$level = ob_get_level();
		$this->pipeline->start();
		$buffered = ob_get_level() > $level;
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test fixture.
		if ( $buffered ) {
			ob_end_flush();
		}

		return array( (string) ob_get_clean(), $buffered );
	}

	public function test_target_url_renders_translated_text_from_the_database(): void {
		$this->store->saveManual( 'Hello visitor', 'text', 'bn_BD', 'স্বাগতম অতিথি', 0 );
		$this->go_to( '/bn/hello/' );

		[ $out, $buffered ] = $this->render( '<html><body><h1>' . get_the_title( $this->postId ) . '</h1></body></html>' );

		$this->assertTrue( $buffered );
		$this->assertStringContainsString( '<h1>স্বাগতম অতিথি</h1>', $out );
	}

	public function test_default_language_request_is_not_buffered_or_changed(): void {
		$this->store->saveManual( 'Hello visitor', 'text', 'bn_BD', 'স্বাগতম অতিথি', 0 );
		$this->go_to( '/hello/' );
		$html = '<html><body><h1>Hello visitor</h1><a href="/about/">About</a></body></html>';

		[ $out, $buffered ] = $this->render( $html );

		$this->assertFalse( $buffered );
		$this->assertSame( $html, $out );
	}

	public function test_menus_and_hard_coded_links_stay_in_the_language(): void {
		$menu = wp_create_nav_menu( 'Main' );
		$this->assertIsInt( $menu );
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-object-id' => $this->postId,
				'menu-item-object'    => 'post',
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
			)
		);
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'About',
				'menu-item-url'    => home_url( '/about/' ),
				'menu-item-type'   => 'custom',
				'menu-item-status' => 'publish',
			)
		);
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'Contact',
				'menu-item-url'    => '/contact/',
				'menu-item-type'   => 'custom',
				'menu-item-status' => 'publish',
			)
		);
		$this->go_to( '/bn/hello/' );

		$nav       = (string) wp_nav_menu(
			array(
				'menu' => $menu,
				'echo' => false,
			)
		);
		[ $out ]   = $this->render( '<html><body>' . $nav . '</body></html>' );
		$processor = new \WP_HTML_Tag_Processor( $out );
		$hrefs     = array();
		while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
			$hrefs[] = (string) $processor->get_attribute( 'href' );
		}

		$this->assertSame( array( 'http://example.org/bn/hello/', 'http://example.org/bn/about/', '/bn/contact/' ), $hrefs );
	}
}

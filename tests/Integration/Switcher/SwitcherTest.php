<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Switcher;

use WP_UnitTestCase;
use WST\Languages\Current;
use WST\Languages\Registry;
use WST\Modes\Resolver;
use WST\Routing\LanguageUrls;
use WST\Routing\Router;
use WST\Routing\Urls;
use WST\Settings;
use WST\Switcher\Switcher;

final class SwitcherTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		self::factory()->post->create( array( 'post_name' => 'hello' ) );
	}

	public function tear_down(): void {
		Current::reset();
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $values Settings.
	 */
	private function switcher( array $values = array() ): Switcher {
		$settings = new Settings( $values + array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() );
		$target   = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$urls     = Urls::fromHome( 'http://example.org', 'wp-json', $settings->defaultPrefix() );
		( new Router( $settings, $target, $urls ) )->boot();
		$switcher = new Switcher( $settings, $settings->defaultLanguage(), $target, new LanguageUrls( $urls, $target, 'http://example.org' ), new Resolver( $settings, $urls, $target ), dirname( __DIR__, 3 ) . '/wp-site-translator.php' );
		$switcher->boot();

		return $switcher;
	}

	public function test_label_styles(): void {
		$this->go_to( '/hello/' );
		$cases = array(
			'native names'   => array( array(), 'বাংলা', 'English' ),
			'english names'  => array( array( 'name_style' => 'english' ), 'Bengali', 'English' ),
			'codes'          => array( array( 'switcher_style' => 'codes' ), 'BN', 'EN' ),
			'codes and name' => array( array( 'switcher_style' => 'codes_names' ), 'BN · বাংলা', 'EN · English' ),
		);
		foreach ( $cases as $label => [ $values, $target, $default ] ) {
			$html = $this->switcher( $values )->render();
			$this->assertStringContainsString( '>' . $target . '</a>', $html, $label );
			$this->assertStringContainsString( '>' . $default . '</a>', $html, $label );
		}
	}

	public function test_custom_colours_become_css_variables_and_invalid_ones_are_dropped(): void {
		$this->go_to( '/hello/' );
		$html = $this->switcher(
			array(
				'switcher_theme'  => 'custom',
				'switcher_colors' => array(
					'text'       => '#ABCDEF',
					'background' => 'red;position:fixed',
					'accent'     => '#123',
				),
			)
		)->render();

		$this->assertStringContainsString( 'wst-switcher--custom', $html );
		$this->assertStringContainsString( 'style="--wst-switcher-text:#abcdef;--wst-switcher-bg:#ffffff;--wst-switcher-accent:#123"', $html );
	}

	public function test_floating_switcher_prints_in_the_footer_only_when_enabled(): void {
		$this->go_to( '/bn/hello/' );
		$off = $this->switcher();
		$this->assertFalse( has_action( 'wp_footer', array( $off, 'printFloating' ) ) );

		$on = $this->switcher(
			array(
				'switcher_floating' => true,
				'switcher_position' => 'top-left',
				'switcher_theme'    => 'dark',
			)
		);
		$this->assertNotFalse( has_action( 'wp_footer', array( $on, 'printFloating' ) ) );
		ob_start();
		$on->printFloating();
		$footer = (string) ob_get_clean();
		$this->assertStringContainsString( 'class="wst-switcher wst-switcher--dark wst-switcher--floating wst-switcher--top-left"', $footer );
		$this->assertStringContainsString( 'aria-current="true">বাংলা</a>', $footer );
		$this->assertStringContainsString( 'style="--wst-switcher-offset:16px"', $footer );
	}

	public function test_floating_offset_is_a_css_variable_and_inline_switchers_have_none(): void {
		$this->go_to( '/bn/hello/' );
		$switcher = $this->switcher(
			array(
				'switcher_floating' => true,
				'switcher_offset'   => 88,
				'switcher_theme'    => 'custom',
			)
		);
		ob_start();
		$switcher->printFloating();
		$footer = (string) ob_get_clean();

		$this->assertStringContainsString( ';--wst-switcher-offset:88px"', $footer );
		$this->assertStringNotContainsString( '--wst-switcher-offset', $switcher->render() );
	}

	public function test_menu_item_expands_into_one_link_per_language(): void {
		$menu = wp_create_nav_menu( 'Main' );
		$this->assertIsInt( $menu );
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'Language switcher',
				'menu-item-url'    => Switcher::MENU_URL,
				'menu-item-status' => 'publish',
				'menu-item-type'   => 'custom',
			)
		);
		$this->go_to( '/bn/hello/' );
		$this->switcher();

		$html = (string) wp_nav_menu(
			array(
				'menu'        => $menu,
				'echo'        => false,
				'fallback_cb' => false,
			)
		);

		$this->assertStringNotContainsString( Switcher::MENU_URL, $html );
		$this->assertMatchesRegularExpression( '#<a href="http://example.org/hello/" hreflang="en-US" lang="en-US" data-wst-no-translate="1">English</a>#', $html );
		$this->assertMatchesRegularExpression( '#<a href="http://example.org/bn/hello/"[^>]* hreflang="bn-BD" lang="bn-BD" data-wst-no-translate="1"[^>]*>বাংলা</a>#', $html );
		$this->assertSame( 1, substr_count( $html, 'aria-current="true"' ) );
		$this->assertStringContainsString( 'current-language', $html );
	}

	public function test_block_is_registered_and_renders_the_switcher(): void {
		$this->go_to( '/hello/' );
		$this->switcher();
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( Switcher::BLOCK ) );
		$this->assertStringContainsString( 'wst-switcher__list', do_blocks( '<!-- wp:wst/switcher /-->' ) );
		$this->assertTrue( wp_script_is( Switcher::BLOCK_SCRIPT, 'registered' ) );
		unregister_block_type( Switcher::BLOCK );
	}

	public function test_styles_are_enqueued_on_the_front_end(): void {
		$this->go_to( '/hello/' );
		$this->switcher();
		do_action( 'wp_enqueue_scripts' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$this->assertTrue( wp_style_is( Switcher::STYLE_HANDLE, 'enqueued' ) );
		$this->assertStringEndsWith( '/assets/switcher.css', wp_styles()->registered[ Switcher::STYLE_HANDLE ]->src );
	}

	public function test_links_carry_the_default_prefix_when_enabled(): void {
		$switcher = $this->switcher( array( 'prefix_default' => true ) );
		$this->go_to( '/en/hello/' );

		$html = $switcher->render();

		$this->assertStringContainsString( 'href="http://example.org/en/hello/" hreflang="en-US"', $html );
		$this->assertStringContainsString( 'href="http://example.org/bn/hello/" hreflang="bn-BD"', $html );
	}
}

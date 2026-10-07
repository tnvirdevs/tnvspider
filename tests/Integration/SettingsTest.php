<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration;

use WP_UnitTestCase;
use WST\Languages\Registry;
use WST\Settings;

final class SettingsTest extends WP_UnitTestCase {

	public function test_defaults(): void {
		$settings = new Settings( array(), 'en_US', new Registry() );

		$this->assertSame( 'en_US', $settings->defaultLanguage()->locale() );
		$this->assertNull( $settings->targetLanguage() );
		$this->assertSame( Settings::MODE_AUTO, $settings->siteMode() );
		$this->assertTrue( $settings->flag( 'discover_on_visit' ) );
		$this->assertTrue( $settings->flag( 'block_crawlers' ) );
		$this->assertTrue( $settings->flag( 'force_language_links' ) );
		$this->assertTrue( $settings->flag( 'hreflang_x_default' ) );
		$this->assertFalse( $settings->flag( 'hreflang_drop_region' ) );
		$this->assertSame( array( 'paged' ), $settings->discoveryQueryArgs() );
		$this->assertSame( 100, $settings->number( 'discovery_cap_page_hour' ) );
		$this->assertSame( 1000, $settings->number( 'discovery_cap_site_hour' ) );
		$this->assertSame( 2000, $settings->number( 'max_string_length' ) );
	}

	public function test_target_language_and_custom_slug(): void {
		$settings = new Settings(
			array(
				'target_language' => 'bn_BD',
				'target_slug'     => '/BD/',
			),
			'en_US',
			new Registry()
		);

		$this->assertSame( 'bn_BD', $settings->targetLanguage()?->locale() );
		$this->assertSame( 'bd', $settings->targetLanguage()?->slug() );
	}

	public function test_invalid_values_fall_back_to_defaults(): void {
		$settings = new Settings(
			array(
				'default_language'        => 'not a locale',
				'target_language'         => 'en_US',
				'target_slug'             => 'bad slug!',
				'site_mode'               => 'everything',
				'discovery_cap_page_hour' => -5,
				'max_string_length'       => 'many',
				'discovery_query_args'    => 'paged',
			),
			'en_US',
			new Registry()
		);

		$this->assertSame( 'en_US', $settings->defaultLanguage()->locale() );
		$this->assertNull( $settings->targetLanguage(), 'Target equal to the default language is not a target.' );
		$this->assertSame( Settings::MODE_AUTO, $settings->siteMode() );
		$this->assertSame( 100, $settings->number( 'discovery_cap_page_hour' ) );
		$this->assertSame( 2000, $settings->number( 'max_string_length' ) );
		$this->assertSame( array( 'paged' ), $settings->discoveryQueryArgs() );
	}

	public function test_absurd_numbers_are_clamped(): void {
		$settings = new Settings( array( 'discovery_cap_site_hour' => 999999999 ), 'en_US', new Registry() );

		$this->assertSame( 1000000, $settings->number( 'discovery_cap_site_hour' ) );
	}

	public function test_switcher_offset_defaults_and_is_clamped(): void {
		$registry = new Registry();
		$this->assertSame( Settings::SWITCHER_OFFSET, ( new Settings( array(), 'en_US', $registry ) )->number( 'switcher_offset' ) );
		$this->assertSame( 72, ( new Settings( array( 'switcher_offset' => 72 ), 'en_US', $registry ) )->number( 'switcher_offset' ) );
		$this->assertSame( 0, ( new Settings( array( 'switcher_offset' => 0 ), 'en_US', $registry ) )->number( 'switcher_offset' ) );
		$this->assertSame( Settings::SWITCHER_OFFSET_MAX, ( new Settings( array( 'switcher_offset' => 99999 ), 'en_US', $registry ) )->number( 'switcher_offset' ) );
		$this->assertSame( Settings::SWITCHER_OFFSET, ( new Settings( array( 'switcher_offset' => 'tall' ), 'en_US', $registry ) )->number( 'switcher_offset' ) );
	}

	public function test_load_reads_the_option_and_site_locale(): void {
		// Core refuses to store WPLANG for a language pack that is not installed.
		add_filter( 'pre_option_WPLANG', static fn(): string => 'bn_BD' );
		update_option(
			Settings::OPTION,
			array(
				'target_language' => 'en_US',
				'site_mode'       => 'manual',
			)
		);

		$settings = Settings::load();

		$this->assertSame( 'bn_BD', $settings->defaultLanguage()->locale() );
		$this->assertSame( 'en_US', $settings->targetLanguage()?->locale() );
		$this->assertSame( Settings::MODE_MANUAL, $settings->siteMode() );
		$this->assertSame( Settings::SCHEMA, $settings->toArray()['schema'] );
	}

	public function test_phase4_keys_default_and_validate(): void {
		$defaults = new Settings( array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() );
		$this->assertSame( 'native', $defaults->choice( 'name_style' ) );
		$this->assertSame( 'names', $defaults->choice( 'switcher_style' ) );
		$this->assertSame( 'bottom-right', $defaults->choice( 'switcher_position' ) );
		$this->assertSame( 'inherit', $defaults->choice( 'switcher_theme' ) );
		$this->assertSame( 'warning', $defaults->choice( 'log_level' ) );
		$this->assertSame( Settings::SWITCHER_COLORS, $defaults->switcherColors() );
		$this->assertFalse( $defaults->flag( 'switcher_floating' ) );
		$this->assertFalse( $defaults->flag( 'delete_on_uninstall' ) );
		$this->assertNull( $defaults->defaultPrefix() );

		$settings = new Settings(
			array(
				'target_language'   => 'bn_BD',
				'default_slug'      => 'english',
				'prefix_default'    => true,
				'name_style'        => 'english',
				'switcher_style'    => 'flags',
				'switcher_colors'   => array(
					'text'   => '#ABC',
					'accent' => 'blue',
				),
				'exclude_selectors' => array( '.a', 'div > p', '#b [data-x="1"]' ),
				'log_level'         => 'debug',
			),
			'en_US',
			new Registry()
		);
		$this->assertSame( 'english', $settings->defaultPrefix() );
		$this->assertSame( 'english', $settings->defaultLanguage()->slug() );
		$this->assertSame( 'english', $settings->choice( 'name_style' ) );
		$this->assertSame( 'names', $settings->choice( 'switcher_style' ), 'Flags are not offered (text-only styles).' );
		$this->assertSame( '#abc', $settings->switcherColors()['text'] );
		$this->assertSame( Settings::SWITCHER_COLORS['accent'], $settings->switcherColors()['accent'] );
		$this->assertSame( array( '.a', '#b [data-x="1"]' ), $settings->excludeSelectors() );
		$this->assertSame( 'warning', $settings->choice( 'log_level' ) );
	}

	public function test_default_prefix_is_off_when_it_would_equal_the_target_prefix(): void {
		$settings = new Settings(
			array(
				'target_language' => 'bn_BD',
				'target_slug'     => 'en',
				'prefix_default'  => true,
			),
			'en_US',
			new Registry()
		);

		$this->assertNull( $settings->defaultPrefix() );
	}
}

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
}

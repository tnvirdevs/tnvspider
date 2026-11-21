<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Html;

use WP_UnitTestCase;
use WST\Html\Extractor;
use WST\Html\Selectors;
use WST\Languages\Registry;
use WST\Settings;

final class ExcludeSelectorsTest extends WP_UnitTestCase {

	/**
	 * Texts found with these selectors.
	 *
	 * @param list<string> $selectors Selectors.
	 * @return list<string>
	 */
	private static function texts( string $html, array $selectors ): array {
		return array_map( static fn( $segment ): string => $segment->text, ( new Extractor( new Selectors( $selectors ) ) )->extract( $html ) );
	}

	public function test_matching_elements_text_and_attributes_are_not_extracted(): void {
		$html = '<body><header><p>Welcome</p></header>'
			. '<div class="product"><span class="price" data-role="price">Only 20 dollars</span><img alt="Soap bar" class="photo"></div>'
			. '<footer class="site-footer"><div><p class="legal">Company Ltd, Dhaka</p></div><p>Contact us</p></footer>'
			. '<div id="customer-name">Rahim Uddin</div></body>';

		$this->assertSame(
			array( 'Welcome', 'Contact us' ),
			self::texts( $html, array( '.site-footer .legal', '[data-role=price], #customer-name', 'img.photo' ) )
		);
		$this->assertContains( 'Soap bar', self::texts( $html, array() ), 'Without selectors everything is found.' );
	}

	public function test_descendants_of_an_excluded_element_are_excluded(): void {
		$this->assertSame( array( 'Keep' ), self::texts( '<section class="x"><p>Skip <b>this</b> part</p><a title="Skip title">Skip</a></section><p>Keep</p>', array( 'section.x' ) ) );
	}

	public function test_settings_keep_only_supported_selectors(): void {
		$settings = new Settings( array( 'exclude_selectors' => array( '.ok', 'ul > li', 'a:hover', '#id, [data-x]' ) ), 'en_US', new Registry() );

		$this->assertSame( array( '.ok', '#id, [data-x]' ), $settings->excludeSelectors() );
	}
}

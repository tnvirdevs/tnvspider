<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Html;

use WP_UnitTestCase;
use WST\Html\InlineMarkup;

final class InlineMarkupTest extends WP_UnitTestCase {

	/**
	 * @dataProvider structureProvider
	 */
	public function test_same_structure( string $translation, bool $expected ): void {
		$original = '<b>Price</b> for <a href="/members/" class="m">members</a><br>';

		$this->assertSame( $expected, InlineMarkup::sameStructure( $original, $translation ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function structureProvider(): array {
		return array(
			'same order'        => array( '<b>মূল্য</b> <a href="/members/" class="m">সদস্যদের</a><br>', true ),
			'reordered tags'    => array( '<a class="m" href="/members/">সদস্যদের</a> জন্য <b>মূল্য</b><br>', true ),
			'changed attribute' => array( '<b>মূল্য</b> <a href="/evil/" class="m">x</a><br>', false ),
			'missing tag'       => array( 'মূল্য <a href="/members/" class="m">x</a><br>', false ),
			'extra tag'         => array( '<b>মূল্য</b> <a href="/members/" class="m">x</a><br><i>!</i>', false ),
			'duplicated tag'    => array( '<b>a</b><b>b</b> <a href="/members/" class="m">x</a><br>', false ),
			'bad nesting'       => array( '<b>মূল্য <a href="/members/" class="m">x</b></a><br>', false ),
			'added attribute'   => array( '<b onclick="x()">মূল্য</b> <a href="/members/" class="m">x</a><br>', false ),
		);
	}
}

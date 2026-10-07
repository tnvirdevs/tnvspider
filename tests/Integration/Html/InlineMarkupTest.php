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

	/**
	 * Known issue 11: wp_kses() rewrites style="display:none;" as
	 * style="display:none", so the core comment-reply string failed against
	 * its own sanitised copy, whatever the provider returned.
	 */
	public function test_style_formatting_changed_by_kses_is_the_same_structure(): void {
		$original = 'Leave a comment <small><a rel="nofollow" id="cancel-comment-reply-link" href="/hello-world/#respond" style="display:none;">Cancel reply</a></small>';
		$restored = 'একটি মন্তব্য করুন <small><a rel="nofollow" id="cancel-comment-reply-link" href="/hello-world/#respond" style="display:none;">উত্তর বাতিল</a></small>';

		$sanitized = InlineMarkup::sanitize( $original, $restored );

		$this->assertStringContainsString( 'style="display:none"', $sanitized, 'kses drops the trailing semicolon.' );
		$this->assertTrue( InlineMarkup::sameStructure( $original, $sanitized ) );
		$this->assertTrue( InlineMarkup::survivesSanitize( $original ) );
		$this->assertTrue( InlineMarkup::sameStructure( '<b style=" color: red ;; ">x</b>', '<b style="color: red">y</b>' ) );
		$this->assertFalse( InlineMarkup::sameStructure( '<b style="color:red">x</b>', '<b style="color:blue">y</b>' ), 'Declarations still have to match.' );
	}

	public function test_markup_kses_would_change_does_not_survive_sanitize(): void {
		$this->assertFalse( InlineMarkup::survivesSanitize( 'Drag <span style="user-select:none">here</span>' ), 'user-select is not an allowed CSS property.' );
		$this->assertTrue( InlineMarkup::survivesSanitize( 'Drag <span style="cursor:move">here</span>' ) );
	}
}

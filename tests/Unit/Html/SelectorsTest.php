<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Unit\Html;

use PHPUnit\Framework\TestCase;
use WST\Html\Selectors;

final class SelectorsTest extends TestCase {

	/**
	 * @dataProvider validProvider
	 */
	public function test_supported_subset_is_valid( string $selector ): void {
		$this->assertTrue( Selectors::isValid( $selector ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function validProvider(): array {
		return array(
			'type'        => array( 'footer' ),
			'class'       => array( '.price' ),
			'id'          => array( '#cart-total' ),
			'attr'        => array( '[data-sku]' ),
			'attr value'  => array( '[data-role="price"]' ),
			'attr single' => array( "[data-role='price']" ),
			'attr bare'   => array( '[data-role=price]' ),
			'compound'    => array( 'span.price.amount[data-x]' ),
			'descendant'  => array( '.site-footer .legal p' ),
			'comma list'  => array( '.a, #b, footer .c' ),
		);
	}

	/**
	 * @dataProvider invalidProvider
	 */
	public function test_other_syntax_is_rejected( string $selector ): void {
		$this->assertFalse( Selectors::isValid( $selector ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function invalidProvider(): array {
		return array(
			'universal' => array( '*' ),
			'child'     => array( 'ul > li' ),
			'sibling'   => array( 'h2 + p' ),
			'general'   => array( 'h2 ~ p' ),
			'pseudo'    => array( 'a:hover' ),
			'operator'  => array( '[href^="http"]' ),
			'empty'     => array( ' , ' ),
			'dangling'  => array( 'p#' ),
		);
	}

	public function test_matching_with_ancestors(): void {
		$selectors = new Selectors( array( '.site-footer .legal', '#total, [data-role="price"]', 'aside' ) );
		$element   = static fn( string $tag, string $id = '', array $classes = array(), array $attrs = array() ): array => array(
			'tag'     => $tag,
			'id'      => $id,
			'classes' => $classes,
			'attrs'   => $attrs,
		);

		$this->assertTrue( $selectors->matches( $element( 'P', '', array( 'legal' ) ), array( $element( 'FOOTER', '', array( 'site-footer' ) ), $element( 'DIV' ) ) ), 'Descendant at any depth.' );
		$this->assertFalse( $selectors->matches( $element( 'P', '', array( 'legal' ) ), array( $element( 'DIV' ) ) ), 'Ancestor missing.' );
		$this->assertTrue( $selectors->matches( $element( 'SPAN', 'total' ), array() ) );
		$this->assertTrue( $selectors->matches( $element( 'SPAN', '', array(), array( 'data-role' => 'price' ) ), array() ) );
		$this->assertFalse( $selectors->matches( $element( 'SPAN', '', array(), array( 'data-role' => 'title' ) ), array() ) );
		$this->assertTrue( $selectors->matches( $element( 'ASIDE' ), array() ) );
		$this->assertSame( array( 'data-role' ), $selectors->attributeNames() );
	}

	public function test_unsupported_selector_fails_loudly(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Selectors( array( 'ul > li' ) );
	}
}

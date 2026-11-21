<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use WST\Routing\PathRules;

final class PathRulesTest extends TestCase {

	/**
	 * @dataProvider ruleProvider
	 */
	public function test_matches( string $path, string $rule, bool $expected ): void {
		$this->assertSame( $expected, PathRules::matches( $path, $rule ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public function ruleProvider(): array {
		return array(
			'home token'             => array( '/', '{{home}}', true ),
			'home token other page'  => array( '/shop/', '{{home}}', false ),
			'exact'                  => array( '/about/', '/about', true ),
			'exact ignores query'    => array( '/about/?x=1', 'about/', true ),
			'exact is not prefix'    => array( '/about/team/', '/about', false ),
			'wildcard child'         => array( '/shop/hoodie/', '/shop/*', true ),
			'wildcard itself'        => array( '/shop', '/shop/*', true ),
			'wildcard sibling'       => array( '/shopping/', '/shop/*', false ),
			'case insensitive'       => array( '/Shop/A/', '/shop/*', true ),
			'root wildcard'          => array( '/anything/', '/*', true ),
			'partial wildcard'       => array( '/hello/', '/hel*', true ),
			'partial wildcard child' => array( '/sale-2026/item/', '/sale*', true ),
			'partial wildcard miss'  => array( '/shop/', '/sale*', false ),
		);
	}

	public function test_matches_any(): void {
		$this->assertTrue( PathRules::matchesAny( '/cart/', array( '/checkout/*', '/cart' ) ) );
		$this->assertFalse( PathRules::matchesAny( '/blog/', array() ) );
	}
}

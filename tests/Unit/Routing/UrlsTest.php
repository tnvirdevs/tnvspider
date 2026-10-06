<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use WST\Routing\Urls;

final class UrlsTest extends TestCase {

	private function root(): Urls {
		return new Urls( '', 'example.com', 'wp-json' );
	}

	private function subdir(): Urls {
		return new Urls( '/blog', 'example.com', 'wp-json' );
	}

	public function test_relative_path(): void {
		$this->assertSame( 'bn/shop/', $this->root()->relativePath( '/bn/shop/?x=1' ) );
		$this->assertSame( '', $this->root()->relativePath( '/' ) );
		$this->assertSame( 'bn/', $this->subdir()->relativePath( '/blog/bn/' ) );
		$this->assertSame( '', $this->subdir()->relativePath( '/blog' ) );
		$this->assertNull( $this->subdir()->relativePath( '/blogger/bn/' ) );
	}

	/**
	 * @dataProvider exemptProvider
	 */
	public function test_exempt_paths( string $path, bool $exempt ): void {
		$this->assertSame( $exempt, $this->root()->isExempt( $path ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function exemptProvider(): array {
		return array(
			'admin'     => array( 'wp-admin/edit.php', true ),
			'ajax'      => array( 'wp-admin/admin-ajax.php', true ),
			'login'     => array( 'wp-login.php', true ),
			'rest'      => array( 'wp-json/wp/v2/posts', true ),
			'cron'      => array( 'wp-cron.php', true ),
			'xmlrpc'    => array( 'xmlrpc.php', true ),
			'robots'    => array( 'robots.txt', true ),
			'sitemap'   => array( 'wp-sitemap-posts-post-1.xml', true ),
			'uploads'   => array( 'wp-content/uploads/a.jpg', true ),
			'home'      => array( '', false ),
			'page'      => array( 'shop/', false ),
			'slug-like' => array( 'wp-adminer/', false ),
		);
	}

	public function test_prefix_detection_needs_a_whole_segment(): void {
		$this->assertTrue( $this->root()->hasPrefix( 'bn', 'bn' ) );
		$this->assertTrue( $this->root()->hasPrefix( 'bn/shop/', 'bn' ) );
		$this->assertFalse( $this->root()->hasPrefix( 'bngla/', 'bn' ) );
	}

	public function test_strip_prefix_keeps_query(): void {
		$this->assertSame( '/shop/?orderby=price', $this->root()->stripPrefix( '/bn/shop/?orderby=price', 'bn' ) );
		$this->assertSame( '/', $this->root()->stripPrefix( '/bn', 'bn' ) );
		$this->assertSame( '/', $this->root()->stripPrefix( '/bn/', 'bn' ) );
		$this->assertSame( '/blog/shop/', $this->subdir()->stripPrefix( '/blog/bn/shop/', 'bn' ) );
		$this->assertSame( '/shop/', $this->root()->stripPrefix( '/shop/', 'bn' ) );
	}

	/**
	 * @dataProvider addPrefixProvider
	 */
	public function test_add_prefix( string $url, string $expected ): void {
		$this->assertSame( $expected, $this->root()->addPrefix( $url, 'bn' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function addPrefixProvider(): array {
		return array(
			'absolute'       => array( 'https://example.com/shop/', 'https://example.com/bn/shop/' ),
			'home no slash'  => array( 'https://example.com', 'https://example.com/bn/' ),
			'query fragment' => array( 'https://example.com/a/?x=1#top', 'https://example.com/bn/a/?x=1#top' ),
			'port'           => array( 'http://example.com:8080/a/', 'http://example.com:8080/bn/a/' ),
			'protocol-less'  => array( '//example.com/a/', '//example.com/bn/a/' ),
			'root relative'  => array( '/a/', '/bn/a/' ),
			'already'        => array( 'https://example.com/bn/a/', 'https://example.com/bn/a/' ),
			'external'       => array( 'https://other.com/a/', 'https://other.com/a/' ),
			'admin'          => array( 'https://example.com/wp-admin/', 'https://example.com/wp-admin/' ),
			'upload'         => array( '/wp-content/uploads/a.pdf', '/wp-content/uploads/a.pdf' ),
			'document rel'   => array( 'shop/', 'shop/' ),
			'fragment only'  => array( '#top', '#top' ),
			'mailto'         => array( 'mailto:a@example.com', 'mailto:a@example.com' ),
			'case host'      => array( 'https://EXAMPLE.com/a/', 'https://EXAMPLE.com/bn/a/' ),
		);
	}

	public function test_remove_prefix(): void {
		$this->assertSame( 'https://example.com/shop/?x=1', $this->root()->removePrefix( 'https://example.com/bn/shop/?x=1', 'bn' ) );
		$this->assertSame( '/', $this->root()->removePrefix( '/bn/', 'bn' ) );
		$this->assertSame( '/bngla/', $this->root()->removePrefix( '/bngla/', 'bn' ) );
		$this->assertSame( 'https://other.com/bn/x/', $this->root()->removePrefix( 'https://other.com/bn/x/', 'bn' ) );
		$this->assertSame( 'https://example.com/blog/a/', $this->subdir()->removePrefix( 'https://example.com/blog/bn/a/', 'bn' ) );
	}

	public function test_add_prefix_in_subdirectory_install(): void {
		$this->assertSame( 'https://example.com/blog/bn/a/', $this->subdir()->addPrefix( 'https://example.com/blog/a/', 'bn' ) );
		$this->assertSame( 'https://example.com/other/', $this->subdir()->addPrefix( 'https://example.com/other/', 'bn' ) );
	}
}

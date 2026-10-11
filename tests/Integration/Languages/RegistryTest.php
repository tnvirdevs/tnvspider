<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Languages;

use WP_UnitTestCase;
use WST\Languages\Registry;

final class RegistryTest extends WP_UnitTestCase {

	public function test_curated_language(): void {
		$bn = ( new Registry() )->get( 'bn_BD' );

		$this->assertSame( 'bn', $bn->slug() );
		$this->assertSame( 'বাংলা', $bn->nativeName() );
		$this->assertSame( 'Bengali', $bn->englishName() );
		$this->assertSame( 'ltr', $bn->dir() );
		$this->assertSame( 'bn-BD', $bn->tag() );
		$this->assertSame( 'bn', $bn->tag( true ) );
	}

	/**
	 * @dataProvider rtlProvider
	 */
	public function test_rtl_detection( string $locale, bool $rtl ): void {
		$this->assertSame( $rtl, ( new Registry() )->get( $locale )->isRtl() );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function rtlProvider(): array {
		return array(
			'arabic'      => array( 'ar', true ),
			'hebrew'      => array( 'he_IL', true ),
			'persian'     => array( 'fa_IR', true ),
			'urdu'        => array( 'ur', true ),
			'unknown rtl' => array( 'ckb', true ),
			'bengali'     => array( 'bn_BD', false ),
			'english'     => array( 'en_US', false ),
			'unknown ltr' => array( 'kab', false ),
		);
	}

	/**
	 * @dataProvider codeProvider
	 */
	public function test_provider_codes( string $locale, string $provider, string $code ): void {
		$this->assertSame( $code, ( new Registry() )->get( $locale )->providerCode( $provider ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function codeProvider(): array {
		return array(
			'default iso'          => array( 'bn_BD', 'microsoft', 'bn' ),
			'translatex hebrew'    => array( 'he_IL', 'translatex', 'iw' ),
			'microsoft hebrew'     => array( 'he_IL', 'microsoft', 'he' ),
			'translatex chinese'   => array( 'zh_CN', 'translatex', 'zh-CN' ),
			'microsoft chinese'    => array( 'zh_TW', 'microsoft', 'zh-Hant' ),
			'translatex norwegian' => array( 'nb_NO', 'translatex', 'no' ),
			'microsoft filipino'   => array( 'tl', 'microsoft', 'fil' ),
		);
	}

	public function test_unknown_locale_gets_derived_defaults(): void {
		$language = ( new Registry() )->get( 'kab' );

		$this->assertSame( 'kab', $language->slug() );
		$this->assertSame( 'kab', $language->englishName() );
		$this->assertFalse( ( new Registry() )->isKnown( 'kab' ) );
	}

	public function test_region_variants_get_distinct_slugs(): void {
		$registry = new Registry();
		$slugs    = array();
		foreach ( $registry->locales() as $locale ) {
			$slugs[] = $registry->get( $locale )->slug();
		}

		$this->assertSame( $slugs, array_unique( $slugs ), 'Two curated languages share a URL slug.' );
	}

	public function test_malformed_locale_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new Registry() )->get( '../etc' );
	}
}

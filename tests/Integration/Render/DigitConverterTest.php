<?php
/**
 * Plan §13A.6 / §16 Phase 6d: digit conversion never touches URLs,
 * attributes, prices (when skipped) or protected text, and round-trips.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Render;

use WP_UnitTestCase;
use WST\Database\Schema;
use WST\Languages\Registry;
use WST\Log\Logger;
use WST\Modes\Resolver;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\Selector;
use WST\Queue\AutoQueue;
use WST\Queue\Queue;
use WST\Queue\Scheduler;
use WST\Render\DigitConverter;
use WST\Render\DiscoveryGate;
use WST\Render\Pipeline;
use WST\Routing\Urls;
use WST\Settings;
use WST\Storage\StringStore;

final class DigitConverterTest extends WP_UnitTestCase {

	private const PAGE = '<!DOCTYPE html><html><head><title>Order 42</title><style>.a{width:10px}</style><script>var n = 5;</script></head><body>'
		. '<p>Call 2026 times</p>'
		. '<a href="/page/2/" title="Page 3" data-id="7">Page 2</a>'
		. '<p>Visit https://example.com/a1 or www.b2.com, mail a1@b2.com &#8217;quote&#8217; &amp; &#038; 9</p>'
		. '<input value="12" placeholder="Code 34"><textarea>56</textarea>'
		. '<code>78</code><pre>90</pre><span data-wst-no-translate>11</span><div class="skip-me">13</div>'
		. '<span class="woocommerce-Price-amount amount">৳15.00</span><p class="price">16</p>'
		. '<p>The iPhone 15 costs 17; already ২০</p>'
		. '</body></html>';

	private static function toAscii( string $text ): string {
		$out = $text;
		foreach ( DigitConverter::SETS as $set ) {
			$out = strtr( $out, array_flip( mb_str_split( $set ) ) );
		}

		return $out;
	}

	private function converter( string $mode = 'bengali', bool $skipPrices = true ): DigitConverter {
		$selectors = array( '.skip-me' );

		return new DigitConverter( $mode, $skipPrices ? array_merge( $selectors, DigitConverter::PRICE_SELECTORS ) : $selectors, array( 'iPhone 15' ), true );
	}

	public function test_only_visible_text_outside_protected_areas_changes(): void {
		$out = $this->converter()->html( self::PAGE );

		$this->assertStringContainsString( '<p>Call ২০২৬ times</p>', $out );
		$this->assertStringContainsString( '<a href="/page/2/" title="Page 3" data-id="7">Page ২</a>', $out, 'Attributes keep their digits.' );
		$this->assertStringContainsString( '<p>Visit https://example.com/a1 or www.b2.com, mail a1@b2.com &#8217;quote&#8217; &amp; &#038; ৯</p>', $out, 'URLs, e-mails and character references keep their digits.' );
		$this->assertStringContainsString( '<input value="12" placeholder="Code 34"><textarea>56</textarea>', $out, 'Form values and textarea stay.' );
		$this->assertStringContainsString( '<code>78</code><pre>90</pre><span data-wst-no-translate>11</span><div class="skip-me">13</div>', $out, 'Excluded areas stay.' );
		$this->assertStringContainsString( '<span class="woocommerce-Price-amount amount">৳15.00</span><p class="price">16</p>', $out, 'Prices are skipped by default.' );
		$this->assertStringContainsString( '<p>The iPhone 15 costs ১৭; already ২০</p>', $out, 'Never-translate terms keep their digits; converted digits stay.' );
		$this->assertStringContainsString( '<title>Order 42</title><style>.a{width:10px}</style><script>var n = 5;</script>', $out, 'Title, style and script stay.' );
		$this->assertStringContainsString( '<p class="price">১৬</p>', $this->converter( 'bengali', false )->html( self::PAGE ), 'Prices convert when not skipped.' );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function modes(): array {
		return array(
			'bengali'      => array( 'bengali', '০১২৩৪৫৬৭৮৯' ),
			'arabic_indic' => array( 'arabic_indic', '٠١٢٣٤٥٦٧٨٩' ),
			'persian'      => array( 'persian', '۰۱۲۳۴۵۶۷۸۹' ),
		);
	}

	/**
	 * @dataProvider modes
	 */
	public function test_each_set_round_trips( string $mode, string $digits ): void {
		$converter = $this->converter( $mode );

		$this->assertSame( '<p>' . $digits . '</p>', $converter->html( '<p>0123456789</p>' ) );
		$ascii = str_replace( 'already ২০', 'already done', self::PAGE );
		$this->assertSame( $ascii, self::toAscii( $converter->html( $ascii ) ), 'Whole page: digits restored to ASCII give the input back.' );
		$plain = '<p>Visit 12 shops, 3 days</p><p>No digits</p>';
		$this->assertSame( $plain, self::toAscii( $converter->html( $plain ) ), 'Digits restored to ASCII give the input back.' );
		$this->assertSame( 'Shop 7 · www.a7.com', self::toAscii( $converter->text( 'Shop 7 · www.a7.com' ) ) );
		$this->assertSame( '<p>No digits here</p>', $converter->html( '<p>No digits here</p>' ) );
	}

	public function test_unknown_mode_fails_loudly(): void {
		$this->expectException( \InvalidArgumentException::class );
		new DigitConverter( 'roman', array(), array(), true );
	}

	public function test_auto_mode_is_arabic_indic_for_arabic_only(): void {
		$registry = new Registry();
		$mode     = static fn( array $values ): string => ( new Settings( $values, 'en_US', $registry ) )->digitsMode();

		$this->assertSame( 'arabic_indic', $mode( array( 'target_language' => 'ar' ) ), 'Owner decision: Arabic gets Arabic-Indic digits by default.' );
		$this->assertSame( 'off', $mode( array( 'target_language' => 'bn_BD' ) ), 'Bengali is only suggested, never enabled silently.' );
		$this->assertSame(
			'off',
			$mode(
				array(
					'target_language' => 'ar',
					'digits_mode'     => 'off',
				)
			)
		);
		$this->assertSame(
			'bengali',
			$mode(
				array(
					'target_language' => 'bn_BD',
					'digits_mode'     => 'bengali',
				)
			)
		);
		$this->assertSame(
			'off',
			$mode(
				array(
					'target_language' => 'bn_BD',
					'digits_mode'     => 'klingon',
				)
			),
			'Invalid values fall back to auto.'
		);
	}

	public function test_pipeline_converts_target_output_fragments_and_texts(): void {
		global $wpdb;
		$schema   = new Schema( $wpdb );
		$store    = new StringStore( $wpdb, $schema );
		$settings = new Settings(
			array(
				'target_language' => 'bn_BD',
				'digits_mode'     => 'bengali',
			),
			'en_US',
			new Registry()
		);
		$target   = $settings->targetLanguage() ?? throw new \LogicException( 'No target.' );
		$urls     = Urls::fromHome( 'http://example.org', 'wp-json' );
		$logger   = new Logger( $wpdb, $schema );
		$queue    = new Queue( $wpdb, $schema );
		$registry = new ProviderRegistry( array() );
		$auto     = new AutoQueue( $settings, new Selector( $settings, $registry, new ProviderState() ), $queue, new Scheduler( $queue, static fn() => throw new \LogicException( 'No worker.' ) ) );
		$pipeline = new Pipeline( $settings, $target, $store, new DiscoveryGate( $settings, $logger ), $logger, $urls, $auto, new Resolver( $settings, $urls, $target ), null );
		$store->saveManual( 'Only 3 left', 'text', 'bn_BD', 'মাত্র 3টি বাকি', 1 );

		$this->assertSame( '<p>মাত্র ৩টি বাকি</p><a href="/bn/p/4">৫</a>', $pipeline->translateFragment( '<p>Only 3 left</p><a href="/p/4">5</a>' ), 'Translated and untranslated text both get target digits; the link only its prefix.' );
		$this->assertSame( array( ' Only 3 left' => ' মাত্র ৩টি বাকি' ), $pipeline->translateTexts( array( ' Only 3 left' ) ) );
	}
}

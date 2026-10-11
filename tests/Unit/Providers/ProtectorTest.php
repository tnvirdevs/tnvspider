<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use WST\Providers\Protection\Protector;

final class ProtectorTest extends TestCase {

	public function test_protects_urls_emails_placeholders_and_shortcodes(): void {
		$protected = ( new Protector() )->protect( 'Mail info@shop.example or see https://shop.example/a?b=1 — %1$s left, {{name}}, [gallery ids="1"].' );

		$this->assertSame( 'Mail [[1]] or see [[2]] — [[3]] left, [[4]], [[5]].', $protected->text );
		$this->assertSame(
			array(
				1 => 'info@shop.example',
				2 => 'https://shop.example/a?b=1',
				3 => '%1$s',
				4 => '{{name}}',
				5 => '[gallery ids="1"]',
			),
			$protected->tokens
		);
	}

	public function test_restore_accepts_reordered_tokens_and_inner_whitespace(): void {
		$protected = ( new Protector() )->protect( 'Visit https://a.example and %s' );

		$this->assertSame( '%s এবং https://a.example দেখুন', $protected->restore( '[[2]] এবং [ [ 1 ] ] দেখুন' ) );
	}

	/**
	 * @dataProvider brokenProvider
	 */
	public function test_restore_rejects_lost_duplicated_or_unknown_tokens( string $translation ): void {
		$protected = ( new Protector() )->protect( 'Visit https://a.example and %s' );

		$this->assertNull( $protected->restore( $translation ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function brokenProvider(): array {
		return array(
			'lost'       => array( '[[1]] দেখুন' ),
			'duplicated' => array( '[[1]] [[1]] [[2]]' ),
			'unknown'    => array( '[[1]] [[2]] [[3]]' ),
			'stray'      => array( '[ [ 2]] এবং [ [ 1]]]] দেখুন' ),
		);
	}

	public function test_stray_token_punctuation_seen_from_translatex_is_rejected(): void {
		// Real output for "Price: [[1]] per item, shipping [[2]], total [[3]]." (fixture translatex/tokens-format-bn.json).
		$protected = ( new Protector() )->protect( 'Price: %s per item, shipping %d, total %.2f.' );

		$this->assertNull( $protected->restore( 'মূল্য: [ [ 1]] প্রতি আইটেম, শিপিং [ [ 2]], মোট [ [ 3]]]]' ) );
		$this->assertSame( 'মূল্য: %s প্রতি আইটেম, শিপিং %d, মোট %.2f।', $protected->restore( 'মূল্য: [ [ 1]] প্রতি আইটেম, শিপিং [ [ 2]], মোট [ [ 3]]।' ) );
	}

	public function test_punctuation_of_the_original_text_is_not_counted_as_stray(): void {
		$protected = ( new Protector( array(), true, true, '{%d}' ) )->protect( 'Sizes {S, M} for %s' );

		$this->assertSame( 'Sizes {S, M} for {1}', $protected->text );
		$this->assertSame( '%s এর জন্য সাইজ {S, M}', $protected->restore( '{1} এর জন্য সাইজ {S, M}' ) );
		$this->assertNull( $protected->restore( '{1} এর জন্য সাইজ S, M}' ) );
	}

	public function test_brace_format_with_real_translatex_output(): void {
		// Fixture translatex/tokens-format-*.json probe: "{1}" survived unchanged in bn and ar.
		$protected = ( new Protector( array(), true, true, '{%d}' ) )->protect( 'Visit %s and %d now' );

		$this->assertSame( 'Visit {1} and {2} now', $protected->text );
		$this->assertSame( 'এখন %s এবং %d দেখুন', $protected->restore( 'এখন {1} এবং {2} দেখুন' ) );
		$this->assertSame( 'قم بزيارة %s و %d الآن', $protected->restore( 'قم بزيارة {1} و {2} الآن' ) );
	}

	public function test_text_that_already_looks_like_a_token_is_protected(): void {
		$protected = ( new Protector() )->protect( 'Use code [[7]] today' );

		$this->assertSame( 'Use code [[1]] today', $protected->text );
		$this->assertSame( 'আজ [[7]] কোড ব্যবহার করুন', $protected->restore( 'আজ [[1]] কোড ব্যবহার করুন' ) );
	}

	public function test_never_translate_terms_whole_word_case_insensitive(): void {
		$protector = new Protector( array( 'Purfello', 'Shea' ) );
		$protected = $protector->protect( 'purfello soap with SHEA butter, not Purfellos or Sheamus.' );

		$this->assertSame( '[[1]] soap with [[2]] butter, not Purfellos or Sheamus.', $protected->text );
		$this->assertTrue( $protector->isTerm( ' PURFELLO ' ) );
		$this->assertFalse( $protector->isTerm( 'Purfello soap' ) );
	}

	public function test_terms_case_sensitive_and_substring_options(): void {
		$protector = new Protector( array( 'Purfello' ), false, false );

		$this->assertSame( 'purfello and [[1]]s', $protector->protect( 'purfello and Purfellos' )->text );
	}

	public function test_longer_terms_win_over_shorter_ones(): void {
		$protected = ( new Protector( array( 'Purfello', 'Purfello Shop' ) ) )->protect( 'Welcome to Purfello Shop' );

		$this->assertSame( array( 1 => 'Purfello Shop' ), $protected->tokens );
	}

	public function test_html_mode_leaves_tags_alone(): void {
		$protected = ( new Protector( array( 'b' ) ) )->protect( '<b id="1">Plan b</b> at https://x.example', true );

		$this->assertSame( '<b id="1">Plan [[1]]</b> at [[2]]', $protected->text );
	}

	public function test_custom_token_format(): void {
		$protected = ( new Protector( array(), true, true, '<x%d/>' ) )->protect( 'Pay %s now' );

		$this->assertSame( 'Pay <x1/> now', $protected->text );
		$this->assertSame( 'এখন %s দিন', $protected->restore( 'এখন < x1 /> দিন' ) );
	}
}

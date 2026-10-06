<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Providers;

use WP_UnitTestCase;
use WST\Providers\Capabilities;
use WST\Providers\Protection\Protector;
use WST\Providers\RequestPlan;

final class RequestPlanTest extends WP_UnitTestCase {

	/**
	 * Fake provider: maps each unit through $translate.
	 *
	 * @param RequestPlan              $plan      Plan.
	 * @param callable(string): string $translate Unit translation.
	 * @return array<int, string>
	 */
	private function translateAll( RequestPlan $plan, callable $translate ): array {
		$out = array();
		foreach ( $plan->requests() as $request ) {
			foreach ( $request['items'] as $unit => $text ) {
				$out[ $unit ] = $translate( $text );
			}
		}

		return $out;
	}

	public function test_plain_text_with_protected_parts(): void {
		$plan = new RequestPlan(
			array(
				7 => array(
					'text' => 'Email us at hi@shop.example',
					'kind' => 'text',
				),
			),
			new Capabilities( false, 100, 10000 ),
			new Protector(),
			100,
			10000
		);

		$this->assertSame(
			array(
				array(
					'html'  => false,
					'items' => array( 1 => 'Email us at [[1]]' ),
				),
			),
			$plan->requests()
		);
		$result = $plan->complete( array( 1 => 'আমাদের ইমেল করুন [[1]]' ) );

		$this->assertSame(
			array(
				7 => array(
					'translation' => 'আমাদের ইমেল করুন hi@shop.example',
					'flags'       => 0,
				),
			),
			$result['translated']
		);
	}

	public function test_inline_html_for_html_providers_uses_tag_placeholders(): void {
		$plan = new RequestPlan(
			array(
				3 => array(
					'text' => 'Read <a href="/story/" class="x">our story</a> today',
					'kind' => 'inline',
				),
			),
			new Capabilities( true, 100, 10000 ),
			new Protector(),
			100,
			10000
		);

		$this->assertSame(
			array(
				array(
					'html'  => true,
					'items' => array( 1 => 'Read <a id="1">our story</a> today' ),
				),
			),
			$plan->requests()
		);
		$result = $plan->complete( array( 1 => 'আজ <a id="1">আমাদের গল্প</a> পড়ুন' ) );

		$this->assertSame( 'আজ <a href="/story/" class="x">আমাদের গল্প</a> পড়ুন', $result['translated'][3]['translation'] );
		$this->assertSame( 0, $result['translated'][3]['flags'] );
	}

	public function test_inline_for_plain_text_providers_is_segmented_and_flagged(): void {
		$plan   = new RequestPlan(
			array(
				3 => array(
					'text' => 'Read <a href="/story/">our story</a> today',
					'kind' => 'inline',
				),
			),
			new Capabilities( false, 100, 10000 ),
			new Protector(),
			100,
			10000
		);
		$result = $plan->complete( $this->translateAll( $plan, static fn( string $t ): string => strtoupper( $t ) ) );

		$this->assertSame( 'READ <a href="/story/">OUR STORY</a> TODAY', $result['translated'][3]['translation'] );
		$this->assertSame( RequestPlan::FLAG_SEGMENTED, $result['translated'][3]['flags'] );
	}

	public function test_retry_after_tag_mismatch_segments_even_for_html_providers(): void {
		$plan = new RequestPlan(
			array(
				3 => array(
					'text' => 'Go <b>now</b>',
					'kind' => 'inline',
				),
			),
			new Capabilities( true, 100, 10000 ),
			new Protector(),
			100,
			10000,
			true
		);

		$this->assertSame(
			array(
				array(
					'html'  => false,
					'items' => array(
						1 => 'Go',
						2 => 'now',
					),
				),
			),
			$plan->requests()
		);
	}

	public function test_failures_are_reported_per_string(): void {
		$plan  = new RequestPlan(
			array(
				1 => array(
					'text' => 'Pay %s now',
					'kind' => 'text',
				),
				2 => array(
					'text' => 'Hello',
					'kind' => 'text',
				),
				3 => array(
					'text' => 'Go <b>now</b>',
					'kind' => 'inline',
				),
			),
			new Capabilities( true, 100, 10000 ),
			new Protector(),
			100,
			10000
		);
		$units = array();
		foreach ( $plan->requests() as $request ) {
			$units += $request['items'];
		}
		$this->assertCount( 3, $units );
		$html   = array_search( 'Go <b id="1">now</b>', $units, true );
		$result = $plan->complete(
			array(
				(int) array_search( 'Pay [[1]] now', $units, true ) => 'এখনই দিন',
				(int) array_search( 'Hello', $units, true ) => '   ',
				(int) $html => 'এখন <i id="1">যান</i>',
			)
		);

		$this->assertSame( array(), $result['translated'] );
		$this->assertSame( array( 1, 2, 3 ), array_keys( $result['failed'] ) );
	}

	public function test_never_translate_terms_are_resolved_without_a_request(): void {
		$plan = new RequestPlan(
			array(
				9 => array(
					'text' => 'Purfello',
					'kind' => 'text',
				),
			),
			new Capabilities( false, 100, 10000 ),
			new Protector( array( 'purfello' ) ),
			100,
			10000
		);

		$this->assertSame( array(), $plan->requests() );
		$this->assertSame(
			array(
				9 => array(
					'translation' => 'Purfello',
					'flags'       => 0,
				),
			),
			$plan->complete( array() )['translated']
		);
	}

	public function test_requests_respect_item_and_character_caps(): void {
		$strings = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$strings[ $i ] = array(
				'text' => str_repeat( 'a', 40 ),
				'kind' => 'text',
			);
		}
		$plan = new RequestPlan( $strings, new Capabilities( false, 100, 10000 ), new Protector(), 2, 100 );

		$this->assertSame( array( 2, 2, 1 ), array_map( static fn( array $r ): int => count( $r['items'] ), $plan->requests() ) );
		$this->assertSame( 200, $plan->chars() );

		$small = new RequestPlan( $strings, new Capabilities( false, 100, 10000 ), new Protector(), 10, 50 );
		$this->assertCount( 5, $small->requests(), 'Character cap of 50 fits one 40-character item per request.' );
	}
}

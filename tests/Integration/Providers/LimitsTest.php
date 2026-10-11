<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Providers;

use WP_UnitTestCase;
use WST\Languages\Registry;
use WST\Providers\Limits;
use WST\Providers\ProviderRegistry;
use WST\Providers\Secrets;
use WST\Settings;

/**
 * Effective limits = user settings within each real adapter's defaults and
 * hard caps (plan §8, D10, D12).
 */
final class LimitsTest extends WP_UnitTestCase {

	/**
	 * @param string               $id     Provider id.
	 * @param array<string, mixed> $values Provider settings.
	 */
	private function limits( string $id, array $values = array() ): Limits {
		$settings = new Settings( array( 'providers' => array( $id => $values ) ), 'en_US', new Registry() );

		return Limits::resolve( $settings, $id, ProviderRegistry::adapter( $id, $settings, new Secrets() )->capabilities() );
	}

	public function test_microsoft_defaults_follow_the_documented_f0_throttle_and_caps(): void {
		$limits = $this->limits( 'microsoft' );

		$this->assertSame( 0, $limits->rpm, 'Microsoft throttles by characters, not requests.' );
		$this->assertSame( 33000, $limits->cpm );
		$this->assertSame( 1000, $limits->maxItems );
		$this->assertSame( 50000, $limits->maxChars );
		$this->assertSame( 'UTC', $limits->dayTimezone );
	}

	public function test_user_values_stay_within_hard_caps_and_zero_means_the_cap(): void {
		$limits = $this->limits(
			'microsoft',
			array(
				'max_items_per_request' => 5000,
				'max_chars_per_request' => 0,
				'chars_per_minute'      => 0,
				'requests_per_minute'   => 30,
			)
		);

		$this->assertSame( 1000, $limits->maxItems, 'Never above the 1,000-element cap (D12).' );
		$this->assertSame( 50000, $limits->maxChars, '0 = provider cap.' );
		$this->assertSame( 0, $limits->cpm, '0 = unlimited characters per minute (D10).' );
		$this->assertSame( 30, $limits->rpm );

		$lower = $this->limits( 'microsoft', array( 'max_items_per_request' => 50 ) );
		$this->assertSame( 50, $lower->maxItems );
	}

	public function test_gemini_defaults_are_conservative_and_use_the_pacific_day(): void {
		$limits = $this->limits( 'gemini' );

		$this->assertSame( 5, $limits->rpm );
		$this->assertSame( 100, $limits->rpd );
		$this->assertSame( 0, $limits->cpm, 'Input-token limits are unpublished; requests are capped by RPM and 5,000 characters.' );
		$this->assertSame( 50, $limits->maxItems );
		$this->assertSame( 5000, $limits->maxChars );
		$this->assertSame( 'America/Los_Angeles', $limits->dayTimezone );

		$edited = $this->limits(
			'gemini',
			array(
				'requests_per_minute' => 15,
				'requests_per_day'    => 1000,
				'chars_per_minute'    => 20000,
				'monthly_char_cap'    => 2000000,
				'timeout'             => 60,
			)
		);
		$this->assertSame( array( 15, 1000, 20000, 2000000, 60 ), array( $edited->rpm, $edited->rpd, $edited->cpm, $edited->monthlyCap, $edited->timeout ) );
	}

	public function test_translatex_rpm_follows_the_plan(): void {
		$this->assertSame( 50, $this->limits( 'translatex' )->rpm );
		$this->assertSame( 75, $this->limits( 'translatex', array( 'plan' => 'business' ) )->rpm );
		$this->assertSame( 100, $this->limits( 'translatex', array( 'plan' => 'enterprise' ) )->rpm );
		$this->assertSame( 20, $this->limits( 'translatex', array( 'requests_per_minute' => 20 ) )->rpm );
	}
}

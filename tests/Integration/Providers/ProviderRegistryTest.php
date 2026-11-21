<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Providers;

use WP_UnitTestCase;
use WST\Languages\Registry;
use WST\Providers\Gemini;
use WST\Providers\Microsoft;
use WST\Providers\ProviderRegistry;
use WST\Providers\Secrets;
use WST\Providers\TranslateX;
use WST\Settings;

final class ProviderRegistryTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( Secrets::OPTION );
		parent::tear_down();
	}

	public function test_only_providers_with_a_key_are_configured_and_secrets_are_read_lazily(): void {
		foreach ( array( TranslateX::SECRET, Microsoft::SECRET, Gemini::SECRET ) as $name ) {
			if ( false !== getenv( $name ) || defined( $name ) ) {
				$this->markTestSkipped( $name . ' is set in the environment; it overrides the stored option.' );
			}
		}
		$secrets = new Secrets();
		$secrets->set( Gemini::SECRET, 'gm-test-key-0123456789' );
		$reads = 0;
		add_filter(
			'option_' . Secrets::OPTION,
			static function ( $value ) use ( &$reads ) {
				++$reads;

				return $value;
			}
		);

		$registry = ProviderRegistry::configured( new Settings( array(), 'en_US', new Registry() ), $secrets );
		$this->assertSame( 0, $reads, 'Building the registry reads no secret.' );

		$this->assertInstanceOf( Gemini::class, $registry->get( 'gemini' ) );
		$this->assertNull( $registry->get( 'translatex' ) );
		$this->assertNull( $registry->get( 'microsoft' ) );
		$this->assertGreaterThan( 0, $reads );
	}
}

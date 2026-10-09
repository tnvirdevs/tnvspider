<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Providers;

use WP_UnitTestCase;
use WST\Database\Schema;
use WST\Languages\Registry;
use WST\Providers\ProviderRegistry;
use WST\Providers\ProviderState;
use WST\Providers\Secrets;
use WST\Providers\StatusReport;
use WST\Providers\Tester;
use WST\Queue\Queue;
use WST\Queue\Usage;
use WST\Settings;
use WST\Tests\Support\HttpStub;

final class TesterAndStatusTest extends WP_UnitTestCase {

	use HttpStub;

	private Secrets $secrets;

	private ProviderState $state;

	public function set_up(): void {
		parent::set_up();
		foreach ( Secrets::NAMES as $name ) {
			if ( false !== getenv( $name ) || defined( $name ) ) {
				$this->markTestSkipped( $name . ' is set in the environment; it overrides the stored option.' );
			}
		}
		$this->secrets = new Secrets();
		$this->state   = new ProviderState();
		delete_option( ProviderState::OPTION );
		delete_option( 'wst_translatex_languages' );
		delete_option( 'wst_microsoft_languages' );
		$this->stubHttp();
	}

	public function tear_down(): void {
		delete_option( Secrets::OPTION );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $values Settings.
	 * @return array{0: Tester, 1: StatusReport}
	 */
	private function services( array $values = array() ): array {
		global $wpdb;
		$settings  = new Settings( $values + array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() );
		$providers = ProviderRegistry::configured( $settings, $this->secrets );
		$tester    = new Tester( $settings, $providers, $this->state, $this->secrets );
		$schema    = new Schema( $wpdb );

		return array( $tester, new StatusReport( $settings, $this->secrets, $providers, $this->state, $tester, new Usage( $wpdb, $schema ), new Queue( $wpdb, $schema ) ) );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function byId( StatusReport $status ): array {
		return array_column( $status->all(), null, 'id' );
	}

	public function test_without_keys_everything_reads_not_configured_and_not_tested(): void {
		[ $tester, $status ] = $this->services();

		$result = $tester->test( 'microsoft' );
		$this->assertFalse( $result->ok );
		$this->assertStringContainsString( 'WST_AZURE_KEY', $result->message );
		foreach ( self::byId( $status ) as $id => $row ) {
			$this->assertFalse( $row['configured'], $id );
			$this->assertNull( $row['verified_at'], $id );
		}
		$this->assertSame( array(), $this->requests );
	}

	public function test_passing_test_verifies_and_lifts_a_pause_until_credentials_change(): void {
		$this->secrets->set( 'WST_GEMINI_KEY', 'gm-test-key-0123456789' );
		$this->state->pause( 'gemini', 'Key rejected.' );
		$this->responses[]   = self::response(
			200,
			array(
				'candidates' => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => '[{"id":1,"text":"Ciao mondo!"}]' ) ) ),
						'finishReason' => 'STOP',
					),
				),
			)
		);
		[ $tester, $status ] = $this->services( array( 'fallback_provider' => 'gemini' ) );

		$this->assertTrue( $tester->test( 'gemini' )->ok );
		$row = self::byId( $status )['gemini'];
		$this->assertIsInt( $row['verified_at'] );
		$this->assertSame( '', $row['unavailable'], 'Pause lifted.' );
		$this->assertSame( 'fallback', $row['role'] );
		$this->assertTrue( $row['any_language'] );

		[ , $otherModel ] = $this->services( array( 'providers' => array( 'gemini' => array( 'model' => 'gemini-3.8-flash' ) ) ) );
		$this->assertNull( self::byId( $otherModel )['gemini']['verified_at'], 'Another model: not tested yet.' );

		$this->secrets->set( 'WST_GEMINI_KEY', 'gm-other-key-9876543210' );
		[ , $newKey ] = $this->services();
		$this->assertNull( self::byId( $newKey )['gemini']['verified_at'], 'Another key: not tested yet.' );
	}

	public function test_failed_test_is_not_recorded(): void {
		$this->secrets->set( 'WST_TRANSLATEX_KEY', 'tx-test-key-0123456789' );
		$this->responses[]   = self::fixture( 'translatex', 'invalid-key' );
		[ $tester, $status ] = $this->services();

		$this->assertFalse( $tester->test( 'translatex' )->ok );
		$this->assertNull( self::byId( $status )['translatex']['verified_at'] );
	}

	public function test_status_shows_language_list_state_limits_and_never_a_secret(): void {
		$this->secrets->set( 'WST_TRANSLATEX_KEY', 'tx-test-key-0123456789' );
		[ , $status ] = $this->services( array( 'provider' => 'translatex' ) );

		$row = self::byId( $status )['translatex'];
		$this->assertNull( $row['languages'], 'Not loaded yet.' );
		$this->assertSame( 'primary', $row['role'] );
		$this->assertSame( 50, $row['limits']['requests_per_minute'] );
		$this->assertSame( 50, $row['defaults']['requests_per_minute'] );
		$this->assertSame(
			array(
				'WST_AZURE_KEY'    => false,
				'WST_AZURE_REGION' => false,
			),
			self::byId( $status )['microsoft']['secrets']
		);

		update_option(
			'wst_translatex_languages',
			array(
				'fetched' => time(),
				'codes'   => array( 'en', 'bn' ),
			),
			false
		);
		$this->assertSame( 2, self::byId( $status )['translatex']['languages'] );
		$this->assertStringNotContainsString( 'tx-test-key', (string) wp_json_encode( $status->all() ) );
	}
}

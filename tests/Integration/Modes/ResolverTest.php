<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Integration\Modes;

use WP_REST_Request;
use WP_UnitTestCase;
use WST\Languages\Registry;
use WST\Modes\Resolver;
use WST\Routing\Urls;
use WST\Settings;

final class ResolverTest extends WP_UnitTestCase {

	/**
	 * @param array<string, mixed> $values Settings.
	 */
	private function resolver( array $values ): Resolver {
		$settings = new Settings( $values + array( 'target_language' => 'bn_BD' ), 'en_US', new Registry() );

		return new Resolver( $settings, Urls::fromHome( 'http://example.org', 'wp-json' ), $settings->targetLanguage() ?? throw new \LogicException( 'No target.' ) );
	}

	public function test_first_matching_path_rule_wins_and_home_token_matches_only_home(): void {
		$resolver = $this->resolver(
			array(
				'site_mode'  => 'auto',
				'path_rules' => array(
					array(
						'path' => '/shop/cart/',
						'mode' => 'off',
					),
					array(
						'path' => '/shop/*',
						'mode' => 'manual',
					),
					array(
						'path' => '{{home}}',
						'mode' => 'manual',
					),
				),
			)
		);

		$this->assertSame(
			array(
				'mode'   => 'off',
				'source' => 'path',
			),
			$resolver->resolve( null, '/shop/cart/' )
		);
		$this->assertSame(
			array(
				'mode'   => 'manual',
				'source' => 'path',
			),
			$resolver->resolve( null, '/shop/soap/' )
		);
		$this->assertSame(
			array(
				'mode'   => 'manual',
				'source' => 'path',
			),
			$resolver->resolve( null, '/' )
		);
		$this->assertSame(
			array(
				'mode'   => 'auto',
				'source' => 'site',
			),
			$resolver->resolve( null, '/about/' )
		);
	}

	public function test_page_setting_wins_and_invalid_values_mean_inherit(): void {
		$post     = self::factory()->post->create();
		$resolver = $this->resolver( array( 'site_mode' => 'manual' ) );

		update_post_meta( $post, Resolver::META_KEY, 'auto' );
		$this->assertSame(
			array(
				'mode'   => 'auto',
				'source' => 'page',
			),
			$resolver->resolve( $post, '/x/' )
		);

		update_post_meta( $post, Resolver::META_KEY, 'bogus' );
		$this->assertSame( Resolver::INHERIT, Resolver::pageMode( $post ) );
		$this->assertSame(
			array(
				'mode'   => 'manual',
				'source' => 'site',
			),
			$resolver->resolve( $post, '/x/' )
		);
	}

	public function test_settings_keep_only_valid_path_rules_and_off_behaviour(): void {
		$settings = new Settings(
			array(
				'path_rules'   => array(
					array(
						'path' => '/ok/*',
						'mode' => 'off',
					),
					array(
						'path' => 'no-leading-slash',
						'mode' => 'auto',
					),
					array(
						'path' => '/bad-mode/',
						'mode' => 'inherit',
					),
					'not an array',
				),
				'off_behavior' => 'nonsense',
			),
			'en_US',
			new Registry()
		);

		$this->assertSame(
			array(
				array(
					'path' => '/ok/*',
					'mode' => 'off',
				),
			),
			$settings->pathRules()
		);
		$this->assertSame( Settings::OFF_REDIRECT, $settings->offBehavior() );
	}

	public function test_meta_is_editable_over_rest_only_by_users_who_can_edit_the_post(): void {
		Resolver::registerMeta();
		$post = self::factory()->post->create( array( 'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post );
		$request->set_body_params( array( 'meta' => array( Resolver::META_KEY => 'manual' ) ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( 'manual', Resolver::pageMode( $post ) );

		$request->set_body_params( array( 'meta' => array( Resolver::META_KEY => 'sometimes' ) ) );
		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status(), 'Enum enforced.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$request->set_body_params( array( 'meta' => array( Resolver::META_KEY => 'off' ) ) );
		$this->assertContains( rest_get_server()->dispatch( $request )->get_status(), array( 401, 403 ) );
		$this->assertSame( 'manual', Resolver::pageMode( $post ) );
	}
}

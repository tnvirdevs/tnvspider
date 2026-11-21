<?php
/**
 * Provider instances by id.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

use WST\Settings;

/**
 * Holds the configured provider adapters.
 */
final class ProviderRegistry {

	/**
	 * Builds the adapters on first use, so requests that never need a
	 * provider never read the secrets.
	 *
	 * @var (callable(): array<string, ProviderInterface>)|null
	 */
	private $loader;

	/**
	 * Create the registry.
	 *
	 * @param array<string, ProviderInterface> $providers Provider id => adapter.
	 * @param callable|null                    $loader    Returns the adapters on first use.
	 * @phpstan-param (callable(): array<string, ProviderInterface>)|null $loader
	 */
	public function __construct( private array $providers, ?callable $loader = null ) {
		$this->loader = $loader;
	}

	/**
	 * The adapters whose key is set. A provider without a key is reported as
	 * not configured instead of failing at request time.
	 *
	 * @param Settings $settings Settings.
	 * @param Secrets  $secrets  Key store.
	 */
	public static function configured( Settings $settings, Secrets $secrets ): self {
		return new self(
			array(),
			static function () use ( $settings, $secrets ): array {
				$providers = array();
				foreach ( Settings::PROVIDER_IDS as $id ) {
					if ( $secrets->has( Secrets::BY_PROVIDER[ $id ][0] ) ) {
						$providers[ $id ] = self::adapter( $id, $settings, $secrets );
					}
				}

				return $providers;
			}
		);
	}

	/**
	 * Build an adapter whether or not its key is set (for capabilities and
	 * status displays; requests without a key fail with AuthError).
	 *
	 * @param string   $id       Provider id.
	 * @param Settings $settings Settings.
	 * @param Secrets  $secrets  Key store.
	 * @throws \InvalidArgumentException For an unknown id.
	 */
	public static function adapter( string $id, Settings $settings, Secrets $secrets ): ProviderInterface {
		$http = new Http( $secrets );
		switch ( $id ) {
			case TranslateX::ID:
				return new TranslateX( $settings, $secrets, $http );
			case Microsoft::ID:
				return new Microsoft( $settings, $secrets, $http );
			case Gemini::ID:
				return new Gemini( $settings, $secrets, $http );
		}
		throw new \InvalidArgumentException( 'Unknown provider.' );
	}

	/**
	 * Adapter by id, or null.
	 *
	 * @param string $id Provider id.
	 */
	public function get( string $id ): ?ProviderInterface {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * All adapters, loading them on first use.
	 *
	 * @return array<string, ProviderInterface>
	 */
	private function all(): array {
		if ( null !== $this->loader ) {
			$this->providers = ( $this->loader )() + $this->providers;
			$this->loader    = null;
		}

		return $this->providers;
	}
}

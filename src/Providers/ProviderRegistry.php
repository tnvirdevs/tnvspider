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
				$http      = new Http( $secrets );
				$providers = array();
				if ( $secrets->has( TranslateX::SECRET ) ) {
					$providers[ TranslateX::ID ] = new TranslateX( $settings, $secrets, $http );
				}
				if ( $secrets->has( Microsoft::SECRET ) ) {
					$providers[ Microsoft::ID ] = new Microsoft( $settings, $secrets, $http );
				}
				if ( $secrets->has( Gemini::SECRET ) ) {
					$providers[ Gemini::ID ] = new Gemini( $settings, $secrets, $http );
				}

				return $providers;
			}
		);
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

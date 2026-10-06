<?php
/**
 * Provider instances by id.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Providers;

/**
 * Holds the configured provider adapters.
 */
final class ProviderRegistry {

	/**
	 * Create the registry.
	 *
	 * @param array<string, ProviderInterface> $providers Provider id => adapter.
	 */
	public function __construct( private array $providers ) {
	}

	/**
	 * Adapter by id, or null.
	 *
	 * @param string $id Provider id.
	 */
	public function get( string $id ): ?ProviderInterface {
		return $this->providers[ $id ] ?? null;
	}
}

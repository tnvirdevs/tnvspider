<?php
/**
 * Scriptable provider for queue and worker tests.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Support;

use WST\Providers\BatchResult;
use WST\Providers\Capabilities;
use WST\Providers\ProviderInterface;
use WST\Providers\TestResult;

/**
 * Translates by a callback (default: "[xx] " prefix), records every call,
 * and throws queued exceptions first.
 */
final class FakeProvider implements ProviderInterface {

	/**
	 * Calls received: [items, source, target, html].
	 *
	 * @var list<array{0: array<int, string>, 1: string, 2: string, 3: bool}>
	 */
	public array $calls = array();

	/**
	 * Exceptions to throw on the next calls, in order.
	 *
	 * @var list<\Throwable>
	 */
	public array $throw = array();

	/**
	 * Target codes this provider supports; empty = all.
	 *
	 * @var list<string>
	 */
	public array $targets = array();

	/**
	 * Translation callback.
	 *
	 * @var callable(string, string, bool): string
	 */
	public $translator;

	/**
	 * Create a fake.
	 *
	 * @param string       $id   Provider id.
	 * @param Capabilities $caps Capabilities.
	 */
	public function __construct( private string $id = 'fake', private ?Capabilities $caps = null ) {
		$this->translator = static function ( string $text, string $target ): string {
			return '[' . $target . '] ' . $text;
		};
	}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return 'Fake ' . $this->id;
	}

	public function capabilities(): Capabilities {
		return $this->caps ?? new Capabilities( true, 50, 5000 );
	}

	public function supportsPair( string $source, string $target ): bool {
		return array() === $this->targets || in_array( $target, $this->targets, true );
	}

	public function translate( array $items, string $source, string $target, bool $html ): BatchResult {
		$this->calls[] = array( $items, $source, $target, $html );
		if ( array() !== $this->throw ) {
			throw array_shift( $this->throw ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double rethrowing a prepared exception.
		}
		$result = new BatchResult();
		foreach ( $items as $id => $text ) {
			$result->translations[ $id ] = ( $this->translator )( $text, $target, $html );
			$result->charsBilled        += mb_strlen( $text );
		}

		return $result;
	}

	public function testConnection(): TestResult {
		return new TestResult( true, 'ok' );
	}
}

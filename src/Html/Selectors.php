<?php
/**
 * User exclude selectors (plan §13): a documented CSS subset.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Html;

/**
 * Supported: type (`div`), `.class`, `#id`, `[attr]`, `[attr=value]` (value
 * bare or quoted), compounds of these (`div.note[data-x]`), descendant
 * combinations separated by spaces (`.footer .legal`) and comma lists. Not
 * supported (rejected as invalid, never half-applied): `*`, `>`, `+`, `~`,
 * pseudo-classes and other attribute operators.
 */
final class Selectors {

	/** Most selectors kept. */
	public const MAX = 50;

	/**
	 * Parsed selectors: each a list of compounds, outermost first.
	 *
	 * @var list<list<array{tag: string, id: string, classes: list<string>, attrs: array<string, string|null>}>>
	 */
	private array $selectors = array();

	/**
	 * Attribute names any selector reads (lowercase), besides id and class.
	 *
	 * @var array<string, true>
	 */
	private array $attributeNames = array();

	/**
	 * Build from selector strings; each string may hold a comma list.
	 *
	 * @param string[] $selectors Selectors.
	 * @throws \InvalidArgumentException For an unsupported selector.
	 */
	public function __construct( array $selectors ) {
		foreach ( $selectors as $selector ) {
			foreach ( explode( ',', $selector ) as $part ) {
				$part = trim( $part );
				if ( '' === $part ) {
					continue;
				}
				$parsed = self::parse( $part );
				if ( null === $parsed ) {
					throw new \InvalidArgumentException( 'Unsupported selector: ' . htmlspecialchars( $part, ENT_QUOTES ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Pure-PHP class (unit-tested without WordPress); escaped with htmlspecialchars.
				}
				$this->selectors[] = $parsed;
				foreach ( $parsed as $compound ) {
					foreach ( array_keys( $compound['attrs'] ) as $name ) {
						$this->attributeNames[ $name ] = true;
					}
				}
			}
		}
	}

	/**
	 * Whether a selector string (possibly a comma list) is in the supported subset.
	 *
	 * @param string $selector Selector.
	 */
	public static function isValid( string $selector ): bool {
		$parts = array_filter( array_map( 'trim', explode( ',', $selector ) ), static fn( string $p ): bool => '' !== $p );
		if ( array() === $parts ) {
			return false;
		}
		foreach ( $parts as $part ) {
			if ( null === self::parse( $part ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether there is anything to match.
	 */
	public function isEmpty(): bool {
		return array() === $this->selectors;
	}

	/**
	 * Attribute names to read from each start tag.
	 *
	 * @return list<string>
	 */
	public function attributeNames(): array {
		return array_keys( $this->attributeNames );
	}

	/**
	 * Whether the element matches any selector, given its ancestors.
	 *
	 * @param array{tag: string, id: string, classes: list<string>, attrs: array<string, string>}       $element   The element.
	 * @param list<array{tag: string, id: string, classes: list<string>, attrs: array<string, string>}> $ancestors Its ancestors, outermost first.
	 */
	public function matches( array $element, array $ancestors ): bool {
		foreach ( $this->selectors as $chain ) {
			$last = count( $chain ) - 1;
			if ( ! self::compoundMatches( $chain[ $last ], $element ) ) {
				continue;
			}
			// Descendant combinators only: match the rest right to left, greedily.
			$need = $last - 1;
			for ( $i = count( $ancestors ) - 1; $i >= 0 && $need >= 0; $i-- ) {
				if ( self::compoundMatches( $chain[ $need ], $ancestors[ $i ] ) ) {
					--$need;
				}
			}
			if ( $need < 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parse one selector (no commas) into compounds, or null when unsupported.
	 *
	 * @param string $selector Selector.
	 * @return list<array{tag: string, id: string, classes: list<string>, attrs: array<string, string|null>}>|null
	 */
	private static function parse( string $selector ): ?array {
		$compounds = array();
		$tokens    = preg_split( '/\s+/', trim( $selector ) );
		foreach ( false === $tokens ? array() : $tokens as $token ) {
			$compound = self::parseCompound( $token );
			if ( null === $compound ) {
				return null;
			}
			$compounds[] = $compound;
		}

		return array() === $compounds ? null : $compounds;
	}

	/**
	 * Parse a compound like `div.a.b#x[data-y="1"]`.
	 *
	 * @param string $token Compound.
	 * @return array{tag: string, id: string, classes: list<string>, attrs: array<string, string|null>}|null
	 */
	private static function parseCompound( string $token ): ?array {
		$name     = '-?[_a-zA-Z][_a-zA-Z0-9-]*';
		$pattern  = '/\G(?:(?<tag>[a-zA-Z][a-zA-Z0-9-]*)|\.(?<class>' . $name . ')|#(?<id>' . $name . ')|\[(?<attr>[a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:=(?:"(?<dq>[^"]*)"|\'(?<sq>[^\']*)\'|(?<bare>[^\]\s"\']+)))?\])/';
		$compound = array(
			'tag'     => '',
			'id'      => '',
			'classes' => array(),
			'attrs'   => array(),
		);
		$offset   = 0;
		$length   = strlen( $token );
		while ( $offset < $length ) {
			if ( 1 !== preg_match( $pattern, $token, $m, PREG_UNMATCHED_AS_NULL, $offset ) ) {
				return null;
			}
			if ( null !== $m['tag'] ) {
				if ( 0 !== $offset ) {
					return null;
				}
				$compound['tag'] = strtoupper( $m['tag'] );
			} elseif ( null !== $m['class'] ) {
				$compound['classes'][] = $m['class'];
			} elseif ( null !== $m['id'] ) {
				$compound['id'] = $m['id'];
			} else {
				$compound['attrs'][ strtolower( (string) $m['attr'] ) ] = $m['dq'] ?? $m['sq'] ?? $m['bare'];
			}
			$offset += strlen( (string) $m[0] );
		}

		return $compound;
	}

	/**
	 * Whether an element satisfies a compound.
	 *
	 * @param array{tag: string, id: string, classes: list<string>, attrs: array<string, string|null>} $compound Compound.
	 * @param array{tag: string, id: string, classes: list<string>, attrs: array<string, string>}      $element  Element.
	 */
	private static function compoundMatches( array $compound, array $element ): bool {
		if ( '' !== $compound['tag'] && $compound['tag'] !== $element['tag'] ) {
			return false;
		}
		if ( '' !== $compound['id'] && $compound['id'] !== $element['id'] ) {
			return false;
		}
		foreach ( $compound['classes'] as $class ) {
			if ( ! in_array( $class, $element['classes'], true ) ) {
				return false;
			}
		}
		foreach ( $compound['attrs'] as $name => $value ) {
			if ( ! array_key_exists( $name, $element['attrs'] ) || ( null !== $value && $value !== $element['attrs'][ $name ] ) ) {
				return false;
			}
		}

		return true;
	}
}

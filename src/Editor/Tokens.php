<?php
/**
 * Short-lived tokens for editor scans and previews (plan §11).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Editor;

use WST\Config;

/**
 * A token is 128 random bits, issued over REST to a user with the
 * wst_translate capability and bound to one page path. Only a hash of it is
 * stored (as a transient), so the database never holds a usable token.
 * Scan tokens work once; preview tokens until they expire (the frame may
 * reload).
 */
final class Tokens {

	public const SCAN    = 'scan';
	public const PREVIEW = 'preview';

	/** Lifetime in seconds. */
	private const TTL = array(
		self::SCAN    => 300,
		self::PREVIEW => 900,
	);

	/**
	 * Issue a token.
	 *
	 * @param string               $type self::SCAN or self::PREVIEW.
	 * @param string               $path Page path without language prefix.
	 * @param array<string, mixed> $data Extra options (allow_personal, scripts).
	 * @throws \InvalidArgumentException For an unknown type.
	 */
	public static function issue( string $type, string $path, array $data = array() ): string {
		if ( ! isset( self::TTL[ $type ] ) ) {
			throw new \InvalidArgumentException( 'Unknown token type.' );
		}
		$token = bin2hex( random_bytes( 16 ) );
		set_transient(
			self::key( $token ),
			array(
				'type'    => $type,
				'path'    => $path,
				'user'    => get_current_user_id(),
				'data'    => $data,
				'expires' => time() + self::TTL[ $type ],
			),
			self::TTL[ $type ]
		);

		return $token;
	}

	/**
	 * The token's record, or null when it is unknown, expired or of another type.
	 *
	 * @param string $token Token from the request.
	 * @param string $type  Expected type.
	 * @return array{type: string, path: string, user: int, data: array<string, mixed>, expires: int}|null
	 */
	public static function read( string $token, string $type ): ?array {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		$record = get_transient( self::key( $token ) );
		if ( ! is_array( $record ) || ( $record['type'] ?? '' ) !== $type || (int) ( $record['expires'] ?? 0 ) < time() ) {
			return null;
		}

		return array(
			'type'    => (string) $record['type'],
			'path'    => (string) ( $record['path'] ?? '' ),
			'user'    => (int) ( $record['user'] ?? 0 ),
			'data'    => is_array( $record['data'] ?? null ) ? $record['data'] : array(),
			'expires' => (int) $record['expires'],
		);
	}

	/**
	 * Read a token and invalidate it.
	 *
	 * @param string $token Token.
	 * @param string $type  Expected type.
	 * @return array{type: string, path: string, user: int, data: array<string, mixed>, expires: int}|null
	 */
	public static function consume( string $token, string $type ): ?array {
		$record = self::read( $token, $type );
		if ( null !== $record ) {
			delete_transient( self::key( $token ) );
		}

		return $record;
	}

	/**
	 * Transient name for a token: a hash, never the token itself.
	 *
	 * @param string $token Token.
	 */
	private static function key( string $token ): string {
		return Config::PREFIX . 'tok_' . substr( hash( 'sha256', $token ), 0, 40 );
	}
}

<?php
/**
 * Plugin log in the wst_log table.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Log;

use WST\Database\Schema;

/**
 * Ring buffer of the latest log entries (plan §4). Never store secrets in
 * messages or context.
 */
final class Logger {

	/** Entries kept. */
	public const KEEP = 1000;

	/**
	 * Create a logger.
	 *
	 * @param \wpdb  $db     Database connection.
	 * @param Schema $schema Table names.
	 */
	public function __construct(
		private \wpdb $db,
		private Schema $schema
	) {
	}

	/**
	 * Log an error.
	 *
	 * @param string               $source  Component, e.g. render.
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Extra data, stored as JSON.
	 */
	public function error( string $source, string $message, array $context = array() ): void {
		$this->write( 'error', $source, $message, $context );
	}

	/**
	 * Log a warning.
	 *
	 * @param string               $source  Component.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra data.
	 */
	public function warning( string $source, string $message, array $context = array() ): void {
		$this->write( 'warning', $source, $message, $context );
	}

	/**
	 * Insert an entry and drop entries beyond self::KEEP.
	 *
	 * @param string               $level   error, warning or info.
	 * @param string               $source  Component.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra data.
	 * @throws \RuntimeException When the entry cannot be written.
	 */
	private function write( string $level, string $source, string $message, array $context ): void {
		$table    = $this->schema->table( 'log' );
		$inserted = $this->db->insert(
			$table,
			array(
				'created_at' => current_time( 'mysql', true ),
				'level'      => $level,
				'source'     => substr( $source, 0, 24 ),
				'message'    => $message,
				'context'    => array() === $context ? null : wp_json_encode( $context ),
			)
		);
		if ( false === $inserted ) {
			throw new \RuntimeException( 'Could not write to the plugin log: ' . esc_html( $this->db->last_error ) );
		}

		$trim = $this->db->prepare( 'DELETE FROM %i WHERE id <= %d', $table, (int) $this->db->insert_id - self::KEEP );
		if ( null === $trim || false === $this->db->query( $trim ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			throw new \RuntimeException( 'Could not trim the plugin log: ' . esc_html( $this->db->last_error ) );
		}
	}
}

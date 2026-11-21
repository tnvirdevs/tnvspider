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

	/** Levels the "log level" setting offers: record warnings and errors, or errors only. */
	public const LEVELS = array( 'warning', 'error' );

	/**
	 * Create a logger.
	 *
	 * @param \wpdb  $db       Database connection.
	 * @param Schema $schema   Table names.
	 * @param string $minLevel "warning" (default) or "error": errors are always recorded.
	 */
	public function __construct(
		private \wpdb $db,
		private Schema $schema,
		private string $minLevel = 'warning'
	) {
	}

	/**
	 * Latest entries, newest first.
	 *
	 * @param int         $limit  Entries (1–200).
	 * @param string|null $level  Only this level.
	 * @param string|null $source Only this source.
	 * @return list<array{id: int, created_at: string, level: string, source: string, message: string, context: array<string, mixed>}>
	 * @throws \RuntimeException On a database error.
	 */
	public function recent( int $limit, ?string $level = null, ?string $source = null ): array {
		$where = array( '1=1' );
		$args  = array( $this->schema->table( 'log' ) );
		if ( null !== $level ) {
			$where[] = 'level = %s';
			$args[]  = $level;
		}
		if ( null !== $source ) {
			$where[] = 'source = %s';
			$args[]  = $source;
		}
		$args[] = max( 1, min( 200, $limit ) );
		$sql    = $this->db->prepare( 'SELECT id, created_at, level, source, message, context FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d', $args ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Conditions are fixed strings with placeholders.
		$rows   = null === $sql ? null : $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		if ( null === $rows || '' !== $this->db->last_error ) {
			throw new \RuntimeException( 'Could not read the plugin log: ' . esc_html( $this->db->last_error ) );
		}
		$entries = array();
		foreach ( $rows as $row ) {
			$context   = null === $row->context ? array() : json_decode( (string) $row->context, true );
			$entries[] = array(
				'id'         => (int) $row->id,
				'created_at' => (string) $row->created_at,
				'level'      => (string) $row->level,
				'source'     => (string) $row->source,
				'message'    => (string) $row->message,
				'context'    => is_array( $context ) ? $context : array(),
			);
		}

		return $entries;
	}

	/**
	 * Number of entries of a level and source in the last $seconds.
	 *
	 * @param string $level   Level.
	 * @param string $source  Source.
	 * @param int    $seconds Window.
	 * @throws \RuntimeException On a database error.
	 */
	public function countSince( string $level, string $source, int $seconds ): int {
		$sql   = $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE level = %s AND source = %s AND created_at > %s', $this->schema->table( 'log' ), $level, $source, gmdate( 'Y-m-d H:i:s', time() - $seconds ) );
		$count = null === $sql ? null : $this->db->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		if ( null === $count || '' !== $this->db->last_error ) {
			throw new \RuntimeException( 'Could not read the plugin log: ' . esc_html( $this->db->last_error ) );
		}

		return (int) $count;
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
		if ( 'error' !== $this->minLevel ) {
			$this->write( 'warning', $source, $message, $context );
		}
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

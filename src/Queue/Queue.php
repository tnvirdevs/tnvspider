<?php
/**
 * The translation queue in wst_queue (plan §8).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Queue;

use WST\Config;
use WST\Database\Schema;

/**
 * Rows move pending → processing → deleted (done) or back to pending with a
 * backoff, and to failed after max_attempts. Claiming runs under a named
 * lock, so concurrent workers never take the same row.
 */
final class Queue {

	public const PRIORITY_EDITOR   = 1;
	public const PRIORITY_PAGE_NOW = 2;
	public const PRIORITY_VISITOR  = 5;
	public const PRIORITY_BULK     = 8;

	/** Backoff after a failure: BACKOFF_BASE x 2^attempts seconds, at most BACKOFF_MAX. */
	public const BACKOFF_BASE = 30;
	public const BACKOFF_MAX  = 3600;

	/**
	 * Create the repository.
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
	 * Queue strings for translation. Rows already queued keep their state but
	 * take the higher priority; explicit requests (priority 2 or better) also
	 * give failed rows a fresh start.
	 *
	 * @param int[]  $stringIds String ids.
	 * @param string $lang      Target locale.
	 * @param string $provider  Provider id.
	 * @param int    $priority  1 (urgent) … 9 (background).
	 * @param int    $now       Unix time.
	 * @phpstan-param list<int> $stringIds
	 */
	public function enqueue( array $stringIds, string $lang, string $provider, int $priority, int $now ): void {
		if ( array() === $stringIds ) {
			return;
		}
		$at     = gmdate( 'Y-m-d H:i:s', $now );
		$values = array();
		$args   = array( $this->table() );
		foreach ( array_values( array_unique( $stringIds ) ) as $id ) {
			$values[] = "(%d, %s, %s, %d, 'pending', 0, %s, %s)";
			array_push( $args, $id, $lang, $provider, $priority, $at, $at );
		}
		$explicit = $priority <= self::PRIORITY_PAGE_NOW ? 1 : 0;
		$this->write(
			$this->prepare(
				'INSERT INTO %i (string_id, lang, provider, priority, state, attempts, next_attempt_at, created_at) VALUES ' . implode( ',', $values ) // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above.
				. ' ON DUPLICATE KEY UPDATE'
				. " attempts = IF({$explicit} = 1 AND state = 'failed', 0, attempts),"
				. " next_attempt_at = IF({$explicit} = 1 AND state = 'failed', VALUES(next_attempt_at), next_attempt_at),"
				. " state = IF({$explicit} = 1 AND state = 'failed', 'pending', state),"
				. ' priority = LEAST(priority, VALUES(priority))',
				$args
			)
		);
	}

	/**
	 * Provider and language pairs with rows due now, most urgent first.
	 *
	 * @param int $now Unix time.
	 * @return list<array{provider: string, lang: string}>
	 */
	public function duePairs( int $now ): array {
		$at   = gmdate( 'Y-m-d H:i:s', $now );
		$rows = $this->db->get_results(
			$this->prepare(
				"SELECT provider, lang, MIN(priority) AS p FROM %i WHERE (state = 'pending' AND next_attempt_at <= %s) OR (state = 'processing' AND locked_until < %s) GROUP BY provider, lang ORDER BY p",
				array( $this->table(), $at, $at )
			)
		) ?? array();

		return array_values(
			array_map(
				static fn( \stdClass $row ): array => array(
					'provider' => (string) $row->provider,
					'lang'     => (string) $row->lang,
				),
				$rows
			)
		);
	}

	/**
	 * Claim due rows for one provider and language, within the item and
	 * character limits, by priority then age. Rows whose processing lock
	 * expired (a crashed worker) are due again.
	 *
	 * @param string $provider    Provider id.
	 * @param string $lang        Target locale.
	 * @param int    $maxItems    Maximum rows.
	 * @param int    $maxChars    Maximum characters (the first row is always taken).
	 * @param int    $lockSeconds How long the claim lasts.
	 * @param int    $now         Unix time.
	 * @return list<array{id: int, string_id: int, attempts: int, last_error: string, text: string, kind: string, chars: int}>
	 * @throws \RuntimeException When the claim lock is busy for too long.
	 */
	public function claim( string $provider, string $lang, int $maxItems, int $maxChars, int $lockSeconds, int $now ): array {
		$lock = Config::PREFIX . 'queue_claim';
		if ( '1' !== (string) $this->db->get_var( $this->prepare( 'SELECT GET_LOCK(%s, %d)', array( $lock, 10 ) ) ) ) {
			throw new \RuntimeException( 'The queue claim lock is busy.' );
		}
		try {
			$at   = gmdate( 'Y-m-d H:i:s', $now );
			$rows = $this->db->get_results(
				$this->prepare(
					'SELECT q.id, q.string_id, q.attempts, q.last_error, s.original, s.kind, s.char_count FROM %i q JOIN %i s ON s.id = q.string_id'
					. " WHERE q.provider = %s AND q.lang = %s AND ((q.state = 'pending' AND q.next_attempt_at <= %s) OR (q.state = 'processing' AND q.locked_until < %s))"
					. ' ORDER BY q.priority, q.id LIMIT %d',
					array( $this->table(), $this->schema->table( 'strings' ), $provider, $lang, $at, $at, max( 1, $maxItems ) )
				)
			) ?? array();

			$claimed = array();
			$chars   = 0;
			foreach ( $rows as $row ) {
				$length = (int) $row->char_count;
				if ( array() !== $claimed && $chars + $length > $maxChars ) {
					break;
				}
				$chars    += $length;
				$claimed[] = array(
					'id'         => (int) $row->id,
					'string_id'  => (int) $row->string_id,
					'attempts'   => (int) $row->attempts,
					'last_error' => (string) $row->last_error,
					'text'       => (string) $row->original,
					'kind'       => (string) $row->kind,
					'chars'      => $length,
				);
			}
			if ( array() !== $claimed ) {
				$ids = array_column( $claimed, 'id' );
				$this->write(
					$this->prepare(
						"UPDATE %i SET state = 'processing', locked_until = %s WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder list built above.
						array_merge( array( $this->table(), gmdate( 'Y-m-d H:i:s', $now + $lockSeconds ) ), $ids )
					)
				);
			}

			return $claimed;
		} finally {
			$this->db->query( $this->prepare( 'SELECT RELEASE_LOCK(%s)', array( $lock ) ) );
		}
	}

	/**
	 * Return claimed rows untouched (no attempt counted), e.g. on a rate-limit wait.
	 *
	 * @param int[] $ids Queue row ids.
	 * @param int   $now Unix time; the rows are due again from then.
	 */
	public function release( array $ids, int $now ): void {
		$this->update( $ids, "state = 'pending', locked_until = NULL, next_attempt_at = %s", array( gmdate( 'Y-m-d H:i:s', $now ) ) );
	}

	/**
	 * Remove finished rows.
	 *
	 * @param int[] $ids Queue row ids.
	 */
	public function complete( array $ids ): void {
		$this->update( $ids, '', array(), true );
	}

	/**
	 * Count a failed attempt. Below max_attempts the row is retried after a
	 * backoff; at max_attempts it fails, unless a fallback provider takes it
	 * over with a fresh set of attempts.
	 *
	 * @param array{id: int, attempts: int} $row         Claimed row.
	 * @param string                        $error       Message (no secrets).
	 * @param int                           $maxAttempts Attempts allowed on this provider.
	 * @param string                        $fallback    Fallback provider id, or '' when none may take over.
	 * @param int                           $now         Unix time.
	 */
	public function fail( array $row, string $error, int $maxAttempts, string $fallback, int $now ): void {
		$attempts = $row['attempts'] + 1;
		$error    = substr( $error, 0, 1000 );
		if ( $attempts < $maxAttempts ) {
			$delay = (int) min( self::BACKOFF_MAX, self::BACKOFF_BASE * ( 2 ** ( $attempts - 1 ) ) );
			$this->update( array( $row['id'] ), "state = 'pending', locked_until = NULL, attempts = %d, last_error = %s, next_attempt_at = %s", array( $attempts, $error, gmdate( 'Y-m-d H:i:s', $now + $delay ) ) );
		} elseif ( '' !== $fallback ) {
			$this->update( array( $row['id'] ), "state = 'pending', locked_until = NULL, attempts = 0, provider = %s, last_error = %s, next_attempt_at = %s", array( $fallback, $error, gmdate( 'Y-m-d H:i:s', $now ) ) );
		} else {
			$this->update( array( $row['id'] ), "state = 'failed', locked_until = NULL, attempts = %d, last_error = %s", array( $attempts, $error ) );
		}
	}

	/**
	 * Hand all waiting rows of one provider to another (fallback switch).
	 * Rows are re-pointed, never duplicated.
	 *
	 * @param string $from Provider id.
	 * @param string $to   Provider id.
	 * @param string $lang Target locale.
	 */
	public function repoint( string $from, string $to, string $lang ): void {
		$this->write(
			$this->prepare(
				"UPDATE %i SET provider = %s, attempts = 0 WHERE provider = %s AND lang = %s AND state = 'pending'",
				array( $this->table(), $to, $from, $lang )
			)
		);
	}

	/**
	 * Give every failed row a fresh start.
	 *
	 * @param int $now Unix time.
	 */
	public function retryFailed( int $now ): int {
		$this->write(
			$this->prepare(
				"UPDATE %i SET state = 'pending', attempts = 0, next_attempt_at = %s WHERE state = 'failed'",
				array( $this->table(), gmdate( 'Y-m-d H:i:s', $now ) )
			)
		);

		return (int) $this->db->rows_affected;
	}

	/**
	 * Delete rows, all or in one state.
	 *
	 * @param string|null $state pending, processing, failed or null for all.
	 */
	public function clear( ?string $state = null ): int {
		$sql = null === $state
			? $this->prepare( 'DELETE FROM %i', array( $this->table() ) )
			: $this->prepare( 'DELETE FROM %i WHERE state = %s', array( $this->table(), $state ) );
		$this->write( $sql );

		return (int) $this->db->rows_affected;
	}

	/**
	 * Remove rows for strings that now have a manual translation.
	 *
	 * @param int    $stringId String id.
	 * @param string $lang     Target locale.
	 */
	public function forget( int $stringId, string $lang ): void {
		$this->write( $this->prepare( 'DELETE FROM %i WHERE string_id = %d AND lang = %s', array( $this->table(), $stringId, $lang ) ) );
	}

	/**
	 * Counts for status displays and CLI.
	 *
	 * @return array{pending: int, processing: int, failed: int, chars_pending: int, by_provider: array<string, int>, last_errors: array<string, string>}
	 */
	public function stats(): array {
		$counts       = array(
			'pending'    => 0,
			'processing' => 0,
			'failed'     => 0,
		);
		$charsWaiting = 0;
		$byProvider   = array();
		$rows         = $this->db->get_results(
			$this->prepare(
				'SELECT q.state, q.provider, COUNT(*) AS n, SUM(s.char_count) AS chars FROM %i q JOIN %i s ON s.id = q.string_id GROUP BY q.state, q.provider',
				array( $this->table(), $this->schema->table( 'strings' ) )
			)
		) ?? array();
		foreach ( $rows as $row ) {
			$state = (string) $row->state;
			if ( isset( $counts[ $state ] ) ) {
				$counts[ $state ] += (int) $row->n;
			}
			if ( 'failed' !== $state ) {
				$charsWaiting                         += (int) $row->chars;
				$byProvider[ (string) $row->provider ] = ( $byProvider[ (string) $row->provider ] ?? 0 ) + (int) $row->n;
			}
		}
		$lastErrors = array();
		$errors     = $this->db->get_results(
			$this->prepare( "SELECT provider, last_error FROM %i WHERE last_error IS NOT NULL AND last_error <> '' ORDER BY id DESC LIMIT 50", array( $this->table() ) )
		) ?? array();
		foreach ( $errors as $row ) {
			$lastErrors[ (string) $row->provider ] = $lastErrors[ (string) $row->provider ] ?? (string) $row->last_error;
		}

		return array(
			'pending'       => $counts['pending'],
			'processing'    => $counts['processing'],
			'failed'        => $counts['failed'],
			'chars_pending' => $charsWaiting,
			'by_provider'   => $byProvider,
			'last_errors'   => $lastErrors,
		);
	}

	/**
	 * Whether any row is waiting (pending or processing).
	 */
	public function hasWork(): bool {
		return null !== $this->db->get_var( $this->prepare( "SELECT id FROM %i WHERE state IN ('pending', 'processing') LIMIT 1", array( $this->table() ) ) );
	}

	/**
	 * Run an UPDATE (or DELETE) on a set of rows.
	 *
	 * @param int[]             $ids    Row ids.
	 * @param literal-string    $set    SET clause with placeholders ('' for DELETE).
	 * @param array<int, mixed> $args   Values for $set.
	 * @param bool              $delete Whether to delete the rows instead.
	 */
	private function update( array $ids, string $set, array $args, bool $delete = false ): void {
		if ( array() === $ids ) {
			return;
		}
		$in = 'id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
		$this->write(
			$delete
				? $this->prepare( 'DELETE FROM %i WHERE ' . $in, array_merge( array( $this->table() ), $ids ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder list built above.
				: $this->prepare( 'UPDATE %i SET ' . $set . ' WHERE ' . $in, array_merge( array( $this->table() ), $args, $ids ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SET clauses are fixed strings.
		);
	}

	/**
	 * Prepare and fail loudly.
	 *
	 * @param literal-string    $sql  SQL with placeholders.
	 * @param array<int, mixed> $args Values.
	 * @throws \RuntimeException When wpdb rejects the query.
	 */
	private function prepare( string $sql, array $args ): string {
		$prepared = $this->db->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This is the prepare call.
		if ( null === $prepared ) {
			throw new \RuntimeException( 'Could not prepare a queue query.' );
		}

		return $prepared;
	}

	/**
	 * Run a write and fail loudly.
	 *
	 * @param string $sql Prepared SQL.
	 * @throws \RuntimeException On a database error.
	 */
	private function write( string $sql ): void {
		if ( false === $this->db->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Callers pass prepared SQL.
			throw new \RuntimeException( 'Queue write failed: ' . esc_html( $this->db->last_error ) );
		}
	}

	/**
	 * Queue table name.
	 */
	private function table(): string {
		return $this->schema->table( 'queue' );
	}
}

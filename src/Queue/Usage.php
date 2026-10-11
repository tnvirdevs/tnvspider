<?php
/**
 * Characters and requests per provider per month (plan §8, §13A.2).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Queue;

use WST\Database\Schema;

/**
 * Monthly usage counters in wst_usage. Periods follow the site time zone,
 * so budgets reset on the first day of the month there.
 */
final class Usage {

	/**
	 * Create the counter.
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
	 * Budget period (YYYY-MM) of a moment in the site time zone.
	 *
	 * @param int|null $timestamp Unix time; defaults to now.
	 */
	public static function period( ?int $timestamp = null ): string {
		return (string) wp_date( 'Y-m', $timestamp ?? time() );
	}

	/**
	 * Count characters and requests.
	 *
	 * @param string $provider Provider id.
	 * @param int    $chars    Characters.
	 * @param int    $requests Requests.
	 * @param string $period   Period.
	 * @throws \RuntimeException When the counter cannot be written.
	 */
	public function add( string $provider, int $chars, int $requests, string $period ): void {
		$sql = $this->db->prepare(
			'INSERT INTO %i (provider, period, chars, requests) VALUES (%s, %s, %d, %d) ON DUPLICATE KEY UPDATE chars = chars + VALUES(chars), requests = requests + VALUES(requests)',
			$this->schema->table( 'usage' ),
			$provider,
			$period,
			$chars,
			$requests
		);
		if ( null === $sql || false === $this->db->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			throw new \RuntimeException( 'Could not record usage: ' . esc_html( $this->db->last_error ) );
		}
	}

	/**
	 * Characters used in a period.
	 *
	 * @param string $provider Provider id.
	 * @param string $period   Period.
	 */
	public function chars( string $provider, string $period ): int {
		return (int) $this->db->get_var(
			$this->db->prepare( 'SELECT chars FROM %i WHERE provider = %s AND period = %s', $this->schema->table( 'usage' ), $provider, $period )
		);
	}
}

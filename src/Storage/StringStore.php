<?php
/**
 * Original strings, translations, occurrences and pages (plan §4).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Storage;

use WST\Database\Schema;
use WST\Html\InlineMarkup;
use WST\Html\Segment;
use WST\Html\Text;

/**
 * Reads and writes the string tables. Strings are keyed by the md5 of the
 * normalised original; the segment kind is not part of the hash.
 */
final class StringStore {

	public const STATUS_MACHINE = 1;
	public const STATUS_MANUAL  = 2;

	/** A string seen on this many pages is global; its occurrences stop being recorded. */
	public const GLOBAL_THRESHOLD = 20;

	private const CACHE_GROUP = 'wst_translations';

	/**
	 * Create the store.
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
	 * Hash of a normalised original.
	 *
	 * @param string $text Normalised original.
	 */
	public static function hash( string $text ): string {
		return md5( $text );
	}

	/**
	 * Known strings and their translations into $lang, in one query for
	 * everything not already in the object cache.
	 *
	 * @param string[] $texts Normalised originals.
	 * @phpstan-param list<string> $texts
	 * @param string   $lang  Target locale.
	 * @return array<string, array{id: int, translated: string|null, status: int|null}> Keyed by original;
	 *         unknown strings are absent, known but untranslated ones have a null translation.
	 */
	public function lookup( array $texts, string $lang ): array {
		$byHash = array();
		foreach ( $texts as $text ) {
			$byHash[ self::hash( $text ) ] = $text;
		}
		if ( array() === $byHash ) {
			return array();
		}

		$keys   = array();
		$result = array();
		foreach ( array_keys( $byHash ) as $hash ) {
			$keys[ $hash ] = $lang . ':' . $hash;
		}
		$cached = wp_cache_get_multiple( array_values( $keys ), self::CACHE_GROUP );
		$misses = array();
		foreach ( $keys as $hash => $key ) {
			$entry = isset( $cached[ $key ] ) && is_array( $cached[ $key ] ) ? self::cacheEntry( $cached[ $key ] ) : null;
			if ( null !== $entry ) {
				$result[ $byHash[ $hash ] ] = $entry;
			} else {
				$misses[] = $hash;
			}
		}
		if ( array() === $misses ) {
			return $result;
		}

		$rows = $this->results(
			$this->prepare(
				'SELECT s.id, s.hash, t.translated, t.status FROM %i s LEFT JOIN %i t ON t.string_id = s.id AND t.lang = %s WHERE s.hash IN (' . implode( ',', array_fill( 0, count( $misses ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder list built above.
				array_merge( array( $this->schema->table( 'strings' ), $this->schema->table( 'translations' ), $lang ), $misses )
			)
		);

		$fill = array();
		foreach ( $rows as $row ) {
			$entry                           = array(
				'id'         => (int) $row->id,
				'translated' => null === $row->translated ? null : (string) $row->translated,
				'status'     => null === $row->status ? null : (int) $row->status,
			);
			$result[ $byHash[ $row->hash ] ] = $entry;
			$fill[ $keys[ $row->hash ] ]     = $entry;
		}
		if ( array() !== $fill ) {
			wp_cache_set_multiple( $fill, self::CACHE_GROUP );
		}

		return $result;
	}

	/**
	 * Record strings first seen on a page: insert unknown strings, record
	 * occurrences for strings that are not global yet, mark strings global
	 * at GLOBAL_THRESHOLD pages, and update the page row.
	 *
	 * @param array<string, string> $strings Normalised original => kind.
	 * @param string                $path    Page path without language prefix.
	 * @param int|null              $postId  Queried post, if singular.
	 * @return array<string, int> Original => string id.
	 * @throws \RuntimeException When a write fails.
	 */
	public function recordOnPage( array $strings, string $path, ?int $postId ): array {
		if ( array() === $strings ) {
			return array();
		}
		$now     = current_time( 'mysql', true );
		$pageKey = self::pageKey( $path );
		$strTbl  = $this->schema->table( 'strings' );

		$values = array();
		$args   = array( $strTbl );
		foreach ( $strings as $text => $kind ) {
			$values[] = '(%s, %s, %s, %d, %s)';
			array_push( $args, self::hash( (string) $text ), $kind, (string) $text, mb_strlen( (string) $text ), $now );
		}
		$this->query(
			$this->prepare(
				'INSERT IGNORE INTO %i (hash, kind, original, char_count, created_at) VALUES ' . implode( ',', $values ), // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above.
				$args
			)
		);

		$hashes = array_map( array( self::class, 'hash' ), array_map( 'strval', array_keys( $strings ) ) );
		$rows   = $this->results(
			$this->prepare(
				'SELECT id, hash, is_global FROM %i WHERE hash IN (' . implode( ',', array_fill( 0, count( $hashes ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder list built above.
				array_merge( array( $strTbl ), $hashes )
			)
		);
		$byHash = array();
		foreach ( array_keys( $strings ) as $text ) {
			$byHash[ self::hash( (string) $text ) ] = (string) $text;
		}

		$ids       = array();
		$local     = array();
		$occValues = array();
		$occArgs   = array( $this->schema->table( 'occurrences' ) );
		// post_id is the same for every row, so the NULL is part of the template.
		$template = null === $postId ? '(%d, %s, NULL, %s)' : '(%d, %s, %d, %s)';
		foreach ( $rows as $row ) {
			$ids[ $byHash[ $row->hash ] ] = (int) $row->id;
			if ( '1' !== (string) $row->is_global ) {
				$local[]     = (int) $row->id;
				$occValues[] = $template;
				array_push( $occArgs, ...( null === $postId ? array( (int) $row->id, $pageKey, $now ) : array( (int) $row->id, $pageKey, $postId, $now ) ) );
			}
		}

		if ( array() !== $occValues ) {
			$this->query(
				$this->prepare(
					'INSERT IGNORE INTO %i (string_id, page_key, post_id, last_seen) VALUES ' . implode( ',', $occValues ), // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above.
					$occArgs
				)
			);
			$this->query(
				$this->prepare(
					'UPDATE %i s SET s.is_global = 1 WHERE s.id IN (' . implode( ',', array_fill( 0, count( $local ), '%d' ) ) . ') AND (SELECT COUNT(*) FROM %i o WHERE o.string_id = s.id) >= %d', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder list built above.
					array_merge( array( $strTbl ), $local, array( $this->schema->table( 'occurrences' ), self::GLOBAL_THRESHOLD ) )
				)
			);
		}

		$this->touchPage( $pageKey, $path, $postId, $now );

		return $ids;
	}

	/**
	 * Save a manual (protected) translation, creating the string if needed.
	 * Inline translations must keep the original's tags and attributes.
	 *
	 * @param string $original    Original text or inline HTML (normalised here).
	 * @param string $kind        Segment kind.
	 * @param string $lang        Target locale.
	 * @param string $translation Translation.
	 * @param int    $userId      Author, 0 for CLI.
	 * @return int String id.
	 * @throws \InvalidArgumentException When a value is empty or an inline translation changes the markup.
	 */
	public function saveManual( string $original, string $kind, string $lang, string $translation, int $userId ): int {
		$original    = Text::normalize( $original );
		$translation = trim( $translation );
		if ( '' === $original || '' === $translation ) {
			throw new \InvalidArgumentException( 'Original and translation must not be empty.' );
		}
		if ( Segment::INLINE === $kind ) {
			// Reject extra or changed markup instead of silently stripping it;
			// sanitising afterwards is a second line of defence.
			if ( ! InlineMarkup::sameStructure( $original, $translation ) ) {
				throw new \InvalidArgumentException( 'The translation must keep exactly the tags and attributes of the original.' );
			}
			$translation = InlineMarkup::sanitize( $original, $translation );
			if ( ! InlineMarkup::sameStructure( $original, $translation ) ) {
				throw new \InvalidArgumentException( 'The translation contains markup that is not allowed.' );
			}
		}

		$now = current_time( 'mysql', true );
		$this->query(
			$this->prepare(
				'INSERT IGNORE INTO %i (hash, kind, original, char_count, created_at) VALUES (%s, %s, %s, %d, %s)',
				$this->schema->table( 'strings' ),
				self::hash( $original ),
				$kind,
				$original,
				mb_strlen( $original ),
				$now
			)
		);
		$id = (int) $this->value(
			$this->prepare( 'SELECT id FROM %i WHERE hash = %s', $this->schema->table( 'strings' ), self::hash( $original ) )
		);
		$this->query(
			$this->prepare(
				'INSERT INTO %i (string_id, lang, translated, status, provider, flags, updated_by, updated_at) VALUES (%d, %s, %s, %d, NULL, 0, %d, %s)
				ON DUPLICATE KEY UPDATE translated = VALUES(translated), status = VALUES(status), provider = NULL, flags = 0, updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
				$this->schema->table( 'translations' ),
				$id,
				$lang,
				$translation,
				self::STATUS_MANUAL,
				$userId,
				$now
			)
		);
		wp_cache_delete( $lang . ':' . self::hash( $original ), self::CACHE_GROUP );
		// A manual translation is final: nothing may machine-translate it later.
		$this->query( $this->prepare( 'DELETE FROM %i WHERE string_id = %d AND lang = %s', $this->schema->table( 'queue' ), $id, $lang ) );

		return $id;
	}

	/**
	 * Store a machine translation. A manual translation (status 2) is never
	 * overwritten (plan §8).
	 *
	 * @param int    $stringId    String id.
	 * @param string $original    Normalised original, for the cache key.
	 * @param string $lang        Target locale.
	 * @param string $translation Validated translation.
	 * @param string $provider    Provider that produced it.
	 * @param int    $flags       Translation flags.
	 * @return bool Whether the translation was stored (false when a manual one exists).
	 */
	public function saveMachine( int $stringId, string $original, string $lang, string $translation, string $provider, int $flags ): bool {
		$this->query(
			$this->prepare(
				'INSERT INTO %i (string_id, lang, translated, status, provider, flags, updated_by, updated_at) VALUES (%d, %s, %s, %d, %s, %d, NULL, %s)
				ON DUPLICATE KEY UPDATE translated = IF(status = %d, translated, VALUES(translated)), provider = IF(status = %d, provider, VALUES(provider)),
				flags = IF(status = %d, flags, VALUES(flags)), updated_at = IF(status = %d, updated_at, VALUES(updated_at)), status = IF(status = %d, status, VALUES(status))',
				$this->schema->table( 'translations' ),
				$stringId,
				$lang,
				$translation,
				self::STATUS_MACHINE,
				substr( $provider, 0, 24 ),
				$flags,
				current_time( 'mysql', true ),
				self::STATUS_MANUAL,
				self::STATUS_MANUAL,
				self::STATUS_MANUAL,
				self::STATUS_MANUAL,
				self::STATUS_MANUAL
			)
		);
		wp_cache_delete( $lang . ':' . self::hash( $original ), self::CACHE_GROUP );

		return 1 === (int) $this->value(
			$this->prepare(
				'SELECT COUNT(*) FROM %i WHERE string_id = %d AND lang = %s AND status = %d AND provider = %s',
				$this->schema->table( 'translations' ),
				$stringId,
				$lang,
				self::STATUS_MACHINE,
				substr( $provider, 0, 24 )
			)
		);
	}

	/**
	 * One string with its translation into $lang.
	 *
	 * @param string $original Original (normalised here).
	 * @param string $lang     Target locale.
	 * @return array{id: int, kind: string, original: string, translated: string|null, status: int|null}|null
	 */
	public function find( string $original, string $lang ): ?array {
		$row = $this->first(
			$this->prepare(
				'SELECT s.id, s.kind, s.original, t.translated, t.status FROM %i s LEFT JOIN %i t ON t.string_id = s.id AND t.lang = %s WHERE s.hash = %s',
				$this->schema->table( 'strings' ),
				$this->schema->table( 'translations' ),
				$lang,
				self::hash( Text::normalize( $original ) )
			)
		);

		return null === $row ? null : self::row( $row );
	}

	/**
	 * Strings for listing, newest first.
	 *
	 * @param string      $lang   Target locale.
	 * @param string|null $status machine, manual, untranslated or null for all.
	 * @param string      $search Substring of the original, '' for all.
	 * @param int         $limit  Maximum rows.
	 * @return list<array{id: int, kind: string, original: string, translated: string|null, status: int|null}>
	 */
	public function search( string $lang, ?string $status, string $search, int $limit ): array {
		$where = array( '1=1' );
		$args  = array( $this->schema->table( 'strings' ), $this->schema->table( 'translations' ), $lang );
		if ( 'machine' === $status ) {
			$where[] = 't.status = ' . self::STATUS_MACHINE;
		} elseif ( 'manual' === $status ) {
			$where[] = 't.status = ' . self::STATUS_MANUAL;
		} elseif ( 'untranslated' === $status ) {
			$where[] = 't.id IS NULL';
		}
		if ( '' !== $search ) {
			$where[] = 's.original LIKE %s';
			$args[]  = '%' . $this->db->esc_like( $search ) . '%';
		}
		$args[] = max( 1, $limit );

		$rows = $this->results(
			$this->prepare(
				'SELECT s.id, s.kind, s.original, t.translated, t.status FROM %i s LEFT JOIN %i t ON t.string_id = s.id AND t.lang = %s WHERE ' . implode( ' AND ', $where ) . ' ORDER BY s.id DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Conditions are fixed strings.
				$args
			)
		);

		return array_values( array_map( array( self::class, 'row' ), $rows ) );
	}

	/**
	 * Paths of pages that contain any of these strings and have no string
	 * left waiting for automatic translation in $lang.
	 *
	 * @param int[]  $stringIds String ids.
	 * @phpstan-param list<int> $stringIds
	 * @param string $lang      Target locale.
	 * @return list<string>
	 */
	public function finishedPages( array $stringIds, string $lang ): array {
		if ( array() === $stringIds ) {
			return array();
		}
		$rows = $this->results(
			$this->prepare(
				'SELECT DISTINCT p.path FROM %i p JOIN %i o ON o.page_key = p.page_key WHERE o.string_id IN (' . implode( ',', array_fill( 0, count( $stringIds ), '%d' ) ) . ')' // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder list built above.
				. " AND NOT EXISTS (SELECT 1 FROM %i w JOIN %i q ON q.string_id = w.string_id AND q.lang = %s AND q.state IN ('pending', 'processing') WHERE w.page_key = p.page_key)",
				array_merge(
					array( $this->schema->table( 'pages' ), $this->schema->table( 'occurrences' ) ),
					$stringIds,
					array( $this->schema->table( 'occurrences' ), $this->schema->table( 'queue' ), $lang )
				)
			)
		);

		return array_values( array_map( static fn( \stdClass $row ): string => (string) $row->path, $rows ) );
	}

	/**
	 * Translation coverage of a post in one language: the strings recorded on
	 * it plus the global strings (header, menu, footer; plan §4), or 0/0 when
	 * the post was never seen.
	 *
	 * @param int    $postId Post id.
	 * @param string $lang   Target locale.
	 * @return array{total: int, translated: int, last_seen: string|null, last_scan: string|null}
	 */
	public function postCoverage( int $postId, string $lang ): array {
		$page = $this->first( $this->prepare( 'SELECT MAX(last_seen) AS seen, MAX(last_scan) AS scan FROM %i WHERE post_id = %d', $this->schema->table( 'pages' ), $postId ) );
		$seen = null === $page || null === $page->seen ? null : (string) $page->seen;
		if ( null === $seen ) {
			return array(
				'total'      => 0,
				'translated' => 0,
				'last_seen'  => null,
				'last_scan'  => null,
			);
		}
		$row = $this->first(
			$this->prepare(
				'SELECT COUNT(*) AS total, COUNT(t.string_id) AS translated FROM (SELECT string_id FROM %i WHERE post_id = %d UNION SELECT id FROM %i WHERE is_global = 1) u'
				. ' LEFT JOIN %i t ON t.string_id = u.string_id AND t.lang = %s',
				$this->schema->table( 'occurrences' ),
				$postId,
				$this->schema->table( 'strings' ),
				$this->schema->table( 'translations' ),
				$lang
			)
		);

		return array(
			'total'      => null === $row ? 0 : (int) $row->total,
			'translated' => null === $row ? 0 : (int) $row->translated,
			'last_seen'  => $seen,
			'last_scan'  => null === $page || null === $page->scan ? null : (string) $page->scan,
		);
	}

	/**
	 * Strings of a post (plus global strings, if the post was seen) that have
	 * no translation in $lang.
	 *
	 * @param int    $postId Post id.
	 * @param string $lang   Target locale.
	 * @return list<int>
	 */
	public function untranslatedOnPost( int $postId, string $lang ): array {
		if ( 0 === $this->postCoverage( $postId, $lang )['total'] ) {
			return array();
		}
		$rows = $this->results(
			$this->prepare(
				'SELECT u.string_id FROM (SELECT string_id FROM %i WHERE post_id = %d UNION SELECT id FROM %i WHERE is_global = 1) u'
				. ' LEFT JOIN %i t ON t.string_id = u.string_id AND t.lang = %s WHERE t.string_id IS NULL ORDER BY u.string_id',
				$this->schema->table( 'occurrences' ),
				$postId,
				$this->schema->table( 'strings' ),
				$this->schema->table( 'translations' ),
				$lang
			)
		);

		return array_values( array_map( static fn( \stdClass $row ): int => (int) $row->string_id, $rows ) );
	}

	/**
	 * Of these strings, the ids that have a manual translation in $lang.
	 *
	 * @param int[]  $stringIds String ids.
	 * @phpstan-param list<int> $stringIds
	 * @param string $lang      Target locale.
	 * @return list<int>
	 */
	public function manualIds( array $stringIds, string $lang ): array {
		if ( array() === $stringIds ) {
			return array();
		}
		$rows = $this->results(
			$this->prepare(
				'SELECT string_id FROM %i WHERE lang = %s AND status = %d AND string_id IN (' . implode( ',', array_fill( 0, count( $stringIds ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder list built above.
				array_merge( array( $this->schema->table( 'translations' ), $lang, self::STATUS_MANUAL ), $stringIds )
			)
		);

		return array_values( array_map( static fn( \stdClass $row ): int => (int) $row->string_id, $rows ) );
	}

	/**
	 * Ids of these strings that exist.
	 *
	 * @param int[] $stringIds String ids.
	 * @phpstan-param list<int> $stringIds
	 * @return list<int>
	 */
	public function existingIds( array $stringIds ): array {
		if ( array() === $stringIds ) {
			return array();
		}
		$rows = $this->results(
			$this->prepare(
				'SELECT id FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $stringIds ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder list built above.
				array_merge( array( $this->schema->table( 'strings' ) ), $stringIds )
			)
		);

		return array_values( array_map( static fn( \stdClass $row ): int => (int) $row->id, $rows ) );
	}

	/**
	 * Number of queue rows still waiting for automatic translation for these
	 * strings, ignoring rows older than $maxAgeSeconds.
	 *
	 * @param int[]  $stringIds     String ids.
	 * @phpstan-param list<int> $stringIds
	 * @param string $lang          Target locale.
	 * @param int    $maxAgeSeconds Age after which pending rows no longer count.
	 */
	public function pendingCount( array $stringIds, string $lang, int $maxAgeSeconds ): int {
		if ( array() === $stringIds ) {
			return 0;
		}

		return (int) $this->value(
			$this->prepare(
				"SELECT COUNT(*) FROM %i WHERE lang = %s AND state IN ('pending', 'processing') AND created_at > %s AND string_id IN (" . implode( ',', array_fill( 0, count( $stringIds ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder list built above.
				array_merge(
					array( $this->schema->table( 'queue' ), $lang, gmdate( 'Y-m-d H:i:s', time() - $maxAgeSeconds ) ),
					$stringIds
				)
			)
		);
	}

	/**
	 * Page key: md5 of the path without language prefix, leading slash,
	 * no trailing slash and no query string (plan §4).
	 *
	 * @param string $path Path.
	 */
	public static function pageKey( string $path ): string {
		return md5( self::normalizePath( $path ) );
	}

	/**
	 * Path normalised for page keys.
	 *
	 * @param string $path Path, possibly with query string.
	 */
	public static function normalizePath( string $path ): string {
		$path = (string) strtok( $path, '?#' );

		return '/' . trim( $path, '/' );
	}

	/**
	 * Insert or refresh the page row.
	 *
	 * @param string   $pageKey Page key.
	 * @param string   $path    Path.
	 * @param int|null $postId  Post id.
	 * @param string   $now     UTC datetime.
	 */
	private function touchPage( string $pageKey, string $path, ?int $postId, string $now ): void {
		$sql  = 'INSERT INTO %i (page_key, path, post_id, last_seen) VALUES (%s, %s, ' . ( null === $postId ? 'NULL' : '%d' ) . ', %s)'
			. ' ON DUPLICATE KEY UPDATE path = VALUES(path), post_id = VALUES(post_id), last_seen = VALUES(last_seen)';
		$args = array( $this->schema->table( 'pages' ), $pageKey, substr( self::normalizePath( $path ), 0, 255 ) );
		if ( null !== $postId ) {
			$args[] = $postId;
		}
		$args[] = $now;
		$this->query( $this->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Template is fixed strings.
	}

	/**
	 * A validated cache entry, or null when the cached value has another shape.
	 *
	 * @param array<mixed> $entry Cached value.
	 * @return array{id: int, translated: string|null, status: int|null}|null
	 */
	private static function cacheEntry( array $entry ): ?array {
		if ( ! isset( $entry['id'] ) || ! is_int( $entry['id'] ) ) {
			return null;
		}
		$translated = $entry['translated'] ?? null;
		$status     = $entry['status'] ?? null;

		return array(
			'id'         => $entry['id'],
			'translated' => is_string( $translated ) ? $translated : null,
			'status'     => is_int( $status ) ? $status : null,
		);
	}

	/**
	 * Prepare SQL and fail loudly when wpdb rejects it.
	 *
	 * @param literal-string $sql     Query with placeholders.
	 * @param mixed          ...$args Values, or one array of values.
	 * @throws \RuntimeException When wpdb cannot prepare the query.
	 */
	private function prepare( string $sql, ...$args ): string {
		$prepared = $this->db->prepare( $sql, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This is the prepare call.
		if ( null === $prepared ) {
			throw new \RuntimeException( 'Could not prepare a database query.' );
		}

		return $prepared;
	}

	/**
	 * Rows of a read query.
	 *
	 * @param string $sql Prepared SQL.
	 * @return list<\stdClass>
	 * @throws \RuntimeException On a database error.
	 */
	private function results( string $sql ): array {
		$rows = $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Callers pass prepared SQL.
		if ( null === $rows || '' !== $this->db->last_error ) {
			throw new \RuntimeException( 'Database read failed: ' . esc_html( $this->db->last_error ) );
		}

		return array_values( $rows );
	}

	/**
	 * First row of a read query, or null.
	 *
	 * @param string $sql Prepared SQL.
	 */
	private function first( string $sql ): ?\stdClass {
		return $this->results( $sql )[0] ?? null;
	}

	/**
	 * First column of the first row, as a string ('' when there is none).
	 *
	 * @param string $sql Prepared SQL.
	 */
	private function value( string $sql ): string {
		$row = $this->first( $sql );

		return null === $row ? '' : (string) current( get_object_vars( $row ) );
	}

	/**
	 * Run a write query and fail loudly.
	 *
	 * @param string $sql Prepared SQL.
	 * @throws \RuntimeException On a database error.
	 */
	private function query( string $sql ): void {
		if ( false === $this->db->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Callers pass prepared SQL.
			throw new \RuntimeException( 'Database write failed: ' . esc_html( $this->db->last_error ) );
		}
	}

	/**
	 * Typed row.
	 *
	 * @param \stdClass $row Database row.
	 * @return array{id: int, kind: string, original: string, translated: string|null, status: int|null}
	 */
	private static function row( \stdClass $row ): array {
		return array(
			'id'         => (int) $row->id,
			'kind'       => (string) $row->kind,
			'original'   => (string) $row->original,
			'translated' => null === $row->translated ? null : (string) $row->translated,
			'status'     => null === $row->status ? null : (int) $row->status,
		);
	}
}

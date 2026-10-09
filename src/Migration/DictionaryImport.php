<?php
/**
 * Import of a TranslatePress dictionary table (plan §13A.4).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Migration;

use WST\Html\Segment;
use WST\Transfer\Importer;

/**
 * Reads one chunk of a TranslatePress dictionary (read-only) and checks or
 * imports it with the CSV importer's rules (validation, conflict policy,
 * upsert, purge). Rows without a translation and deprecated translation
 * blocks are skipped; status 1 becomes a machine translation, status 2 a
 * manual (protected) one. Originals are normalised like the extractor:
 * plain text is entity-decoded, originals with tags are kind "inline" and
 * keep their markup. Re-running is safe: unchanged rows stay unchanged.
 */
final class DictionaryImport {

	/** Provider recorded for imported machine translations. */
	public const SOURCE = 'translatepress';

	/** TranslatePress rows read per request. */
	public const CHUNK = 1000;

	/**
	 * Create the import.
	 *
	 * @param TranslatePress $translatePress TranslatePress data.
	 * @param Importer       $importer       Shared row importer.
	 */
	public function __construct( private TranslatePress $translatePress, private Importer $importer ) {
	}

	/**
	 * Check or import the rows after $afterId.
	 *
	 * @param string $table   Dictionary table (from TranslatePress::tables()).
	 * @param int    $afterId Last TranslatePress row id handled.
	 * @param string $policy  Conflict policy (Importer::POLICIES).
	 * @param bool   $apply   False for a dry run.
	 * @param int    $userId  Importing user.
	 * @return array{counts: array<string, int>, skipped: array{untranslated: int, deprecated: int}, rows: list<array{line: int, outcome: string, reason: string}>, read: int, next: int, done: bool}
	 * @throws \InvalidArgumentException For a table that is not a TranslatePress dictionary.
	 */
	public function run( string $table, int $afterId, string $policy, bool $apply, int $userId ): array {
		$info    = $this->translatePress->table( $table ) ?? throw new \InvalidArgumentException( 'Not a TranslatePress dictionary table.' );
		$rows    = $this->translatePress->rows( $table, $afterId, self::CHUNK );
		$skipped = array(
			'untranslated' => 0,
			'deprecated'   => 0,
		);
		$import  = array();
		foreach ( $rows as $row ) {
			if ( TranslatePress::BLOCK_DEPRECATED === $row['block_type'] ) {
				++$skipped['deprecated'];
			} elseif ( ! in_array( $row['status'], array( TranslatePress::STATUS_MACHINE, TranslatePress::STATUS_HUMAN ), true ) || '' === trim( $row['translated'] ) ) {
				++$skipped['untranslated'];
			} else {
				$import[] = self::row( $row, $info['target'] );
			}
		}

		$counts = array_fill_keys( Importer::OUTCOMES, 0 );
		$listed = array();
		foreach ( array_chunk( $import, Importer::MAX_ROWS ) as $part ) {
			$result = $this->importer->run( $part, $policy, 'file', $apply, $userId, false, self::SOURCE );
			foreach ( $result['counts'] as $outcome => $count ) {
				$counts[ $outcome ] += $count;
			}
			$listed = array_merge( $listed, $result['rows'] );
		}
		$counts['skipped'] += $skipped['untranslated'] + $skipped['deprecated'];
		$last               = array() === $rows ? $afterId : end( $rows )['id'];

		return array(
			'counts'  => $counts,
			'skipped' => $skipped,
			'rows'    => $listed,
			'read'    => count( $rows ),
			'next'    => $last,
			'done'    => count( $rows ) < self::CHUNK,
		);
	}

	/**
	 * A TranslatePress row as an importer row.
	 *
	 * @param array{id: int, original: string, translated: string, status: int, block_type: int} $row    TranslatePress row.
	 * @param string                                                                             $target Target locale.
	 * @return array{line: int, original: string, translated: string, status: string, kind: string, lang: string}
	 */
	private static function row( array $row, string $target ): array {
		$inline = 1 === preg_match( '#<[a-z/!]#i', $row['original'] );

		return array(
			'line'       => $row['id'],
			'original'   => $inline ? $row['original'] : self::decode( $row['original'] ),
			'translated' => $inline ? $row['translated'] : self::decode( $row['translated'] ),
			'status'     => TranslatePress::STATUS_HUMAN === $row['status'] ? 'manual' : 'machine',
			'kind'       => $inline ? Segment::INLINE : Segment::TEXT,
			'lang'       => $target,
		);
	}

	/**
	 * Plain text as the extractor sees it (entities decoded).
	 *
	 * @param string $text Stored text.
	 */
	private static function decode( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}

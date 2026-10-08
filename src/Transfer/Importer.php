<?php
/**
 * CSV import of translations (plan §13A.3).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Transfer;

use WST\Html\Segment;
use WST\Html\Text;
use WST\Languages\Language;
use WST\Storage\StringStore;

/**
 * Checks (dry run) or applies one chunk of rows read from a CSV file. The
 * browser parses the file and sends chunks; nothing is kept between
 * requests. Each row gets an outcome: new, update, unchanged, conflict
 * (an existing translation is kept by the conflict policy), skipped (no
 * translation in the file) or invalid (with the reason). Applying writes
 * row by row (idempotent: a failed chunk can be sent again) and purges the
 * pages it finishes.
 */
final class Importer {

	/** Conflict policies: add only, overwrite machine but keep manual (default), overwrite all. */
	public const POLICIES = array( 'add_only', 'keep_manual', 'overwrite_all' );

	/** Status of imported rows: manual (default), machine, or the file's status column. */
	public const STATUSES = array( 'manual', 'machine', 'file' );

	public const OUTCOMES = array( 'new', 'update', 'unchanged', 'conflict', 'skipped', 'invalid' );

	/** Most rows per request. */
	public const MAX_ROWS = 500;

	/** Largest cell, in bytes. */
	public const MAX_CELL_BYTES = 65535;

	/** Largest file the screen accepts, in bytes. */
	public const MAX_FILE_BYTES = 20971520;

	/** Most data rows per file. */
	public const MAX_FILE_ROWS = 100000;

	private const KINDS = array( Segment::TEXT, Segment::INLINE, Segment::ATTR, Segment::TITLE, Segment::META );

	/**
	 * Create the importer.
	 *
	 * @param StringStore $store  Strings.
	 * @param Language    $target Target language.
	 */
	public function __construct( private StringStore $store, private Language $target ) {
	}

	/**
	 * Check or apply rows.
	 *
	 * @param array<mixed> $rows   Rows: line, original, translated, status, kind, lang (raw cells).
	 * @param string       $policy One of POLICIES.
	 * @param string       $statusOption One of STATUSES.
	 * @param bool         $apply  False for a dry run (nothing is written).
	 * @param int          $userId Importing user.
	 * @param bool         $guarded Whether cells carry the CSV-injection guard (CSV files; not TranslatePress rows).
	 * @param string       $source  Provider recorded for imported machine translations.
	 * @return array{counts: array<string, int>, rows: list<array{line: int, outcome: string, reason: string}>} Counts per outcome; rows listed only for conflict, skipped and invalid.
	 * @throws \InvalidArgumentException For an unknown policy or status, or too many rows.
	 */
	public function run( array $rows, string $policy, string $statusOption, bool $apply, int $userId, bool $guarded = true, string $source = StringStore::IMPORT_PROVIDER ): array {
		if ( ! in_array( $policy, self::POLICIES, true ) || ! in_array( $statusOption, self::STATUSES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown conflict policy or import status.' );
		}
		if ( count( $rows ) > self::MAX_ROWS ) {
			throw new \InvalidArgumentException( sprintf( 'At most %d rows per request.', self::MAX_ROWS ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Number.
		}

		$counts  = array_fill_keys( self::OUTCOMES, 0 );
		$listed  = array();
		$checked = array();
		$seen    = array();
		foreach ( array_values( $rows ) as $index => $raw ) {
			$line = is_array( $raw ) && isset( $raw['line'] ) && is_int( $raw['line'] ) ? $raw['line'] : $index + 1;
			try {
				$row = $this->parse( $raw, $statusOption, $guarded );
			} catch ( \InvalidArgumentException $e ) {
				++$counts['invalid'];
				$listed[] = self::listed( $line, 'invalid', $e->getMessage() );
				continue;
			}
			if ( null === $row ) {
				++$counts['skipped'];
				$listed[] = self::listed( $line, 'skipped', __( 'No translation in the file.', 'wp-site-translator' ) );
				continue;
			}
			if ( isset( $seen[ $row['original'] ] ) ) {
				++$counts['invalid'];
				/* translators: %d: line number */
				$listed[] = self::listed( $line, 'invalid', sprintf( __( 'Same original as line %d.', 'wp-site-translator' ), $seen[ $row['original'] ] ) );
				continue;
			}
			$seen[ $row['original'] ] = $line;
			$checked[]                = array( 'line' => $line ) + $row;
		}

		$lang     = $this->target->locale();
		$existing = $this->store->findMany( array_column( $checked, 'original' ), $lang );
		$writes   = array();
		foreach ( $checked as $row ) {
			$known = $existing[ $row['original'] ] ?? null;
			$kind  = null === $known ? $row['kind'] : $known['kind'];
			try {
				$translation = StringStore::checkTranslation( $row['original'], $kind, $row['translated'] );
			} catch ( \InvalidArgumentException $e ) {
				++$counts['invalid'];
				$listed[] = self::listed( $row['line'], 'invalid', $e->getMessage() );
				continue;
			}
			[ $outcome, $reason ] = self::outcome( $known, $translation, $row['status'], $policy );
			++$counts[ $outcome ];
			if ( 'conflict' === $outcome ) {
				$listed[] = self::listed( $row['line'], $outcome, $reason );
			} elseif ( 'new' === $outcome || 'update' === $outcome ) {
				$writes[] = array(
					'original'    => $row['original'],
					'kind'        => $kind,
					'translation' => $translation,
					'status'      => $row['status'],
				);
			}
		}

		if ( $apply && array() !== $writes ) {
			// Each write is idempotent, so after a failure the same chunk can be sent again.
			$ids = array();
			foreach ( $writes as $write ) {
				$ids[] = $this->store->importTranslation( $write['original'], $write['kind'], $lang, $write['translation'], $write['status'], $userId, $source );
			}
			/** Purges the cached pages these strings finish (see Cache\Purger). */
			do_action( 'wst_strings_translated', $ids, $lang );
		}

		usort( $listed, static fn( array $a, array $b ): int => $a['line'] <=> $b['line'] );

		return array(
			'counts' => $counts,
			'rows'   => $listed,
		);
	}

	/**
	 * Validate one row's cells.
	 *
	 * @param mixed  $raw Row.
	 * @param string $statusOption  Import status option.
	 * @param bool   $guarded       Whether cells carry the CSV-injection guard.
	 * @return array{original: string, translated: string, kind: string, status: int}|null Null when the row has no translation.
	 * @throws \InvalidArgumentException With the reason the row is invalid.
	 */
	private function parse( $raw, string $statusOption, bool $guarded ): ?array {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Reasons are REST data, shown as text by the import screen.
		if ( ! is_array( $raw ) ) {
			throw new \InvalidArgumentException( __( 'Malformed row.', 'wp-site-translator' ) );
		}
		$cells = array();
		foreach ( Csv::COLUMNS as $column ) {
			$value = $raw[ $column ] ?? '';
			if ( ! is_string( $value ) ) {
				throw new \InvalidArgumentException( __( 'Malformed row.', 'wp-site-translator' ) );
			}
			if ( strlen( $value ) > self::MAX_CELL_BYTES ) {
				/* translators: 1: column name, 2: size in KB */
				throw new \InvalidArgumentException( sprintf( __( 'The %1$s cell is longer than %2$d KB.', 'wp-site-translator' ), $column, (int) ( self::MAX_CELL_BYTES / 1024 ) ) );
			}
			if ( wp_check_invalid_utf8( $value ) !== $value ) {
				/* translators: %s: column name */
				throw new \InvalidArgumentException( sprintf( __( 'The %s cell is not valid UTF-8.', 'wp-site-translator' ), $column ) );
			}
			$cells[ $column ] = $guarded ? Csv::unguard( $value ) : $value;
		}

		$original = Text::normalize( $cells['original'] );
		if ( '' === $original ) {
			throw new \InvalidArgumentException( __( 'The original is empty.', 'wp-site-translator' ) );
		}
		$lang = trim( $cells['lang'] );
		if ( '' !== $lang && $lang !== $this->target->locale() ) {
			/* translators: 1: language code in the file, 2: target locale */
			throw new \InvalidArgumentException( sprintf( __( 'Language %1$s is not the target language (%2$s).', 'wp-site-translator' ), $lang, $this->target->locale() ) );
		}
		$kind = trim( $cells['kind'] );
		if ( '' !== $kind && ! in_array( $kind, self::KINDS, true ) ) {
			/* translators: %s: kind in the file */
			throw new \InvalidArgumentException( sprintf( __( 'Unknown kind "%s".', 'wp-site-translator' ), $kind ) );
		}
		$status = trim( $cells['status'] );
		if ( '' !== $status && 'machine' !== $status && 'manual' !== $status ) {
			/* translators: %s: status in the file */
			throw new \InvalidArgumentException( sprintf( __( 'Unknown status "%s" (use machine or manual).', 'wp-site-translator' ), $status ) );
		}
		if ( '' === trim( $cells['translated'] ) ) {
			return null;
		}
		// phpcs:enable
		$fromFile = 'machine' === $status ? StringStore::STATUS_MACHINE : StringStore::STATUS_MANUAL;

		return array(
			'original'   => $original,
			'translated' => $cells['translated'],
			'kind'       => '' === $kind ? Segment::TEXT : $kind,
			'status'     => match ( $statusOption ) {
				'machine' => StringStore::STATUS_MACHINE,
				'file'    => $fromFile,
				default   => StringStore::STATUS_MANUAL,
			},
		);
	}

	/**
	 * What importing a checked row does under the policy.
	 *
	 * @param array{id: int, kind: string, original: string, translated: string|null, status: int|null}|null $known Stored string, if any.
	 * @param string                                                                                         $translation Checked translation.
	 * @param int                                                                                            $status      Status it would get.
	 * @param string                                                                                         $policy      Conflict policy.
	 * @return array{0: string, 1: string} Outcome and, for conflicts, the reason.
	 */
	private static function outcome( ?array $known, string $translation, int $status, string $policy ): array {
		if ( null === $known || null === $known['translated'] ) {
			return array( 'new', '' );
		}
		if ( $known['translated'] === $translation && $known['status'] === $status ) {
			return array( 'unchanged', '' );
		}
		if ( 'add_only' === $policy ) {
			return array( 'conflict', __( 'Kept: the string is already translated (add only).', 'wp-site-translator' ) );
		}
		if ( 'keep_manual' === $policy && StringStore::STATUS_MANUAL === $known['status'] ) {
			return array( 'conflict', __( 'Kept: the existing manual translation differs.', 'wp-site-translator' ) );
		}

		return array( 'update', '' );
	}

	/**
	 * A row listed in the result.
	 *
	 * @param int    $line    Line in the file.
	 * @param string $outcome Outcome.
	 * @param string $reason  Reason.
	 * @return array{line: int, outcome: string, reason: string}
	 */
	private static function listed( int $line, string $outcome, string $reason ): array {
		return array(
			'line'    => $line,
			'outcome' => $outcome,
			'reason'  => $reason,
		);
	}
}

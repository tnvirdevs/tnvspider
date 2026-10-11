<?php
/**
 * CSV format of translation exports and imports (plan §13A.3).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Transfer;

/**
 * Column names and the CSV-injection guard. A cell that a spreadsheet would
 * run as a formula (it starts with = + - @, a tab or a carriage return) is
 * written with a leading apostrophe; cells that already start with
 * apostrophes before such a character get one more, so import (which strips
 * exactly one) gives back the original bytes.
 */
final class Csv {

	/** Columns every file has, in this order. */
	public const COLUMNS = array( 'original', 'translated', 'status', 'kind', 'lang' );

	/** Optional export column: paths of the pages a string was seen on. */
	public const PAGES_COLUMN = 'pages';

	/** Separator of the paths in the pages column. */
	public const PAGES_SEPARATOR = '|';

	/** UTF-8 byte order mark: spreadsheets need it to read UTF-8. */
	public const BOM = "\xEF\xBB\xBF";

	private const GUARDED = '/^\'*[=+\-@\t\r]/';

	private const STRIP = '/^\'+[=+\-@\t\r]/';

	/**
	 * A cell as it is written.
	 *
	 * @param string $cell Value.
	 */
	public static function guard( string $cell ): string {
		return 1 === preg_match( self::GUARDED, $cell ) ? "'" . $cell : $cell;
	}

	/**
	 * A cell as it was before guard().
	 *
	 * @param string $cell Value read from a file.
	 */
	public static function unguard( string $cell ): string {
		return 1 === preg_match( self::STRIP, $cell ) ? substr( $cell, 1 ) : $cell;
	}

	/**
	 * Write one row (RFC 4180 quoting, no escape character).
	 *
	 * @param resource $out   Stream.
	 * @param string[] $cells Values, guarded here.
	 * @phpstan-param list<string> $cells
	 * @throws \RuntimeException When the stream cannot be written.
	 */
	public static function writeRow( $out, array $cells ): void {
		if ( false === fputcsv( $out, array_map( array( self::class, 'guard' ), $cells ), ',', '"', '' ) ) {
			throw new \RuntimeException( 'Could not write the CSV file.' );
		}
	}
}

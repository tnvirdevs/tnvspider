/**
 * CSV reader for the import screen (plan §13A.3): RFC 4180 (quoted cells,
 * doubled quotes, line breaks inside quotes), UTF-8 BOM tolerated, CRLF or
 * LF line ends. Cells are returned exactly as written; the server removes
 * the CSV-injection guard.
 */

export class CsvError extends Error {
	constructor( message, line ) {
		super( message );
		this.line = line;
	}
}

/**
 * Split CSV text into records.
 *
 * @param {string} text File contents.
 * @return {{line: number, cells: string[]}[]} Records with the line they start on; blank lines skipped.
 */
export function parseCsv( text ) {
	const source = text.charCodeAt( 0 ) === 0xfeff ? text.slice( 1 ) : text;
	const records = [];
	let cells = [];
	let cell = '';
	let quoted = false;
	let afterQuote = false;
	let line = 1;
	let start = 1;
	const endCell = () => {
		cells.push( cell );
		cell = '';
		afterQuote = false;
	};
	const endRecord = () => {
		endCell();
		if ( ! ( cells.length === 1 && cells[ 0 ] === '' ) ) {
			records.push( { line: start, cells } );
		}
		cells = [];
	};
	for ( let i = 0; i < source.length; i++ ) {
		const char = source[ i ];
		if ( quoted ) {
			if ( char === '"' ) {
				if ( source[ i + 1 ] === '"' ) {
					cell += '"';
					i++;
				} else {
					quoted = false;
					afterQuote = true;
				}
			} else {
				if ( char === '\n' ) {
					line++;
				}
				cell += char;
			}
		} else if ( char === ',' ) {
			endCell();
		} else if ( char === '\n' || char === '\r' ) {
			if ( char === '\r' && source[ i + 1 ] === '\n' ) {
				i++;
			}
			endRecord();
			line++;
			start = line;
		} else if ( afterQuote ) {
			throw new CsvError( 'unexpected-after-quote', line );
		} else if ( char === '"' && cell === '' ) {
			quoted = true;
		} else {
			cell += char;
		}
	}
	if ( quoted ) {
		throw new CsvError( 'unterminated-quote', start );
	}
	if ( cell !== '' || cells.length > 0 || afterQuote ) {
		endRecord();
	}
	return records;
}

/**
 * Rows for the import endpoints, keyed by the header's column names.
 *
 * @param {string}   text     File contents.
 * @param {string[]} columns  Columns the server reads.
 * @param {string[]} required Columns the header must have.
 * @return {{rows: Object[], problems: {line: number, cells: number, expected: number}[], missing: string[]}} Rows, records with the wrong number of cells, and missing header columns.
 */
export function importRows( text, columns, required ) {
	const records = parseCsv( text );
	if ( records.length === 0 ) {
		return { rows: [], problems: [], missing: required };
	}
	const header = records[ 0 ].cells.map( ( name ) =>
		name.trim().toLowerCase()
	);
	const missing = required.filter( ( name ) => ! header.includes( name ) );
	const rows = [];
	const problems = [];
	if ( missing.length > 0 ) {
		return { rows, problems, missing };
	}
	for ( const record of records.slice( 1 ) ) {
		if ( record.cells.length !== header.length ) {
			problems.push( {
				line: record.line,
				cells: record.cells.length,
				expected: header.length,
			} );
			continue;
		}
		const row = { line: record.line };
		for ( const column of columns ) {
			const index = header.indexOf( column );
			row[ column ] = index === -1 ? '' : record.cells[ index ];
		}
		rows.push( row );
	}
	return { rows, problems, missing };
}

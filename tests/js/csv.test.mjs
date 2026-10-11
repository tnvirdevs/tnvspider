/**
 * The import screen's CSV reader against files written by the exporter
 * (PHP fputcsv, RFC 4180). Run: npm run test:js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { CsvError, importRows, parseCsv } from '../../assets-src/admin/csv.js';

test( 'reads quotes, commas, line breaks and a BOM', () => {
	const text =
		'\uFEFForiginal,translated\n' +
		'"Say ""hi"", then go","বলুন ""হাই"",\nতারপর"\r\n' +
		"'=1+2,plain\n" +
		'\n' +
		'last,"x"';
	assert.deepEqual( parseCsv( text ), [
		{ line: 1, cells: [ 'original', 'translated' ] },
		{ line: 2, cells: [ 'Say "hi", then go', 'বলুন "হাই",\nতারপর' ] },
		{ line: 4, cells: [ "'=1+2", 'plain' ] },
		{ line: 6, cells: [ 'last', 'x' ] },
	] );
} );

test( 'keeps empty cells and backslashes', () => {
	assert.deepEqual( parseCsv( 'a,,"",C:\\path\\\n' ), [
		{ line: 1, cells: [ 'a', '', '', 'C:\\path\\' ] },
	] );
} );

test( 'fails on broken quoting with the line', () => {
	assert.throws(
		() => parseCsv( 'a,b\n"open,x\n' ),
		( error ) =>
			error instanceof CsvError &&
			error.message === 'unterminated-quote' &&
			error.line === 2
	);
	assert.throws(
		() => parseCsv( 'a,"b"c\n' ),
		( error ) => error instanceof CsvError && error.line === 1
	);
} );

test( 'maps rows by header and reports wrong cell counts', () => {
	const columns = [ 'original', 'translated', 'status', 'kind', 'lang' ];
	const result = importRows(
		'Translated,ORIGINAL,pages\nহ্যালো,Hello,/\nonly one\n',
		columns,
		[ 'original', 'translated' ]
	);
	assert.deepEqual( result.missing, [] );
	assert.deepEqual( result.rows, [
		{
			line: 2,
			original: 'Hello',
			translated: 'হ্যালো',
			status: '',
			kind: '',
			lang: '',
		},
	] );
	assert.deepEqual( result.problems, [ { line: 3, cells: 1, expected: 3 } ] );
	assert.deepEqual(
		importRows( 'source,target\n', columns, [ 'original', 'translated' ] )
			.missing,
		[ 'original', 'translated' ]
	);
} );

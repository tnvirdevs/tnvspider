<?php
/**
 * @package WST
 */

declare(strict_types=1);

namespace WST\Tests\Unit\Transfer;

use PHPUnit\Framework\TestCase;
use WST\Transfer\Csv;

final class CsvTest extends TestCase {

	/**
	 * @return array<string, array{string, string}>
	 */
	public function cells(): array {
		return array(
			'formula'                => array( '=SUM(A1:A2)', "'=SUM(A1:A2)" ),
			'plus'                   => array( '+1 day', "'+1 day" ),
			'minus'                  => array( '-5% off', "'-5% off" ),
			'at'                     => array( '@home', "'@home" ),
			'tab'                    => array( "\tindented", "'\tindented" ),
			'carriage return'        => array( "\rx", "'\rx" ),
			'apostrophe then sign'   => array( "'=already", "''=already" ),
			'two apostrophes'        => array( "''-x", "'''-x" ),
			'plain apostrophe'       => array( "'Tis the season", "'Tis the season" ),
			'sign later in the text' => array( 'Price = 5', 'Price = 5' ),
			'plain'                  => array( 'আমাদের দোকান', 'আমাদের দোকান' ),
			'empty'                  => array( '', '' ),
		);
	}

	/**
	 * @dataProvider cells
	 */
	public function test_guard_and_unguard_round_trip( string $cell, string $written ): void {
		$this->assertSame( $written, Csv::guard( $cell ) );
		$this->assertSame( $cell, Csv::unguard( Csv::guard( $cell ) ) );
	}

	public function test_rows_use_rfc4180_quoting_without_an_escape_character(): void {
		$out = fopen( 'php://memory', 'w+b' );
		$this->assertIsResource( $out );

		Csv::writeRow( $out, array( 'He said "hi", then left', 'C:\\path\\', "two\nlines", '=1+1' ) );
		rewind( $out );

		$this->assertSame( "\"He said \"\"hi\"\", then left\",C:\\path\\,\"two\nlines\",'=1+1\n", stream_get_contents( $out ) );
		rewind( $out );
		$this->assertSame( array( 'He said "hi", then left', 'C:\\path\\', "two\nlines", "'=1+1" ), fgetcsv( $out, null, ',', '"', '' ) );
	}
}

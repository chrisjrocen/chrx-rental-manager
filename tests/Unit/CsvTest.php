<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Unit;

use ChrxRentalManager\Admin\Support\Csv;
use PHPUnit\Framework\TestCase;

/**
 * CSV formula-injection guard used by every export (Payments, Reports):
 * a tenant/property/unit name or reference note starting with a
 * spreadsheet formula trigger character must never reach fputcsv() raw.
 */
final class CsvTest extends TestCase {

	/**
	 * @dataProvider formula_trigger_provider
	 */
	public function test_a_value_starting_with_a_formula_trigger_character_is_quoted( string $dangerous ): void {
		$this->assertSame( "'" . $dangerous, Csv::safe_field( $dangerous ) );
	}

	public static function formula_trigger_provider(): array {
		return array(
			'equals'      => array( '=cmd|\' /C calc\'!A1' ),
			'plus'        => array( '+1+1' ),
			'minus'       => array( '-2+3' ),
			'at'          => array( '@SUM(A1:A9)' ),
			'tab'         => array( "\tmalicious" ),
			'carriage'    => array( "\rmalicious" ),
		);
	}

	public function test_an_ordinary_value_passes_through_unchanged(): void {
		$this->assertSame( 'Kwame Asante', Csv::safe_field( 'Kwame Asante' ) );
		$this->assertSame( 'Unit A1', Csv::safe_field( 'Unit A1' ) );
		$this->assertSame( '1000.5', Csv::safe_field( 1000.5 ) );
		$this->assertSame( '', Csv::safe_field( '' ) );
	}

	public function test_safe_row_applies_to_every_cell(): void {
		$row = Csv::safe_row( array( '=evil', 'fine', '+also evil', 42 ) );

		$this->assertSame( array( "'=evil", 'fine', "'+also evil", '42' ), $row );
	}
}

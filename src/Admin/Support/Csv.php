<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV formula-injection guard for every export in this plugin (Payments
 * list, Reports). Tenant/property/unit names, reference notes, etc. are
 * free-text fields entered via admin forms — if one happens to start
 * with =, +, -, @, tab, or CR, spreadsheet apps (Excel, Google Sheets)
 * will interpret the cell as a formula on open, which is a real attack
 * vector against whoever opens an exported CSV. Prefixing with a single
 * quote neutralizes the formula interpretation while leaving the visible
 * text unchanged in every spreadsheet app that matters here.
 */
final class Csv {

	/**
	 * @param array<int,mixed> $row
	 *
	 * @return array<int,string>
	 */
	public static function safe_row( array $row ): array {
		return array_map( array( self::class, 'safe_field' ), $row );
	}

	public static function safe_field( mixed $value ): string {
		$value = (string) $value;

		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}

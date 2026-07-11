<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single, account-wide currency formatting (SPEC.md §4.3/§7) applied
 * consistently across dashboard, receipts, and portal. Symbol/format are
 * admin-configurable via the Notifications/Reminders settings screen
 * (Settings::currency_symbol()/currency_format()); defaults match the
 * designs (GH₵, symbol first, comma thousands separator).
 */
final class Money {

	public static function format( float $amount ): string {
		$symbol   = Settings::currency_symbol();
		$decimals = 0.0 === round( fmod( $amount, 1.0 ), 2 ) ? 0 : 2;
		$number   = number_format( $amount, $decimals );

		return Settings::CURRENCY_FORMAT_SYMBOL_LAST === Settings::currency_format()
			? $number . ' ' . $symbol
			: $symbol . ' ' . $number;
	}
}

<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single, account-wide currency formatting (SPEC.md §4.3/§7) applied
 * consistently across dashboard, receipts, and portal. The symbol is
 * read from an option so the Notifications/Reminders settings screen
 * (Billing phase) can make it admin-configurable without this call site
 * changing; defaults match the designs (GH₵, comma thousands separator).
 */
final class Money {

	public static function format( float $amount ): string {
		$symbol   = get_option( 'chrx_rm_currency_symbol', 'GH₵' );
		$decimals = 0.0 === round( fmod( $amount, 1.0 ), 2 ) ? 0 : 2;

		return $symbol . ' ' . number_format( $amount, $decimals );
	}
}

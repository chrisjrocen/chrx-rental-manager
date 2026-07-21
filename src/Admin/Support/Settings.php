<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes the account-wide settings the Billing phase's cron jobs
 * consume (designs/24-notifications-reminders-settings.html) — one
 * source of truth for option names/defaults so the cron classes, the
 * settings screen, and Money don't each hardcode them separately.
 */
final class Settings {

	public const OPT_CHARGE_LEAD_DAYS       = 'chrx_rm_charge_lead_days';
	public const OPT_REMINDER_THRESHOLDS    = 'chrx_rm_reminder_thresholds';
	public const OPT_REMINDER_NOTIFY_TENANT = 'chrx_rm_reminder_notify_tenant';
	public const OPT_LATE_FEE_GRACE_DAYS    = 'chrx_rm_late_fee_grace_days';
	public const OPT_LATE_FEE_AMOUNT        = 'chrx_rm_late_fee_amount';
	public const OPT_LATE_FEE_TYPE          = 'chrx_rm_late_fee_type';
	public const OPT_CURRENCY_SYMBOL        = 'chrx_rm_currency_symbol';
	public const OPT_CURRENCY_FORMAT        = 'chrx_rm_currency_format';
	public const OPT_COMPANY_NAME           = 'chrx_rm_company_name';
	public const OPT_COMPANY_ADDRESS        = 'chrx_rm_company_address';
	public const OPT_COMPANY_PHONE          = 'chrx_rm_company_phone';
	public const OPT_MANAGEMENT_FEE_PERCENT = 'chrx_rm_management_fee_percent';
	public const OPT_HIDE_OTHER_MENUS       = 'chrx_rm_hide_other_menus';

	public const LATE_FEE_TYPE_FLAT    = 'flat';
	public const LATE_FEE_TYPE_PERCENT = 'percent';

	public const CURRENCY_FORMAT_SYMBOL_FIRST = 'symbol_first';
	public const CURRENCY_FORMAT_SYMBOL_LAST  = 'symbol_last';

	public static function charge_lead_days(): int {
		return (int) get_option( self::OPT_CHARGE_LEAD_DAYS, 5 );
	}

	/**
	 * @return array<int,int>
	 */
	public static function reminder_thresholds(): array {
		$value = get_option( self::OPT_REMINDER_THRESHOLDS, array( 30, 14, 7 ) );

		if ( ! is_array( $value ) || array() === $value ) {
			return array( 30, 14, 7 );
		}

		$thresholds = array_map( 'intval', $value );
		rsort( $thresholds );

		return $thresholds;
	}

	public static function reminder_notify_tenant(): bool {
		return (bool) get_option( self::OPT_REMINDER_NOTIFY_TENANT, false );
	}

	public static function late_fee_grace_days(): int {
		return (int) get_option( self::OPT_LATE_FEE_GRACE_DAYS, 5 );
	}

	public static function late_fee_amount(): float {
		return (float) get_option( self::OPT_LATE_FEE_AMOUNT, 50.0 );
	}

	public static function late_fee_type(): string {
		$type = get_option( self::OPT_LATE_FEE_TYPE, self::LATE_FEE_TYPE_FLAT );

		return in_array( $type, array( self::LATE_FEE_TYPE_FLAT, self::LATE_FEE_TYPE_PERCENT ), true )
			? $type
			: self::LATE_FEE_TYPE_FLAT;
	}

	/**
	 * Computes the late fee amount for a given rent amount, respecting
	 * the flat-vs-percent setting (SPEC.md §4.3).
	 */
	public static function calculate_late_fee( float $rent_amount ): float {
		if ( self::LATE_FEE_TYPE_PERCENT === self::late_fee_type() ) {
			return round( $rent_amount * ( self::late_fee_amount() / 100 ), 2 );
		}

		return self::late_fee_amount();
	}

	public static function currency_symbol(): string {
		$symbol = get_option( self::OPT_CURRENCY_SYMBOL, 'GH₵' );

		return '' !== $symbol ? $symbol : 'GH₵';
	}

	public static function currency_format(): string {
		$format = get_option( self::OPT_CURRENCY_FORMAT, self::CURRENCY_FORMAT_SYMBOL_FIRST );

		return in_array( $format, array( self::CURRENCY_FORMAT_SYMBOL_FIRST, self::CURRENCY_FORMAT_SYMBOL_LAST ), true )
			? $format
			: self::CURRENCY_FORMAT_SYMBOL_FIRST;
	}

	/**
	 * Letterhead details for receipts (designs/19) and, in a later phase,
	 * landlord statement PDFs, which reuse this same setting rather than
	 * collecting it twice.
	 */
	public static function company_name(): string {
		$name = (string) get_option( self::OPT_COMPANY_NAME, '' );

		return '' !== $name ? $name : get_bloginfo( 'name' );
	}

	public static function company_address(): string {
		return (string) get_option( self::OPT_COMPANY_ADDRESS, '' );
	}

	public static function company_phone(): string {
		return (string) get_option( self::OPT_COMPANY_PHONE, '' );
	}

	/**
	 * Account-wide management fee deducted on the landlord statement PDF
	 * (designs/22-landlord-statement-generator.html) — not mentioned by
	 * SPEC.md's module spec directly, but a normal real-world need for a
	 * management company's owner statements and doesn't conflict with
	 * anything SPEC.md does specify, so it's added here the same way
	 * every other billing constant is (admin-configurable, sane default).
	 */
	public static function management_fee_percent(): float {
		return (float) get_option( self::OPT_MANAGEMENT_FEE_PERCENT, 10.0 );
	}

	/**
	 * Admin-configurable toggle (Settings screen, Administrator-only via
	 * CAP_MANAGE_SETTINGS) for whether non-admin roles see only the Rental
	 * Manager menu in wp-admin, or the full WP admin menu. Defaults to
	 * enabled — a focused, single-purpose admin experience for Staff and
	 * Landlord-Owner accounts out of the box. Administrators always see
	 * every menu regardless of this setting (Admin\Menu::hide_other_menus()
	 * exempts them explicitly).
	 */
	public static function hide_other_menus_enabled(): bool {
		return (bool) get_option( self::OPT_HIDE_OTHER_MENUS, true );
	}
}

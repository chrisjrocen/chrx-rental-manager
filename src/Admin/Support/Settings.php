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

	// v2 (SPEC.md §4.2) — "semester" billing_cycle resolves to this
	// account-wide setting at lease creation time only; changing the
	// setting later never rewrites cycle_months on existing leases.
	public const OPT_SEMESTER_MONTHS = 'chrx_rm_semester_months';

	// v2 (SPEC.md §4.7) — WhatsApp Cloud API credentials. Stored as plain
	// options (WP's options table has no built-in secret store) but never
	// re-rendered into the settings form after save (SettingsController
	// masks them) and never written to logs.
	public const OPT_WHATSAPP_TOKEN                = 'chrx_rm_whatsapp_token';
	public const OPT_WHATSAPP_PHONE_NUMBER_ID      = 'chrx_rm_whatsapp_phone_number_id';
	public const OPT_WHATSAPP_BUSINESS_ACCOUNT_ID  = 'chrx_rm_whatsapp_business_account_id';
	public const OPT_WHATSAPP_DEFAULT_COUNTRY_CODE = 'chrx_rm_whatsapp_default_country_code';
	public const OPT_WHATSAPP_TEMPLATE_MAP         = 'chrx_rm_whatsapp_template_map';

	// The fixed set of notification categories the README documents as
	// required Meta templates (SPEC.md §4.7's "documented set of required
	// templates: invite, renewal reminder, payment received/receipt link,
	// overdue notice, custom alert, move-out notice confirmation"). Payment
	// recorded (staff) and receipt generated (tenant) share the
	// PAYMENT_RECEIVED key: SPEC.md's list names them together as one
	// template ("payment received/receipt link") rather than two, since
	// both describe the same event ("a payment was received") to different
	// audiences via context variables, not two separately-approved templates.
	public const TEMPLATE_KEY_INVITE           = 'invite';
	public const TEMPLATE_KEY_RENEWAL_REMINDER = 'renewal_reminder';
	public const TEMPLATE_KEY_PAYMENT_RECEIVED = 'payment_received';
	public const TEMPLATE_KEY_OVERDUE_NOTICE   = 'overdue_notice';
	public const TEMPLATE_KEY_CUSTOM_ALERT     = 'custom_alert';
	public const TEMPLATE_KEY_MOVE_OUT_NOTICE  = 'move_out_notice';

	// v2 (SPEC.md §4.9/§5): a 7th template — "Nylon Pay payment failed" is
	// its own notification row in §5's table, added after the original
	// six-template set was fixed in V2-1 (that set predates the Nylon Pay
	// phase entirely).
	public const TEMPLATE_KEY_GATEWAY_PAYMENT_FAILED = 'gateway_payment_failed';

	public const LATE_FEE_TYPE_FLAT    = 'flat';
	public const LATE_FEE_TYPE_PERCENT = 'percent';

	public const CURRENCY_FORMAT_SYMBOL_FIRST = 'symbol_first';
	public const CURRENCY_FORMAT_SYMBOL_LAST  = 'symbol_last';

	// v2 (SPEC.md §4.9) — Nylon Pay collections are billed via an ISO 4217
	// currency code, not the display symbol OPT_CURRENCY_SYMBOL already
	// stores — a gap the pre-v2 settings never needed to close since no
	// external API call needed a code before this. Kept as its own
	// setting rather than deriving it from the symbol (symbols aren't
	// 1:1 with codes, e.g. "$" alone can't tell USD from GHS-cedi-adjacent
	// symbols) and defaults to Ghana's code to match the existing
	// currency_symbol() default ('GH₵').
	public const OPT_CURRENCY_CODE = 'chrx_rm_currency_code';

	// v2 (SPEC.md §4.9) — Nylon Pay credentials, masked-after-save exactly
	// like the WhatsApp Cloud API settings above.
	public const OPT_NYLONPAY_ENABLED        = 'chrx_rm_nylonpay_enabled';
	public const OPT_NYLONPAY_API_KEY        = 'chrx_rm_nylonpay_api_key';
	public const OPT_NYLONPAY_API_SECRET     = 'chrx_rm_nylonpay_api_secret';
	public const OPT_NYLONPAY_WEBHOOK_SECRET = 'chrx_rm_nylonpay_webhook_secret';
	public const OPT_NYLONPAY_TEST_MODE      = 'chrx_rm_nylonpay_test_mode';

	// SPEC.md §4.9: "Amount below Nylon Pay's minimum (500 UGX)" — a fixed
	// platform-side minimum, not admin-configurable, so a constant rather
	// than an option.
	public const NYLONPAY_MINIMUM_AMOUNT = 500.0;

	// v2 (SPEC.md §4.10) — account-wide move-out notice period, settable
	// per property as an override (Property::notice_period_months column,
	// null = "use the account default").
	public const OPT_NOTICE_PERIOD_MONTHS = 'chrx_rm_notice_period_months';

	// v2 (SPEC.md §4.11) — receipt print-CSS paper format. Independent of
	// the existing Dompdf pipeline (which always renders A5 for the
	// emailed/downloaded PDF) — this only controls the separate
	// print-optimized HTML view (templates/print/receipt.php) staff and
	// tenants reach via a "Print" action, so a hostel using an 80mm
	// Bluetooth receipt printer doesn't have to fight A5 PDF margins.
	public const OPT_RECEIPT_PRINT_FORMAT = 'chrx_rm_receipt_print_format';

	public const RECEIPT_PRINT_FORMAT_A4         = 'a4';
	public const RECEIPT_PRINT_FORMAT_LETTER     = 'letter';
	public const RECEIPT_PRINT_FORMAT_THERMAL_58 = 'thermal_58';
	public const RECEIPT_PRINT_FORMAT_THERMAL_80 = 'thermal_80';

	public const RECEIPT_PRINT_FORMATS = array(
		self::RECEIPT_PRINT_FORMAT_A4,
		self::RECEIPT_PRINT_FORMAT_LETTER,
		self::RECEIPT_PRINT_FORMAT_THERMAL_58,
		self::RECEIPT_PRINT_FORMAT_THERMAL_80,
	);

	/**
	 * Best-effort list of currencies Nylon Pay's mobile-money rails cover,
	 * per SPEC.md §4.9's "if the site currency isn't supported... the
	 * settings screen refuses to enable the integration." This list is
	 * necessarily approximate — it's compiled from the currencies SPEC.md
	 * itself names (UGX) plus the other major East/West African mobile-
	 * money markets, not verified against Nylon Pay's live documentation
	 * (no network access in this environment) — flagged in the phase
	 * report as needing confirmation against https://docs.nylonpay.nilesquad.com
	 * before this gate is relied on in production.
	 *
	 * @var array<int,string>
	 */
	public const NYLONPAY_SUPPORTED_CURRENCIES = array( 'UGX', 'KES', 'GHS', 'NGN', 'TZS', 'RWF', 'ZMW' );

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
	 * ISO 4217 code for the site's single currency (SPEC.md §1.2: "single
	 * currency per site") — what Nylon Pay's API actually needs, as
	 * opposed to currency_symbol()'s display-only glyph.
	 */
	public static function currency_code(): string {
		$code = strtoupper( (string) get_option( self::OPT_CURRENCY_CODE, 'GHS' ) );

		return '' !== $code ? $code : 'GHS';
	}

	public static function currency_supported_by_nylonpay(): bool {
		return in_array( self::currency_code(), self::NYLONPAY_SUPPORTED_CURRENCIES, true );
	}

	public static function nylonpay_enabled(): bool {
		return (bool) get_option( self::OPT_NYLONPAY_ENABLED, false );
	}

	public static function nylonpay_api_key(): string {
		return (string) get_option( self::OPT_NYLONPAY_API_KEY, '' );
	}

	public static function nylonpay_api_secret(): string {
		return (string) get_option( self::OPT_NYLONPAY_API_SECRET, '' );
	}

	public static function nylonpay_webhook_secret(): string {
		return (string) get_option( self::OPT_NYLONPAY_WEBHOOK_SECRET, '' );
	}

	public static function nylonpay_test_mode(): bool {
		return (bool) get_option( self::OPT_NYLONPAY_TEST_MODE, true );
	}

	public static function nylonpay_credentials_set(): bool {
		return '' !== self::nylonpay_api_key() && '' !== self::nylonpay_api_secret() && '' !== self::nylonpay_webhook_secret();
	}

	/**
	 * Whether Pay Now / staff-sent requests may actually be attempted —
	 * every gate from SPEC.md §4.9 in one place: explicitly enabled,
	 * credentials present, and the site currency is one Nylon Pay supports.
	 */
	public static function nylonpay_is_available(): bool {
		return self::nylonpay_enabled() && self::nylonpay_credentials_set() && self::currency_supported_by_nylonpay();
	}

	/**
	 * Account-wide move-out notice period, in months (SPEC.md §4.10,
	 * default 2). Use notice_period_months_for_property() instead when a
	 * specific property is known — this is the plain account-level
	 * fallback that resolver falls back to.
	 */
	public static function notice_period_months(): int {
		return max( 1, (int) get_option( self::OPT_NOTICE_PERIOD_MONTHS, 2 ) );
	}

	/**
	 * Resolves the applicable notice period for a specific property,
	 * honoring its own override (Property::notice_period_months, nullable)
	 * before falling back to the account-wide setting — SPEC.md §4.10:
	 * "settable per property as an override (hostels may differ from
	 * apartments)."
	 *
	 * @param array<string,mixed>|null $property
	 */
	public static function notice_period_months_for_property( ?array $property ): int {
		if ( null !== $property && null !== ( $property['notice_period_months'] ?? null ) && '' !== $property['notice_period_months'] ) {
			return max( 1, (int) $property['notice_period_months'] );
		}

		return self::notice_period_months();
	}

	public static function receipt_print_format(): string {
		$format = get_option( self::OPT_RECEIPT_PRINT_FORMAT, self::RECEIPT_PRINT_FORMAT_A4 );

		return in_array( $format, self::RECEIPT_PRINT_FORMATS, true ) ? $format : self::RECEIPT_PRINT_FORMAT_A4;
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
	 * Account-wide "semester" length (SPEC.md §4.2), resolved into a
	 * lease's cycle_months at creation time — bounded to Lease's own
	 * custom-cycle range so an out-of-range setting can never produce an
	 * invalid cycle_months on a newly created lease.
	 */
	public static function semester_months(): int {
		$months = (int) get_option( self::OPT_SEMESTER_MONTHS, 4 );

		return min( \ChrxRentalManager\Data\Lease::CYCLE_MONTHS_MAX, max( \ChrxRentalManager\Data\Lease::CYCLE_MONTHS_MIN, $months ) );
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

	public static function whatsapp_token(): string {
		return (string) get_option( self::OPT_WHATSAPP_TOKEN, '' );
	}

	public static function whatsapp_phone_number_id(): string {
		return (string) get_option( self::OPT_WHATSAPP_PHONE_NUMBER_ID, '' );
	}

	public static function whatsapp_business_account_id(): string {
		return (string) get_option( self::OPT_WHATSAPP_BUSINESS_ACCOUNT_ID, '' );
	}

	/**
	 * Used by PhoneNumber::normalize_e164() to resolve local-format
	 * ("0772...") numbers entered on the tenant/staff forms.
	 */
	public static function whatsapp_default_country_code(): string {
		$code = (string) get_option( self::OPT_WHATSAPP_DEFAULT_COUNTRY_CODE, '256' );

		return '' !== $code ? $code : '256';
	}

	public static function whatsapp_is_configured(): bool {
		return '' !== self::whatsapp_token() && '' !== self::whatsapp_phone_number_id();
	}

	/**
	 * @return array<int,string>
	 */
	public static function whatsapp_template_keys(): array {
		return array(
			self::TEMPLATE_KEY_INVITE,
			self::TEMPLATE_KEY_RENEWAL_REMINDER,
			self::TEMPLATE_KEY_PAYMENT_RECEIVED,
			self::TEMPLATE_KEY_OVERDUE_NOTICE,
			self::TEMPLATE_KEY_CUSTOM_ALERT,
			self::TEMPLATE_KEY_MOVE_OUT_NOTICE,
			self::TEMPLATE_KEY_GATEWAY_PAYMENT_FAILED,
		);
	}

	/**
	 * @return array<string,string> template_key => Meta-approved template name
	 */
	public static function whatsapp_template_map(): array {
		$value = get_option( self::OPT_WHATSAPP_TEMPLATE_MAP, array() );

		return is_array( $value ) ? $value : array();
	}

	public static function whatsapp_template_name( string $template_key ): string {
		$map = self::whatsapp_template_map();

		return isset( $map[ $template_key ] ) ? (string) $map[ $template_key ] : '';
	}
}

<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\FlashNotice;
use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notifications/Reminders settings (designs/24-notifications-reminders-settings.html):
 * this is where admins configure the values the Billing phase's cron jobs
 * consume — reminder thresholds, late fee grace period/amount, currency
 * format (SPEC.md §4.2/§4.3/§7). Administrator-only (rm_manage_settings).
 */
final class SettingsController {

	private const NONCE_ACTION = 'rm_settings_save';
	private const PAGE_SLUG    = 'chrx-rm-settings';

	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_handle_save' ) );
	}

	public function maybe_handle_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside handle_save() via check_admin_referer() before any state change.
		if ( ! isset( $_POST['rm_settings_submit'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, only used to gate which screen's POST this is.
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		if ( ! current_user_can( RoleManager::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage settings.', 'chrx-rental-manager' ), 403 );
		}

		$this->handle_save();
	}

	public function render(): void {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage settings.', 'chrx-rental-manager' ), 403 );
		}

		$notice = FlashNotice::take( 'settings' );

		$charge_lead_days         = Settings::charge_lead_days();
		$reminder_thresholds      = Settings::reminder_thresholds();
		$reminder_notify_tenant   = Settings::reminder_notify_tenant();
		$late_fee_grace_days      = Settings::late_fee_grace_days();
		$late_fee_amount          = Settings::late_fee_amount();
		$late_fee_type            = Settings::late_fee_type();
		$currency_symbol          = Settings::currency_symbol();
		$currency_format          = Settings::currency_format();
		$company_name             = Settings::company_name();
		$company_address          = Settings::company_address();
		$company_phone            = Settings::company_phone();
		$management_fee_percent   = Settings::management_fee_percent();
		$hide_other_menus_enabled = Settings::hide_other_menus_enabled();
		$semester_months          = Settings::semester_months();

		$whatsapp_token_set            = '' !== Settings::whatsapp_token();
		$whatsapp_phone_number_id      = Settings::whatsapp_phone_number_id();
		$whatsapp_business_account_id  = Settings::whatsapp_business_account_id();
		$whatsapp_default_country_code = Settings::whatsapp_default_country_code();
		$whatsapp_template_keys        = Settings::whatsapp_template_keys();
		$whatsapp_template_map         = Settings::whatsapp_template_map();

		$currency_code               = Settings::currency_code();
		$nylonpay_enabled            = Settings::nylonpay_enabled();
		$nylonpay_test_mode          = Settings::nylonpay_test_mode();
		$nylonpay_api_key_set        = '' !== Settings::nylonpay_api_key();
		$nylonpay_api_secret_set     = '' !== Settings::nylonpay_api_secret();
		$nylonpay_webhook_secret_set = '' !== Settings::nylonpay_webhook_secret();
		$nylonpay_currency_supported = Settings::currency_supported_by_nylonpay();

		$notice_period_months = Settings::notice_period_months();
		$receipt_print_format = Settings::receipt_print_format();

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/settings.php';
	}

	private function handle_save(): void {
		check_admin_referer( self::NONCE_ACTION, 'rm_settings_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$charge_lead_days = isset( $_POST['rm_charge_lead_days'] ) ? absint( $_POST['rm_charge_lead_days'] ) : 5;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$thresholds_raw = isset( $_POST['rm_reminder_thresholds'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['rm_reminder_thresholds'] ) ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$notify_tenant = ! empty( $_POST['rm_reminder_notify_tenant'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$late_fee_grace_days = isset( $_POST['rm_late_fee_grace_days'] ) ? absint( $_POST['rm_late_fee_grace_days'] ) : 5;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$late_fee_amount = isset( $_POST['rm_late_fee_amount'] ) ? (float) str_replace( ',', '', sanitize_text_field( wp_unslash( $_POST['rm_late_fee_amount'] ) ) ) : 0.0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$late_fee_type = isset( $_POST['rm_late_fee_type'] ) ? sanitize_key( wp_unslash( $_POST['rm_late_fee_type'] ) ) : Settings::LATE_FEE_TYPE_FLAT;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$currency_symbol = isset( $_POST['rm_currency_symbol'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_currency_symbol'] ) ) : 'GH₵';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$currency_format = isset( $_POST['rm_currency_format'] ) ? sanitize_key( wp_unslash( $_POST['rm_currency_format'] ) ) : Settings::CURRENCY_FORMAT_SYMBOL_FIRST;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$company_name = isset( $_POST['rm_company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_company_name'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$company_address = isset( $_POST['rm_company_address'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_company_address'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$company_phone = isset( $_POST['rm_company_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_company_phone'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$management_fee_percent = isset( $_POST['rm_management_fee_percent'] ) ? (float) str_replace( ',', '', sanitize_text_field( wp_unslash( $_POST['rm_management_fee_percent'] ) ) ) : 10.0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$hide_other_menus = ! empty( $_POST['rm_hide_other_menus'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$semester_months = isset( $_POST['rm_semester_months'] ) ? absint( $_POST['rm_semester_months'] ) : 4;

		// v2 (SPEC.md §4.7/§7) — WhatsApp Cloud API. The token field is
		// always rendered blank (masked-after-save, never re-displayed);
		// an empty submission means "leave the stored token unchanged",
		// not "clear it" — a real clear requires the explicit checkbox.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$whatsapp_token_input = isset( $_POST['rm_whatsapp_token'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_whatsapp_token'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$whatsapp_token_clear = ! empty( $_POST['rm_whatsapp_token_clear'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$whatsapp_phone_number_id = isset( $_POST['rm_whatsapp_phone_number_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_whatsapp_phone_number_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$whatsapp_business_account_id = isset( $_POST['rm_whatsapp_business_account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_whatsapp_business_account_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$whatsapp_default_country_code = isset( $_POST['rm_whatsapp_default_country_code'] ) ? preg_replace( '/[^0-9]/', '', sanitize_text_field( wp_unslash( $_POST['rm_whatsapp_default_country_code'] ) ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$whatsapp_template_map_input = isset( $_POST['rm_whatsapp_template'] ) ? (array) wp_unslash( $_POST['rm_whatsapp_template'] ) : array();

		$whatsapp_template_map = array();

		foreach ( Settings::whatsapp_template_keys() as $template_key ) {
			$whatsapp_template_map[ $template_key ] = isset( $whatsapp_template_map_input[ $template_key ] )
				? sanitize_text_field( $whatsapp_template_map_input[ $template_key ] )
				: '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$currency_code = isset( $_POST['rm_currency_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['rm_currency_code'] ) ) ) : 'GHS';

		// v2 (SPEC.md §4.9) — Nylon Pay. Same masked-after-save pattern as
		// the WhatsApp credentials above: a blank submission means "leave
		// unchanged," a real clear requires the explicit checkbox.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$nylonpay_enabled = ! empty( $_POST['rm_nylonpay_enabled'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$nylonpay_test_mode = ! empty( $_POST['rm_nylonpay_test_mode'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$nylonpay_api_key_input = isset( $_POST['rm_nylonpay_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_nylonpay_api_key'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$nylonpay_api_key_clear = ! empty( $_POST['rm_nylonpay_api_key_clear'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$nylonpay_api_secret_input = isset( $_POST['rm_nylonpay_api_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_nylonpay_api_secret'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$nylonpay_api_secret_clear = ! empty( $_POST['rm_nylonpay_api_secret_clear'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$nylonpay_webhook_secret_input = isset( $_POST['rm_nylonpay_webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_nylonpay_webhook_secret'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$nylonpay_webhook_secret_clear = ! empty( $_POST['rm_nylonpay_webhook_secret_clear'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$notice_period_months = isset( $_POST['rm_notice_period_months'] ) ? absint( $_POST['rm_notice_period_months'] ) : 2;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$receipt_print_format = isset( $_POST['rm_receipt_print_format'] ) ? sanitize_key( wp_unslash( $_POST['rm_receipt_print_format'] ) ) : Settings::RECEIPT_PRINT_FORMAT_A4;

		$back_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );

		if ( $charge_lead_days < 0 || $late_fee_grace_days < 0 || $late_fee_amount < 0 || $management_fee_percent < 0 || array() === $thresholds_raw
			|| $semester_months < \ChrxRentalManager\Data\Lease::CYCLE_MONTHS_MIN || $semester_months > \ChrxRentalManager\Data\Lease::CYCLE_MONTHS_MAX
			|| '' === $currency_code || $notice_period_months < 1 ) {
			FlashNotice::set( 'settings', __( 'Please provide valid, non-negative values and at least one reminder threshold.', 'chrx-rental-manager' ) );
			wp_safe_redirect( $back_url );
			exit;
		}

		// SPEC.md §4.9: "if the site currency isn't supported by Nylon Pay,
		// the settings screen refuses to enable the integration rather
		// than failing at payment time."
		if ( $nylonpay_enabled && ! in_array( $currency_code, Settings::NYLONPAY_SUPPORTED_CURRENCIES, true ) ) {
			FlashNotice::set(
				'settings',
				sprintf(
					/* translators: %s: currency code */
					__( 'Nylon Pay does not support %s — choose a supported site currency before enabling it.', 'chrx-rental-manager' ),
					$currency_code
				)
			);
			wp_safe_redirect( $back_url );
			exit;
		}

		update_option( Settings::OPT_CHARGE_LEAD_DAYS, $charge_lead_days );
		update_option( Settings::OPT_REMINDER_THRESHOLDS, $thresholds_raw );
		update_option( Settings::OPT_REMINDER_NOTIFY_TENANT, $notify_tenant );
		update_option( Settings::OPT_LATE_FEE_GRACE_DAYS, $late_fee_grace_days );
		update_option( Settings::OPT_LATE_FEE_AMOUNT, $late_fee_amount );
		update_option(
			Settings::OPT_LATE_FEE_TYPE,
			in_array( $late_fee_type, array( Settings::LATE_FEE_TYPE_FLAT, Settings::LATE_FEE_TYPE_PERCENT ), true ) ? $late_fee_type : Settings::LATE_FEE_TYPE_FLAT
		);
		update_option( Settings::OPT_CURRENCY_SYMBOL, '' !== $currency_symbol ? $currency_symbol : 'GH₵' );
		update_option(
			Settings::OPT_CURRENCY_FORMAT,
			in_array( $currency_format, array( Settings::CURRENCY_FORMAT_SYMBOL_FIRST, Settings::CURRENCY_FORMAT_SYMBOL_LAST ), true ) ? $currency_format : Settings::CURRENCY_FORMAT_SYMBOL_FIRST
		);
		update_option( Settings::OPT_COMPANY_NAME, $company_name );
		update_option( Settings::OPT_COMPANY_ADDRESS, $company_address );
		update_option( Settings::OPT_COMPANY_PHONE, $company_phone );
		update_option( Settings::OPT_MANAGEMENT_FEE_PERCENT, $management_fee_percent );
		update_option( Settings::OPT_HIDE_OTHER_MENUS, $hide_other_menus );
		update_option( Settings::OPT_SEMESTER_MONTHS, $semester_months );

		if ( $whatsapp_token_clear ) {
			update_option( Settings::OPT_WHATSAPP_TOKEN, '' );
		} elseif ( '' !== $whatsapp_token_input ) {
			update_option( Settings::OPT_WHATSAPP_TOKEN, $whatsapp_token_input );
		}

		update_option( Settings::OPT_WHATSAPP_PHONE_NUMBER_ID, $whatsapp_phone_number_id );
		update_option( Settings::OPT_WHATSAPP_BUSINESS_ACCOUNT_ID, $whatsapp_business_account_id );
		update_option( Settings::OPT_WHATSAPP_DEFAULT_COUNTRY_CODE, '' !== $whatsapp_default_country_code ? $whatsapp_default_country_code : '256' );
		update_option( Settings::OPT_WHATSAPP_TEMPLATE_MAP, $whatsapp_template_map );

		update_option( Settings::OPT_CURRENCY_CODE, $currency_code );
		update_option( Settings::OPT_NOTICE_PERIOD_MONTHS, $notice_period_months );
		update_option(
			Settings::OPT_RECEIPT_PRINT_FORMAT,
			in_array( $receipt_print_format, Settings::RECEIPT_PRINT_FORMATS, true ) ? $receipt_print_format : Settings::RECEIPT_PRINT_FORMAT_A4
		);
		update_option( Settings::OPT_NYLONPAY_ENABLED, $nylonpay_enabled );
		update_option( Settings::OPT_NYLONPAY_TEST_MODE, $nylonpay_test_mode );

		if ( $nylonpay_api_key_clear ) {
			update_option( Settings::OPT_NYLONPAY_API_KEY, '' );
		} elseif ( '' !== $nylonpay_api_key_input ) {
			update_option( Settings::OPT_NYLONPAY_API_KEY, $nylonpay_api_key_input );
		}

		if ( $nylonpay_api_secret_clear ) {
			update_option( Settings::OPT_NYLONPAY_API_SECRET, '' );
		} elseif ( '' !== $nylonpay_api_secret_input ) {
			update_option( Settings::OPT_NYLONPAY_API_SECRET, $nylonpay_api_secret_input );
		}

		if ( $nylonpay_webhook_secret_clear ) {
			update_option( Settings::OPT_NYLONPAY_WEBHOOK_SECRET, '' );
		} elseif ( '' !== $nylonpay_webhook_secret_input ) {
			update_option( Settings::OPT_NYLONPAY_WEBHOOK_SECRET, $nylonpay_webhook_secret_input );
		}

		FlashNotice::set( 'settings', __( 'Settings saved.', 'chrx-rental-manager' ) );
		wp_safe_redirect( $back_url );
		exit;
	}

	public static function nonce_action(): string {
		return self::NONCE_ACTION;
	}

	public static function page_slug(): string {
		return self::PAGE_SLUG;
	}
}

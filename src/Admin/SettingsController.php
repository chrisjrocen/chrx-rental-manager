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

		$charge_lead_days       = Settings::charge_lead_days();
		$reminder_thresholds    = Settings::reminder_thresholds();
		$reminder_notify_tenant = Settings::reminder_notify_tenant();
		$late_fee_grace_days    = Settings::late_fee_grace_days();
		$late_fee_amount        = Settings::late_fee_amount();
		$late_fee_type          = Settings::late_fee_type();
		$currency_symbol        = Settings::currency_symbol();
		$currency_format        = Settings::currency_format();

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

		$back_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );

		if ( $charge_lead_days < 0 || $late_fee_grace_days < 0 || $late_fee_amount < 0 || array() === $thresholds_raw ) {
			FlashNotice::set( 'settings', __( 'Please provide valid, non-negative values and at least one reminder threshold.', 'chrx-rental-manager' ) );
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

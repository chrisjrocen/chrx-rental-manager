<?php
/**
 * Notifications/Reminders settings (designs/24-notifications-reminders-settings.html).
 * Variables in scope: $charge_lead_days (int), $reminder_thresholds (array<int,int>),
 * $reminder_notify_tenant (bool), $late_fee_grace_days (int),
 * $late_fee_amount (float), $late_fee_type (string), $currency_symbol (string),
 * $currency_format (string), $company_name (string), $company_address (string),
 * $company_phone (string), $management_fee_percent (float), $notice (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\SettingsController;
use ChrxRentalManager\Admin\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$available_thresholds = array( 30, 14, 7, 3 );
?>
<div class="wrap chrx-rm-admin">
	<h1><?php esc_html_e( 'Settings', 'chrx-rental-manager' ); ?></h1>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<form method="post" style="max-width:760px;">
		<?php wp_nonce_field( SettingsController::nonce_action(), 'rm_settings_nonce' ); ?>

		<div class="chrx-rm-panel">
			<div class="chrx-rm-panel__body">
				<div style="font-weight:700;font-size:15px;margin-bottom:4px;"><?php esc_html_e( 'Lease renewal reminders', 'chrx-rental-manager' ); ?></div>
				<div style="font-size:13px;color:#646970;margin-bottom:16px;"><?php esc_html_e( 'Notify staff before a lease expires.', 'chrx-rental-manager' ); ?></div>
				<div style="display:flex;gap:10px;flex-wrap:wrap;">
					<?php foreach ( $available_thresholds as $threshold ) : ?>
						<?php $checked = in_array( $threshold, $reminder_thresholds, true ); ?>
						<label style="display:flex;align-items:center;gap:7px;background:<?php echo $checked ? '#e4eefa' : '#f6f7f7'; ?>;border:1px solid <?php echo $checked ? '#135e96' : '#c3c4c7'; ?>;color:<?php echo $checked ? '#135e96' : '#646970'; ?>;padding:7px 14px;border-radius:20px;font-size:13px;font-weight:600;">
							<input type="checkbox" name="rm_reminder_thresholds[]" value="<?php echo esc_attr( (string) $threshold ); ?>" <?php checked( $checked ); ?>>
							<?php echo esc_html( sprintf( /* translators: %d: days */ _n( '%d day', '%d days', $threshold, 'chrx-rental-manager' ), $threshold ) ); ?>
						</label>
					<?php endforeach; ?>
				</div>
				<label style="display:flex;align-items:center;gap:8px;margin-top:16px;font-size:13px;">
					<input type="checkbox" name="rm_reminder_notify_tenant" value="1" <?php checked( $reminder_notify_tenant ); ?>>
					<?php esc_html_e( 'Also email the tenant (optional — some companies prefer staff-only reminders)', 'chrx-rental-manager' ); ?>
				</label>
				<p class="description" style="margin-top:12px;">
					<label for="rm_charge_lead_days"><?php esc_html_e( 'Generate rent charges this many days before each due date:', 'chrx-rental-manager' ); ?></label>
					<input type="number" min="0" id="rm_charge_lead_days" name="rm_charge_lead_days" value="<?php echo esc_attr( (string) $charge_lead_days ); ?>" style="width:70px;">
				</p>
			</div>
		</div>

		<div class="chrx-rm-panel">
			<div class="chrx-rm-panel__body">
				<div style="font-weight:700;font-size:15px;margin-bottom:16px;"><?php esc_html_e( 'Late fees', 'chrx-rental-manager' ); ?></div>
				<table class="form-table">
					<tr>
						<th><label for="rm_late_fee_grace_days"><?php esc_html_e( 'Grace period (days)', 'chrx-rental-manager' ); ?></label></th>
						<td><input type="number" min="0" id="rm_late_fee_grace_days" name="rm_late_fee_grace_days" value="<?php echo esc_attr( (string) $late_fee_grace_days ); ?>"></td>
					</tr>
					<tr>
						<th><label for="rm_late_fee_amount"><?php esc_html_e( 'Late fee amount', 'chrx-rental-manager' ); ?></label></th>
						<td>
							<input type="text" id="rm_late_fee_amount" name="rm_late_fee_amount" value="<?php echo esc_attr( (string) $late_fee_amount ); ?>" style="width:120px;">
							<select name="rm_late_fee_type">
								<option value="<?php echo esc_attr( Settings::LATE_FEE_TYPE_FLAT ); ?>" <?php selected( $late_fee_type, Settings::LATE_FEE_TYPE_FLAT ); ?>><?php esc_html_e( 'Flat amount', 'chrx-rental-manager' ); ?></option>
								<option value="<?php echo esc_attr( Settings::LATE_FEE_TYPE_PERCENT ); ?>" <?php selected( $late_fee_type, Settings::LATE_FEE_TYPE_PERCENT ); ?>><?php esc_html_e( '% of rent', 'chrx-rental-manager' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<p class="description"><?php esc_html_e( 'The fee is one-time (non-recurring) per overdue period — it never escalates.', 'chrx-rental-manager' ); ?></p>
			</div>
		</div>

		<div class="chrx-rm-panel">
			<div class="chrx-rm-panel__body">
				<div style="font-weight:700;font-size:15px;margin-bottom:16px;"><?php esc_html_e( 'Currency', 'chrx-rental-manager' ); ?></div>
				<table class="form-table">
					<tr>
						<th><label for="rm_currency_symbol"><?php esc_html_e( 'Symbol', 'chrx-rental-manager' ); ?></label></th>
						<td><input type="text" id="rm_currency_symbol" name="rm_currency_symbol" value="<?php echo esc_attr( $currency_symbol ); ?>" style="width:100px;"></td>
					</tr>
					<tr>
						<th><label for="rm_currency_format"><?php esc_html_e( 'Format', 'chrx-rental-manager' ); ?></label></th>
						<td>
							<select id="rm_currency_format" name="rm_currency_format">
								<option value="<?php echo esc_attr( Settings::CURRENCY_FORMAT_SYMBOL_FIRST ); ?>" <?php selected( $currency_format, Settings::CURRENCY_FORMAT_SYMBOL_FIRST ); ?>>
									<?php echo esc_html( $currency_symbol . ' 1,800.00' ); ?>
								</option>
								<option value="<?php echo esc_attr( Settings::CURRENCY_FORMAT_SYMBOL_LAST ); ?>" <?php selected( $currency_format, Settings::CURRENCY_FORMAT_SYMBOL_LAST ); ?>>
									<?php echo esc_html( '1,800.00 ' . $currency_symbol ); ?>
								</option>
							</select>
						</td>
					</tr>
				</table>
			</div>
		</div>

		<div class="chrx-rm-panel">
			<div class="chrx-rm-panel__body">
				<div style="font-weight:700;font-size:15px;margin-bottom:4px;"><?php esc_html_e( 'Receipt letterhead', 'chrx-rental-manager' ); ?></div>
				<div style="font-size:13px;color:#646970;margin-bottom:16px;"><?php esc_html_e( 'Shown at the top of every payment receipt PDF.', 'chrx-rental-manager' ); ?></div>
				<table class="form-table">
					<tr>
						<th><label for="rm_company_name"><?php esc_html_e( 'Company name', 'chrx-rental-manager' ); ?></label></th>
						<td><input type="text" id="rm_company_name" name="rm_company_name" value="<?php echo esc_attr( $company_name ); ?>" style="width:100%;max-width:360px;"></td>
					</tr>
					<tr>
						<th><label for="rm_company_address"><?php esc_html_e( 'Address', 'chrx-rental-manager' ); ?></label></th>
						<td><input type="text" id="rm_company_address" name="rm_company_address" value="<?php echo esc_attr( $company_address ); ?>" style="width:100%;max-width:360px;"></td>
					</tr>
					<tr>
						<th><label for="rm_company_phone"><?php esc_html_e( 'Phone', 'chrx-rental-manager' ); ?></label></th>
						<td><input type="text" id="rm_company_phone" name="rm_company_phone" value="<?php echo esc_attr( $company_phone ); ?>" style="width:100%;max-width:360px;"></td>
					</tr>
				</table>
			</div>
		</div>

		<div class="chrx-rm-panel">
			<div class="chrx-rm-panel__body">
				<div style="font-weight:700;font-size:15px;margin-bottom:4px;"><?php esc_html_e( 'Landlord statements', 'chrx-rental-manager' ); ?></div>
				<div style="font-size:13px;color:#646970;margin-bottom:16px;"><?php esc_html_e( 'Deducted from gross collected on every owner statement PDF.', 'chrx-rental-manager' ); ?></div>
				<p class="description">
					<label for="rm_management_fee_percent"><?php esc_html_e( 'Management fee (%)', 'chrx-rental-manager' ); ?></label>
					<input type="text" id="rm_management_fee_percent" name="rm_management_fee_percent" value="<?php echo esc_attr( (string) $management_fee_percent ); ?>" style="width:70px;">
				</p>
			</div>
		</div>

		<p class="submit">
			<button type="submit" name="rm_settings_submit" value="1" class="button button-primary"><?php esc_html_e( 'Save settings', 'chrx-rental-manager' ); ?></button>
		</p>
	</form>
</div>

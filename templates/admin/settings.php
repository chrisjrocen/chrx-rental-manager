<?php
/**
 * Notifications/Reminders settings (designs/24-notifications-reminders-settings.html).
 * Variables in scope: $charge_lead_days (int), $reminder_thresholds (array<int,int>),
 * $reminder_notify_tenant (bool), $late_fee_grace_days (int),
 * $late_fee_amount (float), $late_fee_type (string), $currency_symbol (string),
 * $currency_format (string), $company_name (string), $company_address (string),
 * $company_phone (string), $management_fee_percent (float),
 * $hide_other_menus_enabled (bool), $semester_months (int), $notice (?string).
 * v2: $whatsapp_token_set (bool), $whatsapp_phone_number_id (string),
 * $whatsapp_business_account_id (string), $whatsapp_default_country_code (string),
 * $whatsapp_template_keys (array<int,string>), $whatsapp_template_map (array<string,string>).
 * v2 (Nylon Pay): $currency_code (string), $nylonpay_enabled (bool),
 * $nylonpay_test_mode (bool), $nylonpay_api_key_set (bool),
 * $nylonpay_api_secret_set (bool), $nylonpay_webhook_secret_set (bool),
 * $nylonpay_currency_supported (bool).
 * v2 (Move-out notices): $notice_period_months (int).
 * v2 (Receipt printing): $receipt_print_format (string).
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
				<div style="font-weight:700;font-size:15px;margin-bottom:4px;"><?php esc_html_e( 'Billing cycles', 'chrx-rental-manager' ); ?></div>
				<div style="font-size:13px;color:#646970;margin-bottom:16px;"><?php esc_html_e( 'A lease set to "Semester" resolves to this many months at creation time only — later changes here do not affect leases already created.', 'chrx-rental-manager' ); ?></div>
				<p class="description">
					<label for="rm_semester_months"><?php esc_html_e( 'Semester length (months)', 'chrx-rental-manager' ); ?></label>
					<input type="number" min="1" max="24" id="rm_semester_months" name="rm_semester_months" value="<?php echo esc_attr( (string) $semester_months ); ?>" style="width:70px;">
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
						<th><label for="rm_currency_code"><?php esc_html_e( 'Currency code (ISO 4217)', 'chrx-rental-manager' ); ?></label></th>
						<td>
							<input type="text" id="rm_currency_code" name="rm_currency_code" value="<?php echo esc_attr( $currency_code ); ?>" style="width:100px;text-transform:uppercase;" maxlength="3">
							<p class="description"><?php esc_html_e( 'Used for Nylon Pay collections, e.g. UGX, KES, GHS, NGN.', 'chrx-rental-manager' ); ?></p>
						</td>
					</tr>
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
				<div style="font-weight:700;font-size:15px;margin-bottom:4px;"><?php esc_html_e( 'Receipt printing', 'chrx-rental-manager' ); ?></div>
				<div style="font-size:13px;color:#646970;margin-bottom:16px;"><?php esc_html_e( 'Paper size used by the "Print" action on the staff and tenant-portal receipt screens (separate from the emailed/downloaded PDF, which always fits a standard receipt page).', 'chrx-rental-manager' ); ?></div>
				<table class="form-table">
					<tr>
						<th><label for="rm_receipt_print_format"><?php esc_html_e( 'Print format', 'chrx-rental-manager' ); ?></label></th>
						<td>
							<select id="rm_receipt_print_format" name="rm_receipt_print_format">
								<option value="<?php echo esc_attr( Settings::RECEIPT_PRINT_FORMAT_A4 ); ?>" <?php selected( $receipt_print_format, Settings::RECEIPT_PRINT_FORMAT_A4 ); ?>><?php esc_html_e( 'A4', 'chrx-rental-manager' ); ?></option>
								<option value="<?php echo esc_attr( Settings::RECEIPT_PRINT_FORMAT_LETTER ); ?>" <?php selected( $receipt_print_format, Settings::RECEIPT_PRINT_FORMAT_LETTER ); ?>><?php esc_html_e( 'Letter', 'chrx-rental-manager' ); ?></option>
								<option value="<?php echo esc_attr( Settings::RECEIPT_PRINT_FORMAT_THERMAL_58 ); ?>" <?php selected( $receipt_print_format, Settings::RECEIPT_PRINT_FORMAT_THERMAL_58 ); ?>><?php esc_html_e( 'Thermal — 58mm', 'chrx-rental-manager' ); ?></option>
								<option value="<?php echo esc_attr( Settings::RECEIPT_PRINT_FORMAT_THERMAL_80 ); ?>" <?php selected( $receipt_print_format, Settings::RECEIPT_PRINT_FORMAT_THERMAL_80 ); ?>><?php esc_html_e( 'Thermal — 80mm', 'chrx-rental-manager' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'For a Bluetooth thermal printer, print from an Android device using a print-to-Bluetooth app (e.g. RawBT) as the system print handler.', 'chrx-rental-manager' ); ?></p>
						</td>
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

		<div class="chrx-rm-panel">
			<div class="chrx-rm-panel__body">
				<div style="font-weight:700;font-size:15px;margin-bottom:4px;"><?php esc_html_e( 'Move-out notice period', 'chrx-rental-manager' ); ?></div>
				<div style="font-size:13px;color:#646970;margin-bottom:16px;"><?php esc_html_e( 'How much notice a tenant must give before moving out. Individual properties can override this on their own edit screen.', 'chrx-rental-manager' ); ?></div>
				<p class="description">
					<label for="rm_notice_period_months"><?php esc_html_e( 'Notice period (months)', 'chrx-rental-manager' ); ?></label>
					<input type="number" min="1" id="rm_notice_period_months" name="rm_notice_period_months" value="<?php echo esc_attr( (string) $notice_period_months ); ?>" style="width:70px;">
				</p>
			</div>
		</div>

		<div class="chrx-rm-panel">
			<div class="chrx-rm-panel__body">
				<div style="font-weight:700;font-size:15px;margin-bottom:4px;"><?php esc_html_e( 'Admin menu', 'chrx-rental-manager' ); ?></div>
				<div style="font-size:13px;color:#646970;margin-bottom:16px;"><?php esc_html_e( 'Administrators always see the full WP admin menu regardless of this setting.', 'chrx-rental-manager' ); ?></div>
				<label style="display:flex;align-items:center;gap:8px;font-size:13px;">
					<input type="checkbox" name="rm_hide_other_menus" value="1" <?php checked( $hide_other_menus_enabled ); ?>>
					<?php esc_html_e( 'Hide other WP admin menus for non-admin users (Staff, Landlord-Owner)', 'chrx-rental-manager' ); ?>
				</label>
			</div>
		</div>

		<div class="chrx-rm-panel">
			<div class="chrx-rm-panel__body">
				<div style="font-weight:700;font-size:15px;margin-bottom:4px;"><?php esc_html_e( 'WhatsApp (Meta Cloud API)', 'chrx-rental-manager' ); ?></div>
				<div style="font-size:13px;color:#646970;margin-bottom:16px;"><?php esc_html_e( 'Notifications go out by email always, and additionally by WhatsApp for any recipient with a number on file.', 'chrx-rental-manager' ); ?></div>
				<table class="form-table">
					<tr>
						<th><label for="rm_whatsapp_token"><?php esc_html_e( 'Access token', 'chrx-rental-manager' ); ?></label></th>
						<td>
							<input type="password" id="rm_whatsapp_token" name="rm_whatsapp_token" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( $whatsapp_token_set ? __( '•••••••• (unchanged — leave blank to keep)', 'chrx-rental-manager' ) : __( 'Not set', 'chrx-rental-manager' ) ); ?>">
							<?php if ( $whatsapp_token_set ) : ?>
								<label style="display:block;margin-top:6px;font-size:12px;">
									<input type="checkbox" name="rm_whatsapp_token_clear" value="1"> <?php esc_html_e( 'Clear the stored token', 'chrx-rental-manager' ); ?>
								</label>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="rm_whatsapp_phone_number_id"><?php esc_html_e( 'Phone number ID', 'chrx-rental-manager' ); ?></label></th>
						<td><input type="text" id="rm_whatsapp_phone_number_id" name="rm_whatsapp_phone_number_id" class="regular-text" value="<?php echo esc_attr( $whatsapp_phone_number_id ); ?>"></td>
					</tr>
					<tr>
						<th><label for="rm_whatsapp_business_account_id"><?php esc_html_e( 'Business account ID', 'chrx-rental-manager' ); ?></label></th>
						<td><input type="text" id="rm_whatsapp_business_account_id" name="rm_whatsapp_business_account_id" class="regular-text" value="<?php echo esc_attr( $whatsapp_business_account_id ); ?>"></td>
					</tr>
					<tr>
						<th><label for="rm_whatsapp_default_country_code"><?php esc_html_e( 'Default country code', 'chrx-rental-manager' ); ?></label></th>
						<td>
							<input type="text" id="rm_whatsapp_default_country_code" name="rm_whatsapp_default_country_code" value="<?php echo esc_attr( $whatsapp_default_country_code ); ?>" style="width:80px;">
							<p class="description"><?php esc_html_e( 'Used to normalize locally-formatted numbers (e.g. a leading 0) entered on tenant/staff forms.', 'chrx-rental-manager' ); ?></p>
						</td>
					</tr>
				</table>

				<div style="font-weight:600;font-size:13px;margin:16px 0 8px;"><?php esc_html_e( 'Approved template names', 'chrx-rental-manager' ); ?></div>
				<p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'Each notification type must map to a Meta Business-approved template name before it can send over WhatsApp.', 'chrx-rental-manager' ); ?></p>
				<table class="form-table">
					<?php
					$whatsapp_template_labels = array(
						Settings::TEMPLATE_KEY_INVITE => __( 'Portal invite', 'chrx-rental-manager' ),
						Settings::TEMPLATE_KEY_RENEWAL_REMINDER => __( 'Lease renewal reminder', 'chrx-rental-manager' ),
						Settings::TEMPLATE_KEY_PAYMENT_RECEIVED => __( 'Payment received / receipt link', 'chrx-rental-manager' ),
						Settings::TEMPLATE_KEY_OVERDUE_NOTICE => __( 'Overdue notice', 'chrx-rental-manager' ),
						Settings::TEMPLATE_KEY_CUSTOM_ALERT => __( 'Custom alert', 'chrx-rental-manager' ),
						Settings::TEMPLATE_KEY_MOVE_OUT_NOTICE => __( 'Move-out notice confirmation', 'chrx-rental-manager' ),
					);
					?>
					<?php foreach ( $whatsapp_template_keys as $template_key ) : ?>
						<tr>
							<th><label for="rm_whatsapp_template_<?php echo esc_attr( $template_key ); ?>"><?php echo esc_html( $whatsapp_template_labels[ $template_key ] ?? $template_key ); ?></label></th>
							<td><input type="text" id="rm_whatsapp_template_<?php echo esc_attr( $template_key ); ?>" name="rm_whatsapp_template[<?php echo esc_attr( $template_key ); ?>]" class="regular-text" value="<?php echo esc_attr( $whatsapp_template_map[ $template_key ] ?? '' ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</table>
			</div>
		</div>

		<div class="chrx-rm-panel">
			<div class="chrx-rm-panel__body">
				<div style="font-weight:700;font-size:15px;margin-bottom:4px;"><?php esc_html_e( 'Nylon Pay (online payments)', 'chrx-rental-manager' ); ?></div>
				<div style="font-size:13px;color:#646970;margin-bottom:16px;"><?php esc_html_e( 'Lets tenants pay by mobile money from the portal (Pay Now) and staff send payment requests from the lease screen.', 'chrx-rental-manager' ); ?></div>

				<?php if ( ! $nylonpay_currency_supported ) : ?>
					<div class="notice notice-warning inline" style="margin:0 0 14px;">
						<p>
							<?php
							printf(
								/* translators: %s: currency code */
								esc_html__( 'Nylon Pay does not support the current site currency (%s) — the integration cannot be enabled until the currency above is changed to a supported one.', 'chrx-rental-manager' ),
								esc_html( $currency_code )
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:14px;">
					<input type="checkbox" name="rm_nylonpay_enabled" value="1" <?php checked( $nylonpay_enabled ); ?> <?php disabled( ! $nylonpay_currency_supported && ! $nylonpay_enabled ); ?>>
					<?php esc_html_e( 'Enable Nylon Pay', 'chrx-rental-manager' ); ?>
				</label>
				<label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:14px;">
					<input type="checkbox" name="rm_nylonpay_test_mode" value="1" <?php checked( $nylonpay_test_mode ); ?>>
					<?php esc_html_e( 'Test mode (sandbox — behaves identically to production per Nylon Pay\'s docs)', 'chrx-rental-manager' ); ?>
				</label>

				<table class="form-table">
					<tr>
						<th><label for="rm_nylonpay_api_key"><?php esc_html_e( 'API key', 'chrx-rental-manager' ); ?></label></th>
						<td>
							<input type="password" id="rm_nylonpay_api_key" name="rm_nylonpay_api_key" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( $nylonpay_api_key_set ? __( '•••••••• (unchanged — leave blank to keep)', 'chrx-rental-manager' ) : __( 'Not set', 'chrx-rental-manager' ) ); ?>">
							<?php if ( $nylonpay_api_key_set ) : ?>
								<label style="display:block;margin-top:6px;font-size:12px;">
									<input type="checkbox" name="rm_nylonpay_api_key_clear" value="1"> <?php esc_html_e( 'Clear the stored API key', 'chrx-rental-manager' ); ?>
								</label>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="rm_nylonpay_api_secret"><?php esc_html_e( 'API secret', 'chrx-rental-manager' ); ?></label></th>
						<td>
							<input type="password" id="rm_nylonpay_api_secret" name="rm_nylonpay_api_secret" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( $nylonpay_api_secret_set ? __( '•••••••• (unchanged — leave blank to keep)', 'chrx-rental-manager' ) : __( 'Not set', 'chrx-rental-manager' ) ); ?>">
							<?php if ( $nylonpay_api_secret_set ) : ?>
								<label style="display:block;margin-top:6px;font-size:12px;">
									<input type="checkbox" name="rm_nylonpay_api_secret_clear" value="1"> <?php esc_html_e( 'Clear the stored API secret', 'chrx-rental-manager' ); ?>
								</label>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="rm_nylonpay_webhook_secret"><?php esc_html_e( 'Webhook secret', 'chrx-rental-manager' ); ?></label></th>
						<td>
							<input type="password" id="rm_nylonpay_webhook_secret" name="rm_nylonpay_webhook_secret" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( $nylonpay_webhook_secret_set ? __( '•••••••• (unchanged — leave blank to keep)', 'chrx-rental-manager' ) : __( 'Not set', 'chrx-rental-manager' ) ); ?>">
							<?php if ( $nylonpay_webhook_secret_set ) : ?>
								<label style="display:block;margin-top:6px;font-size:12px;">
									<input type="checkbox" name="rm_nylonpay_webhook_secret_clear" value="1"> <?php esc_html_e( 'Clear the stored webhook secret', 'chrx-rental-manager' ); ?>
								</label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Used to verify that webhook requests genuinely came from Nylon Pay.', 'chrx-rental-manager' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="description">
					<?php
					printf(
						/* translators: %s: webhook URL */
						esc_html__( 'Webhook URL to register with Nylon Pay: %s', 'chrx-rental-manager' ),
						esc_html( rest_url( 'chrx-rm/v1/nylonpay-webhook' ) )
					);
					?>
				</p>
			</div>
		</div>

		<p class="submit">
			<button type="submit" name="rm_settings_submit" value="1" class="button button-primary"><?php esc_html_e( 'Save settings', 'chrx-rental-manager' ); ?></button>
		</p>
	</form>
</div>

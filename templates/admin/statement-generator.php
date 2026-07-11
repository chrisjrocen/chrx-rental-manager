<?php
/**
 * Landlord statement generator, staff/admin side (designs/22-landlord-statement-generator.html).
 *
 * Variables in scope: $landlords (array<int,WP_User>), $selected_landlord_id
 * (int), $available_properties (array<int,array>), $default_from (string),
 * $default_to (string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\StatementsController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap chrx-rm-admin">
	<h1 style="font-size:23px;font-weight:600;margin:0 0 18px;"><?php esc_html_e( 'Landlord statement', 'chrx-rental-manager' ); ?></h1>

	<form method="get" style="max-width:360px;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;">
		<input type="hidden" name="page" value="<?php echo esc_attr( StatementsController::page_slug() ); ?>">
		<input type="hidden" name="action" value="preview">

		<div style="margin-bottom:16px;">
			<label for="rm_landlord_id" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Landlord-Owner', 'chrx-rental-manager' ); ?></label>
			<select id="rm_landlord_id" name="landlord_id" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;background:#fff;" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( 'All landlords', 'chrx-rental-manager' ); ?></option>
				<?php foreach ( $landlords as $landlord ) : ?>
					<option value="<?php echo esc_attr( (string) $landlord->ID ); ?>" <?php selected( $selected_landlord_id, $landlord->ID ); ?>><?php echo esc_html( $landlord->display_name ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div style="margin-bottom:16px;">
			<label for="rm_property_id" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Property', 'chrx-rental-manager' ); ?></label>
			<select id="rm_property_id" name="property_id" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;background:#fff;" required>
				<?php if ( array() === $available_properties ) : ?>
					<option value=""><?php esc_html_e( 'No properties available', 'chrx-rental-manager' ); ?></option>
				<?php endif; ?>
				<?php foreach ( $available_properties as $property ) : ?>
					<option value="<?php echo esc_attr( (string) $property['id'] ); ?>"><?php echo esc_html( $property['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
			<div>
				<label for="rm_from" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'From', 'chrx-rental-manager' ); ?></label>
				<input type="date" id="rm_from" name="from" value="<?php echo esc_attr( $default_from ); ?>" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #8c8f94;border-radius:4px;font-size:13px;" required>
			</div>
			<div>
				<label for="rm_to" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'To', 'chrx-rental-manager' ); ?></label>
				<input type="date" id="rm_to" name="to" value="<?php echo esc_attr( $default_to ); ?>" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #8c8f94;border-radius:4px;font-size:13px;" required>
			</div>
		</div>

		<button type="submit" class="button button-primary" style="width:100%;"><?php esc_html_e( 'Generate PDF', 'chrx-rental-manager' ); ?></button>
	</form>
</div>

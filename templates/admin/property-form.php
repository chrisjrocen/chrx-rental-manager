<?php
/**
 * Add/Edit Property form (designs/07-add-edit-property-form.html).
 * Variables in scope: $action ('add'|'edit'), $property_id (int),
 * $property (?array), $landlord_ids (array<int,int>),
 * $staff_ids (array<int,int>), $landlord_users (array<int,WP_User>),
 * $staff_users (array<int,WP_User>), $list_url (string), $notice (?string).
 *
 * Note: the design shows staff assignment as a JS chip-picker; this
 * server-rendered form uses plain checkboxes for the same multi-select
 * behavior without requiring a JS framework this phase doesn't otherwise need.
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\PropertiesController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_edit             = 'edit' === $action;
$current_landlord_id = array() !== $landlord_ids ? $landlord_ids[0] : 0;
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-breadcrumb"><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Properties', 'chrx-rental-manager' ); ?></a> &rsaquo; <?php echo $is_edit ? esc_html__( 'Edit', 'chrx-rental-manager' ) : esc_html__( 'Add new', 'chrx-rental-manager' ); ?></div>
	<h1><?php echo $is_edit ? esc_html__( 'Edit Property', 'chrx-rental-manager' ) : esc_html__( 'Add Property', 'chrx-rental-manager' ); ?></h1>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<form method="post" class="chrx-rm-admin__form" style="max-width:720px;">
		<?php wp_nonce_field( PropertiesController::nonce_action(), 'rm_property_nonce' ); ?>
		<input type="hidden" name="property_id" value="<?php echo esc_attr( (string) $property_id ); ?>">

		<table class="form-table">
			<tr>
				<th><label for="rm_name"><?php esc_html_e( 'Property name', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="text" id="rm_name" name="rm_name" class="regular-text" value="<?php echo esc_attr( $property['name'] ?? '' ); ?>" required></td>
			</tr>
			<tr>
				<th><label for="rm_address"><?php esc_html_e( 'Street address', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="text" id="rm_address" name="rm_address" class="regular-text" value="<?php echo esc_attr( $property['address'] ?? '' ); ?>" required></td>
			</tr>
			<tr>
				<th><label for="rm_city"><?php esc_html_e( 'City', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="text" id="rm_city" name="rm_city" class="regular-text" value="<?php echo esc_attr( $property['city'] ?? '' ); ?>"></td>
			</tr>
			<tr>
				<th><label for="rm_landlord_id"><?php esc_html_e( 'Landlord-Owner', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<select id="rm_landlord_id" name="rm_landlord_id">
						<option value="0"><?php esc_html_e( '— None —', 'chrx-rental-manager' ); ?></option>
						<?php foreach ( $landlord_users as $user ) : ?>
							<option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( $current_landlord_id, $user->ID ); ?>>
								<?php echo esc_html( $user->display_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Assigned staff', 'chrx-rental-manager' ); ?></th>
				<td>
					<?php if ( array() === $staff_users ) : ?>
						<p><?php esc_html_e( 'No staff users exist yet.', 'chrx-rental-manager' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $staff_users as $user ) : ?>
						<label style="display:block;margin-bottom:4px;">
							<input type="checkbox" name="rm_staff_ids[]" value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php checked( in_array( $user->ID, $staff_ids, true ) ); ?>>
							<?php echo esc_html( $user->display_name ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th><label for="rm_notice_period_months"><?php esc_html_e( 'Move-out notice period override', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<input type="number" min="1" id="rm_notice_period_months" name="rm_notice_period_months" value="<?php echo esc_attr( (string) ( $property['notice_period_months'] ?? '' ) ); ?>" style="width:80px;">
					<p class="description"><?php esc_html_e( 'Months. Leave blank to use the account-wide default from Settings.', 'chrx-rental-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rm_notes"><?php esc_html_e( 'Notes', 'chrx-rental-manager' ); ?></label></th>
				<td><textarea id="rm_notes" name="rm_notes" rows="3" class="large-text"><?php echo esc_textarea( $property['notes'] ?? '' ); ?></textarea></td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" name="rm_property_submit" value="1" class="button button-primary"><?php esc_html_e( 'Save Property', 'chrx-rental-manager' ); ?></button>
			<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'chrx-rental-manager' ); ?></a>
		</p>
	</form>
</div>

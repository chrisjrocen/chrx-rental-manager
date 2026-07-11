<?php
/**
 * Add/Edit Unit form (designs/10-add-edit-unit.html).
 * Variables in scope: $action ('add'|'edit'), $unit_id (int), $unit (?array),
 * $properties (array<int,array>), $preselected_property_id (int),
 * $list_url (string), $notice (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\UnitsController;
use ChrxRentalManager\Data\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_edit             = 'edit' === $action;
$current_property_id = $unit['property_id'] ?? $preselected_property_id;

$statuses = array(
	Unit::STATUS_VACANT      => __( 'Vacant', 'chrx-rental-manager' ),
	Unit::STATUS_OCCUPIED    => __( 'Occupied', 'chrx-rental-manager' ),
	Unit::STATUS_MAINTENANCE => __( 'Under Maintenance', 'chrx-rental-manager' ),
	Unit::STATUS_RESERVED    => __( 'Reserved', 'chrx-rental-manager' ),
);
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-breadcrumb"><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Units', 'chrx-rental-manager' ); ?></a> &rsaquo; <?php echo $is_edit ? esc_html__( 'Edit', 'chrx-rental-manager' ) : esc_html__( 'Add new', 'chrx-rental-manager' ); ?></div>
	<h1><?php echo $is_edit ? esc_html__( 'Edit Unit', 'chrx-rental-manager' ) : esc_html__( 'Add Unit', 'chrx-rental-manager' ); ?></h1>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<form method="post" class="chrx-rm-admin__form" style="max-width:720px;">
		<?php wp_nonce_field( UnitsController::nonce_action(), 'rm_unit_nonce' ); ?>
		<input type="hidden" name="unit_id" value="<?php echo esc_attr( (string) $unit_id ); ?>">

		<table class="form-table">
			<tr>
				<th><label for="rm_property_id"><?php esc_html_e( 'Property', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<select id="rm_property_id" name="rm_property_id" required>
						<option value=""><?php esc_html_e( '— Select —', 'chrx-rental-manager' ); ?></option>
						<?php foreach ( $properties as $property ) : ?>
							<option value="<?php echo esc_attr( (string) $property['id'] ); ?>" <?php selected( (int) $current_property_id, (int) $property['id'] ); ?>>
								<?php echo esc_html( $property['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="rm_unit_label"><?php esc_html_e( 'Unit label', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="text" id="rm_unit_label" name="rm_unit_label" class="regular-text" value="<?php echo esc_attr( $unit['unit_label'] ?? '' ); ?>" required></td>
			</tr>
			<tr>
				<th><label for="rm_bedrooms"><?php esc_html_e( 'Bedrooms (0 = studio)', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="number" min="0" id="rm_bedrooms" name="rm_bedrooms" value="<?php echo esc_attr( (string) ( $unit['bedrooms'] ?? 0 ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="rm_rent_amount"><?php esc_html_e( 'Monthly rent', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="text" id="rm_rent_amount" name="rm_rent_amount" value="<?php echo esc_attr( (string) ( $unit['rent_amount'] ?? '' ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="rm_status"><?php esc_html_e( 'Status', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<select id="rm_status" name="rm_status">
						<?php foreach ( $statuses as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $unit['status'] ?? Unit::STATUS_VACANT, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Occupied/Vacant is normally set automatically from lease activity. Under Maintenance/Reserved persists until you change it here.', 'chrx-rental-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rm_notes"><?php esc_html_e( 'Notes', 'chrx-rental-manager' ); ?></label></th>
				<td><textarea id="rm_notes" name="rm_notes" rows="3" class="large-text"><?php echo esc_textarea( $unit['notes'] ?? '' ); ?></textarea></td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" name="rm_unit_submit" value="1" class="button button-primary"><?php esc_html_e( 'Save Unit', 'chrx-rental-manager' ); ?></button>
			<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'chrx-rental-manager' ); ?></a>
		</p>
	</form>
</div>

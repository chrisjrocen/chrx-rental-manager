<?php
/**
 * Units list (designs/08-units-list.html).
 * Variables in scope: $list_table (UnitsListTable), $properties (array),
 * $add_url (string), $can_manage (bool), $is_empty (bool), $notice (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Data\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter params, no state change.
$selected_property_id = isset( $_GET['property_id'] ) ? absint( $_GET['property_id'] ) : 0;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter params, no state change.
$selected_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

$statuses = array(
	Unit::STATUS_OCCUPIED    => __( 'Occupied', 'chrx-rental-manager' ),
	Unit::STATUS_VACANT      => __( 'Vacant', 'chrx-rental-manager' ),
	Unit::STATUS_MAINTENANCE => __( 'Under Maintenance', 'chrx-rental-manager' ),
	Unit::STATUS_RESERVED    => __( 'Reserved', 'chrx-rental-manager' ),
);
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-admin__header">
		<h1><?php esc_html_e( 'Units', 'chrx-rental-manager' ); ?></h1>
		<?php if ( $can_manage ) : ?>
			<a href="<?php echo esc_url( $add_url ); ?>" class="button"><?php esc_html_e( 'Add Unit', 'chrx-rental-manager' ); ?></a>
		<?php endif; ?>
	</div>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<?php if ( $is_empty && '' === $selected_status && 0 === $selected_property_id ) : ?>
		<div class="chrx-rm-panel">
			<div class="chrx-rm-empty-state">
				<div class="chrx-rm-empty-state__title"><?php esc_html_e( 'No units yet', 'chrx-rental-manager' ); ?></div>
				<div class="chrx-rm-empty-state__desc"><?php esc_html_e( 'Add your first unit to a property to start creating leases.', 'chrx-rental-manager' ); ?></div>
				<?php if ( $can_manage ) : ?>
					<a href="<?php echo esc_url( $add_url ); ?>" class="button button-primary"><?php esc_html_e( 'Add Unit', 'chrx-rental-manager' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	<?php else : ?>
		<form method="get">
			<input type="hidden" name="page" value="chrx-rm-units">
			<div class="chrx-rm-list-toolbar">
				<select name="property_id" onchange="this.form.submit()">
					<option value="0"><?php esc_html_e( 'All properties', 'chrx-rental-manager' ); ?></option>
					<?php foreach ( $properties as $property ) : ?>
						<option value="<?php echo esc_attr( (string) $property['id'] ); ?>" <?php selected( $selected_property_id, $property['id'] ); ?>>
							<?php echo esc_html( $property['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<select name="status" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'Any status', 'chrx-rental-manager' ); ?></option>
					<?php foreach ( $statuses as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected_status, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php $list_table->search_box( __( 'Search units…', 'chrx-rental-manager' ), 'rm-units' ); ?>
				<span class="chrx-rm-list-toolbar__count">
					<?php
					printf(
						/* translators: %d: unit count */
						esc_html( _n( '%d unit', '%d units', (int) $list_table->get_pagination_arg( 'total_items' ), 'chrx-rental-manager' ) ),
						(int) $list_table->get_pagination_arg( 'total_items' )
					);
					?>
				</span>
			</div>
			<?php $list_table->display(); ?>
		</form>
	<?php endif; ?>
</div>

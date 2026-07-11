<?php
/**
 * Payments list (designs/20-payments-list.html): filter by
 * property/method/month, CSV export.
 *
 * Variables in scope: $list_table (PaymentsListTable), $properties
 * (array), $selected_property_id (int), $selected_method (string),
 * $selected_month (string), $export_url (string), $notice (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Data\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$methods = array(
	Payment::METHOD_MTN_MOMO      => __( 'MTN MoMo', 'chrx-rental-manager' ),
	Payment::METHOD_AIRTEL_MONEY  => __( 'Airtel Money', 'chrx-rental-manager' ),
	Payment::METHOD_CASH          => __( 'Cash', 'chrx-rental-manager' ),
	Payment::METHOD_BANK_TRANSFER => __( 'Bank transfer', 'chrx-rental-manager' ),
	Payment::METHOD_OTHER         => __( 'Other', 'chrx-rental-manager' ),
);
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-admin__header">
		<h1><?php esc_html_e( 'Payments', 'chrx-rental-manager' ); ?></h1>
		<a href="<?php echo esc_url( $export_url ); ?>" class="button"><?php esc_html_e( 'Export CSV', 'chrx-rental-manager' ); ?></a>
	</div>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<form method="get">
		<input type="hidden" name="page" value="chrx-rm-payments">
		<div class="chrx-rm-list-toolbar">
			<select name="property_id" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( 'All properties', 'chrx-rental-manager' ); ?></option>
				<?php foreach ( $properties as $property ) : ?>
					<option value="<?php echo esc_attr( (string) $property['id'] ); ?>" <?php selected( $selected_property_id, $property['id'] ); ?>>
						<?php echo esc_html( $property['name'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<select name="method" onchange="this.form.submit()">
				<option value=""><?php esc_html_e( 'Any method', 'chrx-rental-manager' ); ?></option>
				<?php foreach ( $methods as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected_method, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="month" name="month" value="<?php echo esc_attr( $selected_month ); ?>" onchange="this.form.submit()">
			<span class="chrx-rm-list-toolbar__count">
				<?php
				printf(
					/* translators: 1: payment count, 2: total amount */
					esc_html__( '%1$s · %2$s', 'chrx-rental-manager' ),
					esc_html(
						sprintf(
							/* translators: %d: payment count */
							_n( '%d payment', '%d payments', $list_table->filtered_count(), 'chrx-rental-manager' ),
							$list_table->filtered_count()
						)
					),
					esc_html( Money::format( $list_table->filtered_total() ) )
				);
				?>
			</span>
		</div>
		<?php $list_table->display(); ?>
	</form>
</div>

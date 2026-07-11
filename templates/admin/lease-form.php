<?php
/**
 * Add/Edit Lease form (designs/16-add-edit-lease.html).
 *
 * Deviation from the design copy: no "this creates 12 monthly charges…"
 * summary — per SPEC.md §4.2, charges are generated incrementally by the
 * daily cron job, not in bulk here. The summary box instead explains that.
 *
 * On edit, unit/tenant are shown read-only — a lease's unit/tenant
 * identity isn't meant to change after creation; ending the lease and
 * creating a new one is the correct path for that, matching how the
 * no-double-active-lease invariant already treats lease identity.
 *
 * Variables in scope: $action ('add'|'edit'), $lease_id (int),
 * $lease (?array), $vacant_units (array<int,array>),
 * $tenants (array<int,array>), $list_url (string), $notice (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\LeasesController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_edit             = 'edit' === $action;
$billing_days        = array( 1, 5, 15, 28 );
$current_billing_day = (int) ( $lease['billing_day'] ?? 1 );

if ( ! in_array( $current_billing_day, $billing_days, true ) ) {
	$billing_days[] = $current_billing_day;
	sort( $billing_days );
}
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-breadcrumb"><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Leases', 'chrx-rental-manager' ); ?></a> &rsaquo; <?php echo $is_edit ? esc_html__( 'Edit', 'chrx-rental-manager' ) : esc_html__( 'Add new', 'chrx-rental-manager' ); ?></div>
	<h1><?php echo $is_edit ? esc_html__( 'Edit Lease', 'chrx-rental-manager' ) : esc_html__( 'Add Lease', 'chrx-rental-manager' ); ?></h1>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<form method="post" class="chrx-rm-admin__form" style="max-width:760px;">
		<?php wp_nonce_field( LeasesController::nonce_action(), 'rm_lease_nonce' ); ?>
		<input type="hidden" name="lease_id" value="<?php echo esc_attr( (string) $lease_id ); ?>">

		<table class="form-table">
			<tr>
				<th><label for="rm_unit_id"><?php esc_html_e( 'Unit', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<?php if ( $is_edit ) : ?>
						<input type="hidden" name="rm_unit_id" value="<?php echo esc_attr( (string) $lease['unit_id'] ); ?>">
						<p><?php echo esc_html( $lease['unit_label'] ?? '#' . $lease['unit_id'] ); ?></p>
					<?php else : ?>
						<select id="rm_unit_id" name="rm_unit_id" required>
							<option value=""><?php esc_html_e( '— Select —', 'chrx-rental-manager' ); ?></option>
							<?php foreach ( $vacant_units as $unit ) : ?>
								<option value="<?php echo esc_attr( (string) $unit['id'] ); ?>">
									<?php echo esc_html( $unit['unit_label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php if ( array() === $vacant_units ) : ?>
							<p class="description"><?php esc_html_e( 'No vacant units available. Add a unit or end an existing lease first.', 'chrx-rental-manager' ); ?></p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="rm_tenant_id"><?php esc_html_e( 'Tenant', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<?php if ( $is_edit ) : ?>
						<input type="hidden" name="rm_tenant_id" value="<?php echo esc_attr( (string) $lease['tenant_id'] ); ?>">
						<p><?php echo esc_html( $lease['tenant_name'] ?? '#' . $lease['tenant_id'] ); ?></p>
					<?php else : ?>
						<select id="rm_tenant_id" name="rm_tenant_id" required>
							<option value=""><?php esc_html_e( '— Select —', 'chrx-rental-manager' ); ?></option>
							<?php foreach ( $tenants as $tenant ) : ?>
								<option value="<?php echo esc_attr( (string) $tenant['id'] ); ?>">
									<?php echo esc_html( $tenant['full_name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="rm_rent_amount"><?php esc_html_e( 'Monthly rent', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="text" id="rm_rent_amount" name="rm_rent_amount" value="<?php echo esc_attr( (string) ( $lease['rent_amount'] ?? '' ) ); ?>" required></td>
			</tr>
			<tr>
				<th><label for="rm_deposit_amount"><?php esc_html_e( 'Deposit', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="text" id="rm_deposit_amount" name="rm_deposit_amount" value="<?php echo esc_attr( (string) ( $lease['deposit_amount'] ?? '' ) ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Deposit collected?', 'chrx-rental-manager' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="rm_deposit_collected" value="1" <?php checked( 'paid' === ( $lease['deposit_status'] ?? '' ) ); ?>>
						<?php esc_html_e( 'Yes, held', 'chrx-rental-manager' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th><label for="rm_billing_day"><?php esc_html_e( 'Billing day', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<select id="rm_billing_day" name="rm_billing_day">
						<?php foreach ( $billing_days as $day ) : ?>
							<option value="<?php echo esc_attr( (string) $day ); ?>" <?php selected( $current_billing_day, $day ); ?>>
								<?php echo 1 === $day ? esc_html__( '1st of month', 'chrx-rental-manager' ) : esc_html( sprintf( /* translators: %d: day */ __( '%dth', 'chrx-rental-manager' ), $day ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="rm_start_date"><?php esc_html_e( 'Start date', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="date" id="rm_start_date" name="rm_start_date" value="<?php echo esc_attr( $lease['start_date'] ?? '' ); ?>" required></td>
			</tr>
			<tr>
				<th><label for="rm_end_date"><?php esc_html_e( 'End date', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="date" id="rm_end_date" name="rm_end_date" value="<?php echo esc_attr( $lease['end_date'] ?? '' ); ?>" required></td>
			</tr>
		</table>

		<?php if ( ! $is_edit ) : ?>
			<div class="chrx-rm-admin__info-banner">
				<?php esc_html_e( 'Rent charges are generated automatically before each due date, not created here — check the lease after saving to see the ledger fill in.', 'chrx-rental-manager' ); ?>
			</div>
		<?php endif; ?>

		<p class="submit">
			<button type="submit" name="rm_lease_submit" value="1" class="button button-primary">
				<?php echo $is_edit ? esc_html__( 'Save Lease', 'chrx-rental-manager' ) : esc_html__( 'Create Lease', 'chrx-rental-manager' ); ?>
			</button>
			<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'chrx-rental-manager' ); ?></a>
		</p>
	</form>
</div>

<?php
/**
 * Reports (designs/21-reports.html): occupancy / outstanding balances /
 * payment history tabs, each CSV-exportable.
 *
 * Variables in scope: $available_properties (array<int,array>), $tab
 * (string), $selected_property_id (int), $as_of (string, Y-m-d),
 * $export_url (string), plus tab-specific data: $occupancy_rows/
 * $occupancy (occupancy tab), $outstanding_rows/$outstanding/
 * $avg_days_overdue (outstanding tab), $payment_rows/$units/$leases/
 * $tenants (payments tab).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\PaymentsListTable;
use ChrxRentalManager\Admin\ReportsController;
use ChrxRentalManager\Admin\Support\Badge;
use ChrxRentalManager\Admin\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$report_tabs = array(
	'occupancy'   => __( 'Occupancy', 'chrx-rental-manager' ),
	'outstanding' => __( 'Outstanding balances', 'chrx-rental-manager' ),
	'payments'    => __( 'Payment history', 'chrx-rental-manager' ),
);

$page_url = add_query_arg( 'page', ReportsController::page_slug(), admin_url( 'admin.php' ) );
?>
<div class="wrap chrx-rm-admin">
	<h1 style="font-size:23px;font-weight:600;margin:0 0 16px;"><?php esc_html_e( 'Reports', 'chrx-rental-manager' ); ?></h1>

	<div style="display:flex;gap:0;margin-bottom:18px;border-bottom:1px solid #dcdcde;">
		<?php foreach ( $report_tabs as $key => $label ) : ?>
			<a href="<?php echo esc_url( add_query_arg( array( 'tab' => $key ), $page_url ) ); ?>"
				style="padding:9px 16px;font-size:13px;text-decoration:none;<?php echo $tab === $key ? 'font-weight:600;color:#2271b1;border-bottom:2px solid #2271b1;margin-bottom:-1px;' : 'color:#646970;'; ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</div>

	<form method="get" style="display:flex;gap:10px;margin-bottom:16px;align-items:center;flex-wrap:wrap;">
		<input type="hidden" name="page" value="<?php echo esc_attr( ReportsController::page_slug() ); ?>">
		<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
		<select name="property_id" onchange="this.form.submit()">
			<option value="0"><?php esc_html_e( 'All properties', 'chrx-rental-manager' ); ?></option>
			<?php foreach ( $available_properties as $property ) : ?>
				<option value="<?php echo esc_attr( (string) $property['id'] ); ?>" <?php selected( $selected_property_id, $property['id'] ); ?>><?php echo esc_html( $property['name'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<label style="font-size:13px;color:#646970;display:flex;align-items:center;gap:6px;">
			<?php esc_html_e( 'As of', 'chrx-rental-manager' ); ?>
			<input type="date" name="as_of" value="<?php echo esc_attr( $as_of ); ?>" onchange="this.form.submit()">
		</label>
		<a href="<?php echo esc_url( $export_url ); ?>" class="button" style="margin-left:auto;"><?php esc_html_e( 'Export CSV', 'chrx-rental-manager' ); ?></a>
	</form>

	<?php if ( 'occupancy' === $tab ) : ?>

		<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px;">
			<div class="chrx-rm-stat-card">
				<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Occupancy rate', 'chrx-rental-manager' ); ?></div>
				<div class="chrx-rm-stat-card__value"><?php echo esc_html( (string) $occupancy['rate'] ); ?>%</div>
			</div>
			<div class="chrx-rm-stat-card">
				<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Occupied units', 'chrx-rental-manager' ); ?></div>
				<div class="chrx-rm-stat-card__value"><?php echo esc_html( (string) $occupancy['occupied'] ); ?></div>
			</div>
			<div class="chrx-rm-stat-card">
				<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Total units', 'chrx-rental-manager' ); ?></div>
				<div class="chrx-rm-stat-card__value"><?php echo esc_html( (string) $occupancy['total'] ); ?></div>
			</div>
		</div>

		<div class="chrx-rm-panel">
			<?php if ( array() === $occupancy_rows ) : ?>
				<div class="chrx-rm-panel__body"><p style="color:#8c8f94;font-size:13px;margin:0;"><?php esc_html_e( 'No properties to report on.', 'chrx-rental-manager' ); ?></p></div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Property', 'chrx-rental-manager' ); ?></th>
							<th style="text-align:right;"><?php esc_html_e( 'Occupied', 'chrx-rental-manager' ); ?></th>
							<th style="text-align:right;"><?php esc_html_e( 'Total units', 'chrx-rental-manager' ); ?></th>
							<th style="text-align:right;"><?php esc_html_e( 'Occupancy rate', 'chrx-rental-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $occupancy_rows as $row ) : ?>
							<?php $rate = $row['total'] > 0 ? round( $row['occupied'] / $row['total'] * 100 ) : 0; ?>
							<tr>
								<td style="font-weight:600;"><?php echo esc_html( $row['property']['name'] ); ?></td>
								<td style="text-align:right;"><?php echo esc_html( (string) $row['occupied'] ); ?></td>
								<td style="text-align:right;"><?php echo esc_html( (string) $row['total'] ); ?></td>
								<td style="text-align:right;"><?php echo esc_html( $rate . '%' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

	<?php elseif ( 'outstanding' === $tab ) : ?>

		<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px;">
			<div class="chrx-rm-stat-card">
				<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Total outstanding', 'chrx-rental-manager' ); ?></div>
				<div class="chrx-rm-stat-card__value" style="color:#b32d2e;"><?php echo esc_html( Money::format( (float) $outstanding['total'] ) ); ?></div>
			</div>
			<div class="chrx-rm-stat-card">
				<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Overdue accounts', 'chrx-rental-manager' ); ?></div>
				<div class="chrx-rm-stat-card__value"><?php echo esc_html( (string) $outstanding['overdue_count'] ); ?></div>
			</div>
			<div class="chrx-rm-stat-card">
				<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Avg. days overdue', 'chrx-rental-manager' ); ?></div>
				<div class="chrx-rm-stat-card__value"><?php echo esc_html( (string) $avg_days_overdue ); ?></div>
			</div>
		</div>

		<div class="chrx-rm-panel">
			<?php if ( array() === $outstanding_rows ) : ?>
				<div class="chrx-rm-panel__body"><p style="color:#8c8f94;font-size:13px;margin:0;"><?php esc_html_e( 'No outstanding balances as of this date.', 'chrx-rental-manager' ); ?></p></div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Tenant', 'chrx-rental-manager' ); ?></th>
							<th><?php esc_html_e( 'Unit', 'chrx-rental-manager' ); ?></th>
							<th style="text-align:right;"><?php esc_html_e( 'Balance', 'chrx-rental-manager' ); ?></th>
							<th style="text-align:right;"><?php esc_html_e( 'Days overdue', 'chrx-rental-manager' ); ?></th>
							<th><?php esc_html_e( 'Status', 'chrx-rental-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $outstanding_rows as $row ) : ?>
							<tr>
								<td style="font-weight:600;"><?php echo esc_html( null === $row['tenant'] ? '' : $row['tenant']['full_name'] ); ?></td>
								<td><?php echo esc_html( ( null === $row['unit'] ? '' : $row['unit']['unit_label'] ) . ( null === $row['property'] ? '' : ' · ' . $row['property']['name'] ) ); ?></td>
								<td style="text-align:right;font-weight:600;"><?php echo esc_html( Money::format( (float) $row['balance'] ) ); ?></td>
								<td style="text-align:right;"><?php echo esc_html( (string) $row['days_overdue'] ); ?></td>
								<td><?php echo wp_kses_post( Badge::render( $row['status'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

	<?php else : ?>

		<div class="chrx-rm-panel">
			<?php if ( array() === $payment_rows ) : ?>
				<div class="chrx-rm-panel__body"><p style="color:#8c8f94;font-size:13px;margin:0;"><?php esc_html_e( 'No payments recorded as of this date.', 'chrx-rental-manager' ); ?></p></div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'chrx-rental-manager' ); ?></th>
							<th><?php esc_html_e( 'Tenant', 'chrx-rental-manager' ); ?></th>
							<th><?php esc_html_e( 'Unit', 'chrx-rental-manager' ); ?></th>
							<th><?php esc_html_e( 'Method', 'chrx-rental-manager' ); ?></th>
							<th style="text-align:right;"><?php esc_html_e( 'Amount', 'chrx-rental-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $payment_rows as $row ) : ?>
							<?php
							$lease  = $leases->find( (int) $row['lease_id'] );
							$unit   = null !== $lease ? $units->find( (int) $lease['unit_id'] ) : null;
							$tenant = null !== $lease ? $tenants->find( (int) $lease['tenant_id'] ) : null;
							?>
							<tr>
								<td><?php echo esc_html( gmdate( 'd M Y', strtotime( $row['paid_at'] ) ) ); ?></td>
								<td style="font-weight:600;"><?php echo esc_html( null === $tenant ? '' : $tenant['full_name'] ); ?></td>
								<td><?php echo esc_html( null === $unit ? '' : $unit['unit_label'] ); ?></td>
								<td><?php echo esc_html( PaymentsListTable::method_label( $row['method'] ) ); ?></td>
								<td style="text-align:right;font-weight:600;"><?php echo esc_html( Money::format( (float) $row['amount'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

	<?php endif; ?>
</div>

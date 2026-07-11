<?php
/**
 * Shared dashboard (designs/04-dashboard.html, designs/27-landlord-dashboard.html).
 *
 * Variables in scope: $is_landlord_view (bool), $available_properties
 * (array<int,array>), $selected_property_id (int), $occupancy
 * (array{occupied,total,rate}), $outstanding
 * (array{total,lease_count,overdue_count}), $collected
 * (array{total,count}), $expiring (array<int,array> lease rows),
 * $recent (array<int,array> payment rows).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\Support\Badge;
use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$units    = new Unit();
$tenants  = new Tenant();
$leases   = new Lease();
$charges  = new Charge();
$payments = new Payment();
?>
<div class="wrap chrx-rm-admin">
	<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:12px;">
		<h1 style="font-size:23px;font-weight:600;margin:0;"><?php echo esc_html( $is_landlord_view ? __( 'My properties', 'chrx-rental-manager' ) : __( 'Dashboard', 'chrx-rental-manager' ) ); ?></h1>
		<div style="display:flex;gap:10px;align-items:center;">
			<form method="get">
				<input type="hidden" name="page" value="chrx-rental-manager">
				<select name="property_id" onchange="this.form.submit()">
					<option value="0"><?php echo esc_html( $is_landlord_view ? __( 'All my properties', 'chrx-rental-manager' ) : __( 'All properties', 'chrx-rental-manager' ) ); ?></option>
					<?php foreach ( $available_properties as $property ) : ?>
						<option value="<?php echo esc_attr( (string) $property['id'] ); ?>" <?php selected( $selected_property_id, $property['id'] ); ?>><?php echo esc_html( $property['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>
			<?php if ( ! $is_landlord_view ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'page', 'chrx-rm-leases', admin_url( 'admin.php' ) ) ); ?>" class="button button-primary"><?php esc_html_e( 'Record Payment', 'chrx-rental-manager' ); ?></a>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $is_landlord_view ) : ?>
		<div style="font-size:13px;color:#8a6116;background:#fbf0dd;border:1px solid #ecd9ad;border-radius:6px;padding:9px 14px;margin-bottom:20px;">
			<?php esc_html_e( 'Viewing as Landlord-Owner — figures cover only the properties assigned to you. This view is read-only.', 'chrx-rental-manager' ); ?>
		</div>
	<?php endif; ?>

	<div class="chrx-rm-stat-grid" style="grid-template-columns:repeat(4,1fr);">
		<div class="chrx-rm-stat-card">
			<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Occupancy rate', 'chrx-rental-manager' ); ?></div>
			<div class="chrx-rm-stat-card__value"><?php echo esc_html( (string) $occupancy['rate'] ); ?>%</div>
			<div style="font-size:12px;color:#646970;margin-top:6px;">
				<?php
				printf(
					/* translators: 1: occupied units, 2: total units */
					esc_html__( '%1$d of %2$d units occupied', 'chrx-rental-manager' ),
					(int) $occupancy['occupied'],
					(int) $occupancy['total']
				);
				?>
			</div>
		</div>
		<div class="chrx-rm-stat-card">
			<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Outstanding balance', 'chrx-rental-manager' ); ?></div>
			<div class="chrx-rm-stat-card__value" style="color:#b32d2e;"><?php echo esc_html( Money::format( (float) $outstanding['total'] ) ); ?></div>
			<div style="font-size:12px;color:#646970;margin-top:6px;">
				<?php
				printf(
					/* translators: 1: lease count, 2: overdue count */
					esc_html__( 'across %1$d leases · %2$d overdue', 'chrx-rental-manager' ),
					(int) $outstanding['lease_count'],
					(int) $outstanding['overdue_count']
				);
				?>
			</div>
		</div>
		<div class="chrx-rm-stat-card">
			<div class="chrx-rm-stat-card__label"><?php echo esc_html( sprintf( /* translators: %s: current month */ __( 'Collected · %s', 'chrx-rental-manager' ), gmdate( 'M Y', strtotime( current_time( 'Y-m-d' ) ) ) ) ); ?></div>
			<div class="chrx-rm-stat-card__value" style="color:#0a7d34;"><?php echo esc_html( Money::format( (float) $collected['total'] ) ); ?></div>
			<div style="font-size:12px;color:#646970;margin-top:6px;">
				<?php echo esc_html( sprintf( /* translators: %d: payment count */ _n( 'from %d payment', 'from %d payments', (int) $collected['count'], 'chrx-rental-manager' ), (int) $collected['count'] ) ); ?>
			</div>
		</div>
		<div class="chrx-rm-stat-card">
			<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Expiring soon', 'chrx-rental-manager' ); ?></div>
			<div class="chrx-rm-stat-card__value"><?php echo esc_html( (string) count( $expiring ) ); ?></div>
			<div style="font-size:12px;color:#646970;margin-top:6px;"><?php esc_html_e( 'leases end in next 30 days', 'chrx-rental-manager' ); ?></div>
		</div>
	</div>

	<div style="display:grid;grid-template-columns:1.55fr 1fr;gap:18px;">
		<div class="chrx-rm-panel">
			<div class="chrx-rm-panel__header">
				<span><?php echo esc_html( $is_landlord_view ? __( 'Recent payments · my properties', 'chrx-rental-manager' ) : __( 'Recent payments', 'chrx-rental-manager' ) ); ?></span>
				<?php if ( ! $is_landlord_view ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'page', 'chrx-rm-payments', admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'View all', 'chrx-rental-manager' ); ?></a>
				<?php endif; ?>
			</div>
			<?php if ( array() === $recent ) : ?>
				<div class="chrx-rm-panel__body"><p style="color:#8c8f94;font-size:13px;margin:0;"><?php esc_html_e( 'No payments recorded yet.', 'chrx-rental-manager' ); ?></p></div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Tenant', 'chrx-rental-manager' ); ?></th>
							<th><?php esc_html_e( 'Unit', 'chrx-rental-manager' ); ?></th>
							<th><?php esc_html_e( 'Method', 'chrx-rental-manager' ); ?></th>
							<th style="text-align:right;"><?php esc_html_e( 'Amount', 'chrx-rental-manager' ); ?></th>
							<th><?php esc_html_e( 'Status', 'chrx-rental-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent as $payment ) : ?>
							<?php
							$lease         = $leases->find( (int) $payment['lease_id'] );
							$tenant        = null !== $lease ? $tenants->find( (int) $lease['tenant_id'] ) : null;
							$unit          = null !== $lease ? $units->find( (int) $lease['unit_id'] ) : null;
							$charge        = null !== $payment['charge_id'] ? $charges->find( (int) $payment['charge_id'] ) : null;
							$charge_status = null !== $charge ? $charge['status'] : 'unallocated';
							?>
							<tr>
								<td style="font-weight:600;"><?php echo esc_html( null === $tenant ? '' : $tenant['full_name'] ); ?></td>
								<td><?php echo esc_html( null === $unit ? '' : $unit['unit_label'] ); ?></td>
								<td><?php echo esc_html( \ChrxRentalManager\Admin\PaymentsListTable::method_label( $payment['method'] ) ); ?></td>
								<td style="text-align:right;font-weight:600;"><?php echo esc_html( Money::format( (float) $payment['amount'] ) ); ?></td>
								<td>
									<?php if ( 'unallocated' === $charge_status ) : ?>
										<?php echo wp_kses_post( Badge::render( 'reserved', __( 'Credit', 'chrx-rental-manager' ) ) ); ?>
									<?php else : ?>
										<?php echo wp_kses_post( Badge::render( $charge_status ) ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="chrx-rm-panel">
			<div class="chrx-rm-panel__header"><span><?php esc_html_e( 'Upcoming lease expirations', 'chrx-rental-manager' ); ?></span></div>
			<?php if ( array() === $expiring ) : ?>
				<div class="chrx-rm-panel__body"><p style="color:#8c8f94;font-size:13px;margin:0;"><?php esc_html_e( 'No leases expiring in the next 30 days.', 'chrx-rental-manager' ); ?></p></div>
			<?php else : ?>
				<div style="padding:6px 0;">
					<?php foreach ( $expiring as $i => $lease ) : ?>
						<?php
						$tenant    = $tenants->find( (int) $lease['tenant_id'] );
						$unit      = $units->find( (int) $lease['unit_id'] );
						$property  = null !== $unit ? ( new \ChrxRentalManager\Data\Property() )->find( (int) $unit['property_id'] ) : null;
						$days_left = (int) ceil( ( strtotime( $lease['end_date'] ) - time() ) / DAY_IN_SECONDS );
						$badge_key = $days_left <= 7 ? 'overdue' : ( $days_left <= 21 ? 'maintenance' : 'renewed' );
						$border    = count( $expiring ) - 1 === $i ? '' : 'border-bottom:1px solid #f0f0f1;';
						?>
						<div style="display:flex;justify-content:space-between;align-items:center;padding:11px 16px;<?php echo esc_attr( $border ); ?>">
							<div>
								<div style="font-size:13px;font-weight:600;"><?php echo esc_html( null === $tenant ? '' : $tenant['full_name'] ); ?></div>
								<div style="font-size:12px;color:#646970;"><?php echo esc_html( ( null === $unit ? '' : $unit['unit_label'] ) . ( null === $property ? '' : ' · ' . $property['name'] ) ); ?></div>
							</div>
							<?php echo wp_kses_post( Badge::render( $badge_key, sprintf( /* translators: %d: days */ _n( '%d day', '%d days', $days_left, 'chrx-rental-manager' ), $days_left ) ) ); ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>

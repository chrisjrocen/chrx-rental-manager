<?php
/**
 * Move-out / termination (designs/23-move-out-termination.html).
 * Variables in scope: $lease (array), $unit (array), $tenant (?array),
 * $deposit_held (float), $outstanding_rent (float), $list_url (string),
 * $notice (?string).
 * v2 (SPEC.md §4.10): $active_notice (?array), $notice_shortfall_preview
 * (float — computed against today's date, informational only).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\LeaseMoveOutController;
use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Data\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tenant_name = null === $tenant ? '' : $tenant['full_name'];
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-breadcrumb">
		<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Leases', 'chrx-rental-manager' ); ?></a> &rsaquo;
		<?php echo esc_html( $tenant_name . ' · ' . $unit['unit_label'] ); ?> &rsaquo; <?php esc_html_e( 'Move-out', 'chrx-rental-manager' ); ?>
	</div>
	<h1 style="font-size:23px;font-weight:600;margin:0 0 18px;">
		<?php echo esc_html( sprintf( /* translators: 1: tenant name, 2: unit label */ __( 'Move-out — %1$s, Unit %2$s', 'chrx-rental-manager' ), $tenant_name, $unit['unit_label'] ) ); ?>
	</h1>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="rm_lease_move_out">
		<input type="hidden" name="lease_id" value="<?php echo esc_attr( (string) $lease['id'] ); ?>">
		<?php wp_nonce_field( LeaseMoveOutController::nonce_action() ); ?>

		<?php if ( null !== $active_notice ) : ?>
			<div class="chrx-rm-admin__info-banner" style="margin-bottom:16px;">
				<?php
				printf(
					/* translators: 1: notice date, 2: earliest move-out date */
					esc_html__( 'Active move-out notice on file: given %1$s, earliest move-out date %2$s.', 'chrx-rental-manager' ),
					esc_html( gmdate( 'j M Y', strtotime( $active_notice['notice_date'] ) ) ),
					esc_html( gmdate( 'j M Y', strtotime( $active_notice['earliest_move_out_date'] ) ) )
				);
				?>
				<?php if ( $notice_shortfall_preview > 0 ) : ?>
					<br>
					<?php
					printf(
						/* translators: %s: shortfall amount */
						esc_html__( 'Leaving before that date (as of today) would add a %s rent shortfall — recalculated against whatever move-out date is actually submitted below.', 'chrx-rental-manager' ),
						esc_html( \ChrxRentalManager\Admin\Support\Money::format( $notice_shortfall_preview ) )
					);
					?>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div style="display:grid;grid-template-columns:1fr 380px;gap:22px;">
			<div class="chrx-rm-panel">
				<div class="chrx-rm-panel__body">
					<table class="form-table">
						<tr>
							<th><label for="rm_move_out_date"><?php esc_html_e( 'Move-out date', 'chrx-rental-manager' ); ?></label></th>
							<td><input type="date" id="rm_move_out_date" name="rm_move_out_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required></td>
						</tr>
						<tr>
							<th><label for="rm_unit_status"><?php esc_html_e( 'Set unit status to', 'chrx-rental-manager' ); ?></label></th>
							<td>
								<select id="rm_unit_status" name="rm_unit_status">
									<option value="<?php echo esc_attr( Unit::STATUS_VACANT ); ?>"><?php esc_html_e( 'Vacant', 'chrx-rental-manager' ); ?></option>
									<option value="<?php echo esc_attr( Unit::STATUS_MAINTENANCE ); ?>"><?php esc_html_e( 'Under Maintenance', 'chrx-rental-manager' ); ?></option>
									<option value="<?php echo esc_attr( Unit::STATUS_BOOKED ); ?>"><?php esc_html_e( 'Booked', 'chrx-rental-manager' ); ?></option>
								</select>
							</td>
						</tr>
					</table>

					<div style="font-weight:700;font-size:14px;margin:20px 0 12px;"><?php esc_html_e( 'Deposit settlement', 'chrx-rental-manager' ); ?></div>
					<div style="border:1px solid #dcdcde;border-radius:6px;overflow:hidden;">
						<div style="display:flex;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #f0f0f1;font-size:13px;">
							<span><?php esc_html_e( 'Deposit held', 'chrx-rental-manager' ); ?></span>
							<span style="font-weight:600;" id="rm-deposit-held" data-value="<?php echo esc_attr( (string) $deposit_held ); ?>"><?php echo esc_html( Money::format( $deposit_held ) ); ?></span>
						</div>
						<div style="display:flex;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #f0f0f1;font-size:13px;">
							<span><?php esc_html_e( 'Outstanding balance', 'chrx-rental-manager' ); ?></span>
							<span style="color:#b32d2e;font-weight:600;" id="rm-outstanding" data-value="<?php echo esc_attr( (string) $outstanding_rent ); ?>">– <?php echo esc_html( Money::format( $outstanding_rent ) ); ?></span>
						</div>
						<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #f0f0f1;font-size:13px;">
							<span><?php esc_html_e( 'Deductions (cleaning, damages)', 'chrx-rental-manager' ); ?></span>
							<input type="text" id="rm_deductions" name="rm_deductions" value="0" style="width:90px;text-align:right;">
						</div>
						<div style="display:flex;justify-content:space-between;padding:12px 14px;background:#f6f7f7;font-size:14px;font-weight:800;">
							<span><?php esc_html_e( 'Refund to tenant', 'chrx-rental-manager' ); ?></span>
							<span id="rm-refund" style="color:#0a7d34;"><?php echo esc_html( Money::format( max( 0, $deposit_held - $outstanding_rent ) ) ); ?></span>
						</div>
					</div>

					<?php if ( null !== $active_notice ) : ?>
						<div style="margin-top:20px;background:#fbf0dd;border:1px solid #ecd9ad;border-radius:6px;padding:12px 14px;">
							<label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;font-weight:600;margin-bottom:8px;">
								<input type="checkbox" id="rm_waive_shortfall" name="rm_waive_shortfall" value="1" style="margin-top:2px;">
								<?php esc_html_e( 'Waive the notice-period rent shortfall (if the move-out date is before the earliest move-out date)', 'chrx-rental-manager' ); ?>
							</label>
							<input type="text" id="rm_waiver_reason" name="rm_waiver_reason" placeholder="<?php esc_attr_e( 'Reason for waiving (required if waiving)', 'chrx-rental-manager' ); ?>" style="width:100%;box-sizing:border-box;">
						</div>
					<?php endif; ?>

					<div style="margin-top:20px;">
						<label for="rm_refund_method" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Refund method', 'chrx-rental-manager' ); ?></label>
						<select id="rm_refund_method" name="rm_refund_method" style="width:260px;">
							<option value="mtn_momo"><?php esc_html_e( 'MTN Mobile Money', 'chrx-rental-manager' ); ?></option>
							<option value="bank_transfer"><?php esc_html_e( 'Bank transfer', 'chrx-rental-manager' ); ?></option>
							<option value="cash"><?php esc_html_e( 'Cash', 'chrx-rental-manager' ); ?></option>
						</select>
					</div>
				</div>
			</div>

			<div style="display:flex;flex-direction:column;gap:14px;">
				<div style="background:#fdf5f5;border:1px solid #f0c9c9;border-radius:6px;padding:16px;font-size:13px;color:#b32d2e;">
					<?php esc_html_e( 'Terminating ends the lease and closes its ledger. This cannot be undone.', 'chrx-rental-manager' ); ?>
				</div>
				<button type="submit" style="background:#b32d2e;color:#fff;border:none;border-radius:4px;padding:11px;font-size:14px;font-weight:700;cursor:pointer;">
					<?php esc_html_e( 'Complete move-out', 'chrx-rental-manager' ); ?>
				</button>
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'page' => 'chrx-rm-leases',
							'id'   => $lease['id'],
						),
						admin_url( 'admin.php' )
					)
				);
				?>
				" class="button"><?php esc_html_e( 'Cancel', 'chrx-rental-manager' ); ?></a>
			</div>
		</div>
	</form>
</div>
<script>
(function () {
	var deductionsInput = document.getElementById( 'rm_deductions' );
	var refundEl = document.getElementById( 'rm-refund' );
	var held = parseFloat( document.getElementById( 'rm-deposit-held' ).dataset.value ) || 0;
	var outstanding = parseFloat( document.getElementById( 'rm-outstanding' ).dataset.value ) || 0;

	function update() {
		var deductions = parseFloat( deductionsInput.value.replace( /,/g, '' ) ) || 0;
		var refund = held - outstanding - deductions;

		refundEl.textContent = ( refund < 0 ? '– ' : '' ) + Math.abs( refund ).toLocaleString();
		refundEl.style.color = refund < 0 ? '#b32d2e' : '#0a7d34';
	}

	deductionsInput.addEventListener( 'input', update );
})();
</script>

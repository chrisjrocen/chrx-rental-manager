<?php
/**
 * Record Payment (designs/18-record-payment-modal.html — built as its own
 * admin screen rather than a literal modal, same deviation already used
 * by the Renew/Move-out screens).
 *
 * Variables in scope: $lease (array), $unit (array), $tenant (?array),
 * $open_charges (array<int,array> with 'outstanding' added),
 * $preselected_charge_id (int), $is_closing_out (bool), $list_url is not
 * set here — use LeasesController's page slug directly — $notice (?string).
 * v2 (SPEC.md §4.9): $nylonpay_available (bool).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\LeasesController;
use ChrxRentalManager\Admin\RecordPaymentController;
use ChrxRentalManager\Admin\SendPaymentRequestController;
use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tenant_name      = null === $tenant ? '' : $tenant['full_name'];
$lease_detail_url = add_query_arg(
	array(
		'page' => LeasesController::page_slug(),
		'id'   => $lease['id'],
	),
	admin_url( 'admin.php' )
);
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-breadcrumb">
		<a href="<?php echo esc_url( add_query_arg( 'page', LeasesController::page_slug(), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Leases', 'chrx-rental-manager' ); ?></a> &rsaquo;
		<a href="<?php echo esc_url( $lease_detail_url ); ?>"><?php echo esc_html( $tenant_name . ' · ' . $unit['unit_label'] ); ?></a> &rsaquo; <?php esc_html_e( 'Record Payment', 'chrx-rental-manager' ); ?>
	</div>
	<h1 style="font-size:23px;font-weight:600;margin:0 0 6px;">
		<?php esc_html_e( 'Record Payment', 'chrx-rental-manager' ); ?>
	</h1>

	<?php if ( $is_closing_out ) : ?>
		<div style="background:#fbf0dd;border:1px solid #ecd9ad;border-radius:6px;padding:10px 14px;font-size:13px;color:#8a6116;font-weight:600;margin:0 0 16px;max-width:460px;">
			<?php esc_html_e( 'This lease has ended — this is a closing-out settlement, not current activity.', 'chrx-rental-manager' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:460px;">
		<input type="hidden" name="action" value="rm_record_payment">
		<input type="hidden" name="lease_id" value="<?php echo esc_attr( (string) $lease['id'] ); ?>">
		<?php wp_nonce_field( RecordPaymentController::nonce_action() ); ?>

		<div class="chrx-rm-panel">
			<div class="chrx-rm-panel__body">
				<label for="rm_charge_id" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Apply to', 'chrx-rental-manager' ); ?></label>
				<select id="rm_charge_id" name="rm_charge_id" style="width:100%;box-sizing:border-box;margin-bottom:16px;">
					<option value="0"><?php esc_html_e( 'No specific charge — advance / unallocated payment', 'chrx-rental-manager' ); ?></option>
					<?php foreach ( $open_charges as $charge ) : ?>
						<option value="<?php echo esc_attr( (string) $charge['id'] ); ?>" data-outstanding="<?php echo esc_attr( (string) $charge['outstanding'] ); ?>" <?php selected( $preselected_charge_id, (int) $charge['id'] ); ?>>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: charge type/period, 2: outstanding amount */
									__( '%1$s — outstanding %2$s', 'chrx-rental-manager' ),
									( Charge::TYPE_LATE_FEE === $charge['type'] ? __( 'Late fee', 'chrx-rental-manager' ) : __( 'Rent', 'chrx-rental-manager' ) ) . ' ' . gmdate( 'M Y', strtotime( $charge['period_start'] ) ),
									Money::format( (float) $charge['outstanding'] )
								)
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>

				<div id="rm-outstanding-box" style="background:#f6f7f7;border-radius:6px;padding:12px 14px;margin-bottom:18px;display:none;justify-content:space-between;font-size:13px;">
					<span style="color:#646970;"><?php esc_html_e( 'Outstanding for selected charge', 'chrx-rental-manager' ); ?></span>
					<span id="rm-outstanding-value" style="font-weight:700;"></span>
				</div>

				<label for="rm_amount" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Amount received', 'chrx-rental-manager' ); ?></label>
				<input type="text" id="rm_amount" name="rm_amount" value="" style="width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #8c8f94;border-radius:4px;font-size:15px;margin-bottom:8px;" required>
				<div id="rm-partial-note" style="background:#fbf0dd;border:1px solid #ecd9ad;border-radius:6px;padding:9px 12px;font-size:12px;color:#8a6116;font-weight:600;margin-bottom:8px;display:none;"></div>
				<div id="rm-overpay-note" style="background:#e5f5eb;border:1px solid #b6e0c2;border-radius:6px;padding:9px 12px;font-size:12px;color:#0a7d34;font-weight:600;margin-bottom:8px;display:none;"></div>

				<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:16px 0;">
					<div>
						<label for="rm_method" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Method', 'chrx-rental-manager' ); ?></label>
						<select id="rm_method" name="rm_method" style="width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;">
							<option value="<?php echo esc_attr( Payment::METHOD_MTN_MOMO ); ?>"><?php esc_html_e( 'MTN Mobile Money', 'chrx-rental-manager' ); ?></option>
							<option value="<?php echo esc_attr( Payment::METHOD_AIRTEL_MONEY ); ?>"><?php esc_html_e( 'Airtel Money', 'chrx-rental-manager' ); ?></option>
							<option value="<?php echo esc_attr( Payment::METHOD_CASH ); ?>"><?php esc_html_e( 'Cash', 'chrx-rental-manager' ); ?></option>
							<option value="<?php echo esc_attr( Payment::METHOD_BANK_TRANSFER ); ?>"><?php esc_html_e( 'Bank transfer', 'chrx-rental-manager' ); ?></option>
							<option value="<?php echo esc_attr( Payment::METHOD_OTHER ); ?>"><?php esc_html_e( 'Other', 'chrx-rental-manager' ); ?></option>
						</select>
					</div>
					<div>
						<label for="rm_paid_date" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Date', 'chrx-rental-manager' ); ?></label>
						<input type="date" id="rm_paid_date" name="rm_paid_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" style="width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;" required>
					</div>
				</div>

				<label for="rm_note" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Note', 'chrx-rental-manager' ); ?> <span style="color:#8c8f94;font-weight:400;">(<?php esc_html_e( 'optional', 'chrx-rental-manager' ); ?>)</span></label>
				<input type="text" id="rm_note" name="rm_note" value="" style="width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;margin-bottom:20px;">

				<div style="display:flex;gap:10px;justify-content:flex-end;">
					<a href="<?php echo esc_url( $lease_detail_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'chrx-rental-manager' ); ?></a>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save & generate receipt', 'chrx-rental-manager' ); ?></button>
				</div>
			</div>
		</div>
	</form>

	<?php if ( $nylonpay_available && array() !== $open_charges ) : ?>
		<div class="chrx-rm-panel" style="max-width:460px;margin-top:20px;">
			<div class="chrx-rm-panel__header"><span><?php esc_html_e( 'Or send a Nylon Pay request', 'chrx-rental-manager' ); ?></span></div>
			<div class="chrx-rm-panel__body">
				<p class="description" style="margin-top:0;"><?php esc_html_e( 'The tenant gets a mobile-money prompt on their phone; the payment records itself automatically once confirmed.', 'chrx-rental-manager' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="rm_send_payment_request">
					<input type="hidden" name="lease_id" value="<?php echo esc_attr( (string) $lease['id'] ); ?>">
					<?php wp_nonce_field( SendPaymentRequestController::nonce_action() ); ?>

					<label for="rm_gw_charge_id" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Charge', 'chrx-rental-manager' ); ?></label>
					<select id="rm_gw_charge_id" name="rm_charge_id" style="width:100%;box-sizing:border-box;margin-bottom:12px;" required>
						<?php foreach ( $open_charges as $charge ) : ?>
							<option value="<?php echo esc_attr( (string) $charge['id'] ); ?>" data-outstanding="<?php echo esc_attr( (string) $charge['outstanding'] ); ?>">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: charge type/period, 2: outstanding amount */
										__( '%1$s — outstanding %2$s', 'chrx-rental-manager' ),
										( Charge::TYPE_LATE_FEE === $charge['type'] ? __( 'Late fee', 'chrx-rental-manager' ) : __( 'Rent', 'chrx-rental-manager' ) ) . ' ' . gmdate( 'M Y', strtotime( $charge['period_start'] ) ),
										Money::format( (float) $charge['outstanding'] )
									)
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>

					<label for="rm_gw_phone" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Phone to charge', 'chrx-rental-manager' ); ?></label>
					<input type="text" id="rm_gw_phone" name="rm_phone" value="<?php echo esc_attr( (string) ( $tenant['phone'] ?? '' ) ); ?>" style="width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;margin-bottom:12px;" required>

					<label for="rm_gw_amount" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Amount', 'chrx-rental-manager' ); ?></label>
					<input type="text" id="rm_gw_amount" name="rm_amount" value="<?php echo esc_attr( (string) ( $open_charges[0]['outstanding'] ?? '' ) ); ?>" style="width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;margin-bottom:16px;" required>

					<button type="submit" class="button"><?php esc_html_e( 'Send Nylon Pay request', 'chrx-rental-manager' ); ?></button>
				</form>
			</div>
		</div>
	<?php endif; ?>
</div>
<script>
(function () {
	var select = document.getElementById( 'rm_charge_id' );
	var amountInput = document.getElementById( 'rm_amount' );
	var outstandingBox = document.getElementById( 'rm-outstanding-box' );
	var outstandingValue = document.getElementById( 'rm-outstanding-value' );
	var partialNote = document.getElementById( 'rm-partial-note' );
	var overpayNote = document.getElementById( 'rm-overpay-note' );

	function currentOutstanding() {
		var option = select.options[ select.selectedIndex ];
		return option && option.dataset.outstanding ? parseFloat( option.dataset.outstanding ) : null;
	}

	function update() {
		var outstanding = currentOutstanding();
		var amount = parseFloat( amountInput.value.replace( /,/g, '' ) ) || 0;

		partialNote.style.display = 'none';
		overpayNote.style.display = 'none';

		if ( null === outstanding ) {
			outstandingBox.style.display = 'none';
			return;
		}

		outstandingBox.style.display = 'flex';
		outstandingValue.textContent = outstanding.toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );

		if ( amount > 0 && amount < outstanding ) {
			partialNote.style.display = 'block';
			partialNote.textContent = <?php echo wp_json_encode( __( 'Partial payment — the remainder will stay outstanding. Charge stays "Partial".', 'chrx-rental-manager' ) ); ?> + ' (' + ( outstanding - amount ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) + ')';
		} else if ( amount > outstanding ) {
			overpayNote.style.display = 'block';
			overpayNote.textContent = <?php echo wp_json_encode( __( 'Overpayment — the excess will be held as credit and auto-applied to the next charge.', 'chrx-rental-manager' ) ); ?> + ' (' + ( amount - outstanding ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) + ')';
		}
	}

	select.addEventListener( 'change', update );
	amountInput.addEventListener( 'input', update );
	update();

	<?php if ( $preselected_charge_id > 0 ) : ?>
	select.value = <?php echo wp_json_encode( (string) $preselected_charge_id ); ?>;
	update();
	<?php endif; ?>
})();
</script>

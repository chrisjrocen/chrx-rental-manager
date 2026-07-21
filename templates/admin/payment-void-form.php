<?php
/**
 * Void payment confirmation form.
 * Variables in scope: $payment (array), $lease (?array), $unit (?array),
 * $tenant (?array), $list_url (string), $notice (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tenant_name = null === $tenant ? '' : $tenant['full_name'];
$unit_label  = null === $unit ? '' : $unit['unit_label'];
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-breadcrumb">
		<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Payments', 'chrx-rental-manager' ); ?></a> &rsaquo;
		<?php esc_html_e( 'Void payment', 'chrx-rental-manager' ); ?>
	</div>
	<h1 style="font-size:23px;font-weight:600;margin:0 0 18px;"><?php esc_html_e( 'Void payment', 'chrx-rental-manager' ); ?></h1>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<div class="chrx-rm-panel" style="max-width:520px;">
		<div class="chrx-rm-panel__body">
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Tenant', 'chrx-rental-manager' ); ?></th>
					<td><?php echo esc_html( $tenant_name ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Unit', 'chrx-rental-manager' ); ?></th>
					<td><?php echo esc_html( $unit_label ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Amount', 'chrx-rental-manager' ); ?></th>
					<td><?php echo esc_html( Money::format( (float) $payment['amount'] ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Date', 'chrx-rental-manager' ); ?></th>
					<td><?php echo esc_html( gmdate( 'j M Y', strtotime( $payment['paid_at'] ) ) ); ?></td>
				</tr>
			</table>

			<div style="background:#fdf5f5;border:1px solid #f0c9c9;border-radius:6px;padding:12px 14px;font-size:13px;color:#b32d2e;margin-bottom:16px;">
				<?php esc_html_e( 'Voiding removes this payment from the balance calculation but keeps it visible in payment history for audit purposes. This cannot be undone.', 'chrx-rental-manager' ); ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rm_void_payment">
				<input type="hidden" name="payment_id" value="<?php echo esc_attr( (string) $payment['id'] ); ?>">
				<?php wp_nonce_field( 'rm_void_payment' ); ?>

				<label for="rm_void_reason" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Reason for voiding', 'chrx-rental-manager' ); ?></label>
				<textarea id="rm_void_reason" name="rm_void_reason" rows="3" style="width:100%;" required></textarea>

				<div style="margin-top:16px;display:flex;gap:10px;">
					<button type="submit" class="button" style="background:#b32d2e;color:#fff;border-color:#b32d2e;"><?php esc_html_e( 'Void payment', 'chrx-rental-manager' ); ?></button>
					<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'chrx-rental-manager' ); ?></a>
				</div>
			</form>
		</div>
	</div>
</div>

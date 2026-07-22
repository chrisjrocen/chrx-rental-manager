<?php
/**
 * Renew Lease (designs/17-renew-lease.html): pre-filled from the
 * expiring lease, editable before confirming.
 *
 * Variables in scope: $old_lease (array), $unit (array), $tenant (?array),
 * $new_start (DateTimeImmutable), $new_end (DateTimeImmutable),
 * $list_url (string), $notice (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\LeaseRenewalController;
use ChrxRentalManager\Admin\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tenant_name  = null === $tenant ? '' : $tenant['full_name'];
$current_rent = (float) $old_lease['rent_amount'];
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-breadcrumb">
		<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Leases', 'chrx-rental-manager' ); ?></a> &rsaquo;
		<?php echo esc_html( $tenant_name . ' · ' . $unit['unit_label'] ); ?> &rsaquo; <?php esc_html_e( 'Renew', 'chrx-rental-manager' ); ?>
	</div>
	<h1 style="font-size:23px;font-weight:600;margin:0 0 6px;">
		<?php echo esc_html( sprintf( /* translators: 1: tenant name, 2: unit label */ __( 'Renew lease — %1$s, Unit %2$s', 'chrx-rental-manager' ), $tenant_name, $unit['unit_label'] ) ); ?>
	</h1>
	<p style="font-size:13px;color:#646970;margin:0 0 18px;">
		<?php
		printf(
			/* translators: %s: current term end date */
			esc_html__( 'Current term ends %s. Values are carried over — adjust before confirming.', 'chrx-rental-manager' ),
			esc_html( gmdate( 'j M Y', strtotime( $old_lease['end_date'] ) ) )
		);
		?>
	</p>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<form method="post" class="chrx-rm-admin__form" style="max-width:760px;" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="rm_lease_renew">
		<input type="hidden" name="old_lease_id" value="<?php echo esc_attr( (string) $old_lease['id'] ); ?>">
		<?php wp_nonce_field( LeaseRenewalController::nonce_action() ); ?>

		<table class="form-table">
			<tr>
				<th><label for="rm_start_date"><?php esc_html_e( 'New start date', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="date" id="rm_start_date" name="rm_start_date" value="<?php echo esc_attr( $new_start->format( 'Y-m-d' ) ); ?>" required></td>
			</tr>
			<tr>
				<th><label for="rm_end_date"><?php esc_html_e( 'New end date', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="date" id="rm_end_date" name="rm_end_date" value="<?php echo esc_attr( $new_end->format( 'Y-m-d' ) ); ?>" required></td>
			</tr>
			<tr>
				<th><label for="rm_rent_amount"><?php esc_html_e( 'Rent per billing period', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<input type="text" id="rm_rent_amount" name="rm_rent_amount" value="<?php echo esc_attr( (string) $current_rent ); ?>" style="width:180px;" required>
					<span id="rm-rent-delta" style="font-size:13px;font-weight:600;margin-left:10px;"></span>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Billing cycle', 'chrx-rental-manager' ); ?></th>
				<td>
					<p class="description"><?php esc_html_e( 'Carried over from the expiring lease and cannot be changed on renewal.', 'chrx-rental-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Deposit', 'chrx-rental-manager' ); ?></th>
				<td>
					<label style="display:flex;align-items:center;gap:8px;background:#f6f7f7;padding:11px 14px;border-radius:6px;">
						<input type="checkbox" name="rm_carry_over_deposit" value="1" checked>
						<?php
						printf(
							/* translators: %s: deposit amount */
							esc_html__( 'Carry over deposit (%s) · no new deposit required', 'chrx-rental-manager' ),
							esc_html( Money::format( (float) $old_lease['deposit_amount'] ) )
						);
						?>
					</label>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Confirm renewal', 'chrx-rental-manager' ); ?></button>
			<a href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'page' => 'chrx-rm-leases',
						'id'   => $old_lease['id'],
					),
					admin_url( 'admin.php' )
				)
			);
			?>
			" class="button"><?php esc_html_e( 'Cancel', 'chrx-rental-manager' ); ?></a>
		</p>
	</form>
</div>
<script>
(function () {
	var input = document.getElementById( 'rm_rent_amount' );
	var delta = document.getElementById( 'rm-rent-delta' );
	var original = <?php echo wp_json_encode( $current_rent ); ?>;

	function update() {
		var value = parseFloat( input.value.replace( /,/g, '' ) ) || 0;
		var diff = value - original;

		if ( diff === 0 ) {
			delta.textContent = '';
			return;
		}

		var arrow = diff > 0 ? '▲' : '▼';
		delta.style.color = diff > 0 ? '#0a7d34' : '#b32d2e';
		delta.textContent = arrow + ' ' + Math.abs( diff ).toLocaleString() + ' ' + ( diff > 0 ? 'increase' : 'decrease' ) + ' from ' + original.toLocaleString();
	}

	input.addEventListener( 'input', update );
})();
</script>

<?php
/**
 * Lease details (designs/30-lease-details.html).
 *
 * Variables in scope: $tenant (array), $lease (array), $unit (?array),
 * $property (?array).
 * v2 (SPEC.md §4.10 — move-out notices): $active_notice (?array),
 * $notice_rent_owed (float), $notice_ok (?bool).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Portal\PortalFormat;
use ChrxRentalManager\Portal\PortalMoveOutNoticeController;
use ChrxRentalManager\Portal\PortalShortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$property_name = null === $property ? '' : $property['name'];
$active        = PortalShortcode::VIEW_LEASE;
$page_title    = __( 'Lease details', 'chrx-rental-manager' );

$status_labels = array(
	Lease::STATUS_ACTIVE  => __( 'Active', 'chrx-rental-manager' ),
	Lease::STATUS_ENDED   => __( 'Ended', 'chrx-rental-manager' ),
	Lease::STATUS_RENEWED => __( 'Renewed', 'chrx-rental-manager' ),
);

$deposit_labels = array(
	'paid'      => __( 'Held', 'chrx-rental-manager' ),
	'unpaid'    => __( 'Unpaid', 'chrx-rental-manager' ),
	'refunded'  => __( 'Refunded', 'chrx-rental-manager' ),
	'forfeited' => __( 'Forfeited', 'chrx-rental-manager' ),
);
$deposit_status = $lease['deposit_status'];
$deposit_pill   = 'paid' === $deposit_status ? 'chrx-rm-portal__pill--ok' : 'chrx-rm-portal__pill--partial';
?>
<div class="chrx-rm-portal">
	<?php require \ChrxRentalManager\PLUGIN_DIR . '/templates/portal/partials/desktop-nav.php'; ?>
	<?php require \ChrxRentalManager\PLUGIN_DIR . '/templates/portal/partials/mobile-back-header.php'; ?>

	<div class="chrx-rm-portal__content">
		<div class="chrx-rm-portal__card">
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
				<span style="font-size:16px;font-weight:800;"><?php echo esc_html( null === $unit ? '' : 'Unit ' . $unit['unit_label'] ); ?></span>
				<span class="chrx-rm-portal__pill <?php echo Lease::STATUS_ACTIVE === $lease['status'] ? 'chrx-rm-portal__pill--ok' : 'chrx-rm-portal__pill--partial'; ?>">
					<?php echo esc_html( $status_labels[ $lease['status'] ] ?? ucfirst( $lease['status'] ) ); ?>
				</span>
			</div>
			<div class="chrx-rm-portal__card-row">
				<span class="chrx-rm-portal__card-row-label"><?php esc_html_e( 'Property', 'chrx-rental-manager' ); ?></span>
				<span class="chrx-rm-portal__card-row-value"><?php echo esc_html( $property_name ); ?></span>
			</div>
			<?php if ( null !== $property && '' !== (string) $property['address'] ) : ?>
				<div class="chrx-rm-portal__card-row">
					<span class="chrx-rm-portal__card-row-label"><?php esc_html_e( 'Address', 'chrx-rental-manager' ); ?></span>
					<span class="chrx-rm-portal__card-row-value"><?php echo esc_html( trim( $property['address'] . ( '' !== (string) $property['city'] ? ', ' . $property['city'] : '' ) ) ); ?></span>
				</div>
			<?php endif; ?>
			<?php if ( null !== $unit && '' !== (string) $unit['bedrooms'] ) : ?>
				<div class="chrx-rm-portal__card-row">
					<span class="chrx-rm-portal__card-row-label"><?php esc_html_e( 'Type', 'chrx-rental-manager' ); ?></span>
					<span class="chrx-rm-portal__card-row-value"><?php echo esc_html( sprintf( /* translators: %d: bedroom count */ __( '%d-bedroom', 'chrx-rental-manager' ), (int) $unit['bedrooms'] ) ); ?></span>
				</div>
			<?php endif; ?>
			<div class="chrx-rm-portal__card-row">
				<span class="chrx-rm-portal__card-row-label"><?php esc_html_e( 'Rent per billing period', 'chrx-rental-manager' ); ?></span>
				<span class="chrx-rm-portal__card-row-value"><?php echo esc_html( Money::format( (float) $lease['rent_amount'] ) ); ?></span>
			</div>
			<div class="chrx-rm-portal__card-row">
				<span class="chrx-rm-portal__card-row-label"><?php esc_html_e( 'Billing day', 'chrx-rental-manager' ); ?></span>
				<span class="chrx-rm-portal__card-row-value"><?php echo esc_html( sprintf( /* translators: %s: ordinal day of month */ __( '%s of month', 'chrx-rental-manager' ), PortalFormat::ordinal( (int) $lease['billing_day'] ) ) ); ?></span>
			</div>
		</div>

		<div class="chrx-rm-portal__card">
			<div class="chrx-rm-portal__card-title"><?php esc_html_e( 'Term', 'chrx-rental-manager' ); ?></div>
			<div class="chrx-rm-portal__card-row">
				<span class="chrx-rm-portal__card-row-label"><?php esc_html_e( 'Start', 'chrx-rental-manager' ); ?></span>
				<span class="chrx-rm-portal__card-row-value"><?php echo esc_html( gmdate( 'j M Y', strtotime( $lease['start_date'] ) ) ); ?></span>
			</div>
			<div class="chrx-rm-portal__card-row">
				<span class="chrx-rm-portal__card-row-label"><?php esc_html_e( 'End', 'chrx-rental-manager' ); ?></span>
				<span class="chrx-rm-portal__card-row-value"><?php echo esc_html( gmdate( 'j M Y', strtotime( $lease['end_date'] ) ) ); ?></span>
			</div>
		</div>

		<div class="chrx-rm-portal__card">
			<div style="display:flex;justify-content:space-between;align-items:center;">
				<div>
					<div class="chrx-rm-portal__card-title" style="margin-bottom:3px;"><?php esc_html_e( 'Deposit', 'chrx-rental-manager' ); ?></div>
					<div style="font-size:12px;color:#646970;"><?php echo esc_html( Money::format( (float) $lease['deposit_amount'] ) ); ?></div>
				</div>
				<span class="chrx-rm-portal__pill <?php echo esc_attr( $deposit_pill ); ?>">
					<?php echo esc_html( $deposit_labels[ $deposit_status ] ?? ucfirst( $deposit_status ) ); ?>
				</span>
			</div>
		</div>

		<?php if ( null !== $notice_ok ) : ?>
			<div class="chrx-rm-portal__card" style="background:<?php echo $notice_ok ? '#e5f5eb' : '#fdf5f5'; ?>;">
				<?php echo $notice_ok ? esc_html__( 'Done.', 'chrx-rental-manager' ) : esc_html__( 'That could not be completed — please try again.', 'chrx-rental-manager' ); ?>
			</div>
		<?php endif; ?>

		<?php if ( Lease::STATUS_ACTIVE === $lease['status'] ) : ?>
			<div class="chrx-rm-portal__card">
				<div class="chrx-rm-portal__card-title"><?php esc_html_e( 'Move-out notice', 'chrx-rental-manager' ); ?></div>

				<?php if ( null !== $active_notice ) : ?>
					<div class="chrx-rm-portal__card-row">
						<span class="chrx-rm-portal__card-row-label"><?php esc_html_e( 'Notice given', 'chrx-rental-manager' ); ?></span>
						<span class="chrx-rm-portal__card-row-value"><?php echo esc_html( gmdate( 'j M Y', strtotime( $active_notice['notice_date'] ) ) ); ?></span>
					</div>
					<div class="chrx-rm-portal__card-row">
						<span class="chrx-rm-portal__card-row-label"><?php esc_html_e( 'Earliest move-out date', 'chrx-rental-manager' ); ?></span>
						<span class="chrx-rm-portal__card-row-value"><?php echo esc_html( gmdate( 'j M Y', strtotime( $active_notice['earliest_move_out_date'] ) ) ); ?></span>
					</div>
					<?php if ( $notice_rent_owed > 0 ) : ?>
						<div class="chrx-rm-portal__card-row">
							<span class="chrx-rm-portal__card-row-label"><?php esc_html_e( 'Rent owed through notice period', 'chrx-rental-manager' ); ?></span>
							<span class="chrx-rm-portal__card-row-value"><?php echo esc_html( Money::format( $notice_rent_owed ) ); ?></span>
						</div>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;" onsubmit="return confirm('<?php echo esc_js( __( 'Cancel this move-out notice?', 'chrx-rental-manager' ) ); ?>');">
						<input type="hidden" name="action" value="rm_portal_cancel_notice">
						<input type="hidden" name="rm_notice_id" value="<?php echo esc_attr( (string) $active_notice['id'] ); ?>">
						<?php wp_nonce_field( PortalMoveOutNoticeController::cancel_notice_action_for( (int) $active_notice['id'] ) ); ?>
						<button type="submit" class="chrx-rm-portal__pay-now-button" style="background:#8c8f94;"><?php esc_html_e( 'Cancel notice', 'chrx-rental-manager' ); ?></button>
					</form>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="rm_portal_give_notice">
						<input type="hidden" name="rm_lease_id" value="<?php echo esc_attr( (string) $lease['id'] ); ?>">
						<?php wp_nonce_field( PortalMoveOutNoticeController::give_notice_action() ); ?>
						<label style="display:block;font-size:12px;color:#646970;margin-bottom:4px;">
							<?php esc_html_e( 'Preferred move-out date (optional)', 'chrx-rental-manager' ); ?>
							<input type="date" name="rm_requested_move_out_date" style="display:block;width:100%;margin-top:4px;">
						</label>
						<label style="display:block;font-size:12px;color:#646970;margin:8px 0 4px;">
							<?php esc_html_e( 'Notes (optional)', 'chrx-rental-manager' ); ?>
							<textarea name="rm_notes" rows="2" style="display:block;width:100%;margin-top:4px;"></textarea>
						</label>
						<button type="submit" class="chrx-rm-portal__pay-now-button"><?php esc_html_e( 'Give notice', 'chrx-rental-manager' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</div>

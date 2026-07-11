<?php
/**
 * Payment history (designs/31-payment-history.html) — stacked cards,
 * never a data table (SPEC.md §4.6: no forced horizontal scroll on
 * narrow viewports).
 *
 * Variables in scope: $tenant (array), $rows (array<int,array{payment,charge}>).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\PaymentsListTable;
use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Portal\PortalShortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$property_name = '';
$active        = PortalShortcode::VIEW_PAYMENTS;
$page_title    = __( 'Payment history', 'chrx-rental-manager' );
?>
<div class="chrx-rm-portal">
	<?php require \ChrxRentalManager\PLUGIN_DIR . '/templates/portal/partials/desktop-nav.php'; ?>
	<?php require \ChrxRentalManager\PLUGIN_DIR . '/templates/portal/partials/mobile-back-header.php'; ?>

	<div class="chrx-rm-portal__content">
		<?php if ( array() === $rows ) : ?>
			<div class="chrx-rm-portal__card" style="text-align:center;color:#8c8f94;">
				<?php esc_html_e( 'No payments recorded yet.', 'chrx-rental-manager' ); ?>
			</div>
		<?php else : ?>
			<div class="chrx-rm-portal__payment-list">
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$payment = $row['payment'];
					$charge  = $row['charge'];

					if ( null === $charge ) {
						$period_label = __( 'Advance payment', 'chrx-rental-manager' );
					} elseif ( Charge::TYPE_LATE_FEE === $charge['type'] ) {
						$period_label = __( 'Late fee', 'chrx-rental-manager' );
					} else {
						$period_label = gmdate( 'M Y', strtotime( $charge['period_start'] ) );
					}
					?>
					<div class="chrx-rm-portal__payment-card">
						<div>
							<div class="chrx-rm-portal__payment-amount">
								<?php echo esc_html( Money::format( (float) $payment['amount'] ) ); ?>
								<?php if ( null !== $charge && Charge::STATUS_PARTIAL === $charge['status'] ) : ?>
									<span class="chrx-rm-portal__pill chrx-rm-portal__pill--partial" style="margin-left:4px;padding:2px 8px;font-size:10px;"><?php esc_html_e( 'Partial', 'chrx-rental-manager' ); ?></span>
								<?php endif; ?>
							</div>
							<div class="chrx-rm-portal__payment-meta">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: period label, 2: payment method, 3: day/month paid */
										'%1$s · %2$s · %3$s',
										$period_label,
										PaymentsListTable::method_label( $payment['method'] ),
										gmdate( 'j M', strtotime( $payment['paid_at'] ) )
									)
								);
								?>
							</div>
						</div>
						<?php if ( null !== $payment['receipt_id'] ) : ?>
							<?php
							$receipt_url = add_query_arg(
								array(
									'rm_view'       => PortalShortcode::VIEW_RECEIPT,
									'rm_receipt_id' => $payment['receipt_id'],
								),
								( new \ChrxRentalManager\Auth\Pages() )->url( \ChrxRentalManager\Auth\Pages::KEY_PORTAL )
							);
							?>
							<a href="<?php echo esc_url( $receipt_url ); ?>" class="chrx-rm-portal__receipt-link">
								<?php esc_html_e( 'Receipt', 'chrx-rental-manager' ); ?>
							</a>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</div>

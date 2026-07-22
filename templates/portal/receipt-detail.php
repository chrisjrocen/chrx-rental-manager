<?php
/**
 * Receipt detail / download (designs/32-receipt-detail.html).
 *
 * Variables in scope: $tenant (array), $receipt (array), $payment (array),
 * $charge (?array), $unit (?array), $property (?array).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Portal\PortalReceiptDownload;
use ChrxRentalManager\Portal\PortalReceiptPrint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$property_name = null === $property ? '' : $property['name'];
$active        = '';
$page_title    = __( 'Receipt', 'chrx-rental-manager' );

if ( null === $charge ) {
	$period_label = __( 'Advance payment', 'chrx-rental-manager' );
} elseif ( Charge::TYPE_LATE_FEE === $charge['type'] ) {
	$period_label = __( 'Late fee', 'chrx-rental-manager' );
} else {
	$period_label = sprintf( /* translators: %s: month/year */ __( 'Rent — %s', 'chrx-rental-manager' ), gmdate( 'M Y', strtotime( $charge['period_start'] ) ) );
}

$method_labels = array(
	'cash'          => __( 'Cash', 'chrx-rental-manager' ),
	'bank_transfer' => __( 'Bank transfer', 'chrx-rental-manager' ),
	'mtn_momo'      => __( 'MTN Mobile Money', 'chrx-rental-manager' ),
	'airtel_money'  => __( 'Airtel Money', 'chrx-rental-manager' ),
	'other'         => __( 'Other', 'chrx-rental-manager' ),
);
$method_label  = $method_labels[ $payment['method'] ] ?? ucfirst( str_replace( '_', ' ', $payment['method'] ) );

$download_url = PortalReceiptDownload::download_url( (int) $receipt['id'] );
$print_url    = PortalReceiptPrint::print_url( (int) $receipt['id'] );
?>
<div class="chrx-rm-portal">
	<?php require \ChrxRentalManager\PLUGIN_DIR . '/templates/portal/partials/desktop-nav.php'; ?>
	<?php require \ChrxRentalManager\PLUGIN_DIR . '/templates/portal/partials/mobile-back-header.php'; ?>

	<div class="chrx-rm-portal__content" style="max-width:420px;">
		<div class="chrx-rm-portal__receipt-card">
			<div class="chrx-rm-portal__receipt-brand">
				<div class="chrx-rm-portal__receipt-brand-name"><?php echo esc_html( Settings::company_name() ); ?></div>
				<div class="chrx-rm-portal__receipt-number"><?php echo esc_html( sprintf( /* translators: %s: receipt number */ __( 'Receipt #%s', 'chrx-rental-manager' ), $receipt['receipt_number'] ) ); ?></div>
			</div>
			<div class="chrx-rm-portal__card-row">
				<span class="chrx-rm-portal__card-row-label"><?php esc_html_e( 'Tenant', 'chrx-rental-manager' ); ?></span>
				<span class="chrx-rm-portal__card-row-value"><?php echo esc_html( $tenant['full_name'] ); ?></span>
			</div>
			<div class="chrx-rm-portal__card-row">
				<span class="chrx-rm-portal__card-row-label"><?php esc_html_e( 'Unit', 'chrx-rental-manager' ); ?></span>
				<span class="chrx-rm-portal__card-row-value"><?php echo esc_html( trim( ( null === $unit ? '' : $unit['unit_label'] ) . ( '' !== $property_name ? ' · ' . $property_name : '' ) ) ); ?></span>
			</div>
			<div class="chrx-rm-portal__card-row">
				<span class="chrx-rm-portal__card-row-label"><?php esc_html_e( 'Period', 'chrx-rental-manager' ); ?></span>
				<span class="chrx-rm-portal__card-row-value"><?php echo esc_html( $period_label ); ?></span>
			</div>
			<div class="chrx-rm-portal__card-row">
				<span class="chrx-rm-portal__card-row-label"><?php esc_html_e( 'Method', 'chrx-rental-manager' ); ?></span>
				<span class="chrx-rm-portal__card-row-value"><?php echo esc_html( $method_label ); ?></span>
			</div>
			<div class="chrx-rm-portal__card-row">
				<span class="chrx-rm-portal__card-row-label"><?php esc_html_e( 'Date', 'chrx-rental-manager' ); ?></span>
				<span class="chrx-rm-portal__card-row-value"><?php echo esc_html( gmdate( 'j M Y', strtotime( $payment['paid_at'] ) ) ); ?></span>
			</div>
			<div class="chrx-rm-portal__receipt-total">
				<span><?php esc_html_e( 'Total paid', 'chrx-rental-manager' ); ?></span>
				<span><?php echo esc_html( Money::format( (float) $payment['amount'] ) ); ?></span>
			</div>
		</div>

		<a href="<?php echo esc_url( $download_url ); ?>" target="_blank" rel="noopener" class="chrx-rm-portal__download-button">
			<?php esc_html_e( 'Download PDF', 'chrx-rental-manager' ); ?>
		</a>
		<a href="<?php echo esc_url( $print_url ); ?>" target="_blank" rel="noopener" class="chrx-rm-portal__download-button" style="background:#f6f7f7;color:#1d2327;margin-top:10px;">
			<?php esc_html_e( 'Print', 'chrx-rental-manager' ); ?>
		</a>
	</div>
</div>

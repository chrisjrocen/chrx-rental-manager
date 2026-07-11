<?php
/**
 * Payment receipt PDF body (designs/19-receipt-preview.html), rendered by
 * Dompdf — plain HTML/inline CSS only, no wp-admin chrome.
 *
 * Variables in scope (all pre-formatted/escaped-safe strings, from
 * ReceiptPdf::render_pdf()): $receipt_number, $company_name,
 * $company_address, $company_phone, $tenant_name, $unit_label,
 * $property_name, $paid_at (Y-m-d H:i:s), $line_label, $amount (formatted
 * money string), $credit_applied (formatted money string, or null),
 * $total (formatted money string), $method, $balance (formatted money
 * string).
 *
 * @package ChrxRentalManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$method_labels = array(
	'cash'          => __( 'Cash', 'chrx-rental-manager' ),
	'bank_transfer' => __( 'Bank transfer', 'chrx-rental-manager' ),
	'mtn_momo'      => __( 'MTN Mobile Money', 'chrx-rental-manager' ),
	'airtel_money'  => __( 'Airtel Money', 'chrx-rental-manager' ),
	'other'         => __( 'Other', 'chrx-rental-manager' ),
);

$method_label = $method_labels[ $method ] ?? ucfirst( str_replace( '_', ' ', $method ) );
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
	body { font-family: DejaVu Sans, sans-serif; color: #1d2327; font-size: 12px; margin: 0; padding: 24px; }
	.header { display: flex; justify-content: space-between; border-bottom: 2px solid #1d2327; padding-bottom: 14px; margin-bottom: 18px; }
	.header .company { font-weight: 800; font-size: 16px; }
	.header .meta { color: #646970; }
	.header .receipt-title { text-align: right; }
	.header .receipt-title .label { font-weight: 800; font-size: 15px; }
	.parties { width: 100%; margin-bottom: 18px; }
	.parties .col-label { color: #646970; text-transform: uppercase; font-size: 10px; letter-spacing: .05em; margin-bottom: 3px; }
	.parties .name { font-weight: 700; }
	table.lines { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
	table.lines td { padding: 7px 0; }
	table.lines .total td { font-weight: 800; font-size: 14px; border-top: 1px solid #dcdcde; padding-top: 10px; }
	.footer-row { display: flex; justify-content: space-between; color: #646970; border-top: 1px dashed #c3c4c7; padding-top: 12px; }
	.thanks { text-align: center; color: #8c8f94; margin-top: 20px; font-size: 11px; }
</style>
</head>
<body>
	<div class="header">
		<div>
			<div class="company"><?php echo esc_html( $company_name ); ?></div>
			<div class="meta"><?php echo esc_html( trim( $company_address . ( '' !== $company_phone ? ' · ' . $company_phone : '' ), ' ·' ) ); ?></div>
		</div>
		<div class="receipt-title">
			<div class="label"><?php esc_html_e( 'RECEIPT', 'chrx-rental-manager' ); ?></div>
			<div class="meta">#<?php echo esc_html( $receipt_number ); ?></div>
		</div>
	</div>

	<table class="parties">
		<tr>
			<td>
				<div class="col-label"><?php esc_html_e( 'Received from', 'chrx-rental-manager' ); ?></div>
				<div class="name"><?php echo esc_html( $tenant_name ); ?></div>
				<div><?php echo esc_html( trim( ( '' !== $unit_label ? 'Unit ' . $unit_label : '' ) . ( '' !== $property_name ? ' · ' . $property_name : '' ), ' ·' ) ); ?></div>
			</td>
			<td style="text-align:right;">
				<div class="col-label"><?php esc_html_e( 'Date', 'chrx-rental-manager' ); ?></div>
				<div><?php echo esc_html( gmdate( 'j F Y', strtotime( $paid_at ) ) ); ?></div>
			</td>
		</tr>
	</table>

	<table class="lines">
		<tr>
			<td style="color:#646970;"><?php echo esc_html( $line_label ); ?></td>
			<td style="text-align:right;font-weight:600;"><?php echo esc_html( $amount ); ?></td>
		</tr>
		<?php if ( null !== $credit_applied ) : ?>
			<tr>
				<td style="color:#646970;"><?php esc_html_e( 'Held as credit toward next charge', 'chrx-rental-manager' ); ?></td>
				<td style="text-align:right;font-weight:600;"><?php echo esc_html( $credit_applied ); ?></td>
			</tr>
		<?php endif; ?>
		<tr class="total">
			<td><?php esc_html_e( 'Total paid', 'chrx-rental-manager' ); ?></td>
			<td style="text-align:right;"><?php echo esc_html( $total ); ?></td>
		</tr>
	</table>

	<div class="footer-row">
		<span><?php echo esc_html( sprintf( /* translators: %s: payment method */ __( 'Method: %s', 'chrx-rental-manager' ), $method_label ) ); ?></span>
		<span><?php echo esc_html( sprintf( /* translators: %s: formatted balance */ __( 'Balance: %s', 'chrx-rental-manager' ), $balance ) ); ?></span>
	</div>

	<div class="thanks"><?php esc_html_e( 'Thank you for your payment.', 'chrx-rental-manager' ); ?></div>
</body>
</html>

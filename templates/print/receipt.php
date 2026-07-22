<?php
/**
 * Print-CSS receipt view (SPEC.md §4.11) — a standalone HTML page for the
 * browser's native print dialog, separate from the Dompdf-rendered PDF
 * (templates/pdf/receipt.php) that's emailed/downloaded. Reached via the
 * "Print" action on both the staff (Admin\PaymentsController) and tenant
 * portal (Portal\PortalReceiptPrint) receipt screens. No Web Bluetooth/
 * ESC-POS code: a thermal printer is reached by installing a print-to-
 * Bluetooth Android app (e.g. RawBT) as the OS print handler, which
 * intercepts this same print job.
 *
 * Variables in scope: $receipt_number, $company_name, $company_address,
 * $company_phone, $tenant_name, $unit_label, $property_name, $paid_at
 * (Y-m-d H:i:s), $line_label, $amount (formatted money string),
 * $credit_applied (formatted money string, or null), $total (formatted
 * money string), $method, $balance (formatted money string),
 * $paper_format (Settings::RECEIPT_PRINT_FORMAT_* constant), $back_url
 * (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\Support\Settings;

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

$body_class = 'chrx-rm-paper-' . str_replace( '_', '-', $paper_format );

switch ( $paper_format ) {
	case Settings::RECEIPT_PRINT_FORMAT_LETTER:
		$page_rule = '@page { size: Letter; margin: 15mm; }';
		break;
	case Settings::RECEIPT_PRINT_FORMAT_THERMAL_58:
		$page_rule = '@page { size: 58mm auto; margin: 2mm; }';
		break;
	case Settings::RECEIPT_PRINT_FORMAT_THERMAL_80:
		$page_rule = '@page { size: 80mm auto; margin: 2mm; }';
		break;
	default:
		$page_rule = '@page { size: A4; margin: 15mm; }';
		break;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( sprintf( /* translators: %s: receipt number */ __( 'Receipt #%s', 'chrx-rental-manager' ), $receipt_number ) ); ?></title>
<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- this is a standalone admin-post response, not a normal WP page render that fires wp_head()/wp_print_styles(), so wp_enqueue_style() would never actually output anything here. ?>
<link rel="stylesheet" href="<?php echo esc_url( \ChrxRentalManager\PLUGIN_URL . 'assets/css/receipt-print.css' ); ?>">
<style><?php echo $page_rule; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $page_rule is one of 4 fixed internal strings selected by a switch above, never user input. ?></style>
</head>
<body class="<?php echo esc_attr( $body_class ); ?>">
	<div class="chrx-rm-print-toolbar">
		<button type="button" onclick="window.print();"><?php esc_html_e( 'Print', 'chrx-rental-manager' ); ?></button>
		<?php if ( null !== $back_url ) : ?>
			<a href="<?php echo esc_url( $back_url ); ?>" class="button"><?php esc_html_e( 'Back', 'chrx-rental-manager' ); ?></a>
		<?php endif; ?>
	</div>

	<div class="chrx-rm-receipt">
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
	</div>
</body>
</html>

<?php
/**
 * Landlord statement PDF body (designs/22-landlord-statement-generator.html),
 * rendered by Dompdf.
 *
 * Variables in scope (all pre-formatted/escape-safe strings, from
 * StatementPdf::render()): $company_name, $property_name, $landlord_name,
 * $period_label, $prepared_date, $line_items (array<int,array{unit_label,
 * tenant_name:?string,collected:float}>), $gross, $fee_label, $fee_amount,
 * $net.
 * v2 (SPEC.md §4.4, P&L upgrade): $expense_lines (array<int,array{label,
 * amount}>), $expenses.
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
	body { font-family: DejaVu Sans, sans-serif; color: #1d2327; font-size: 12px; margin: 0; padding: 32px; }
	.header { border-bottom: 2px solid #1d2327; padding-bottom: 14px; margin-bottom: 18px; }
	.header .title { font-weight: 800; font-size: 16px; }
	.header .meta { color: #646970; }
	table.lines { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
	table.lines th { text-align: left; padding: 7px 0; border-bottom: 1px solid #dcdcde; color: #646970; text-transform: uppercase; font-size: 10px; }
	table.lines td { padding: 7px 0; }
	table.lines td.amount, table.lines th.amount { text-align: right; }
	.vacant { color: #8c8f94; }
	.totals { border-top: 1px solid #dcdcde; padding-top: 12px; }
	.totals .row { display: flex; justify-content: space-between; margin-bottom: 5px; }
	.totals .fee { color: #646970; }
	.totals .net { font-weight: 800; font-size: 14px; border-top: 1px solid #1d2327; margin-top: 8px; padding-top: 8px; }
	.footnote { color: #8c8f94; font-size: 10px; margin-top: 16px; }
</style>
</head>
<body>
	<div class="header">
		<div class="title"><?php echo esc_html( sprintf( /* translators: %s: period label, e.g. "June 2026" */ __( 'Owner Statement — %s', 'chrx-rental-manager' ), $period_label ) ); ?></div>
		<div class="meta">
			<?php
			echo esc_html(
				trim(
					( '' !== $landlord_name ? $landlord_name . ' · ' : '' ) . $property_name . ' · ' .
					sprintf( /* translators: %s: prepared date */ __( 'Prepared %s', 'chrx-rental-manager' ), $prepared_date )
				)
			);
			?>
		</div>
	</div>

	<table class="lines">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Unit', 'chrx-rental-manager' ); ?></th>
				<th><?php esc_html_e( 'Tenant', 'chrx-rental-manager' ); ?></th>
				<th class="amount"><?php esc_html_e( 'Collected', 'chrx-rental-manager' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $line_items as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['unit_label'] ); ?></td>
					<?php if ( null === $row['tenant_name'] ) : ?>
						<td class="vacant"><?php esc_html_e( 'Vacant', 'chrx-rental-manager' ); ?></td>
					<?php else : ?>
						<td><?php echo esc_html( $row['tenant_name'] ); ?></td>
					<?php endif; ?>
					<td class="amount"><?php echo esc_html( Money::format( (float) $row['collected'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( array() !== $expense_lines ) : ?>
		<table class="lines">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Expense', 'chrx-rental-manager' ); ?></th>
					<th class="amount"><?php esc_html_e( 'Amount', 'chrx-rental-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $expense_lines as $expense_line ) : ?>
					<tr>
						<td><?php echo esc_html( $expense_line['label'] ); ?></td>
						<td class="amount"><?php echo esc_html( $expense_line['amount'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<div class="totals">
		<div class="row"><span><?php esc_html_e( 'Gross collected', 'chrx-rental-manager' ); ?></span><span><?php echo esc_html( $gross ); ?></span></div>
		<div class="row fee"><span><?php echo esc_html( $fee_label ); ?></span><span>&#8211; <?php echo esc_html( $fee_amount ); ?></span></div>
		<div class="row fee"><span><?php esc_html_e( 'Expenses', 'chrx-rental-manager' ); ?></span><span>&#8211; <?php echo esc_html( $expenses ); ?></span></div>
		<div class="row net"><span><?php esc_html_e( 'Net due to owner', 'chrx-rental-manager' ); ?></span><span><?php echo esc_html( $net ); ?></span></div>
	</div>

	<p class="footnote">
		<?php esc_html_e( 'Account-level expenses (not scoped to this property) are excluded from this statement — they appear only on admin-level expense reports.', 'chrx-rental-manager' ); ?>
	</p>
</body>
</html>

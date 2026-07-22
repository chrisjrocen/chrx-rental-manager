<?php
/**
 * Expense Report PDF body (SPEC.md §4.4), rendered by Dompdf.
 *
 * Variables in scope (all pre-formatted/escape-safe strings, from
 * ExpenseReportPdf::render()): $period_label, $prepared_date,
 * $category_totals (array<int,array{label,amount}>), $property_totals
 * (array<int,array{label,amount}>), $total.
 *
 * @package ChrxRentalManager
 */

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
	.section-title { font-weight: 700; font-size: 13px; margin: 18px 0 8px; }
	table.lines { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
	table.lines th { text-align: left; padding: 7px 0; border-bottom: 1px solid #dcdcde; color: #646970; text-transform: uppercase; font-size: 10px; }
	table.lines td { padding: 7px 0; border-bottom: 1px solid #f0f0f1; }
	table.lines td.amount, table.lines th.amount { text-align: right; }
	.totals { border-top: 1px solid #1d2327; padding-top: 12px; margin-top: 12px; }
	.totals .row { display: flex; justify-content: space-between; font-weight: 800; font-size: 14px; }
</style>
</head>
<body>
	<div class="header">
		<div class="title"><?php esc_html_e( 'Expense Report', 'chrx-rental-manager' ); ?></div>
		<div class="meta">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: period label, e.g. "1 Jun 2026 – 30 Jun 2026", 2: prepared date */
					__( '%1$s · Prepared %2$s', 'chrx-rental-manager' ),
					$period_label,
					$prepared_date
				)
			);
			?>
		</div>
	</div>

	<div class="section-title"><?php esc_html_e( 'By category', 'chrx-rental-manager' ); ?></div>
	<table class="lines">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Category', 'chrx-rental-manager' ); ?></th>
				<th class="amount"><?php esc_html_e( 'Amount', 'chrx-rental-manager' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $category_totals as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['label'] ); ?></td>
					<td class="amount"><?php echo esc_html( $row['amount'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<div class="section-title"><?php esc_html_e( 'By property', 'chrx-rental-manager' ); ?></div>
	<table class="lines">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Property', 'chrx-rental-manager' ); ?></th>
				<th class="amount"><?php esc_html_e( 'Amount', 'chrx-rental-manager' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $property_totals as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['label'] ); ?></td>
					<td class="amount"><?php echo esc_html( $row['amount'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<div class="totals">
		<div class="row"><span><?php esc_html_e( 'Total', 'chrx-rental-manager' ); ?></span><span><?php echo esc_html( $total ); ?></span></div>
	</div>
</body>
</html>

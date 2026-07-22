<?php
/**
 * "My statements" — Landlord-Owner, read-only (designs/28-landlord-reports-statement-download.html).
 *
 * Variables in scope: $rows (array<int,array{property:array,
 * period:array{from,to,label},summary:array{gross,fee_amount,expenses,net}}>).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\StatementsController;
use ChrxRentalManager\Admin\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$controller = new StatementsController();
?>
<div class="wrap chrx-rm-admin">
	<h1 style="font-size:23px;font-weight:600;margin:0 0 16px;"><?php esc_html_e( 'My statements', 'chrx-rental-manager' ); ?></h1>

	<div style="font-size:13px;color:#8a6116;background:#fbf0dd;border:1px solid #ecd9ad;border-radius:6px;padding:9px 14px;margin-bottom:20px;">
		<?php esc_html_e( 'Statements are prepared by management. You can view and download, but not edit.', 'chrx-rental-manager' ); ?>
	</div>

	<?php if ( array() === $rows ) : ?>
		<div class="chrx-rm-panel">
			<div class="chrx-rm-panel__body"><p style="color:#8c8f94;font-size:13px;margin:0;"><?php esc_html_e( 'No properties are assigned to your account yet.', 'chrx-rental-manager' ); ?></p></div>
		</div>
	<?php else : ?>
		<div class="chrx-rm-panel">
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Period', 'chrx-rental-manager' ); ?></th>
						<th><?php esc_html_e( 'Property', 'chrx-rental-manager' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Gross', 'chrx-rental-manager' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Net to you', 'chrx-rental-manager' ); ?></th>
						<th style="text-align:right;"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$download_url = $controller->download_url(
							(int) $row['property']['id'],
							$row['period']['from'],
							$row['period']['to']
						);
						?>
						<tr>
							<td style="font-weight:600;"><?php echo esc_html( $row['period']['label'] ); ?></td>
							<td><?php echo esc_html( $row['property']['name'] ); ?></td>
							<td style="text-align:right;"><?php echo esc_html( Money::format( (float) $row['summary']['gross'] ) ); ?></td>
							<td style="text-align:right;font-weight:600;"><?php echo esc_html( Money::format( (float) $row['summary']['net'] ) ); ?></td>
							<td style="text-align:right;">
								<a href="<?php echo esc_url( $download_url ); ?>" target="_blank" rel="noopener" class="button"><?php esc_html_e( 'Download PDF', 'chrx-rental-manager' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>

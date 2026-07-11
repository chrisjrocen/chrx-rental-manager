<?php
/**
 * Lease detail — charge ledger (designs/15-lease-detail-charge-ledger.html).
 *
 * "Renew lease" and "Record Payment" are shown per the design but disabled
 * here — they belong to the Billing (renewal) and Payments & Receipts
 * phases respectively, not this one, and wiring them now would mean
 * rebuilding them against a different data flow once those phases land.
 *
 * Variables in scope: $lease (array), $unit (?array), $tenant (?array),
 * $property (?array), $charges (array<int,array> with 'paid' added),
 * $paid_to_date (float), $balance (float), $documents (array<int,array>),
 * $can_manage (bool), $notice (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\Support\Badge;
use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Document;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$list_url    = add_query_arg( 'page', 'chrx-rm-leases', admin_url( 'admin.php' ) );
$edit_url    = add_query_arg(
	array(
		'page'   => 'chrx-rm-leases',
		'action' => 'edit',
		'id'     => $lease['id'],
	),
	admin_url( 'admin.php' )
);
$entity_type = Document::ENTITY_LEASE;
$entity_id   = (int) $lease['id'];

$deposit_label     = 'paid' === $lease['deposit_status'] ? __( 'Held', 'chrx-rental-manager' ) : ucfirst( $lease['deposit_status'] );
$deposit_badge_key = 'paid' === $lease['deposit_status'] ? 'paid' : 'unpaid';
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-breadcrumb">
		<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Leases', 'chrx-rental-manager' ); ?></a> &rsaquo;
		<?php echo esc_html( ( null === $tenant ? '' : $tenant['full_name'] ) . ' · ' . ( null === $unit ? '' : $unit['unit_label'] ) . ( null === $property ? '' : ' ' . $property['name'] ) ); ?>
	</div>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<div class="chrx-rm-detail-header">
		<div class="chrx-rm-detail-header__title">
			<h1>
				<?php
				echo esc_html(
					sprintf(
					/* translators: 1: tenant name, 2: unit label */
						__( '%1$s — %2$s', 'chrx-rental-manager' ),
						null === $tenant ? '' : $tenant['full_name'],
						null === $unit ? '' : 'Unit ' . $unit['unit_label']
					)
				);
				?>
			</h1>
			<?php echo wp_kses_post( Badge::render( $lease['status'] ) ); ?>
		</div>
		<div class="chrx-rm-detail-header__actions">
			<button type="button" class="button" disabled title="<?php esc_attr_e( 'Available in a later phase', 'chrx-rental-manager' ); ?>"><?php esc_html_e( 'Renew lease', 'chrx-rental-manager' ); ?></button>
			<button type="button" class="button button-primary" disabled title="<?php esc_attr_e( 'Available in a later phase', 'chrx-rental-manager' ); ?>"><?php esc_html_e( 'Record Payment', 'chrx-rental-manager' ); ?></button>
			<?php if ( $can_manage ) : ?>
				<?php
				$archive_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'      => 'chrx-rm-leases',
							'rm_action' => 'archive',
							'id'        => $lease['id'],
						),
						admin_url( 'admin.php' )
					),
					'rm_lease_archive'
				);
				?>
				<a href="<?php echo esc_url( $edit_url ); ?>" class="button"><?php esc_html_e( 'Edit lease', 'chrx-rental-manager' ); ?></a>
				<a href="<?php echo esc_url( $archive_url ); ?>" class="button" onclick="return confirm('<?php echo esc_js( __( 'Archive this lease?', 'chrx-rental-manager' ) ); ?>');"><?php esc_html_e( 'Archive', 'chrx-rental-manager' ); ?></a>
			<?php endif; ?>
		</div>
	</div>

	<div class="chrx-rm-stat-grid">
		<div class="chrx-rm-stat-card">
			<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Rent', 'chrx-rental-manager' ); ?></div>
			<div class="chrx-rm-stat-card__value"><?php echo esc_html( Money::format( (float) $lease['rent_amount'] ) ); ?></div>
			<div style="font-size:11px;color:#646970;">/ <?php esc_html_e( 'month', 'chrx-rental-manager' ); ?></div>
		</div>
		<div class="chrx-rm-stat-card">
			<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Term', 'chrx-rental-manager' ); ?></div>
			<div style="font-size:13px;font-weight:600;"><?php echo esc_html( gmdate( 'M y', strtotime( $lease['start_date'] ) ) . ' – ' . gmdate( 'M y', strtotime( $lease['end_date'] ) ) ); ?></div>
			<div style="font-size:11px;color:#646970;"><?php echo esc_html( sprintf( /* translators: %d: day of month */ __( 'billed on day %d', 'chrx-rental-manager' ), (int) $lease['billing_day'] ) ); ?></div>
		</div>
		<div class="chrx-rm-stat-card">
			<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Deposit', 'chrx-rental-manager' ); ?></div>
			<div class="chrx-rm-stat-card__value"><?php echo esc_html( Money::format( (float) $lease['deposit_amount'] ) ); ?></div>
			<?php echo wp_kses_post( Badge::render( $deposit_badge_key, $deposit_label ) ); ?>
		</div>
		<div class="chrx-rm-stat-card">
			<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Paid to date', 'chrx-rental-manager' ); ?></div>
			<div class="chrx-rm-stat-card__value" style="color:#0a7d34;"><?php echo esc_html( Money::format( $paid_to_date ) ); ?></div>
		</div>
		<div class="chrx-rm-stat-card">
			<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Balance', 'chrx-rental-manager' ); ?></div>
			<div class="chrx-rm-stat-card__value" style="<?php echo $balance > 0 ? 'color:#b32d2e;' : ''; ?>"><?php echo esc_html( Money::format( $balance ) ); ?></div>
		</div>
	</div>

	<div class="chrx-rm-panel">
		<div class="chrx-rm-panel__header">
			<span><?php esc_html_e( 'Charge ledger', 'chrx-rental-manager' ); ?></span>
		</div>
		<?php if ( array() === $charges ) : ?>
			<div class="chrx-rm-panel__body">
				<p style="color:#8c8f94;font-size:13px;margin:0;">
					<?php esc_html_e( 'No charges yet — charges are generated automatically a few days before each due date.', 'chrx-rental-manager' ); ?>
				</p>
			</div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Period', 'chrx-rental-manager' ); ?></th>
						<th><?php esc_html_e( 'Due date', 'chrx-rental-manager' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Charged', 'chrx-rental-manager' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Paid', 'chrx-rental-manager' ); ?></th>
						<th><?php esc_html_e( 'Status', 'chrx-rental-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $charges as $charge ) : ?>
						<tr>
							<td style="font-weight:600;"><?php echo esc_html( gmdate( 'M Y', strtotime( $charge['period_start'] ) ) ); ?></td>
							<td><?php echo esc_html( gmdate( 'j M Y', strtotime( $charge['period_due_date'] ) ) ); ?></td>
							<td style="text-align:right;">
								<?php echo esc_html( Money::format( (float) $charge['amount_due'] ) ); ?>
								<?php if ( Charge::TYPE_LATE_FEE === $charge['type'] ) : ?>
									<span style="color:#b32d2e;font-size:11px;">(<?php esc_html_e( 'late fee', 'chrx-rental-manager' ); ?>)</span>
								<?php endif; ?>
							</td>
							<td style="text-align:right;"><?php echo esc_html( Money::format( (float) $charge['paid'] ) ); ?></td>
							<td><?php echo wp_kses_post( Badge::render( $charge['status'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<?php require \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/partials/documents-panel.php'; ?>
</div>

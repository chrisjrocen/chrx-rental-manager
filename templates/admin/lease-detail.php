<?php
/**
 * Lease detail — charge ledger (designs/15-lease-detail-charge-ledger.html).
 *
 * "Renew lease" and "Move-out" (designs/17, /23) are built in the Billing
 * phase; "Record Payment" (designs/18) is built in the Payments &
 * Receipts phase. All three are live regardless of lease status — a
 * closing-out payment against an ended lease is allowed (SPEC.md §4.3
 * edge case), flagged distinctly on the record-payment screen itself.
 *
 * Variables in scope: $lease (array), $unit (?array), $tenant (?array),
 * $property (?array), $charges (array<int,array> with 'paid' added),
 * $paid_to_date (float), $balance (float), $documents (array<int,array>),
 * $can_manage (bool), $notice (?string).
 * v2 (SPEC.md §4.10 — move-out notices): $active_notice (?array).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\Support\Badge;
use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Document;
use ChrxRentalManager\Data\Lease;

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
			<?php if ( $can_manage && Lease::STATUS_ACTIVE === $lease['status'] ) : ?>
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'page'   => 'chrx-rm-leases',
							'action' => 'renew',
							'id'     => $lease['id'],
						),
						admin_url( 'admin.php' )
					)
				);
				?>
							" class="button"><?php esc_html_e( 'Renew lease', 'chrx-rental-manager' ); ?></a>
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'page'   => 'chrx-rm-leases',
							'action' => 'move-out',
							'id'     => $lease['id'],
						),
						admin_url( 'admin.php' )
					)
				);
				?>
							" class="button"><?php esc_html_e( 'Move-out', 'chrx-rental-manager' ); ?></a>
			<?php endif; ?>
			<?php if ( $can_manage ) : ?>
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'page'   => 'chrx-rm-leases',
							'action' => 'record-payment',
							'id'     => $lease['id'],
						),
						admin_url( 'admin.php' )
					)
				);
				?>
							" class="button button-primary"><?php esc_html_e( 'Record Payment', 'chrx-rental-manager' ); ?></a>
			<?php endif; ?>
			<?php if ( $can_manage ) : ?>
				<?php
				$trash_url = wp_nonce_url(
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
				<a href="<?php echo esc_url( $trash_url ); ?>" class="button" onclick="return confirm('<?php echo esc_js( __( 'Move this lease to trash?', 'chrx-rental-manager' ) ); ?>');"><?php esc_html_e( 'Move to Trash', 'chrx-rental-manager' ); ?></a>
			<?php endif; ?>
		</div>
	</div>

	<div class="chrx-rm-stat-grid">
		<div class="chrx-rm-stat-card">
			<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Rent', 'chrx-rental-manager' ); ?></div>
			<div class="chrx-rm-stat-card__value"><?php echo esc_html( Money::format( (float) $lease['rent_amount'] ) ); ?></div>
			<div style="font-size:11px;color:#646970;">/ <?php esc_html_e( 'billing period', 'chrx-rental-manager' ); ?></div>
		</div>
		<div class="chrx-rm-stat-card">
			<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Term', 'chrx-rental-manager' ); ?></div>
			<div style="font-size:13px;font-weight:600;"><?php echo esc_html( gmdate( 'M y', strtotime( $lease['start_date'] ) ) . ' – ' . gmdate( 'M y', strtotime( $lease['end_date'] ) ) ); ?></div>
			<?php
			$lease_cycle_months = (int) ( $lease['cycle_months'] ?? 1 );
			$lease_cycle_labels = array(
				Lease::CYCLE_MONTHLY   => __( 'monthly', 'chrx-rental-manager' ),
				Lease::CYCLE_QUARTERLY => __( 'quarterly', 'chrx-rental-manager' ),
				Lease::CYCLE_SEMESTER  => __( 'semester', 'chrx-rental-manager' ),
				Lease::CYCLE_ANNUAL    => __( 'annual', 'chrx-rental-manager' ),
				Lease::CYCLE_CUSTOM    => sprintf( /* translators: %d: months */ __( 'every %d months', 'chrx-rental-manager' ), $lease_cycle_months ),
			);
			?>
			<div style="font-size:11px;color:#646970;">
				<?php echo esc_html( $lease_cycle_labels[ $lease['billing_cycle'] ?? Lease::CYCLE_MONTHLY ] ?? Lease::CYCLE_MONTHLY ); ?> ·
				<?php echo esc_html( sprintf( /* translators: %d: day of month */ __( 'billed on day %d', 'chrx-rental-manager' ), (int) $lease['billing_day'] ) ); ?>
			</div>
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

	<?php if ( Lease::STATUS_ACTIVE === $lease['status'] ) : ?>
		<div class="chrx-rm-panel">
			<div class="chrx-rm-panel__header"><span><?php esc_html_e( 'Move-out notice', 'chrx-rental-manager' ); ?></span></div>
			<div class="chrx-rm-panel__body">
				<?php if ( null !== $active_notice ) : ?>
					<p style="margin:0 0 10px;font-size:13px;">
						<?php
						printf(
							/* translators: 1: notice date, 2: earliest move-out date, 3: submitted by */
							esc_html__( 'Given %1$s (by %3$s) — earliest move-out date %2$s.', 'chrx-rental-manager' ),
							esc_html( gmdate( 'j M Y', strtotime( $active_notice['notice_date'] ) ) ),
							esc_html( gmdate( 'j M Y', strtotime( $active_notice['earliest_move_out_date'] ) ) ),
							esc_html( ucfirst( $active_notice['submitted_by'] ) )
						);
						?>
					</p>
					<?php if ( $can_manage ) : ?>
						<a href="
						<?php
						echo esc_url(
							wp_nonce_url(
								add_query_arg(
									array(
										'action'    => 'rm_staff_cancel_notice',
										'notice_id' => $active_notice['id'],
									),
									admin_url( 'admin-post.php' )
								),
								\ChrxRentalManager\Admin\StaffMoveOutNoticeController::cancel_notice_action_for( (int) $active_notice['id'] )
							)
						);
						?>
						" class="button" onclick="return confirm('<?php echo esc_js( __( 'Cancel this move-out notice?', 'chrx-rental-manager' ) ); ?>');"><?php esc_html_e( 'Cancel notice', 'chrx-rental-manager' ); ?></a>
					<?php endif; ?>
				<?php elseif ( $can_manage ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
						<input type="hidden" name="action" value="rm_staff_give_notice">
						<input type="hidden" name="lease_id" value="<?php echo esc_attr( (string) $lease['id'] ); ?>">
						<?php wp_nonce_field( \ChrxRentalManager\Admin\StaffMoveOutNoticeController::give_notice_action() ); ?>
						<label style="font-size:12px;color:#646970;">
							<?php esc_html_e( 'Preferred move-out date (optional)', 'chrx-rental-manager' ); ?>
							<input type="date" name="rm_requested_move_out_date" style="display:block;margin-top:4px;">
						</label>
						<button type="submit" class="button"><?php esc_html_e( 'Record walk-in notice', 'chrx-rental-manager' ); ?></button>
					</form>
				<?php else : ?>
					<p style="color:#8c8f94;font-size:13px;margin:0;"><?php esc_html_e( 'No active notice.', 'chrx-rental-manager' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

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
						<th><?php esc_html_e( 'Actions', 'chrx-rental-manager' ); ?></th>
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
							<td>
								<?php if ( $can_manage && Charge::TYPE_LATE_FEE === $charge['type'] && in_array( $charge['status'], array( Charge::STATUS_UNPAID, Charge::STATUS_PARTIAL ), true ) ) : ?>
									<?php
									$waive_url = wp_nonce_url(
										add_query_arg(
											array(
												'page' => 'chrx-rm-leases',
												'rm_action' => 'waive_charge',
												'charge_id' => $charge['id'],
												'id'   => $lease['id'],
											),
											admin_url( 'admin.php' )
										),
										'rm_charge_waive'
									);
									?>
									<a href="<?php echo esc_url( $waive_url ); ?>" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Waive this late fee?', 'chrx-rental-manager' ) ); ?>');"><?php esc_html_e( 'Waive', 'chrx-rental-manager' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<?php require \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/partials/documents-panel.php'; ?>
</div>

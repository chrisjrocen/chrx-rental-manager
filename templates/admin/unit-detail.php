<?php
/**
 * Unit detail (designs/09-unit-detail.html).
 * Variables in scope: $unit (array), $property (?array),
 * $lease_history (array<int,array> with 'tenant_name' added),
 * $documents (array<int,array>), $can_manage (bool), $notice (?string).
 * v2: $active_lease_count (int), $unit_amenities (array<int,array>).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\Support\Badge;
use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Data\Document;
use ChrxRentalManager\Data\Lease;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$list_url    = add_query_arg( 'page', 'chrx-rm-units', admin_url( 'admin.php' ) );
$edit_url    = add_query_arg(
	array(
		'page'   => 'chrx-rm-units',
		'action' => 'edit',
		'id'     => $unit['id'],
	),
	admin_url( 'admin.php' )
);
$entity_type = Document::ENTITY_UNIT;
$entity_id   = (int) $unit['id'];
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-breadcrumb">
		<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Units', 'chrx-rental-manager' ); ?></a> &rsaquo;
		<?php echo null === $property ? '' : esc_html( $property['name'] ) . ' &rsaquo; '; ?>
		<?php echo esc_html( $unit['unit_label'] ); ?>
	</div>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<div class="chrx-rm-detail-header">
		<div class="chrx-rm-detail-header__title">
			<h1><?php echo esc_html( sprintf( /* translators: %s: unit label */ __( 'Unit %s', 'chrx-rental-manager' ), $unit['unit_label'] ) ); ?></h1>
			<?php echo wp_kses_post( Badge::render( $unit['status'] ) ); ?>
		</div>
		<?php if ( $can_manage ) : ?>
			<?php
			$trash_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'      => 'chrx-rm-units',
						'rm_action' => 'archive',
						'id'        => $unit['id'],
					),
					admin_url( 'admin.php' )
				),
				'rm_unit_archive'
			);
			?>
			<div class="chrx-rm-detail-header__actions">
				<a href="<?php echo esc_url( $edit_url ); ?>" class="button"><?php esc_html_e( 'Edit unit', 'chrx-rental-manager' ); ?></a>
				<a href="<?php echo esc_url( $trash_url ); ?>" class="button" onclick="return confirm('<?php echo esc_js( __( 'Move this unit to trash?', 'chrx-rental-manager' ) ); ?>');"><?php esc_html_e( 'Move to Trash', 'chrx-rental-manager' ); ?></a>
			</div>
		<?php endif; ?>
	</div>

	<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:18px;">
		<div>
			<div class="chrx-rm-panel">
				<div class="chrx-rm-panel__body" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:16px;">
					<div>
						<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Type', 'chrx-rental-manager' ); ?></div>
						<div style="font-size:15px;font-weight:600;"><?php echo 0 === (int) $unit['bedrooms'] ? esc_html__( 'Studio', 'chrx-rental-manager' ) : esc_html( sprintf( '%d-bedroom', (int) $unit['bedrooms'] ) ); ?></div>
					</div>
					<div>
						<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Monthly rent', 'chrx-rental-manager' ); ?></div>
						<div style="font-size:15px;font-weight:600;"><?php echo esc_html( Money::format( (float) $unit['rent_amount'] ) ); ?></div>
					</div>
					<div>
						<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Property', 'chrx-rental-manager' ); ?></div>
						<div style="font-size:15px;font-weight:600;"><?php echo null === $property ? '' : esc_html( $property['name'] ); ?></div>
					</div>
					<div>
						<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Occupancy', 'chrx-rental-manager' ); ?></div>
						<div style="font-size:15px;font-weight:600;">
							<?php
							$capacity = (int) $unit['capacity'];
							echo $capacity > 1
								? esc_html( sprintf( /* translators: 1: filled beds, 2: total beds */ __( '%1$d/%2$d beds', 'chrx-rental-manager' ), $active_lease_count, $capacity ) )
								: esc_html( $active_lease_count > 0 ? __( 'Occupied', 'chrx-rental-manager' ) : __( 'Vacant', 'chrx-rental-manager' ) );
							?>
						</div>
						<div style="font-size:12px;color:#646970;margin-top:4px;">
							<?php
							echo esc_html(
								array(
									\ChrxRentalManager\Data\Unit::OCCUPANCY_SINGLE => __( 'Single', 'chrx-rental-manager' ),
									\ChrxRentalManager\Data\Unit::OCCUPANCY_DOUBLE => __( 'Double', 'chrx-rental-manager' ),
									\ChrxRentalManager\Data\Unit::OCCUPANCY_FAMILY => __( 'Family', 'chrx-rental-manager' ),
								)[ $unit['occupancy_type'] ] ?? $unit['occupancy_type']
							);
							echo $unit['self_contained'] ? esc_html( ' · ' . __( 'Self-contained', 'chrx-rental-manager' ) ) : esc_html( ' · ' . __( 'Shared facilities', 'chrx-rental-manager' ) );
							?>
						</div>
					</div>
				</div>
			</div>

			<?php if ( array() !== $unit_amenities ) : ?>
				<div class="chrx-rm-panel">
					<div class="chrx-rm-panel__header"><?php esc_html_e( 'Amenities', 'chrx-rental-manager' ); ?></div>
					<div class="chrx-rm-panel__body" style="display:flex;flex-wrap:wrap;gap:6px;">
						<?php foreach ( $unit_amenities as $amenity ) : ?>
							<span style="background:#f0f0f1;border-radius:14px;padding:4px 12px;font-size:12px;font-weight:600;color:#3c434a;"><?php echo esc_html( $amenity['tag'] ); ?></span>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="chrx-rm-panel">
				<div class="chrx-rm-panel__header"><?php esc_html_e( 'Lease history', 'chrx-rental-manager' ); ?></div>
				<?php if ( array() === $lease_history ) : ?>
					<div class="chrx-rm-panel__body"><p style="color:#8c8f94;font-size:13px;margin:0;"><?php esc_html_e( 'No leases yet.', 'chrx-rental-manager' ); ?></p></div>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Tenant', 'chrx-rental-manager' ); ?></th>
								<th><?php esc_html_e( 'Term', 'chrx-rental-manager' ); ?></th>
								<th><?php esc_html_e( 'Status', 'chrx-rental-manager' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $lease_history as $lease ) : ?>
								<?php
								$lease_url = add_query_arg(
									array(
										'page' => 'chrx-rm-leases',
										'id'   => $lease['id'],
									),
									admin_url( 'admin.php' )
								);
								?>
								<tr>
									<td><a href="<?php echo esc_url( $lease_url ); ?>" style="font-weight:600;"><?php echo esc_html( $lease['tenant_name'] ); ?></a></td>
									<td><?php echo esc_html( gmdate( 'M Y', strtotime( $lease['start_date'] ) ) . ' – ' . gmdate( 'M Y', strtotime( $lease['end_date'] ) ) ); ?></td>
									<td><?php echo wp_kses_post( Badge::render( $lease['status'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<div>
			<?php require \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/partials/documents-panel.php'; ?>
		</div>
	</div>
</div>

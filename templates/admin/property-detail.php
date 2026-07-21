<?php
/**
 * Property detail (designs/06-property-detail.html).
 * Variables in scope: $property (array), $units (array<int,array>),
 * $occupied_count (int), $vacant_count (int), $landlord_ids (array<int,int>),
 * $staff_ids (array<int,int>), $can_manage (bool), $notice (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\Support\Badge;
use ChrxRentalManager\Admin\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$list_url     = add_query_arg( 'page', 'chrx-rm-properties', admin_url( 'admin.php' ) );
$edit_url     = add_query_arg(
	array(
		'page'   => 'chrx-rm-properties',
		'action' => 'edit',
		'id'     => $property['id'],
	),
	admin_url( 'admin.php' )
);
$add_unit_url = add_query_arg(
	array(
		'page'        => 'chrx-rm-units',
		'action'      => 'add',
		'property_id' => $property['id'],
	),
	admin_url( 'admin.php' )
);
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-breadcrumb"><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Properties', 'chrx-rental-manager' ); ?></a> &rsaquo; <?php echo esc_html( $property['name'] ); ?></div>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<div class="chrx-rm-detail-header">
		<div>
			<h1 style="margin:0;font-size:23px;font-weight:600;"><?php echo esc_html( $property['name'] ); ?></h1>
			<div style="font-size:13px;color:#646970;">
				<?php echo esc_html( trim( $property['address'] . ( '' !== $property['city'] ? ', ' . $property['city'] : '' ), ', ' ) ); ?>
				&middot;
				<?php
				printf(
					/* translators: %d: unit count */
					esc_html( _n( '%d unit', '%d units', count( $units ), 'chrx-rental-manager' ) ),
					count( $units )
				);
				?>
			</div>
		</div>
		<?php if ( $can_manage ) : ?>
			<?php
			$trash_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'      => 'chrx-rm-properties',
						'rm_action' => 'archive',
						'id'        => $property['id'],
					),
					admin_url( 'admin.php' )
				),
				'rm_property_archive'
			);
			?>
			<div class="chrx-rm-detail-header__actions">
				<a href="<?php echo esc_url( $edit_url ); ?>" class="button"><?php esc_html_e( 'Edit property', 'chrx-rental-manager' ); ?></a>
				<a href="<?php echo esc_url( $add_unit_url ); ?>" class="button button-primary"><?php esc_html_e( 'Add unit', 'chrx-rental-manager' ); ?></a>
				<a href="<?php echo esc_url( $trash_url ); ?>" class="button" onclick="return confirm('<?php echo esc_js( __( 'Move this property to trash?', 'chrx-rental-manager' ) ); ?>');"><?php esc_html_e( 'Move to Trash', 'chrx-rental-manager' ); ?></a>
			</div>
		<?php endif; ?>
	</div>

	<div class="chrx-rm-stat-grid">
		<div class="chrx-rm-stat-card">
			<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Occupied', 'chrx-rental-manager' ); ?></div>
			<div class="chrx-rm-stat-card__value"><?php echo esc_html( (string) $occupied_count ); ?></div>
		</div>
		<div class="chrx-rm-stat-card">
			<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Vacant', 'chrx-rental-manager' ); ?></div>
			<div class="chrx-rm-stat-card__value"><?php echo esc_html( (string) $vacant_count ); ?></div>
		</div>
		<div class="chrx-rm-stat-card">
			<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Landlord-Owner', 'chrx-rental-manager' ); ?></div>
			<?php if ( array() === $landlord_ids ) : ?>
				<div style="color:#8c8f94;">&mdash;</div>
			<?php else : ?>
				<?php foreach ( $landlord_ids as $landlord_id ) : ?>
					<?php $user = get_userdata( $landlord_id ); ?>
					<?php if ( false !== $user ) : ?>
						<div style="font-size:15px;font-weight:700;margin-top:4px;"><?php echo esc_html( $user->display_name ); ?></div>
						<div style="font-size:12px;color:#646970;"><?php echo esc_html( $user->user_email ); ?></div>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<div class="chrx-rm-stat-card">
			<div class="chrx-rm-stat-card__label"><?php esc_html_e( 'Assigned staff', 'chrx-rental-manager' ); ?></div>
			<?php if ( array() === $staff_ids ) : ?>
				<div style="color:#8c8f94;">&mdash;</div>
			<?php else : ?>
				<?php foreach ( $staff_ids as $staff_id ) : ?>
					<?php $user = get_userdata( $staff_id ); ?>
					<?php if ( false !== $user ) : ?>
						<div style="font-size:14px;font-weight:600;margin-top:4px;"><?php echo esc_html( $user->display_name ); ?></div>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>

	<div class="chrx-rm-panel">
		<div class="chrx-rm-panel__header"><?php esc_html_e( 'Units', 'chrx-rental-manager' ); ?></div>
		<?php if ( array() === $units ) : ?>
			<div class="chrx-rm-empty-state">
				<div class="chrx-rm-empty-state__title"><?php esc_html_e( 'No units yet', 'chrx-rental-manager' ); ?></div>
				<div class="chrx-rm-empty-state__desc"><?php esc_html_e( 'Add your first unit to this property to start creating leases.', 'chrx-rental-manager' ); ?></div>
				<?php if ( $can_manage ) : ?>
					<a href="<?php echo esc_url( $add_unit_url ); ?>" class="button button-primary"><?php esc_html_e( 'Add Unit', 'chrx-rental-manager' ); ?></a>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Unit', 'chrx-rental-manager' ); ?></th>
						<th><?php esc_html_e( 'Bedrooms', 'chrx-rental-manager' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Rent', 'chrx-rental-manager' ); ?></th>
						<th><?php esc_html_e( 'Status', 'chrx-rental-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $units as $unit ) : ?>
						<?php
						$unit_url = add_query_arg(
							array(
								'page' => 'chrx-rm-units',
								'id'   => $unit['id'],
							),
							admin_url( 'admin.php' )
						);
						?>
						<tr>
							<td><a href="<?php echo esc_url( $unit_url ); ?>" style="font-weight:600;"><?php echo esc_html( $unit['unit_label'] ); ?></a></td>
							<td><?php echo esc_html( (string) $unit['bedrooms'] ); ?></td>
							<td style="text-align:right;"><?php echo esc_html( Money::format( (float) $unit['rent_amount'] ) ); ?></td>
							<td><?php echo wp_kses_post( Badge::render( $unit['status'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
